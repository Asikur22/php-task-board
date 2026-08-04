<?php
/**
 * Task Board — API router (SQLite-backed, multi-project).
 *
 *   GET  api.php?op=projects                        -> [{id,name}, ...]
 *   POST api.php?op=project_create      {name}      -> {ok,id,name}
 *   POST api.php?op=project_archive     {id}        -> {ok}
 *   POST api.php?op=project_unarchive   {id}        -> {ok}
 *   GET  api.php?op=board&project=ID                -> {ok,data:{title,members,lists}}
 *   POST api.php?op=board_save&project=ID {title,members,lists} -> {ok}
 *
 * board_save transactionally REPLACES the project's lists/cards/members/images
 * (delete + re-insert with the client's existing text ids), then runs GC over
 * uploads/.
 *
 * NOTE: local / trusted single-user use. Add auth before exposing publicly.
 */

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/sanitize.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
auth_start_session();

/** Member color palette (kept in sync with MEMBER_COLORS in app.js). */
const MEMBER_COLORS = [
    '#e85d75', '#f4a261', '#2a9d8f', '#3a86ff',
    '#8338ec', '#fb5607', '#ffbe0b', '#06d6a0',
];

/** Deterministic color for an invitee, seeded by their user id. */
function pick_member_color(string $seed): string
{
    $h = 0;
    for ($i = 0, $n = strlen($seed); $i < $n; $i++) {
        $h = (($h * 31) + ord($seed[$i])) & 0x7fffffff;
    }
    return MEMBER_COLORS[$h % count(MEMBER_COLORS)];
}

/** JSON error + stop. */
function fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/** True when the project exists and has archived_at set. */
function project_is_archived(PDO $pdo, string $pid): bool
{
    if ($pid === '') {
        return false;
    }
    $st = $pdo->prepare('SELECT archived_at FROM projects WHERE id = ?');
    $st->execute([$pid]);
    $v = $st->fetchColumn();
    return $v !== false && $v !== null && trim((string) $v) !== '';
}

/** Reject mutating ops on archived projects (restore first). */
function require_project_writable(PDO $pdo, string $pid): void
{
    if (project_is_archived($pdo, $pid)) {
        fail('This project is archived and cannot be edited. Restore it first.', 403);
    }
}

/** Log a server exception and return a generic client-safe error. */
function fail_server(Throwable $e): void
{
    error_log('Task Board error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Something went wrong. Please try again.', 500);
}

/** True when self-service signup is allowed (or first-user bootstrap). */
function registration_is_open(PDO $pdo): bool
{
    if (ALLOW_OPEN_REGISTRATION) {
        return true;
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    return $count === 0;
}

/** Decoded JSON request body (or []); cached so it survives multiple reads. */
function json_body(): array
{
    static $body = null;
    if ($body === null) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($decoded) ? $decoded : [];
    }
    return $body;
}

/** Helper: fetch all rows. */
function rows(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Helper: fetch a single column as a flat array. */
function col(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Notify every linked project member except the actor.
 *
 * @param array{type:string,message:string,card_id?:?string,meta?:?array} $payload
 */
function create_notifications(PDO $pdo, string $projectId, array $actor, array $payload): void
{
    $recipients = col(
        $pdo,
        'SELECT DISTINCT user_id FROM members WHERE project_id = ? AND user_id IS NOT NULL AND user_id != ?',
        [$projectId, $actor['id']]
    );
    if (!$recipients) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO notifications
            (id, user_id, actor_id, actor_name, project_id, card_id, type, message, meta_json, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
    );
    $now  = gmdate('Y-m-d H:i:s');
    $meta = isset($payload['meta']) ? json_encode($payload['meta'], JSON_UNESCAPED_UNICODE) : null;
    $card = $payload['card_id'] ?? null;
    $type = (string) ($payload['type'] ?? 'activity');
    $msg  = (string) ($payload['message'] ?? 'New activity');

    foreach ($recipients as $uid) {
        $ins->execute([
            'ntf_' . bin2hex(random_bytes(6)),
            (string) $uid,
            (string) $actor['id'],
            (string) ($actor['name'] ?? 'Someone'),
            $projectId,
            $card,
            $type,
            $msg,
            $meta,
            $now,
        ]);
    }
}

/** Plain-text excerpt from rich HTML (for notification previews). */
function plain_excerpt(string $html, int $max = 120): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
    if (strlen($text) <= $max) {
        return $text;
    }
    return rtrim(substr($text, 0, $max - 1)) . '…';
}

$pdo = db();
$op  = $_GET['op'] ?? '';

try {
    switch ($op) {

        // ===================================================================
        // Public auth ops (no login required)
        // ===================================================================

        case 'me': // who am I? { user: null } when logged out
            $me = current_user();
            $pollCfg = [
                'notification_poll_seconds' => NOTIFICATION_POLL_SECONDS,
                'debug' => DEBUG,
            ];
            if ($me === null) {
                echo json_encode([
                    'ok' => true,
                    'user' => null,
                    'registration_open' => registration_is_open($pdo),
                ] + $pollCfg);
            } else {
                echo json_encode([
                    'ok' => true,
                    'user' => $me,
                    'csrf' => csrf_token(),
                    'registration_open' => registration_is_open($pdo),
                ] + $pollCfg);
            }
            break;

        case 'register': // {name,email,password}
            if (!registration_is_open($pdo)) {
                fail('Registration is closed. Ask a project owner to invite you after you have an account, or contact the administrator.', 403);
            }
            $b        = json_body();
            $name     = trim((string) ($b['name'] ?? ''));
            $email    = normalize_email((string) ($b['email'] ?? ''));
            $password = (string) ($b['password'] ?? '');
            if ($name === '' || mb_strlen($name) > 60) {
                fail('Please enter your name (1–60 characters).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('Please enter a valid email address.');
            }
            if (strlen($password) < 8) {
                fail('Password must be at least 8 characters.');
            }
            rate_limit($pdo, 'register:ip:' . client_ip(), REGISTER_RATE_MAX, REGISTER_RATE_WINDOW);
            rate_limit($pdo, 'register:email:' . $email, REGISTER_RATE_MAX, REGISTER_RATE_WINDOW);
            $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $isFirstUser = ($userCount === 0);

            // First account bootstraps without email (SMTP may not be ready yet).
            // Everyone else must verify via SMTP email.
            if (!$isFirstUser && !smtp_is_configured()) {
                fail('Email delivery is not configured. Ask the administrator to set MAIL_* in the project .env file.');
            }

            try {
                $userId = create_user($pdo, $name, $email, $password, $isFirstUser);
            } catch (Throwable $e) {
                fail('An account with that email already exists.');
            }

            if ($isFirstUser) {
                login_as($userId);
                adopt_orphan_projects($pdo, $userId);
                $st = $pdo->prepare('SELECT COUNT(*) FROM members WHERE user_id = ?');
                $st->execute([$userId]);
                if ((int) $st->fetchColumn() === 0) {
                    seed_starter_project($pdo, $userId);
                }
                echo json_encode([
                    'ok' => true,
                    'user' => current_user(),
                    'csrf' => csrf_token(),
                    'registration_open' => registration_is_open($pdo),
                    'message' => 'Welcome! Your admin account is ready.',
                ]);
                break;
            }

            try {
                $token = create_email_verification($pdo, $userId);
                deliver_verification_email($email, $name, $token);
            } catch (Throwable $e) {
                error_log('Task Board verification mail failed: ' . $e->getMessage());
                // Roll back the unverified user so they can retry cleanly.
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                fail('Could not send the verification email. Please try again later or contact the administrator.');
            }

            echo json_encode([
                'ok' => true,
                'needs_verification' => true,
                'message' => 'Account created. Check your email for a verification link before logging in.',
                'registration_open' => registration_is_open($pdo),
            ]);
            break;

        case 'verify_email': // {token}
            $b     = json_body();
            $token = (string) ($b['token'] ?? '');
            if ($token === '') {
                fail('Missing verification token.');
            }
            $uid = consume_email_verification($pdo, $token);
            if ($uid === '') {
                fail('This verification link is invalid or has expired.');
            }
            mark_user_verified($pdo, $uid);

            // Ensure they have a starter project if somehow none.
            $st = $pdo->prepare('SELECT COUNT(*) FROM members WHERE user_id = ?');
            $st->execute([$uid]);
            if ((int) $st->fetchColumn() === 0) {
                seed_starter_project($pdo, $uid);
            }

            login_as($uid);
            $st = $pdo->prepare('SELECT id, name, email, photo_url, email_verified_at FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch();
            echo json_encode([
                'ok' => true,
                'user' => [
                    'id' => $u['id'],
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'photo_url' => $u['photo_url'] ?? null,
                    'email_verified' => true,
                ],
                'csrf' => csrf_token(),
                'message' => 'Email verified. You are signed in.',
            ]);
            break;

        case 'resend_verification': // {email}
            $b     = json_body();
            $email = normalize_email((string) ($b['email'] ?? ''));
            $msg   = 'If an unverified account exists for that email, a new verification link has been sent.';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && smtp_is_configured()) {
                rate_limit($pdo, 'resend:ip:' . client_ip(), MAIL_RATE_MAX, MAIL_RATE_WINDOW);
                rate_limit($pdo, 'resend:email:' . $email, MAIL_RATE_MAX, MAIL_RATE_WINDOW);
                $st = $pdo->prepare('SELECT id, name, email_verified_at FROM users WHERE email = ?');
                $st->execute([$email]);
                $u = $st->fetch();
                if ($u && empty($u['email_verified_at'])) {
                    try {
                        $token = create_email_verification($pdo, (string) $u['id']);
                        deliver_verification_email($email, (string) $u['name'], $token);
                    } catch (Throwable $e) {
                        error_log('Task Board resend verification failed: ' . $e->getMessage());
                    }
                }
            }
            echo json_encode(['ok' => true, 'message' => $msg]);
            break;

        case 'login': // {email,password}
            $b        = json_body();
            $email    = normalize_email((string) ($b['email'] ?? ''));
            $password = (string) ($b['password'] ?? '');
            if ($email === '' || $password === '') {
                fail('Enter your email and password.');
            }
            throttle_check($pdo, $email);
            $st = $pdo->prepare('SELECT id, name, email, photo_url, password_hash, email_verified_at FROM users WHERE email = ?');
            $st->execute([$email]);
            $user = $st->fetch();
            if (!$user || !password_verify($password, (string) $user['password_hash'])) {
                throttle_record_failure($pdo, $email);
                fail('Wrong email or password.', 401);
            }
            if (empty($user['email_verified_at'])) {
                fail('Please verify your email before logging in. Check your inbox or resend the verification email.', 403);
            }
            throttle_clear($pdo, $email);
            login_as((string) $user['id']);
            echo json_encode([
                'ok'   => true,
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'photo_url' => $user['photo_url'] ?? null,
                    'email_verified' => true,
                ],
                'csrf' => csrf_token(),
            ]);
            break;

        case 'request_reset': // {email}
            $b     = json_body();
            $email = normalize_email((string) ($b['email'] ?? ''));
            // Generic reply — never reveals whether the email has an account.
            $msg = 'If an account exists for that email, a password reset email has been sent.';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                rate_limit($pdo, 'reset:ip:' . client_ip(), MAIL_RATE_MAX, MAIL_RATE_WINDOW);
                rate_limit($pdo, 'reset:email:' . $email, MAIL_RATE_MAX, MAIL_RATE_WINDOW);
                $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $st->execute([$email]);
                $uid = $st->fetchColumn();
                if ($uid) {
                    if (!smtp_is_configured()) {
                        // Still return generic success to avoid account enumeration,
                        // but log that mail cannot be sent.
                        error_log('Task Board password reset skipped: SMTP is not configured.');
                    } else {
                        try {
                            $token = create_password_reset($pdo, (string) $uid);
                            deliver_reset_link($email, $token);
                        } catch (Throwable $e) {
                            error_log('Task Board reset mail failed: ' . $e->getMessage());
                        }
                    }
                }
            }
            echo json_encode(['ok' => true, 'message' => $msg]);
            break;

        case 'check_reset': // {token}
            $b     = json_body();
            $token = (string) ($b['token'] ?? '');
            if ($token === '') {
                fail('Missing reset token.');
            }
            if (!password_reset_token_valid($pdo, $token)) {
                fail('This reset link is invalid or has expired.');
            }
            echo json_encode(['ok' => true]);
            break;

        case 'reset_password': // {token,password}
            $b        = json_body();
            $token    = (string) ($b['token'] ?? '');
            $password = (string) ($b['password'] ?? '');
            if (strlen($password) < 8) {
                fail('Password must be at least 8 characters.');
            }
            $uid = consume_password_reset($pdo, $token);
            if ($uid === '') {
                fail('This reset link is invalid or has expired.');
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
            mark_user_verified($pdo, $uid); // resetting via email proves ownership
            login_as($uid);   // convenience: stay signed in
            $st = $pdo->prepare('SELECT name, email, photo_url FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch();
            echo json_encode([
                'ok'      => true,
                'user'    => ['id' => $uid, 'name' => $u['name'], 'email' => $u['email'], 'photo_url' => $u['photo_url'] ?? null, 'email_verified' => true],
                'csrf'    => csrf_token(),
                'message' => 'Your password was updated.',
            ]);
            break;

        // ===================================================================
        // Authenticated ops
        // ===================================================================

        case 'logout':
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $leaving = current_user();
            if ($leaving) {
                clear_user_presence($pdo, (string) $leaving['id']);
            }
            logout();
            echo json_encode(['ok' => true]);
            break;

        case 'projects': // list projects the current user can access
            $me       = require_login();
            $rows = rows(
                $pdo,
                'SELECT p.id, p.name, p.archived_at, m.role
                   FROM projects p
                   JOIN members m ON m.project_id = p.id AND m.user_id = ?
               ORDER BY
                   CASE WHEN p.archived_at IS NULL OR p.archived_at = \'\' THEN 0 ELSE 1 END,
                   p.position, p.created_at',
                [$me['id']]
            );
            $projects = [];
            foreach ($rows as $row) {
                $projects[] = [
                    'id'       => $row['id'],
                    'name'     => $row['name'],
                    'archived' => !empty($row['archived_at']),
                    'role'     => (string) ($row['role'] ?? 'member'),
                ];
            }
            echo json_encode(['ok' => true, 'projects' => $projects]);
            break;

        case 'project_create': // {name} — creator becomes owner
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $name = trim((string) (json_body()['name'] ?? ''));
            if ($name === '') {
                fail('Project name is required.');
            }
            $id  = 'proj_' . bin2hex(random_bytes(6));
            $pos = (int) $pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM projects')->fetchColumn();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO projects (id, name, position) VALUES (?, ?, ?)')
                    ->execute([$id, $name, $pos]);
                $pdo->prepare(
                    "INSERT INTO members (id, project_id, name, color, position, user_id, role, email)
                     VALUES (?, ?, ?, ?, ?, ?, 'owner', ?)"
                )->execute([
                    'mem_' . bin2hex(random_bytes(6)), $id,
                    $me['name'], '#2563eb', 0, $me['id'], $me['email'],
                ]);
                insert_default_lists($pdo, $id);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            echo json_encode(['ok' => true, 'id' => $id, 'name' => $name]);
            break;

        case 'project_archive': // {id} — owner only; soft-hide, no delete
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $id = (string) (json_body()['id'] ?? '');
            if ($id === '') {
                fail('Project id is required.');
            }
            $m = membership($pdo, $id);
            if ($m === null) {
                fail('You do not have access to this project.', 403);
            }
            if (($m['role'] ?? 'member') !== 'owner') {
                fail('Only the project owner can archive it.', 403);
            }
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM projects p
                   JOIN members m ON m.project_id = p.id AND m.user_id = ?
                  WHERE (p.archived_at IS NULL OR p.archived_at = '')"
            );
            $st->execute([$me['id']]);
            if ((int) $st->fetchColumn() <= 1) {
                fail('Cannot archive your last active project.');
            }
            $pdo->prepare("UPDATE projects SET archived_at = ?, updated_at = ?, share_token = NULL WHERE id = ? AND (archived_at IS NULL OR archived_at = '')")
                ->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id]);
            echo json_encode(['ok' => true]);
            break;

        case 'project_unarchive': // {id} — owner only
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $id = (string) (json_body()['id'] ?? '');
            if ($id === '') {
                fail('Project id is required.');
            }
            $m = membership($pdo, $id);
            if ($m === null) {
                fail('You do not have access to this project.', 403);
            }
            if (($m['role'] ?? 'member') !== 'owner') {
                fail('Only the project owner can restore it.', 403);
            }
            $pdo->prepare('UPDATE projects SET archived_at = NULL, updated_at = ? WHERE id = ?')
                ->execute([gmdate('Y-m-d H:i:s'), $id]);
            echo json_encode(['ok' => true]);
            break;

        case 'project_share_status': // GET ?project=ID — owner only
            $me = require_login();
            $pid = trim((string) ($_GET['project'] ?? ''));
            if ($pid === '') {
                fail('Project id is required.');
            }
            $m = membership($pdo, $pid);
            if ($m === null) {
                fail('You do not have access to this project.', 403);
            }
            if (($m['role'] ?? '') !== 'owner') {
                fail('Only the project owner can manage the share link.', 403);
            }
            $st = $pdo->prepare('SELECT share_token, archived_at FROM projects WHERE id = ?');
            $st->execute([$pid]);
            $row = $st->fetch();
            if (!$row) {
                fail('Project not found.', 404);
            }
            $token = trim((string) ($row['share_token'] ?? ''));
            echo json_encode([
                'ok'       => true,
                'enabled'  => $token !== '',
                'token'    => $token !== '' ? $token : null,
                'archived' => !empty($row['archived_at']),
            ]);
            break;

        case 'project_share_enable': // {project} — owner only; create or rotate token
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $pid = trim((string) (json_body()['project'] ?? ''));
            if ($pid === '') {
                fail('Project id is required.');
            }
            $m = membership($pdo, $pid);
            if ($m === null) {
                fail('You do not have access to this project.', 403);
            }
            if (($m['role'] ?? '') !== 'owner') {
                fail('Only the project owner can manage the share link.', 403);
            }
            require_project_writable($pdo, $pid);
            $token = bin2hex(random_bytes(24));
            $pdo->prepare('UPDATE projects SET share_token = ?, updated_at = ? WHERE id = ?')
                ->execute([$token, gmdate('Y-m-d H:i:s'), $pid]);
            echo json_encode(['ok' => true, 'enabled' => true, 'token' => $token]);
            break;

        case 'project_share_disable': // {project} — owner only
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $pid = trim((string) (json_body()['project'] ?? ''));
            if ($pid === '') {
                fail('Project id is required.');
            }
            $m = membership($pdo, $pid);
            if ($m === null) {
                fail('You do not have access to this project.', 403);
            }
            if (($m['role'] ?? '') !== 'owner') {
                fail('Only the project owner can manage the share link.', 403);
            }
            $pdo->prepare('UPDATE projects SET share_token = NULL, updated_at = ? WHERE id = ?')
                ->execute([gmdate('Y-m-d H:i:s'), $pid]);
            echo json_encode(['ok' => true, 'enabled' => false]);
            break;

        case 'board_public': // GET ?token= [&since=] — anonymous read-only board
            $token = trim((string) ($_GET['token'] ?? ''));
            if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
                // Still consume IP budget so invalid-token probing is throttled.
                rate_limit($pdo, 'share:ip:' . client_ip(), SHARE_PUBLIC_RATE_MAX, SHARE_PUBLIC_RATE_WINDOW);
                fail('Invalid or missing share link.', 404);
            }
            rate_limit($pdo, 'share:ip:' . client_ip(), SHARE_PUBLIC_RATE_MAX, SHARE_PUBLIC_RATE_WINDOW);
            rate_limit(
                $pdo,
                'share:tok:' . hash('sha256', strtolower($token)),
                SHARE_PUBLIC_RATE_MAX,
                SHARE_PUBLIC_RATE_WINDOW
            );

            $st = $pdo->prepare(
                'SELECT id, archived_at, updated_at FROM projects WHERE share_token = ? LIMIT 1'
            );
            $st->execute([$token]);
            $proj = $st->fetch();
            if (!$proj) {
                fail('This share link is invalid or has been turned off.', 404);
            }
            if (!empty($proj['archived_at'])) {
                fail('This project is archived and can no longer be viewed via share link.', 404);
            }

            $pid = (string) $proj['id'];
            $updatedAt = trim((string) ($proj['updated_at'] ?? ''));
            if ($updatedAt === '') {
                $updatedAt = project_updated_at($pdo, $pid);
            }

            // Cheap poll: clients send the last stamp; skip full payload when unchanged.
            $since = trim((string) ($_GET['since'] ?? ''));
            if ($since !== '' && $since === $updatedAt) {
                echo json_encode([
                    'ok' => true,
                    'unchanged' => true,
                    'updated_at' => $updatedAt,
                ], JSON_UNESCAPED_SLASHES);
                break;
            }

            $data = load_board_public($pdo, $pid);
            $data['updated_at'] = $updatedAt;
            echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
            break;

        case 'board': // GET ?project=ID
            $me  = require_login();
            $pid = (string) ($_GET['project'] ?? '');
            if ($pid === '') { // default to the user's first active project
                $st = $pdo->prepare(
                    "SELECT p.id FROM projects p
                       JOIN members m ON m.project_id = p.id AND m.user_id = ?
                   ORDER BY
                       CASE WHEN p.archived_at IS NULL OR p.archived_at = '' THEN 0 ELSE 1 END,
                       p.position, p.created_at
                   LIMIT 1"
                );
                $st->execute([$me['id']]);
                $pid = (string) $st->fetchColumn();
            }
            if ($pid === '') {
                echo json_encode(['ok' => true, 'data' => [
                    'title' => '', 'members' => [], 'lists' => [],
                    'current_member_id' => null, 'current_role' => null, 'empty' => true,
                ]]);
                break;
            }
            require_membership($pdo, $pid);
            echo json_encode(['ok' => true, 'data' => load_board($pdo, $pid, $me)], JSON_UNESCAPED_SLASHES);
            break;

        case 'board_save': // POST ?project=ID  {title,members,lists}
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $pid = (string) ($_GET['project'] ?? '');
            save_board($pdo, $pid, json_body(), $me);
            gc_uploads($pdo);
            $updatedAt = touch_project($pdo, $pid);
            // save_board stores conflict-merge results on the request scope via $GLOBALS
            $protected = $GLOBALS['tb_protected_cards'] ?? [];
            unset($GLOBALS['tb_protected_cards']);
            echo json_encode([
                'ok' => true,
                'updated_at' => $updatedAt,
                'protected_cards' => array_values($protected),
            ], JSON_UNESCAPED_SLASHES);
            break;

        case 'board_sync': // GET ?project=ID&since=UTC — incremental card/list sync
            $me = require_login();
            $pid = trim((string) ($_GET['project'] ?? ''));
            if ($pid === '') {
                fail('Project id is required.');
            }
            require_membership($pdo, $pid);
            $since = trim((string) ($_GET['since'] ?? ''));
            echo json_encode(['ok' => true, 'data' => load_board_sync($pdo, $pid, $me, $since)], JSON_UNESCAPED_SLASHES);
            break;

        case 'invite': // {project,email} — invite a registered user to a project
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $b     = json_body();
            $pid   = (string) ($b['project'] ?? ($_GET['project'] ?? ''));
            $email = normalize_email((string) ($b['email'] ?? ''));
            if ($pid === '') {
                fail('Project id is required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('Enter a valid email address.');
            }
            if (membership($pdo, $pid) === null) {
                fail('You do not have access to this project.', 403);
            }
            require_project_writable($pdo, $pid);
            $st = $pdo->prepare('SELECT id, name, email_verified_at FROM users WHERE email = ?');
            $st->execute([$email]);
            $invitee = $st->fetch();
            if (!$invitee) {
                fail('No account found for that email.');
            }
            if (empty($invitee['email_verified_at'])) {
                fail('That account has not verified their email yet.');
            }
            $chk = $pdo->prepare('SELECT 1 FROM members WHERE project_id = ? AND user_id = ?');
            $chk->execute([$pid, $invitee['id']]);
            if ($chk->fetchColumn()) {
                fail('That user is already a member of this project.');
            }
            $color = pick_member_color((string) $invitee['id']);
            $stp = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM members WHERE project_id = ?');
            $stp->execute([$pid]);
            $pos = (int) $stp->fetchColumn();
            $mid = 'mem_' . bin2hex(random_bytes(6));
            $pdo->prepare(
                "INSERT INTO members (id, project_id, name, color, position, user_id, role, email)
                 VALUES (?, ?, ?, ?, ?, ?, 'member', ?)"
            )->execute([$mid, $pid, $invitee['name'], $color, $pos, $invitee['id'], $email]);
            echo json_encode(['ok' => true, 'member' => [
                'id' => $mid, 'name' => $invitee['name'], 'color' => $color,
                'role' => 'member', 'email' => $email, 'is_you' => false,
            ], 'updated_at' => touch_project($pdo, $pid)]);
            break;

        case 'leave_project': // {project} — a non-owner leaves
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $pid = (string) (json_body()['project'] ?? ($_GET['project'] ?? ''));
            if ($pid === '') {
                fail('Project id is required.');
            }
            $m = membership($pdo, $pid);
            if ($m === null) {
                fail('You are not a member of this project.', 404);
            }
            if (($m['role'] ?? 'member') === 'owner') {
                fail('Owners cannot leave their own project. Delete it instead.', 403);
            }
            $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$m['id']]); // cascade clears card_members
            echo json_encode(['ok' => true]);
            break;

        case 'profile_update': // {name, photo_url, current_password, new_password}
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $b     = json_body();
            $name  = trim((string) ($b['name'] ?? ''));
            $photo = $me['photo_url'] ?? null;
            if (array_key_exists('photo_url', $b)) {
                if ($b['photo_url'] === null || $b['photo_url'] === '') {
                    $photo = null;
                } else {
                    $photo = safe_upload_url((string) $b['photo_url']);
                    if ($photo === null) {
                        fail('Invalid profile photo URL.');
                    }
                }
            }
            if ($name === '' || mb_strlen($name) > 60) {
                fail('Please enter your name (1–60 characters).');
            }

            $currentPassword = (string) ($b['current_password'] ?? '');
            $newPassword     = (string) ($b['new_password'] ?? '');
            $updatePassword  = ($newPassword !== '');

            if ($updatePassword) {
                if ($currentPassword === '') {
                    fail('Current password is required to update password.');
                }
                if (strlen($newPassword) < 8) {
                    fail('New password must be at least 8 characters.');
                }
                $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $st->execute([$me['id']]);
                $hash = (string) $st->fetchColumn();
                if (!password_verify($currentPassword, $hash)) {
                    fail('Current password is incorrect.');
                }
            }

            $pdo->beginTransaction();
            try {
                if ($updatePassword) {
                    $pdo->prepare('UPDATE users SET name = ?, photo_url = ?, password_hash = ? WHERE id = ?')
                        ->execute([$name, $photo, password_hash($newPassword, PASSWORD_DEFAULT), $me['id']]);
                } else {
                    $pdo->prepare('UPDATE users SET name = ?, photo_url = ? WHERE id = ?')
                        ->execute([$name, $photo, $me['id']]);
                }

                // Sync updated name to all member rows linked to this account
                $pdo->prepare('UPDATE members SET name = ? WHERE user_id = ?')
                    ->execute([$name, $me['id']]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            gc_uploads($pdo);

            $updatedUser = [
                'id'        => $me['id'],
                'name'      => $name,
                'email'     => $me['email'],
                'photo_url' => $photo,
            ];

            echo json_encode([
                'ok'      => true,
                'user'    => $updatedUser,
                'message' => 'Profile updated successfully.',
            ]);
            break;

        case 'comment_add': // {card_id, comment}
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $b      = json_body();
            $cardId = trim((string) ($b['card_id'] ?? ''));
            $text   = sanitize_rich_html((string) ($b['comment'] ?? ''));
            if ($cardId === '') {
                fail('Card id is required.');
            }
            if ($text === '') {
                fail('Comment cannot be empty.');
            }
            $st = $pdo->prepare(
                'SELECT c.title AS card_title, l.project_id
                   FROM cards c
                   JOIN lists l ON l.id = c.list_id
                  WHERE c.id = ?'
            );
            $st->execute([$cardId]);
            $cardRow = $st->fetch();
            if (!$cardRow || membership($pdo, (string) $cardRow['project_id']) === null) {
                fail('You do not have access to this card.', 403);
            }
            $pid = (string) $cardRow['project_id'];
            require_project_writable($pdo, $pid);
            $cardTitle = (string) ($cardRow['card_title'] ?? 'Card');

            $id   = 'cmt_' . bin2hex(random_bytes(6));
            $now  = gmdate('Y-m-d H:i:s');
            $ins  = $pdo->prepare('INSERT INTO card_comments (id, card_id, user_id, author_name, comment, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([$id, $cardId, $me['id'], $me['name'], $text, $now]);

            $excerpt = plain_excerpt($text);
            create_notifications($pdo, $pid, $me, [
                'type'    => 'comment',
                'card_id' => $cardId,
                'message' => $me['name'] . ' commented on "' . $cardTitle . '"',
                'meta'    => [
                    'card_title' => $cardTitle,
                    'excerpt'    => $excerpt,
                ],
            ]);

            $commentObj = [
                'id'          => $id,
                'user_id'     => $me['id'],
                'author_name' => $me['name'],
                'photo_url'   => $me['photo_url'] ?? null,
                'comment'     => $text,
                'created_at'  => $now,
            ];

            echo json_encode(['ok' => true, 'comment' => $commentObj, 'updated_at' => touch_project($pdo, $pid)]);
            break;

        case 'ai_comment_add': // {card_id, comment} — authored by the AI Chat bot user
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $b      = json_body();
            $cardId = trim((string) ($b['card_id'] ?? ''));
            $text   = sanitize_rich_html((string) ($b['comment'] ?? ''));
            if ($cardId === '') {
                fail('Card id is required.');
            }
            if ($text === '') {
                fail('Comment cannot be empty.');
            }
            $st = $pdo->prepare(
                'SELECT c.title AS card_title, l.project_id
                   FROM cards c
                   JOIN lists l ON l.id = c.list_id
                  WHERE c.id = ?'
            );
            $st->execute([$cardId]);
            $cardRow = $st->fetch();
            if (!$cardRow || membership($pdo, (string) $cardRow['project_id']) === null) {
                fail('You do not have access to this card.', 403);
            }
            $pid = (string) $cardRow['project_id'];
            require_project_writable($pdo, $pid);
            $cardTitle = (string) ($cardRow['card_title'] ?? 'Card');
            $bot = ensure_ai_chat_user($pdo);

            $id   = 'cmt_' . bin2hex(random_bytes(6));
            $now  = gmdate('Y-m-d H:i:s');
            $ins  = $pdo->prepare('INSERT INTO card_comments (id, card_id, user_id, author_name, comment, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([$id, $cardId, $bot['id'], $bot['name'], $text, $now]);

            $excerpt = plain_excerpt($text);
            create_notifications($pdo, $pid, $bot, [
                'type'    => 'comment',
                'card_id' => $cardId,
                'message' => $bot['name'] . ' commented on "' . $cardTitle . '"',
                'meta'    => [
                    'card_title' => $cardTitle,
                    'excerpt'    => $excerpt,
                ],
            ]);

            echo json_encode([
                'ok'      => true,
                'comment' => [
                    'id'          => $id,
                    'user_id'     => $bot['id'],
                    'author_name' => $bot['name'],
                    'photo_url'   => $bot['photo_url'],
                    'comment'     => $text,
                    'created_at'  => $now,
                ],
                'updated_at' => touch_project($pdo, $pid),
            ]);
            break;

        case 'ai_debug_log': // {provider, model, request, response, error?, project_id?}
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            if (!DEBUG) {
                echo json_encode(['ok' => true, 'skipped' => true]);
                break;
            }
            $b = json_body();
            $logDir = __DIR__ . '/logs';
            if (!is_dir($logDir) && !@mkdir($logDir, 0775, true)) {
                fail('Could not create logs directory.', 500);
            }
            if (!is_writable($logDir)) {
                fail('logs/ directory is not writable.', 500);
            }

            $provider = substr(trim((string) ($b['provider'] ?? 'unknown')), 0, 64);
            $model    = substr(trim((string) ($b['model'] ?? 'unknown')), 0, 128);
            $entry = [
                'ts'         => gmdate('c'),
                'user_id'    => $me['id'],
                'user_name'  => $me['name'] ?? null,
                'project_id' => isset($b['project_id']) ? substr((string) $b['project_id'], 0, 64) : null,
                'provider'   => $provider,
                'model'      => $model,
                'request'    => $b['request'] ?? null,
                'response'   => $b['response'] ?? null,
                'error'      => isset($b['error']) ? substr((string) $b['error'], 0, 2000) : null,
            ];
            // Cap payload size so a runaway model dump can't fill the disk.
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                fail('Could not encode debug log.');
            }
            if (strlen($line) > 200_000) {
                $entry['response'] = '[truncated]';
                $entry['request'] = is_array($entry['request'])
                    ? array_merge($entry['request'], ['_truncated' => true])
                    : '[truncated]';
                $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $file = $logDir . '/ai-chat-' . gmdate('Y-m-d') . '.log';
            $ok = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            if ($ok === false) {
                fail('Could not write AI debug log.', 500);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'notifications': // list current user's notifications (+ optional board sync stamp)
            $me = require_login();
            $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
            $st = $pdo->prepare(
                'SELECT n.id, n.actor_id, n.actor_name, n.project_id, n.card_id, n.type,
                        n.message, n.meta_json, n.is_read, n.created_at,
                        u.photo_url AS actor_photo, p.name AS project_name
                   FROM notifications n
                   LEFT JOIN users u ON u.id = n.actor_id
                   LEFT JOIN projects p ON p.id = n.project_id
                  WHERE n.user_id = ?
                  ORDER BY n.created_at DESC, n.id DESC
                  LIMIT ?'
            );
            $st->bindValue(1, $me['id'], PDO::PARAM_STR);
            $st->bindValue(2, $limit, PDO::PARAM_INT);
            $st->execute();
            $items = [];
            foreach ($st->fetchAll() as $row) {
                $meta = null;
                if (!empty($row['meta_json'])) {
                    $decoded = json_decode((string) $row['meta_json'], true);
                    $meta = is_array($decoded) ? $decoded : null;
                }
                $items[] = [
                    'id'           => $row['id'],
                    'actor_id'     => $row['actor_id'],
                    'actor_name'   => $row['actor_name'],
                    'actor_photo'  => $row['actor_photo'] ?? null,
                    'project_id'   => $row['project_id'],
                    'project_name' => $row['project_name'] ?? null,
                    'card_id'      => $row['card_id'],
                    'type'         => $row['type'],
                    'message'      => $row['message'],
                    'meta'         => $meta,
                    'is_read'      => !empty($row['is_read']),
                    'created_at'   => $row['created_at'],
                ];
            }
            $ust = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
            $ust->execute([$me['id']]);
            $unread = (int) $ust->fetchColumn();

            $payload = ['ok' => true, 'notifications' => $items, 'unread' => $unread, 'online' => []];

            // Cheap board sync stamp + presence heartbeat for the open project.
            $syncPid = trim((string) ($_GET['project'] ?? ''));
            if ($syncPid !== '' && membership($pdo, $syncPid) !== null) {
                touch_user_presence($pdo, (string) $me['id'], $syncPid);
                $payload['board_updated_at'] = project_updated_at($pdo, $syncPid);
                $payload['project_id'] = $syncPid;
                $payload['online'] = project_online_users($pdo, $syncPid);
            }

            echo json_encode($payload);
            break;

        case 'notifications_read': // {id?} mark one or all as read
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $nid = trim((string) (json_body()['id'] ?? ''));
            if ($nid !== '') {
                $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
                    ->execute([$nid, $me['id']]);
            } else {
                $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
                    ->execute([$me['id']]);
            }
            $ust = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
            $ust->execute([$me['id']]);
            echo json_encode(['ok' => true, 'unread' => (int) $ust->fetchColumn()]);
            break;

        case 'comment_delete': // {comment_id}
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }
            $cid = trim((string) (json_body()['comment_id'] ?? ''));
            if ($cid === '') {
                fail('Comment id is required.');
            }
            $st = $pdo->prepare('SELECT c.user_id, l.project_id FROM card_comments c JOIN cards cd ON cd.id = c.card_id JOIN lists l ON l.id = cd.list_id WHERE c.id = ?');
            $st->execute([$cid]);
            $row = $st->fetch();
            if (!$row) {
                fail('Comment not found.', 404);
            }
            $m = membership($pdo, (string) $row['project_id']);
            if ($m === null) {
                fail('Access denied.', 403);
            }
            require_project_writable($pdo, (string) $row['project_id']);
            $isAuthor = ($row['user_id'] === $me['id']);
            $isOwner  = (($m['role'] ?? '') === 'owner');
            if (!$isAuthor && !$isOwner) {
                fail('You can only delete your own comments.', 403);
            }

            $pdo->prepare('DELETE FROM card_comments WHERE id = ?')->execute([$cid]);
            echo json_encode(['ok' => true, 'updated_at' => touch_project($pdo, (string) $row['project_id'])]);
            break;

        case 'upload': // multipart/form-data, field "file" = image
            $me = require_login();
            if (!csrf_check()) {
                fail('Bad CSRF token. Reload the page.', 419);
            }

            $uploadDir = __DIR__ . '/uploads';
            $maxBytes  = 5_000_000;   // 5 MB hard cap
            $mimeMap   = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            ];

            if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
                fail('Could not create uploads directory.', 500);
            }
            if (!is_writable($uploadDir)) {
                fail('uploads/ directory is not writable.', 500);
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                fail('No file received (expected a multipart field named "file").');
            }

            $tmp  = $_FILES['file']['tmp_name'];
            $size = $_FILES['file']['size'];

            if ($size <= 0 || $size > $maxBytes) {
                fail('Image is empty or exceeds the ' . $maxBytes . ' byte limit.');
            }

            $info = @getimagesize($tmp);
            if ($info === false) {
                fail('Uploaded file is not a valid image.');
            }
            $mime = $info['mime'] ?? '';
            if (!isset($mimeMap[$mime])) {
                fail('Unsupported image type: ' . ($mime ?: 'unknown'));
            }

            $ext  = $mimeMap[$mime];
            $name = bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $uploadDir . '/' . $name;

            if (!move_uploaded_file($tmp, $dest)) {
                fail('Could not save the uploaded file.', 500);
            }

            echo json_encode(['ok' => true, 'url' => 'uploads/' . $name]);
            break;

        default:
            fail('Unknown op.', 404);
    }
} catch (Throwable $e) {
    fail_server($e);
}

// ---------------------------------------------------------------------------
// Board load: assemble one project's board from its rows.
// ---------------------------------------------------------------------------

/** Bump projects.updated_at and return the new UTC stamp. */
function touch_project(PDO $pdo, string $pid): string
{
    $now = gmdate('Y-m-d H:i:s');
    if ($pid === '') {
        return $now;
    }
    $pdo->prepare('UPDATE projects SET updated_at = ? WHERE id = ?')->execute([$now, $pid]);
    return $now;
}

/**
 * How long after the last heartbeat a user stays "online".
 * Fixed at 6s so a couple of missed 2s polls don't flicker offline.
 */
function presence_ttl_seconds(): int
{
    return 6;
}

/** Record that $userId is viewing $projectId right now. */
function touch_user_presence(PDO $pdo, string $userId, string $projectId): void
{
    if ($userId === '' || $projectId === '') {
        return;
    }
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO user_presence (user_id, project_id, last_seen) VALUES (?, ?, ?)
         ON CONFLICT(user_id) DO UPDATE SET
           project_id = excluded.project_id,
           last_seen  = excluded.last_seen'
    )->execute([$userId, $projectId, $now]);
}

/** Drop presence when the user signs out. */
function clear_user_presence(PDO $pdo, string $userId): void
{
    if ($userId === '') {
        return;
    }
    $pdo->prepare('DELETE FROM user_presence WHERE user_id = ?')->execute([$userId]);
}

/**
 * Project members who heartbeated recently (same project).
 *
 * @return list<array{id:string,name:string,photo_url:?string,color:string,is_you:bool}>
 */
function project_online_users(PDO $pdo, string $projectId): array
{
    if ($projectId === '') {
        return [];
    }
    $me = current_user();
    $meId = $me ? (string) $me['id'] : '';
    $cutoff = gmdate('Y-m-d H:i:s', time() - presence_ttl_seconds());
    $st = $pdo->prepare(
        'SELECT u.id, u.name, u.photo_url, m.color
           FROM user_presence p
           JOIN users u ON u.id = p.user_id
           JOIN members m ON m.user_id = p.user_id AND m.project_id = p.project_id
          WHERE p.project_id = ?
            AND p.last_seen >= ?
          ORDER BY (u.id = ?) DESC, lower(u.name) ASC'
    );
    $st->execute([$projectId, $cutoff, $meId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[] = [
            'id'        => (string) $row['id'],
            'name'      => (string) $row['name'],
            'photo_url' => $row['photo_url'] ?? null,
            'color'     => (string) ($row['color'] ?: pick_member_color((string) $row['id'])),
            'is_you'    => ($meId !== '' && (string) $row['id'] === $meId),
        ];
    }
    return $out;
}

/** Current projects.updated_at (or now if missing). */
function project_updated_at(PDO $pdo, string $pid): string
{
    if ($pid === '') {
        return gmdate('Y-m-d H:i:s');
    }
    $st = $pdo->prepare('SELECT updated_at FROM projects WHERE id = ?');
    $st->execute([$pid]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null || $v === '') {
        return touch_project($pdo, $pid);
    }
    return (string) $v;
}

function load_board(PDO $pdo, string $pid, array $me): array
{
    if ($pid === '') {
        return [
            'title' => '', 'members' => [], 'lists' => [],
            'current_member_id' => null, 'current_role' => null, 'updated_at' => null, 'archived' => false,
        ];
    }

    $st = $pdo->prepare('SELECT name, updated_at, archived_at FROM projects WHERE id = ?');
    $st->execute([$pid]);
    $proj = $st->fetch();
    if ($proj === false) {
        fail('Project not found.', 404);
    }
    $name = (string) $proj['name'];
    $updatedAt = (string) ($proj['updated_at'] ?? '');
    if ($updatedAt === '') {
        $updatedAt = touch_project($pdo, $pid);
    }
    $archived = !empty($proj['archived_at']);

    $members       = [];
    $currentMember = null;
    $currentRole   = null;
    $sql = 'SELECT m.id, m.name, m.color, m.role, m.email, m.user_id, u.photo_url
              FROM members m
         LEFT JOIN users u ON u.id = m.user_id
             WHERE m.project_id = ?
          ORDER BY m.position';
    foreach (rows($pdo, $sql, [$pid]) as $m) {
        $isYou = ($m['user_id'] !== null && $m['user_id'] === $me['id']);
        if ($isYou) {
            $currentMember = $m['id'];
            $currentRole   = $m['role'];
        }
        $members[] = [
            'id'        => $m['id'],
            'name'      => $m['name'],
            'color'     => $m['color'],
            'role'      => $m['role'],
            'email'     => (string) ($m['email'] ?? ''),
            'photo_url' => $m['photo_url'] ?? null,
            'is_you'    => $isYou,
        ];
    }

    $lists = [];
    foreach (rows($pdo, 'SELECT id, title, color FROM lists WHERE project_id = ? ORDER BY position', [$pid]) as $l) {
        $color = is_hex_color($l['color'] ?? null) ? (string) $l['color'] : null;
        $lists[] = [
            'id'    => $l['id'],
            'title' => $l['title'],
            'color' => $color,
            'cards' => load_cards($pdo, $l['id']),
        ];
    }

    return [
        'title'             => $name,
        'members'           => $members,
        'lists'             => $lists,
        'current_member_id' => $currentMember,
        'current_role'      => $currentRole,
        'updated_at'        => $updatedAt,
        'archived'          => $archived,
    ];
}

/**
 * Public share-link payload: same board shape, no emails / membership role.
 *
 * @return array<string,mixed>
 */
function load_board_public(PDO $pdo, string $pid): array
{
    $data = load_board($pdo, $pid, ['id' => '']);
    foreach ($data['members'] as &$m) {
        $m['email'] = '';
        $m['is_you'] = false;
        // Keep role for Owner badge display only; guests cannot act on it.
    }
    unset($m);
    $data['current_member_id'] = null;
    $data['current_role'] = null;
    $data['share'] = true;
    return $data;
}

function load_cards(PDO $pdo, string $listId): array
{
    $out = [];
    foreach (rows($pdo, 'SELECT id, title, description, due, updated_at FROM cards WHERE list_id = ? ORDER BY position', [$listId]) as $c) {
        $out[] = hydrate_card_row($pdo, $c, $listId, null);
    }
    return $out;
}

/**
 * Full card payload from a cards row (+ list_id / optional position).
 *
 * @param array<string,mixed> $c
 * @return array<string,mixed>
 */
function hydrate_card_row(PDO $pdo, array $c, ?string $listId = null, ?int $position = null): array
{
    $cid = (string) $c['id'];
    $checklist = [];
    foreach (rows($pdo, 'SELECT id, text, checked FROM card_checklists WHERE card_id = ? ORDER BY position', [$cid]) as $chk) {
        $checklist[] = [
            'id'      => $chk['id'],
            'text'    => $chk['text'],
            'checked' => (bool) $chk['checked'],
        ];
    }
    $comments = [];
    $cSql = 'SELECT c.id, c.user_id, c.author_name, u.photo_url, c.comment, c.created_at
               FROM card_comments c
          LEFT JOIN users u ON u.id = c.user_id
              WHERE c.card_id = ?
           ORDER BY c.created_at ASC';
    foreach (rows($pdo, $cSql, [$cid]) as $cmt) {
        $comments[] = [
            'id'          => $cmt['id'],
            'user_id'     => $cmt['user_id'],
            'author_name' => $cmt['author_name'],
            'photo_url'   => $cmt['photo_url'] ?? null,
            'comment'     => $cmt['comment'],
            'created_at'  => $cmt['created_at'],
        ];
    }
    $card = [
        'id'          => $cid,
        'title'       => $c['title'],
        'description' => $c['description'],
        'due'         => $c['due'],
        'updated_at'  => $c['updated_at'] ?? null,
        'labels'      => col($pdo, 'SELECT label FROM card_labels WHERE card_id = ?', [$cid]),
        'members'     => col($pdo, 'SELECT member_id FROM card_members WHERE card_id = ?', [$cid]),
        'images'      => rows($pdo, 'SELECT id, url, name FROM images WHERE card_id = ? ORDER BY position', [$cid]),
        'checklist'   => $checklist,
        'comments'    => $comments,
    ];
    if ($listId !== null) {
        $card['list_id'] = $listId;
    }
    if ($position !== null) {
        $card['position'] = $position;
    }
    return $card;
}

/**
 * Incremental sync payload: list skeleton + cards changed since $since.
 *
 * @return array<string,mixed>
 */
function load_board_sync(PDO $pdo, string $pid, array $me, string $since): array
{
    $board = load_board($pdo, $pid, $me);
    $lists = [];
    $cardIds = [];
    $changed = [];

    foreach (($board['lists'] ?? []) as $li => $l) {
        $lists[] = [
            'id'       => $l['id'],
            'title'    => $l['title'],
            'color'    => $l['color'] ?? null,
            'position' => (int) $li,
        ];
        foreach (($l['cards'] ?? []) as $ci => $c) {
            $cid = (string) ($c['id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $cardIds[] = $cid;
            $ts = (string) ($c['updated_at'] ?? '');
            // First sync (empty since) or card newer than client stamp.
            if ($since === '' || $ts === '' || $ts > $since) {
                $c['list_id'] = $l['id'];
                $c['position'] = (int) $ci;
                $changed[] = $c;
            }
        }
    }

    return [
        'updated_at' => $board['updated_at'] ?? null,
        'title'      => $board['title'] ?? '',
        'members'    => $board['members'] ?? [],
        'lists'      => $lists,
        'cards'      => $changed,
        'card_ids'   => $cardIds,
        'current_member_id' => $board['current_member_id'] ?? null,
        'current_role'      => $board['current_role'] ?? null,
    ];
}

// ---------------------------------------------------------------------------
// Board save: transactional full-replace of one project's contents.
// ---------------------------------------------------------------------------
function save_board(PDO $pdo, string $pid, array $data, array $me): void
{
    if ($pid === '') {
        fail('Project id is required.');
    }
    $mine = membership($pdo, $pid);
    if ($mine === null) {
        fail('You do not have access to this project.', 403);
    }
    require_project_writable($pdo, $pid);
    $isOwner = (($mine['role'] ?? 'member') === 'owner');

    // Existing members keyed by id. The user_id/role/email fields are server-
    // owned and PRESERVED across the client's bulk replace, so:
    //   - an owner can never be wiped (the project can't be orphaned), and
    //   - a user can never inject role='owner' or steal an account link by
    //     sending those fields in the payload (we ignore them for display).
    $existing   = [];
    $myMemberId = null;
    foreach (rows($pdo, 'SELECT id, name, color, user_id, role, email FROM members WHERE project_id = ?', [$pid]) as $r) {
        $existing[$r['id']] = $r;
        if ($r['user_id'] !== null && $r['user_id'] === $me['id']) {
            $myMemberId = $r['id'];   // never let a save lock the actor out
        }
    }

    // Snapshot comments BEFORE wipe — board_save must not accept client-forged
    // comment authorship. Comments are written only via comment_add/delete.
    $savedComments = rows(
        $pdo,
        'SELECT c.id, c.card_id, c.user_id, c.author_name, c.comment, c.created_at
           FROM card_comments c
           JOIN cards cd ON cd.id = c.card_id
           JOIN lists l  ON l.id = cd.list_id
          WHERE l.project_id = ?',
        [$pid]
    );

    // Snapshot checklist checked-state so we can notify on newly completed items.
    $prevChecklist = [];
    foreach (rows(
        $pdo,
        'SELECT chk.id, chk.checked
           FROM card_checklists chk
           JOIN cards cd ON cd.id = chk.card_id
           JOIN lists l  ON l.id = cd.list_id
          WHERE l.project_id = ?',
        [$pid]
    ) as $r) {
        $prevChecklist[(string) $r['id']] = !empty($r['checked']);
    }

    // Snapshot cards for conflict merge (keep newer remote content if client is stale).
    $serverCardsById = [];
    foreach (rows(
        $pdo,
        'SELECT c.id, c.list_id, c.title, c.description, c.due, c.position, c.updated_at
           FROM cards c
           JOIN lists l ON l.id = c.list_id
          WHERE l.project_id = ?',
        [$pid]
    ) as $row) {
        $cid = (string) $row['id'];
        $serverCardsById[$cid] = hydrate_card_row(
            $pdo,
            $row,
            (string) $row['list_id'],
            (int) $row['position']
        );
    }
    $protectedCards = [];

    // Build the member roster that will be re-inserted.
    $toInsert = []; // member_id => payload row | null
    if ($isOwner) {
        // Owners control the roster (placeholders + kicking linked members),
        // but owners and the actor are always force-kept.
        foreach (($data['members'] ?? []) as $m) {
            $mid = (string) ($m['id'] ?? '');
            if ($mid === '') {
                $mid = 'mem_' . bin2hex(random_bytes(4));
            }
            $toInsert[$mid] = $m;
        }
        foreach ($existing as $eid => $r) {
            $mustKeep = (($r['role'] ?? '') === 'owner')
                || ($myMemberId !== null && $eid === $myMemberId);
            if ($mustKeep && !isset($toInsert[$eid])) {
                $toInsert[$eid] = null;
            }
        }
    } else {
        // Non-owners cannot kick anyone. Keep the full existing roster and
        // only allow NEW placeholder members (no user_id) from the client.
        foreach ($existing as $eid => $r) {
            $toInsert[$eid] = null;
        }
        foreach (($data['members'] ?? []) as $m) {
            $mid = (string) ($m['id'] ?? '');
            if ($mid === '' || isset($existing[$mid]) || isset($toInsert[$mid])) {
                continue;
            }
            // Reject any attempt to attach a user_id via board_save.
            $toInsert[$mid] = [
                'id'    => $mid,
                'name'  => (string) ($m['name'] ?? 'Member'),
                'color' => (string) ($m['color'] ?? '#888888'),
            ];
        }
        if ($myMemberId !== null && !isset($toInsert[$myMemberId])) {
            $toInsert[$myMemberId] = null;
        }
    }

    $newlyChecked = []; // [{card_id, card_title, item_id, text}]

    $pdo->beginTransaction();
    try {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title !== '') {
            $pdo->prepare('UPDATE projects SET name = ? WHERE id = ?')->execute([$title, $pid]);
        }

        // Wipe + rebuild (cascades clear cards/labels/card_members/images/comments).
        $pdo->prepare('DELETE FROM lists   WHERE project_id = ?')->execute([$pid]);
        $pdo->prepare('DELETE FROM members WHERE project_id = ?')->execute([$pid]);

        $insMem   = $pdo->prepare('INSERT INTO members (id, project_id, name, color, position, user_id, role, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insList  = $pdo->prepare('INSERT INTO lists (id, project_id, title, position, color) VALUES (?, ?, ?, ?, ?)');
        $insCard  = $pdo->prepare('INSERT INTO cards (id, list_id, title, description, due, position, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insLabel = $pdo->prepare('INSERT INTO card_labels (card_id, label) VALUES (?, ?)');
        $insCM    = $pdo->prepare('INSERT INTO card_members (card_id, member_id) VALUES (?, ?)');
        $insImg   = $pdo->prepare('INSERT INTO images (id, card_id, url, name, position) VALUES (?, ?, ?, ?, ?)');
        $insChk   = $pdo->prepare('INSERT INTO card_checklists (id, card_id, text, checked, position) VALUES (?, ?, ?, ?, ?)');
        $insCmt   = $pdo->prepare('INSERT INTO card_comments (id, card_id, user_id, author_name, comment, created_at) VALUES (?, ?, ?, ?, ?, ?)');

        // Members: client controls name/color; server-owned fields are reattached.
        $pos = 0;
        foreach ($toInsert as $mid => $m) {
            $ex     = $existing[$mid] ?? null;
            $userId = $ex['user_id'] ?? null;
            $role   = $ex['role'] ?? 'member';
            $email  = $ex['email'] ?? null;
            $name   = $m ? (string) ($m['name'] ?? '') : (string) ($ex['name'] ?? '');
            if ($name === '') {
                $name = (string) ($ex['name'] ?? 'Member');
            }
            $color  = $m ? (string) ($m['color'] ?? '') : (string) ($ex['color'] ?? '#888888');
            if ($color === '') {
                $color = (string) ($ex['color'] ?? '#888888');
            }
            // New placeholders never get a user_id/role/email from the client.
            if ($ex === null) {
                $userId = null;
                $role   = 'member';
                $email  = null;
            }
            $insMem->execute([$mid, $pid, $name, $color, $pos, $userId, $role, $email]);
            $pos++;
        }

        // Assignee refs are valid only against the final member set.
        $memberIds = array_fill_keys(array_keys($toInsert), true);
        $cardIds   = [];

        foreach (($data['lists'] ?? []) as $li => $l) {
            $lid = (string) ($l['id'] ?? '') ?: ('list_' . bin2hex(random_bytes(4)));
            $listColor = is_hex_color(isset($l['color']) ? (string) $l['color'] : null)
                ? (string) $l['color']
                : null;
            $insList->execute([$lid, $pid, (string) ($l['title'] ?? 'List'), (int) $li, $listColor]);

            foreach (($l['cards'] ?? []) as $ci => $c) {
                $cid = (string) ($c['id'] ?? '') ?: ('card_' . bin2hex(random_bytes(4)));
                $clientTs = trim((string) ($c['updated_at'] ?? ''));
                $serverCard = $serverCardsById[$cid] ?? null;
                $serverTs = is_array($serverCard) ? trim((string) ($serverCard['updated_at'] ?? '')) : '';
                $useServer = $serverCard && $serverTs !== '' && ($clientTs === '' || $serverTs > $clientTs);
                if ($useServer) {
                    // Remote wins on content; keep this list placement from the client save.
                    $c = $serverCard;
                    $protectedCards[$cid] = $serverCard;
                    $protectedCards[$cid]['list_id'] = $lid;
                    $protectedCards[$cid]['position'] = (int) $ci;
                }
                $cardTitle = (string) ($c['title'] ?? '');
                $due = (!empty($c['due']) && is_string($c['due'])) ? (string) $c['due'] : null;
                $desc = sanitize_rich_html((string) ($c['description'] ?? ''));
                $cardUpdatedAt = $useServer
                    ? $serverTs
                    : gmdate('Y-m-d H:i:s');
                $insCard->execute([$cid, $lid, $cardTitle, $desc, $due, (int) $ci, $cardUpdatedAt]);
                $cardIds[$cid] = true;

                foreach (($c['labels'] ?? []) as $lab) {
                    $insLabel->execute([$cid, (string) $lab]);
                }
                foreach (($c['members'] ?? []) as $memid) {
                    $memid = (string) $memid;
                    if (isset($memberIds[$memid])) {        // drop refs to members no longer in the project
                        $insCM->execute([$cid, $memid]);
                    }
                }
                foreach (($c['images'] ?? []) as $ii => $img) {
                    $url = safe_upload_url((string) ($img['url'] ?? ''));
                    if ($url === null) {
                        continue;                           // skip empty / non-local / unsafe urls
                    }
                    $iid = (string) ($img['id'] ?? '') ?: ('img_' . bin2hex(random_bytes(4)));
                    $insImg->execute([$iid, $cid, $url, (string) ($img['name'] ?? ''), (int) $ii]);
                }
                foreach (($c['checklist'] ?? []) as $chki => $chk) {
                    $chkid = (string) ($chk['id'] ?? '') ?: ('chk_' . bin2hex(random_bytes(4)));
                    $chkText = (string) ($chk['text'] ?? '');
                    $isChecked = !empty($chk['checked']);
                    $insChk->execute([
                        $chkid,
                        $cid,
                        $chkText,
                        $isChecked ? 1 : 0,
                        (int) $chki,
                    ]);
                    // Notify when an item becomes checked (was unchecked or new).
                    if ($isChecked && empty($prevChecklist[$chkid])) {
                        $newlyChecked[] = [
                            'card_id'    => $cid,
                            'card_title' => $cardTitle !== '' ? $cardTitle : 'Card',
                            'item_id'    => $chkid,
                            'text'       => $chkText !== '' ? $chkText : 'Checklist item',
                        ];
                    }
                }
                // Client-supplied comments are intentionally ignored.
            }
        }

        // Re-attach server-authoritative comments onto surviving cards.
        foreach ($savedComments as $cmt) {
            $cid = (string) ($cmt['card_id'] ?? '');
            if ($cid === '' || !isset($cardIds[$cid])) {
                continue; // card was deleted in this save
            }
            $insCmt->execute([
                (string) $cmt['id'],
                $cid,
                (string) $cmt['user_id'],
                (string) $cmt['author_name'],
                (string) $cmt['comment'],
                (string) $cmt['created_at'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $GLOBALS['tb_protected_cards'] = array_values($protectedCards);

    // Notifications are written after a successful commit so a rolled-back
    // save never leaves phantom activity in the inbox.
    foreach ($newlyChecked as $item) {
        create_notifications($pdo, $pid, $me, [
            'type'    => 'checklist',
            'card_id' => $item['card_id'],
            'message' => $me['name'] . ' completed "' . $item['text'] . '" on "' . $item['card_title'] . '"',
            'meta'    => [
                'card_title' => $item['card_title'],
                'item_text'  => $item['text'],
                'item_id'    => $item['item_id'],
            ],
        ]);
    }
}

/**
 * Delete uploaded image files no card (in any project) references. Files newer
 * than 2 minutes are spared so an in-flight upload is never swept.
 */
function gc_uploads(PDO $pdo): void
{
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        return;
    }
    $cardImages = $pdo->query('SELECT url FROM images')->fetchAll(PDO::FETCH_COLUMN);
    $userPhotos = $pdo->query('SELECT photo_url FROM users WHERE photo_url IS NOT NULL AND photo_url != ""')->fetchAll(PDO::FETCH_COLUMN);
    $referenced = array_fill_keys(array_merge($cardImages, $userPhotos), true);
    $cutoff = time() - 120;
    foreach ((glob($dir . '/*') ?: []) as $path) {
        if (!is_file($path)) {
            continue;
        }
        $rel = 'uploads/' . basename($path);
        if (isset($referenced[$rel])) {
            continue;
        }
        if (filemtime($path) > $cutoff) {
            continue;
        }
        @unlink($path);
    }
}
