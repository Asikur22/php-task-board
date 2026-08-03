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
