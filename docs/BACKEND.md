# NAS Web Server — Backend Documentation

## Technology Stack

- **PHP 8.2** — Server-side scripting
- **Apache 2.4** — Web server with `mod_rewrite` enabled
- **MySQL 8.0** — Relational database
- **PDO** — Database access layer (prepared statements)
- **Cron** — Scheduled task execution (automatic backups)

## File Structure

```
www/
├── index.php                 # File manager (main page)
├── login.php                 # Authentication page + rate limiting
├── logout.php                # Session destruction
├── register.php              # Self-service account creation (always role=user)
├── profile.php               # Change own username/password
├── auth.php                  # Authentication + session_version validation
├── db.php                    # Database connection (shared)
├── users.php                 # User management page (admin)
├── monitor.php               # System monitoring dashboard (admin)
├── logs.php                  # System log viewer (admin)
├── backup.php                # Backup & restore page (admin)
├── permissions.php           # Per-file permissions page (admin)
├── cron_backup.php           # Automated backup script (cron, no auth)
├── usb_manifest.php          # Shared helper: writes hash-to-username manifest
├── monitor_data.php          # JSON endpoint for live monitor poll (admin)
├── backup_data.php           # JSON endpoint for live backup poll (admin)
├── user_files.php            # JSON endpoint for user detail modal (admin)
├── download.php              # File download handler
├── delete.php                # File/folder deletion handler
├── action_upload.php         # File upload handler
├── action_folder.php         # Folder creation handler
├── action_rename.php         # File/folder rename handler
├── action_user_create.php    # User creation handler (admin)
├── action_user_edit.php      # User edit handler (admin)
└── action_user_delete.php    # User deletion handler (admin)
```

## Shared Modules

### db.php — Database Connection

Establishes a PDO connection to MySQL using environment variables. Included by any file that needs database access.

- **Connection**: `mysql:host=db` (Docker service name resolved via internal DNS)
- **Credentials**: Read from `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` environment variables
- **Settings**: Exceptions enabled, associative fetch mode, native prepared statements

### auth.php — Authentication Helpers

Provides authentication helpers used across all protected pages, plus session-version validation:

| Function | Purpose | Used By |
|---|---|---|
| `require_login()` | Redirects to `/login.php` if not authenticated; also runs `validate_session_version()` | All protected pages |
| `require_admin()` | Returns 403 if user is not an admin | Admin-only pages |
| `current_user()` | Returns array with `id`, `username`, `role` from session | All pages for display |
| `is_admin()` | Returns boolean for admin check | Nav visibility, action gates |
| `validate_session_version()` | Compares session's cached `session_version` to the DB row. Mismatch → `session_destroy()` + redirect to `/login.php?reason=role_changed`. User gone → redirect with `reason=deleted`. | Called internally by `require_login()` |

The top of `auth.php` calls `session_start()` and `require_once 'db.php'`, making `$pdo` globally available so `validate_session_version()` can query without re-requiring.

### usb_manifest.php — USB Archive Manifest

Small helper that writes the host-side hash-to-username manifest used by the USB mirror watcher.

- **`update_user_manifest(PDO $pdo): array`** — regenerates `external_backups/.user_manifest.json`. Generates a salt on first run, then computes `u_<sha256(salt + user_id)[:12]>` for every user and writes `{ salt, users: { <id>: { hash, username } }, updated_at }`.
- **`username_for_hash(array $manifest, string $hash): ?string`** — reverse lookup for UI rendering.

Called from:
- `action_user_create.php`, `action_user_edit.php`, `action_user_delete.php`, `register.php` — any mutation of the `users` table
- `monitor.php`, `backup.php` — at page load as a safety net to keep the manifest fresh even if a mutation ran outside these handlers

## Authentication

### login.php

- **Method**: POST form submission
- **Rate limiting**: 5 failed attempts per IP within a 5-minute window trigger a lockout. Tracked per-IP in `/tmp/nas_login_attempts/<sha256(ip)>`. Cleared on any successful login.
- **Process**:
  1. Check rate-limit file for this IP; reject with countdown message if locked out
  2. Query `users` table by username (selects `id, username, password, role, session_version`)
  3. Verify password with `password_verify()` against bcrypt hash
  4. On success: store `user_id`, `username`, `role`, `session_version` in `$_SESSION`, update `last_login`, clear rate-limit file, redirect to `index.php`
  5. On failure: append timestamp to rate-limit file, display error, preserve submitted username
- **Force-logout notice**: Renders a friendly banner when redirected here with `?reason=role_changed` or `?reason=deleted`
- **Protection**: Already-logged-in users are redirected to `index.php`

### register.php

- Self-service sign-up — available to anyone who can reach the login page.
- Role is **hardcoded to `user`**; admins can only be created by existing admins.
- Username uniqueness checked before insert; password minimum 8 chars.
- Calls `update_user_manifest()` so the new user is in the USB manifest immediately.

### logout.php

- Calls `session_destroy()` and redirects to `/login.php`

## File Management

### action_upload.php

- **Auth**: `require_login()`
- **Process**:
  1. Validate `$_FILES['file']` has no errors
  2. Sanitize filename (replace non-word characters with `_`)
  3. Create user-specific directory: `/var/www/uploads/{user_id}/`
  4. Handle duplicate filenames by appending counter (`file_1.txt`, `file_2.txt`)
  5. Move uploaded file to destination
  6. Insert metadata into `files` table (owner, name, path, size, MIME type, parent folder)
- **Storage**: Files stored at `/var/www/uploads/{user_id}/{filename}`

### download.php

- **Auth**: `require_login()`, owner or admin only
- **Process**:
  1. Fetch file record by ID
  2. Verify ownership or admin role
  3. Serve file with `Content-Disposition: attachment` header

### delete.php

- **Auth**: `require_login()`, owner or admin only
- **Process**:
  1. Fetch file/folder record
  2. Verify ownership or admin role
  3. If file: delete from disk (`/var/www/uploads/`)
  4. Delete from database (foreign key cascades handle children and permissions)

### action_folder.php

- **Auth**: `require_login()`
- **Process**:
  1. Sanitize folder name
  2. Insert into `files` table with `is_folder = 1`, `filetype = 'inode/directory'`
  3. Set `parent_id` for nested folders

### action_rename.php

- **Auth**: `require_login()`, owner or admin only
- **Process**:
  1. Fetch file/folder record
  2. Sanitize new name
  3. If file: rename on disk, update `filepath` in database
  4. If folder: update `filename` only (no disk path)

## User Management (Admin Only)

### action_user_create.php

- **Auth**: `require_admin()`
- **Validation**: Username required, password minimum 8 characters, duplicate username check
- **Password**: Hashed with `password_hash($password, PASSWORD_BCRYPT)`
- **Roles**: `admin` or `user` (validated against whitelist)
- **Post-action**: calls `update_user_manifest()` so the new user gets a USB archive hash immediately

### action_user_edit.php

- **Auth**: `require_admin()`
- **Features**: Update username, email, role; optionally change password
- **Password**: If blank, keeps existing password; if provided, re-hashed
- **Role guards**:
  - **Self-demotion blocked** — an admin cannot change their own role to `user`
  - **Last-admin guard** — demoting the only remaining admin is refused (would leave the system unmanageable)
- **Session invalidation**: if the role actually changed, `UPDATE users SET session_version = session_version + 1` is issued. The target's next request to any protected page fails `validate_session_version()` and force-logs-them-out.
- **Post-action**: calls `update_user_manifest()`

### action_user_delete.php

- **Auth**: `require_admin()`
- **Safety**:
  - Cannot delete yourself
  - Cannot delete the last remaining admin
- **Cleanup**:
  1. Delete user's physical files from disk
  2. Remove empty upload directory
  3. Delete user from database (cascades to files and permissions)
  4. `update_user_manifest()` — manifest drops the entry so the watcher stops actively syncing that user. Their existing USB folder persists as an "orphaned archive" (forensic retention).

## System Monitoring (Admin Only)

### monitor.php

Renders the dashboard shell (hero stats, gauges, External Storage panel, charts toggle). Most dynamic values are populated/refreshed by a background JSON poll every 3 seconds — see `monitor_data.php` below. Reads from both `/proc` and the DB.

| Metric | Source | Method |
|---|---|---|
| Uptime | `/proc/uptime` | Formatted as d/h/m |
| CPU usage | `/proc/stat` (two samples, 150ms apart) | Custom `cpu_usage()` |
| Memory | `/proc/meminfo` | Custom `mem_info()` |
| Load average | `/proc/loadavg` | 1m / 5m / 15m |
| Uploads / Backups volume | `disk_total_space`, `disk_free_space` on the actual bind-mount paths (`/var/www/uploads`, `/var/www/backups`) — reflects the host disk, not container overlay | PHP built-in |
| User / file / folder counts | SQL `COUNT(*)` queries | PDO |
| Uploads total bytes | `SUM(filesize)` across `files` — DB is source of truth | PDO |
| Recent uploads | SQL query (last 10 files) | PDO |
| Per-user storage | SQL `SUM(filesize) GROUP BY user` | PDO |
| Active sessions | `users WHERE last_login >= NOW() - 30 MIN` | PDO |
| Last automatic backup | `backups WHERE filename LIKE 'nas_auto_backup_%' ORDER BY created_at DESC LIMIT 1` | PDO |
| USB mirror stats | `external_backups/.usb_sync_status` heartbeat file (written by the host watcher) | File read |

### Live Polling Endpoints

These are JSON-only endpoints polled by the pages above (every 3–5 seconds) for in-place UI updates without full page reloads. All require `require_admin()`.

| Endpoint | Consumed by | Returns |
|---|---|---|
| `monitor_data.php` | `monitor.php` (3s poll) | Everything in the monitor metrics table plus a `usb` sub-object with full External Storage state |
| `backup_data.php` | `backup.php` (5s poll) | Full backup list + counts + USB badge state. Also runs the reconciler each call. |
| `user_files.php?id=X` | `users.php` (on row click) | One user's owned files (with who each is shared with) plus files shared with them. Validates ID; returns 400/404 for bad input. |

## System Logs (Admin Only)

### logs.php

Displays server log files with tab-based navigation:

| Log | Path | Notes |
|---|---|---|
| Apache Access | `/var/log/apache2/access.log` | Symlinked to `/dev/stdout` in Docker |
| Apache Error | `/var/log/apache2/error.log` | Symlinked to `/dev/stderr` in Docker |
| PHP Errors | `/var/log/php_errors.log` | Standard PHP error log |
| Backup Log | `/var/log/backup_cron.log` | Output from scheduled backups |
| System Log | `/var/log/syslog` | General system messages |

- **Docker handling**: Detects symlinks to `/dev/stdout`/`/dev/stderr` and reads from `/proc/1/fd/` with a timeout to prevent hangs
- **Line limit**: Configurable (50, 100, 250, 500 lines)

## Backup & Restore (Admin Only)

### backup.php — Manual Backups

**Create backup:**
1. Dump database with `mysqldump --skip-ssl --no-tablespaces`
2. Copy `/var/www/uploads/` directory
3. ZIP everything into `/var/www/backups/nas_backup_{timestamp}.zip`
4. Record in `backups` table

**Restore backup:**
1. Extract ZIP to temp directory
2. Import `database.sql` with `mysql --skip-ssl`
3. Replace `/var/www/uploads/` contents
4. Clean up temp directory

**Push All to USB:**
- Admin-triggered action that drops an `external_backups/.sync_request` marker file. The host watcher detects it on its next 3-second tick, runs an explicit full mirror, updates the heartbeat with `status: ok_manual`, and deletes the marker. The button is disabled in the UI when USB isn't connected.

**Other actions:** Download backup ZIP, delete backup (file + DB record).

### DB/disk Reconciler

`backup.php` and `backup_data.php` both call `reconcile_backups($pdo)` before listing backups. This keeps three sources consistent — DB rows, disk files, and USB files:

1. For each `backups` row whose `filepath` no longer exists on disk → delete the row (orphan-row).
2. For each `/var/www/backups/nas_*backup_*.zip` on disk without a matching DB row → insert one (orphan-file).

This matters specifically around **restore**, which replaces the entire `backups` table with the snapshot's version — creating orphan rows pointing at zips that no longer exist. Running the reconciler post-action keeps counts honest.

The reconciler intentionally runs *after* action handlers (not at the top of the file) so that the restore path's mid-request DB replacement is caught before the list query renders.

### Scheduled Backups

- **Schedule options**: Daily (2 AM), Weekly (Sunday 2 AM), Monthly (1st, 2 AM), Disabled
- **Implementation**: Writes cron entries inside the container via `crontab`
- **Script**: `cron_backup.php` runs standalone (no session/auth), connects directly to database
- **Retention**: Automatically keeps only the last 10 automatic backups

### USB Archive (optional, host-side)

Orthogonal to the in-container backup flow. When the Windows-side watcher is running (see [ARCHITECTURE.md](ARCHITECTURE.md#usb-mirror-architecture-host-side)), every new backup zip and every user upload is mirrored to a USB drive using `robocopy /E` (append-only semantics — copies new files, never deletes).

The container doesn't interact with the USB directly. It only reads the watcher's heartbeat file (`external_backups/.usb_sync_status`) to surface live stats in the Monitor and Backup pages. When an admin deletes a backup in the web UI, the local file + DB row are removed; the USB copy survives (archive property).

## Per-File Permissions (Admin Only)

### permissions.php

Manages the `permissions` table for granular file access control:

| Permission | Column | Default |
|---|---|---|
| Read | `can_read` | 1 (granted) |
| Write | `can_write` | 0 (denied) |
| Delete | `can_delete` | 0 (denied) |

- **Unique constraint**: One permission entry per user per file (`unique_file_user`)
- **Upsert**: Uses `INSERT ... ON DUPLICATE KEY UPDATE` for updates
- **Owner**: Always has full access (not shown in permissions list)

## Error Handling

- **Flash messages**: Stored in `$_SESSION['flash']` with `type` (success/error) and `msg`
- **Database errors**: PDO exceptions caught in `db.php`, returns 500 JSON error
- **Auth errors**: Redirect to login (unauthenticated) or 403 (unauthorized)
- **File errors**: Flash messages for upload failures, missing files, access denied

## Environment Variables

| Variable | Used By | Purpose |
|---|---|---|
| `MYSQL_DATABASE` | db.php, backup.php, cron_backup.php | Database name |
| `MYSQL_USER` | db.php, backup.php, cron_backup.php | Database username |
| `MYSQL_PASSWORD` | db.php, backup.php, cron_backup.php | Database password |
| `MYSQL_ROOT_PASSWORD` | MySQL container only | Root password for MySQL |
