<?php
/**
 * Task Board — authentication + authorization layer.
 *
 *   session + CSRF        auth_start_session(), csrf_token(), csrf_check()
 *   current user          current_user(), require_login()
 *   project access        membership(), require_membership()
 *   accounts              create_user(), login_as(), logout()
 *   email verification    create_email_verification(), consume_email_verification()
 *   password reset        create_password_reset(), password_reset_token_valid(), consume_password_reset()
 *   mail                  deliver_reset_link(), deliver_verification_email()
 *   brute-force throttle  throttle_check(), throttle_record_failure(), throttle_clear()
 *   first-run setup       adopt_orphan_projects(), seed_starter_project()
 *
 * Uses PHP native sessions (single-server, self-hosted) and password_hash().
 * No external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

tb_load_env(__DIR__ . '/../.env');

// ---- Config (from .env; see .env.example) ----------------------------------

/** How long a password-reset token stays valid. */
const RESET_TTL_SECONDS = 3600;        // 1 hour

/** How long an email-verification token stays valid. */
const VERIFY_TTL_SECONDS = 86400;      // 24 hours

/** Max failed logins for one email before the throttle engages. */
const LOGIN_MAX_FAILURES = 5;

/** Window over which failures are counted. */
const LOGIN_WINDOW_SECONDS = 900;      // 15 min

/**
 * When true, anyone can create an account (email verification required).
 * When false, only the first user may register — thereafter invite-only.
 */
define('ALLOW_OPEN_REGISTRATION', tb_env_bool('ALLOW_OPEN_REGISTRATION', true));

/** Trust X-Forwarded-* only behind a known reverse proxy. */
define('TRUST_PROXY', tb_env_bool('TRUST_PROXY', false));

/** Mail / signup rate limits (per bucket, per window). */
const MAIL_RATE_MAX = 3;
const MAIL_RATE_WINDOW = 900;          // 15 min
const REGISTER_RATE_MAX = 5;
const REGISTER_RATE_WINDOW = 3600;     // 1 hour

/** Anonymous share-board reads (per IP / per token, per window). */
const SHARE_PUBLIC_RATE_MAX = 60;      // ~1/sec average; share UI polls ~every 5s
const SHARE_PUBLIC_RATE_WINDOW = 60;   // 1 min

// SMTP — prefer MAIL_* (Laravel-style); SMTP_* still accepted as aliases.
define('SMTP_HOST', tb_env('MAIL_HOST', tb_env('SMTP_HOST', '')) ?? '');
define('SMTP_PORT', tb_env_int('MAIL_PORT', tb_env_int('SMTP_PORT', 587)));
define('SMTP_USER', tb_env('MAIL_USERNAME', tb_env('SMTP_USER', '')) ?? '');
define('SMTP_PASS', tb_env('MAIL_PASSWORD', tb_env('SMTP_PASS', '')) ?? '');
define('SMTP_ENCRYPTION', strtolower(tb_env('MAIL_ENCRYPTION', tb_env('SMTP_ENCRYPTION', 'tls')) ?? 'tls'));
define('SMTP_FROM_EMAIL', tb_env('MAIL_FROM_ADDRESS', tb_env('SMTP_FROM_EMAIL', '')) ?? '');
define('SMTP_FROM_NAME', tb_env('MAIL_FROM_NAME', tb_env('SMTP_FROM_NAME', 'Task Board')) ?? 'Task Board');

/**
 * Public base URL of this app (no trailing slash), used in email links.
 * Example: https://example.com/task-board
 * Leave empty to auto-detect from the current request (less reliable behind proxies).
 */
define('APP_BASE_URL', rtrim(tb_env('APP_BASE_URL', '') ?? '', '/'));

/**
 * How often the browser polls for new notifications (seconds).
 * Clamped to 1–300. Change via NOTIFICATION_POLL_SECONDS in .env.
 */
define(
    'NOTIFICATION_POLL_SECONDS',
    max(1, min(300, tb_env_int('NOTIFICATION_POLL_SECONDS', 2)))
);

/**
 * When true, enable debug logging (e.g. AI Chat request/response → logs/).
 * Keep false in production. Set via DEBUG in .env.
 */
define('DEBUG', tb_env_bool('DEBUG', false));

/**
 * How long the login session lasts (seconds). Default 15552000 = 180 days (6 months).
 * Cookie Max-Age and session.gc_maxlifetime both use this value.
 */
define('SESSION_LIFETIME', max(3600, tb_env_int('SESSION_LIFETIME', 15552000)));

require_once __DIR__ . '/mail.php';
// ---- Session + CSRF --------------------------------------------------------

function auth_start_session(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (TRUST_PROXY && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }

    // Keep server-side session files as long as the cookie (PHP default GC is ~24 min).
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.cookie_lifetime', (string) SESSION_LIFETIME);

    $cookie = [
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,              // not readable from JS
        'samesite' => 'Lax',             // mitigates CSRF on top-level navigations
        'secure'   => $https,            // HTTPS-only when the connection is HTTPS
    ];
    session_set_cookie_params($cookie);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Sliding expiry: refresh the cookie on each request while logged in.
    if (!empty($_SESSION['uid'])) {
        setcookie((string) session_name(), (string) session_id(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => $cookie['path'],
            'secure'   => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);
    }

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    return (string) ($_SESSION['csrf'] ?? '');
}

/** True if the request carries the matching CSRF token header. */
function csrf_check(): bool
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf'], $sent);
}

/** Emit a JSON error and stop. Used by api.php. */
function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// ---- Current user + access -------------------------------------------------

/** Logged-in user row {id,name,email,photo_url,email_verified}, or null. Cached per request. */
function current_user(): ?array
{
    static $me = false; // false = unresolved
    if ($me !== false) {
        return $me ?: null;
    }
    if (empty($_SESSION['uid'])) {
        $me = null;
        return null;
    }
    $st = db()->prepare('SELECT id, name, email, photo_url, email_verified_at FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $row = $st->fetch() ?: null;
    if ($row) {
        $row['email_verified'] = !empty($row['email_verified_at']);
        unset($row['email_verified_at']);
    }
    $me = $row;
    return $me;
}

/** The current user, or fail 401. */
function require_login(): array
{
    $me = current_user();
    if ($me === null) {
        json_error('Not logged in.', 401);
    }
    if (empty($me['email_verified'])) {
        json_error('Please verify your email before continuing.', 403);
    }
    return $me;
}

/** The current user's member row in a project, or null. */
function membership(PDO $pdo, string $pid): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM members WHERE project_id = ? AND user_id = ? LIMIT 1');
    $st->execute([$pid, $_SESSION['uid']]);
    return $st->fetch() ?: null;
}

/** Require the current user to be a member of $pid; return their member row. */
function require_membership(PDO $pdo, string $pid): array
{
    require_login();
    $m = membership($pdo, $pid);
    if ($m === null) {
        json_error('You do not have access to this project.', 403);
    }
    return $m;
}

// ---- Login / logout --------------------------------------------------------

/** Authenticate the session as $userId (regenerates the id to defeat fixation). */
function login_as(string $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    // Bust current_user() static cache in this request.
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            (string) session_name(),
            '',
            time() - 42000,
            (string) $p['path'],
            (string) ($p['domain'] ?? ''),
            (bool) ($p['secure'] ?? false),
            (bool) ($p['httponly'] ?? false)
        );
    }
    session_destroy();
}

// ---- Account creation ------------------------------------------------------

/**
 * Create a user. Pass $verified=true for first-user bootstrap (no email needed).
 * Otherwise email_verified_at stays NULL until they click the verify link.
 */
function create_user(PDO $pdo, string $name, string $email, string $password, bool $verified = false): string
{
    $id = 'user_' . bin2hex(random_bytes(8));
    $verifiedAt = $verified ? gmdate('Y-m-d H:i:s') : null;
    $pdo->prepare('INSERT INTO users (id, name, email, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $name, $email, password_hash($password, PASSWORD_DEFAULT), $verifiedAt]);
    return $id;
}

/**
 * Ensure a non-login bot account exists for AI-authored comments.
 *
 * @return array{id:string,name:string,email:string,photo_url:?string}
 */
function ensure_ai_chat_user(PDO $pdo): array
{
    $email = 'ai-chat@local.task-board';
    $st = $pdo->prepare('SELECT id, name, email, photo_url FROM users WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row) {
        return [
            'id'        => (string) $row['id'],
            'name'      => (string) ($row['name'] ?: 'AI Chat'),
            'email'     => (string) $row['email'],
            'photo_url' => $row['photo_url'] ?? null,
        ];
    }

    $id = 'user_ai_' . bin2hex(random_bytes(6));
    $pdo->prepare(
        'INSERT INTO users (id, name, email, password_hash, email_verified_at)
         VALUES (?, ?, ?, ?, datetime(\'now\'))'
    )->execute([
        $id,
        'AI Chat',
        $email,
        password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
    ]);

    return [
        'id'        => $id,
        'name'      => 'AI Chat',
        'email'     => $email,
        'photo_url' => null,
    ];
}

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function user_is_verified(array $userRow): bool
{
    return !empty($userRow['email_verified_at']);
}

function mark_user_verified(PDO $pdo, string $userId): void
{
    $pdo->prepare("UPDATE users SET email_verified_at = COALESCE(email_verified_at, datetime('now')) WHERE id = ?")
        ->execute([$userId]);
}

// ---- Brute-force throttle --------------------------------------------------

/** Reject with 429 if $email has tripped the throttle. */
function throttle_check(PDO $pdo, string $email): void
{
    $st = $pdo->prepare('SELECT failures, last_fail FROM login_throttle WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if (!$row) {
        return;
    }
    if ((int) $row['failures'] >= LOGIN_MAX_FAILURES) {
        $elapsed = time() - strtotime((string) $row['last_fail']);
        if ($elapsed < LOGIN_WINDOW_SECONDS) {
            $wait = (int) ceil((LOGIN_WINDOW_SECONDS - $elapsed) / 60);
            json_error("Too many failed attempts. Try again in ~{$wait} min.", 429);
        }
        // Window elapsed: forgive and let this attempt through.
        $pdo->prepare('UPDATE login_throttle SET failures = 0 WHERE email = ?')->execute([$email]);
    }
}

function throttle_record_failure(PDO $pdo, string $email): void
{
    $pdo->prepare(
        "INSERT INTO login_throttle (email, failures, last_fail) VALUES (?, 1, datetime('now'))
         ON CONFLICT(email) DO UPDATE SET failures = failures + 1, last_fail = datetime('now')"
    )->execute([$email]);
}

function throttle_clear(PDO $pdo, string $email): void
{
    $pdo->prepare('DELETE FROM login_throttle WHERE email = ?')->execute([$email]);
}

/**
 * Sliding fixed-window rate limit. Increments on each call; rejects with 429
 * when $max hits are exceeded inside $windowSeconds.
 */
function rate_limit(PDO $pdo, string $bucket, int $max, int $windowSeconds): void
{
    $bucket = mb_strtolower(trim($bucket));
    if ($bucket === '') {
        return;
    }
    $st = $pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE bucket = ?');
    $st->execute([$bucket]);
    $row = $st->fetch();
    $now = time();

    if (!$row) {
        $pdo->prepare('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)')
            ->execute([$bucket, gmdate('Y-m-d H:i:s', $now)]);
        return;
    }

    $start = strtotime((string) $row['window_start'] . ' UTC');
    if ($start === false) {
        $start = $now;
    }
    $elapsed = $now - $start;
    if ($elapsed >= $windowSeconds) {
        $pdo->prepare('UPDATE rate_limits SET hits = 1, window_start = ? WHERE bucket = ?')
            ->execute([gmdate('Y-m-d H:i:s', $now), $bucket]);
        return;
    }

    $hits = (int) $row['hits'];
    if ($hits >= $max) {
        $wait = (int) ceil(($windowSeconds - $elapsed) / 60);
        if ($wait < 1) {
            $wait = 1;
        }
        json_error("Too many requests. Try again in ~{$wait} min.", 429);
    }

    $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?')->execute([$bucket]);
}

/** Client IP for rate limiting (direct connection; proxies need TRUST_PROXY=true). */
function client_ip(): string
{
    if (defined('TRUST_PROXY') && TRUST_PROXY) {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            if ($parts[0] !== '') {
                return $parts[0];
            }
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// ---- Tokens ----------------------------------------------------------------

function new_token(): string
{
    return bin2hex(random_bytes(32));
}

function hash_token(string $token): string
{
    return hash('sha256', $token);
}

/** Absolute app URL for email links (no trailing slash). */
function app_base_url(): string
{
    if (APP_BASE_URL !== '') {
        return rtrim(APP_BASE_URL, '/');
    }
    // Host-header auto-detect is disabled for email links (open redirect risk).
    // Local/dev fallback only when explicitly empty — prefer setting APP_BASE_URL.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (TRUST_PROXY && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }
    $scheme = $https ? 'https' : 'http';
    // Prefer SERVER_NAME over Host header when APP_BASE_URL is unset.
    $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if ($host === '' || $host === '0.0.0.0') {
        $host = 'localhost';
    }
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    if ($port > 0 && !in_array($port, [80, 443], true) && !str_contains($host, ':')) {
        $host .= ':' . $port;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $dir = '';
    }
    return $scheme . '://' . $host . $dir;
}

/** Require a configured APP_BASE_URL before sending mail with links. */
function require_app_base_url(): void
{
    if (APP_BASE_URL === '') {
        throw new RuntimeException('APP_BASE_URL is not set in .env (required for email links).');
    }
}

function app_link(string $queryPath): string
{
    // $queryPath like 'index.php?verify=TOKEN'
    return app_base_url() . '/' . ltrim($queryPath, '/');
}

// ---- Email verification ----------------------------------------------------

/** Create a fresh verification token for $userId (invalidating prior unused ones). */
function create_email_verification(PDO $pdo, string $userId): string
{
    $pdo->prepare("UPDATE email_verifications SET used_at = datetime('now') WHERE user_id = ? AND used_at IS NULL")
        ->execute([$userId]);

    $token   = new_token();
    $expires = gmdate('Y-m-d H:i:s', time() + VERIFY_TTL_SECONDS);
    $id      = 'ver_' . bin2hex(random_bytes(6));
    $pdo->prepare('INSERT INTO email_verifications (id, user_id, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, $userId, hash_token($token), $expires]);
    return $token;
}

/** Consume a verification token; return user_id if valid, else ''. */
function consume_email_verification(PDO $pdo, string $token): string
{
    $st = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM email_verifications WHERE token_hash = ? LIMIT 1');
    $st->execute([hash_token($token)]);
    $row = $st->fetch();
    if (!$row || $row['used_at'] !== null) {
        return '';
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return '';
    }
    $pdo->prepare("UPDATE email_verifications SET used_at = datetime('now') WHERE id = ?")->execute([$row['id']]);
    return (string) $row['user_id'];
}

/** Send the verification email. Returns true on success. */
function deliver_verification_email(string $email, string $name, string $token): bool
{
    require_app_base_url();
    $link = app_link('index.php?verify=' . urlencode($token));
    $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = '<p>Hi ' . $safeName . ',</p>'
        . '<p>Welcome to <strong>Task Board</strong>. Please verify your email to activate your account:</p>'
        . '<p><a href="' . $safeLink . '">Verify my email</a></p>'
        . '<p>Or copy this link:<br><code>' . $safeLink . '</code></p>'
        . '<p>This link expires in 24 hours.</p>';
    $text = "Hi {$name},\n\nVerify your Task Board email:\n{$link}\n\nThis link expires in 24 hours.\n";
    return smtp_send($email, 'Verify your Task Board email', $html, $text);
}

// ---- Password reset --------------------------------------------------------

/** Create a fresh reset token for $userId (invalidating prior unused ones). */
function create_password_reset(PDO $pdo, string $userId): string
{
    $pdo->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE user_id = ? AND used_at IS NULL")
        ->execute([$userId]);

    $token   = new_token();
    $expires = gmdate('Y-m-d H:i:s', time() + RESET_TTL_SECONDS);
    $id      = 'rst_' . bin2hex(random_bytes(6));
    $pdo->prepare('INSERT INTO password_resets (id, user_id, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, $userId, hash_token($token), $expires]);
    return $token;
}

/** Check whether a reset token is still valid (unused and unexpired). */
function password_reset_token_valid(PDO $pdo, string $token): bool
{
    if ($token === '') {
        return false;
    }
    $st = $pdo->prepare('SELECT expires_at, used_at FROM password_resets WHERE token_hash = ? LIMIT 1');
    $st->execute([hash_token($token)]);
    $row = $st->fetch();
    if (!$row || $row['used_at'] !== null) {
        return false;
    }
    return strtotime((string) $row['expires_at']) >= time();
}

/** Mark every unused reset token for $userId as consumed. */
function invalidate_password_resets(PDO $pdo, string $userId): void
{
    $pdo->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE user_id = ? AND used_at IS NULL")
        ->execute([$userId]);
}

/** Consume a reset token; return the user_id if valid+unused+unexpired, else ''. */
function consume_password_reset(PDO $pdo, string $token): string
{
    $st = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM password_resets WHERE token_hash = ? LIMIT 1');
    $st->execute([hash_token($token)]);
    $row = $st->fetch();
    if (!$row || $row['used_at'] !== null) {
        return '';
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return '';
    }
    invalidate_password_resets($pdo, (string) $row['user_id']);
    return (string) $row['user_id'];
}

/**
 * Email a password-reset link via SMTP.
 */
function deliver_reset_link(string $email, string $token): void
{
    require_app_base_url();
    $link = app_link('index.php?reset=' . urlencode($token));
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = '<p>We received a request to reset your Task Board password.</p>'
        . '<p><a href="' . $safeLink . '">Reset my password</a></p>'
        . '<p>Or copy this link:<br><code>' . $safeLink . '</code></p>'
        . '<p>If you did not request this, you can ignore this email. The link expires in 1 hour.</p>';
    $text = "Reset your Task Board password:\n{$link}\n\nIf you did not request this, ignore this email. Expires in 1 hour.\n";
    smtp_send($email, 'Reset your Task Board password', $html, $text);
}

// ---- First-run project setup ----------------------------------------------

/** Projects that pre-date accounts and have no owner member. */
function orphan_projects(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id FROM projects p
         WHERE NOT EXISTS (SELECT 1 FROM members m WHERE m.project_id = p.id AND m.role = 'owner')"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** Make $userId the owner of every ownerless project. Returns count adopted. */
function adopt_orphan_projects(PDO $pdo, string $userId): int
{
    $pids = orphan_projects($pdo);
    if (!$pids) {
        return 0;
    }
    $u = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) {
        return 0;
    }
    $ins = $pdo->prepare(
        "INSERT INTO members (id, project_id, name, color, position, user_id, role, email)
         VALUES (?, ?, ?, ?, ?, ?, 'owner', ?)"
    );
    foreach ($pids as $pid) {
        $ins->execute([
            'mem_' . bin2hex(random_bytes(6)), $pid,
            (string) $user['name'], '#2563eb', 0, $userId, (string) $user['email'],
        ]);
    }
    return count($pids);
}

/** Seed a starter project owned by $userId (used if the first user has none). */
function seed_starter_project(PDO $pdo, string $userId): string
{
    $u = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $u->execute([$userId]);
    $user = $u->fetch() ?: ['name' => 'Me', 'email' => null];

    $pid = 'proj_' . bin2hex(random_bytes(6));
    $pdo->prepare('INSERT INTO projects (id, name, position) VALUES (?, ?, ?)')
        ->execute([$pid, 'My First Project', 0]);

    $firstListId = insert_default_lists($pdo, $pid);

    $cid = 'card_' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO cards (id, list_id, title, description, position) VALUES (?, ?, ?, ?, ?)')
        ->execute([$cid, $firstListId, 'Welcome to your board', 'Invite teammates from the Members button, then assign them to cards.', 0]);

    $pdo->prepare(
        "INSERT INTO members (id, project_id, name, color, position, user_id, role, email)
         VALUES (?, ?, ?, ?, ?, ?, 'owner', ?)"
    )->execute([
        'mem_' . bin2hex(random_bytes(6)), $pid,
        (string) $user['name'], '#2563eb', 0, $userId, (string) $user['email'],
    ]);
    return $pid;
}
