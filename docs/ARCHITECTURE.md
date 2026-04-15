# NAS Web Server — Architecture

## System Architecture

```
┌───────────────────────────────────────────────────────────────────────┐
│                         Docker Environment                             │
│                                                                        │
│  ┌──────────────┐   ┌──────────────┐   ┌─────────────┐  ┌───────────┐ │
│  │   nas-web     │   │   nas-db      │   │nas-phpmy-   │  │nas-tunnel │ │
│  │              │   │              │   │admin        │  │           │ │
│  │  Apache 2.4  │◄─►│  MySQL 8.0   │◄─►│             │  │cloudflared│ │
│  │  PHP 8.2     │   │              │   │             │  │           │ │
│  │  Cron        │   │              │   │             │  │(outbound) │ │
│  │              │   │  expose 3306 │   │             │  │           │ │
│  │  Port: 8080  │   │  (no host    │   │  Port: 8081 │  │  no port  │ │
│  │              │   │   publish)   │   │             │  │   exposed │ │
│  └──────┬───────┘   └──────┬───────┘   └─────────────┘  └─────▲─────┘ │
│         │                  │                                  │       │
│         │                  │     ┌───────────────────┐        │       │
│         │                  │     │ Cloudflare edge   │◄───────┘       │
│         │                  │     │ *.trycloudflare   │ reverse tunnel │
│         │                  │     │ .com              │                │
│         │                  │     └───────────────────┘                │
│         ▼                  ▼                                           │
│  ┌──────────────┐   ┌──────────────┐   ┌────────────────────┐         │
│  │ ./www         │   │  db_data     │   │ ./external_backups │         │
│  │ (bind mount)  │   │  (volume)    │   │  (bind mount)      │         │
│  └──────────────┘   └──────────────┘   └────────────────────┘         │
│         │                                                              │
│  ┌──────────────┐                                                      │
│  │ ./uploads     │                                                      │
│  │ (bind mount)  │                                                      │
│  └──────────────┘                                                      │
└───────────────────────────────────────────────────────────────────────┘

    (host-only) mirror_watcher.ps1 ── robocopy ──►  D:\nas-backups
                                                     D:\nas-users\u_<hash>
```

## Container Services

| Service | Container | Image | Host Port | Purpose |
|---|---|---|---|---|
| web | nas-web | Custom (php:8.2-apache) | 8080 | Web server, PHP runtime, cron daemon |
| db | nas-db | mysql:8.0 | **none** | Relational database (Docker network only — no host publish) |
| phpmyadmin | nas-phpmyadmin | phpmyadmin:latest | 8081 | Database management UI |
| tunnel | nas-tunnel | cloudflare/cloudflared | — | Outbound reverse tunnel → public HTTPS URL |

The `db` service intentionally does **not** publish port 3306 to the host. Only other containers on the Docker network can reach MySQL. The `tunnel` service opens an outbound connection to Cloudflare and never listens on any host port.

## Data Storage

| Volume/Mount | Type | Path in Container | Purpose |
|---|---|---|---|
| `./www` | Bind mount | `/var/www/html` | PHP application source code |
| `./uploads` | Bind mount | `/var/www/uploads` | User-uploaded files |
| `./external_backups` | Bind mount | `/var/www/backups` | Backup ZIP archives (survives container rebuilds) |
| `db_data` | Docker volume | `/var/lib/mysql` | MySQL database data |
| `D:\nas-backups` | Host-side USB mirror (optional) | — | Append-only mirror of backup zips |
| `D:\nas-users\u_<hash>\` | Host-side USB mirror (optional) | — | Per-user append-only archive of uploaded files |

## Request Flow

```
Browser Request
      │
      ▼
  Apache (port 80 inside container, mapped to 8080)
      │
      ▼
  PHP Script (e.g., index.php)
      │
      ├── auth.php ──► Session check ──► Redirect to login.php if unauthenticated
      │
      ├── db.php ──► PDO connection to MySQL (host: "db", Docker DNS)
      │
      └── Business Logic ──► Query database, read/write files
              │
              ▼
          HTML Response (server-rendered with embedded CSS/JS)
```

## Authentication Flow

```
login.php (POST)
      │
      ├── Rate limit check: ≤5 failed attempts per IP per 5 min
      │   (file-based tracker in /tmp/nas_login_attempts/)
      │
      ├── Query users table by username
      │
      ├── password_verify() against bcrypt hash
      │
      ├── On success: Set $_SESSION[user_id, username, role, session_version]
      │               Redirect to index.php
      │
      └── On failure: Increment attempt counter, show error

Protected Pages:
      │
      ├── require_login()  ──► Check $_SESSION['user_id'] exists
      │                    ──► validate_session_version() (see below)
      │                        Redirect to login.php if invalid
      │
      └── require_admin()  ──► Check $_SESSION['role'] === 'admin'
                               Return 403 if not

validate_session_version():
      │
      ├── Query users row for current role + session_version
      ├── User gone?  ──► session_destroy() + redirect to /login.php?reason=deleted
      ├── Version mismatch?  ──► session_destroy() + redirect to
      │                          /login.php?reason=role_changed
      └── Match  ──► refresh $_SESSION['role'] from DB, proceed
```

**How `session_version` works.** The `users` table has a `session_version INT` column (default 0). Each login stamps the current version into `$_SESSION['session_version']`. When an admin changes a target user's role, the row's version is incremented. On the target's next request, `validate_session_version()` sees the mismatch and force-logs-them-out. This closes the "demoted admin keeps admin rights until logout" window.

## Database Schema

```
┌──────────────────┐       ┌──────────────────┐       ┌──────────────┐
│     users         │       │      files        │       │  permissions  │
├──────────────────┤       ├──────────────────┤       ├──────────────┤
│ id            PK │◄──┐   │ id            PK │◄──┐   │ id        PK │
│ username         │   │   │ owner_id      FK │───┘   │ file_id   FK │───┐
│ password         │   │   │ filename         │       │ user_id   FK │───┤
│ email            │   │   │ filepath         │       │ can_read     │   │
│ role             │   │   │ filesize         │       │ can_write    │   │
│ storage_quota    │   │   │ filetype         │       │ can_delete   │   │
│ session_version  │   │   │ is_folder        │       └──────────────┘   │
│ created_at       │   │   │ parent_id     FK │───┐                      │
│ last_login       │   │   │ created_at       │   │   (self-referencing   │
└──────────────────┘   │   │ updated_at       │   │    for nested folders)│
                       │   └──────────────────┘   │                      │
                       │                          │                      │
                       │   ┌──────────────────┐   │                      │
                       │   │     backups       │   │                      │
                       │   ├──────────────────┤   │                      │
                       │   │ id            PK │   │                      │
                       └───│ created_by    FK │   │                      │
                           │ filename         │   │                      │
                           │ filepath         │   │                      │
                           │ filesize         │   │                      │
                           │ created_at       │   │                      │
                           └──────────────────┘   │                      │
                                                  │                      │
                  References: files.parent_id ────┘                      │
                  References: permissions.file_id ───────────────────────┘
```

### Notable user columns

| Column | Purpose |
|---|---|
| `storage_quota` | Per-user upload cap in bytes (`NULL` = unlimited, enforced in `action_upload.php`) |
| `session_version` | Bumped on role change; cached in session and checked by `auth.php` on every request for immediate invalidation |

### Cascade Rules

| Relationship | On Delete |
|---|---|
| `files.owner_id` → `users.id` | CASCADE (delete user = delete all their files) |
| `files.parent_id` → `files.id` | CASCADE (delete folder = delete children) |
| `permissions.file_id` → `files.id` | CASCADE (delete file = delete its permissions) |
| `permissions.user_id` → `users.id` | CASCADE (delete user = delete their permissions) |
| `backups.created_by` → `users.id` | SET NULL (delete user = keep backup, set creator to NULL) |

## Security Model

```
                    ┌─────────────┐
                    │    Admin     │
                    │             │
                    │ All pages   │
                    │ All files   │
                    │ User CRUD   │
                    │ Backups     │
                    │ Monitoring  │
                    │ Logs        │
                    │ Permissions │
                    └──────┬──────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
              ▼            ▼            ▼
         ┌─────────┐ ┌─────────┐ ┌─────────┐
         │ User A   │ │ User B   │ │ User C   │
         │          │ │          │ │          │
         │ Own files│ │ Own files│ │ Own files│
         │ only     │ │ only     │ │ only     │
         └─────────┘ └─────────┘ └─────────┘
```

- **Passwords**: Hashed with bcrypt (`PASSWORD_BCRYPT`)
- **Sessions**: PHP native sessions, server-side; invalidated on role change via `session_version`
- **Login brute-force**: Rate-limited to 5 failed attempts per IP per 5 min (file-based tracker)
- **SQL Injection**: Prevented via PDO prepared statements
- **XSS**: Output escaped with `htmlspecialchars()`
- **File Uploads**: Filenames sanitized, stored outside web root path
- **Credentials**: Stored in `.env` file, excluded from git via `.gitignore`
- **Network**: MySQL port 3306 not exposed to host — only reachable via Docker internal network
- **Role guards**: Self-demotion blocked; last-admin demote/delete blocked

See [SECURITY.md](SECURITY.md) for the full layered defense breakdown.

## USB Mirror Architecture (Host-Side)

The NAS can optionally mirror its data to a USB drive in near-real-time, providing physical off-machine redundancy without requiring any code inside Docker to reach the USB directly. This decouples the application from the mirroring concern — the container has no awareness of the drive.

### Components

```
  (inside container)              (host Windows)              (USB drive)
  ─────────────────               ──────────────              ───────────
  PHP writes a backup      ┌─►  external_backups/*.zip  ─┐
   or a user upload        │                              │
   →  external_backups/    │    mirror_watcher.ps1       │
   →  uploads/<id>/  ──────┘     (Task Scheduler, hidden, ├─►  D:\nas-backups\
                                  runs at logon, polls    │       *.zip
                                  every 3s)               │
  PHP reads heartbeat      ◄──   writes heartbeat JSON    ├─►  D:\nas-users\
   from external_backups/        (.usb_sync_status) +    │       u_<hash>\
   .usb_sync_status              reads the manifest      │         <user files>
                                  (.user_manifest.json)   │
                                                          │    (append-only,
                                                          └─    hashed folders)
```

### Pieces

| Piece | Location | Purpose |
|---|---|---|
| `mirror_watcher.ps1` | Host `scripts/` | Long-running watcher; polls every 3s and mirrors `external_backups/*.zip` → USB, plus each user's `uploads/<id>/` → `D:\nas-users\u_<hash>/`. Uses `robocopy /E` (append-only). |
| `mirror_watcher_launcher.vbs` | Host `scripts/` | Hidden launcher so the PowerShell console window never appears |
| `install_usb_sync.ps1` | Host `scripts/` | Registers the watcher as a Windows Scheduled Task triggered at user logon |
| `.usb_sync_status` | `external_backups/` (host + container see it) | Heartbeat JSON — watcher writes, PHP reads. Surfaces live stats in the Monitor page. |
| `.user_manifest.json` | `external_backups/` (host + container see it) | Hash-to-username mapping — PHP writes, watcher reads. Only on the host, never copied to USB. |
| `usb_manifest.php` | `www/` | Shared helper that regenerates the manifest whenever users are created/edited/deleted/registered |

### Key design decisions

1. **Append-only** — `robocopy /E` copies new and changed files but never deletes from the target. A user deleting a file in the NAS web UI leaves the USB copy intact. This protects against accidental deletion (real backup semantics, not sync semantics).
2. **Hashed folders** — users are mirrored to `D:\nas-users\u_<sha256(salt+user_id)[:12]>\` instead of by username. The salt and hash map live only on the host side. Physical theft of the drive reveals folder contents but not user identities.
3. **Orphan retention** — when a user is deleted, their USB folder isn't removed. The watcher counts folders whose hash isn't in the current manifest as "orphaned archives" and surfaces the count on the Monitor page. Admins can reclaim space by deleting those folders manually from File Explorer.
4. **Manual trigger** — PHP can drop a `.sync_request` marker file that the watcher reads on its next tick; "Push All to USB" on the backup page uses this for on-demand mirroring.
5. **No Docker dependency on the drive** — Docker Desktop for Windows has WSL2/mount quirks for non-C drives. Putting the mirror entirely on the host avoids these issues completely.
