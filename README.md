# Task Board

A self-hosted **Trello-like kanban board** built with PHP, CSS, and vanilla JS.
Data lives in **SQLite** (`database/data.db`). No build step, no framework.

## Features

- **Boards & lists** — multiple projects; drag-and-drop lists and cards (SortableJS)
- **Cards** — title, rich-text description, labels, due dates, checklists, comments
- **Members** — per-project roster; assign people to cards; owner / invite / leave
- **Accounts** — register, email verification, login, password reset (SMTP)
- **Images** — attach or paste images on cards; stored in `uploads/` with orphan GC
- **Import / Export** — project JSON export and import
- **Security basics** — sessions + CSRF, hashed passwords, rate limits, sanitized HTML, upload URL allowlist

## Configuration

Copy `.env.example` to `.env` and set:

| Variable | Purpose |
|---|---|
| `APP_BASE_URL` | Public HTTPS URL of the app (required for email links) |
| `ALLOW_OPEN_REGISTRATION` | `true` = open signup; `false` = first user only, then invite-only |
| `TRUST_PROXY` | `true` only behind a trusted reverse proxy |
| `MAIL_*` | SMTP host, port, credentials, from address |

`.env` is gitignored and blocked from HTTP. For nginx, see `nginx.example.conf`.

## Stack

| Path | Role |
|---|---|
| `index.php` | App UI |
| `api.php` | JSON API |
| `assets/` | `app.js`, `style.css` |
| `inc/` | DB, auth, mail, env, sanitizer |
| `database/` | SQLite (`data.db`) |
| `uploads/` | Card and profile images |
