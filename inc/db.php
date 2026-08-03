<?php
/**
 * Task Board — SQLite data layer.
 *
 * Exposes db(): a shared PDO connection. On first use it creates the schema
 * (CREATE TABLE IF NOT EXISTS) and, if the DB is empty, seeds a starter
 * project. The database (data.db) is the single source of truth — there is
 * no file-based store for board data.
 *
 * Design notes:
 *   - Primary keys are TEXT (client-generated ids), so the client's ids are
 *     the DB's ids — saving a board is a simple transactional replace.
 *   - Foreign keys + ON DELETE CASCADE keep children consistent when a project
 *     or list is removed (requires PRAGMA foreign_keys = ON per connection).
 *   - WAL journal mode for better read/write concurrency.
 */

declare(strict_types=1);

const DB_FILE = __DIR__ . '/../database/data.db';

/** Shared PDO connection with sane defaults; initializes schema on first call. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dbDir = dirname(DB_FILE);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        db_init($pdo);
    }
    return $pdo;
}

/**
 * Add a column to a table only if it is missing. SQLite has no
 * "ADD COLUMN IF NOT EXISTS", so this introspects PRAGMA table_info first.
 *
 * @return bool true if the column was newly added
 */
function add_column_if_missing(PDO $pdo, string $table, string $column, string $type): bool
{
    $st = $pdo->prepare("PRAGMA table_info($table)");
    $st->execute();
    foreach ($st->fetchAll() as $row) {
        if (strcasecmp((string) $row['name'], $column) === 0) {
            return false; // already present
        }
    }
    // $type / identifiers are trusted literals from this codebase, never user input.
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
    return true;
}

/** Create tables if missing and seed/migrate on a brand-new database. */
function db_init(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id         TEXT PRIMARY KEY,
            name       TEXT NOT NULL DEFAULT 'Project',
            position   INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS lists (
            id         TEXT PRIMARY KEY,
            project_id TEXT NOT NULL,
            title      TEXT NOT NULL DEFAULT 'List',
            position   INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS cards (
            id          TEXT PRIMARY KEY,
            list_id     TEXT NOT NULL,
            title       TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            due         TEXT,
            position    INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS card_labels (
            card_id TEXT NOT NULL,
            label   TEXT NOT NULL,
            PRIMARY KEY (card_id, label),
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS members (
            id         TEXT PRIMARY KEY,
            project_id TEXT NOT NULL,
            name       TEXT NOT NULL,
            color      TEXT NOT NULL,
            position   INTEGER NOT NULL DEFAULT 0,
            user_id    TEXT,                                  -- linked account, NULL for a placeholder
            role       TEXT NOT NULL DEFAULT 'member',        -- 'owner' | 'member'
            email      TEXT,                                  -- invitee email when linked
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS card_members (
            card_id   TEXT NOT NULL,
            member_id TEXT NOT NULL,
            PRIMARY KEY (card_id, member_id),
            FOREIGN KEY (card_id)   REFERENCES cards(id)   ON DELETE CASCADE,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS images (
            id       TEXT PRIMARY KEY,
            card_id  TEXT NOT NULL,
            url      TEXT NOT NULL,
            name     TEXT NOT NULL DEFAULT '',
            position INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS card_checklists (
            id       TEXT PRIMARY KEY,
            card_id  TEXT NOT NULL,
            text     TEXT NOT NULL DEFAULT '',
            checked  INTEGER NOT NULL DEFAULT 0,
            position INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS card_comments (
            id          TEXT PRIMARY KEY,
            card_id     TEXT NOT NULL,
            user_id     TEXT NOT NULL,
            author_name TEXT NOT NULL,
            comment     TEXT NOT NULL,
            created_at  TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        -- Accounts + auth. Added when the app gained a login system; the
        -- tables are created idempotently so existing data.db files upgrade
        -- in place without losing boards.
        CREATE TABLE IF NOT EXISTS users (
            id            TEXT PRIMARY KEY,
            name          TEXT NOT NULL,
            email         TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at    TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS password_resets (
            id         TEXT PRIMARY KEY,
            user_id    TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at    TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS login_throttle (
            email     TEXT PRIMARY KEY,
            failures  INTEGER NOT NULL DEFAULT 0,
            last_fail TEXT
        );
        CREATE TABLE IF NOT EXISTS rate_limits (
            bucket       TEXT PRIMARY KEY,
            hits         INTEGER NOT NULL DEFAULT 0,
            window_start TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS email_verifications (
            id         TEXT PRIMARY KEY,
            user_id    TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at    TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    // Add the account-link columns to the legacy `members` table if this is an
    // older database. SQLite has no ADD COLUMN IF NOT EXISTS, so check first.
    add_column_if_missing($pdo, 'members', 'user_id', 'TEXT');
    add_column_if_missing($pdo, 'members', 'role', "TEXT NOT NULL DEFAULT 'member'");
    add_column_if_missing($pdo, 'members', 'email', 'TEXT');
    add_column_if_missing($pdo, 'users', 'photo_url', 'TEXT');
    // One-shot: when the verification column is first added, treat existing
    // accounts as already verified. Do NOT re-run on every request.
    $addedVerified = add_column_if_missing($pdo, 'users', 'email_verified_at', 'TEXT');
    if ($addedVerified) {
        $pdo->prepare(
            "UPDATE users SET email_verified_at = COALESCE(created_at, datetime('now')) WHERE email_verified_at IS NULL"
        )->execute();
    }
    // First run only: seed a starter project so a brand-new DB isn't empty.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($count === 0) {
        db_seed_default($pdo);
    }
}

/**
 * Seed a minimal first project when the database is brand new and empty.
 */
function db_seed_default(PDO $pdo): void
{
    $pid = 'proj_' . bin2hex(random_bytes(6));
    $pdo->prepare('INSERT INTO projects (id, name, position) VALUES (?, ?, ?)')
        ->execute([$pid, 'My First Project', 0]);

    $lid = 'list_' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO lists (id, project_id, title, position) VALUES (?, ?, ?, ?)')
        ->execute([$lid, $pid, 'To Do', 0]);

    $cid = 'card_' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO cards (id, list_id, title, description, position) VALUES (?, ?, ?, ?, ?)')
        ->execute([$cid, $lid, 'Welcome to your board', 'Switch projects from the dropdown, or create a new one with +.', 0]);
}
