# NAS Web Server

A self-hosted, Docker-orchestrated Network Attached Storage platform with role-based access control, per-file ACLs, scheduled backup-and-restore, live system monitoring, and zero-config public HTTPS via Cloudflare Tunnel.

Built as a full-stack engineering project to demonstrate production-grade practices in a constrained environment: containerized service orchestration, defense-in-depth security, end-to-end automated testing, and an optional host-side data-redundancy layer that mirrors backups to an external drive in real time.

---

## Highlights

- **Full-stack delivery** — PHP 8.2 / Apache 2.4 backend, MySQL 8.0 persistence, vanilla JS/HTML/CSS frontend, 25+ server-side modules across auth, file ops, permissions, monitoring, and backup.
- **Containerized architecture** — four-service Docker Compose stack (web, db, phpMyAdmin, Cloudflare tunnel) with isolated networks, bind-mounted persistent volumes, and a custom Apache + PHP image.
- **Defense-in-depth security** — bcrypt password hashing, PDO prepared statements (SQL injection), `htmlspecialchars` output encoding (XSS), `escapeshellarg` on every shell invocation (command injection), IP-based login rate limiting, session-invalidation on role transitions, and MySQL with no host-port exposure.
- **75 automated tests** — 45 PHP unit tests covering DB and business-logic invariants, plus 30 bash/cURL end-to-end tests exercising the live HTTP stack.
- **Operational tooling** — cron-scheduled backups with calendar UI, point-in-time restore, automatic 10-archive rotation, real-time monitoring dashboard (CPU, memory, disk, active sessions, per-user storage), and live log viewer.
- **Zero-config remote access** — Cloudflare Quick Tunnel sidecar publishes a public HTTPS URL on every startup with no router, firewall, or DNS configuration required.
- **Optional secondary backup tier** — Windows host-side PowerShell watcher (registered as a logon-time scheduled task) mirrors every backup ZIP to a USB drive within ~3 seconds of creation, with hashed per-user folders for privacy and BitLocker recommendations for physical security.
- **Self-healing schema** — boot-time schema patcher upgrades existing DB volumes in place, so older deployments stay compatible with new feature columns without manual migrations.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Web server | Apache 2.4 (custom image) |
| Backend | PHP 8.2 (PDO, sessions, bcrypt) |
| Database | MySQL 8.0 |
| DB admin | phpMyAdmin |
| Public access | Cloudflare Tunnel (`cloudflared`) |
| Orchestration | Docker Compose |
| Host-side automation | PowerShell + Windows Task Scheduler |
| Testing | PHP unit harness, bash + cURL e2e |

---

## Architecture

```
        ┌────────────────────────┐
        │  Cloudflare Quick      │  public HTTPS URL
        │  Tunnel  (cloudflared) │  rotates on restart
        └───────────┬────────────┘
                    │
                    ▼
┌──────────────────────────────────┐       ┌──────────────────┐
│  nas-web                         │       │  nas-phpmyadmin  │
│  Apache + PHP 8.2                │       │  DB admin UI      │
│  - file manager / ACLs / auth    │       └────────┬─────────┘
│  - monitoring + backup + cron    │                │
└────────────────┬─────────────────┘                │
                 │  internal docker network         │
                 ▼                                  ▼
        ┌──────────────────────────────────────────────┐
        │  nas-db  (MySQL 8.0, no host port exposed)    │
        └──────────────────────────────────────────────┘
                 │
                 ▼  (bind mount)
        ./external_backups/  ──►  optional USB mirror (PowerShell watcher)
```

---

## Feature Summary

### File Management
Upload, download, rename, delete files and folders. Breadcrumb navigation, search, drag-and-drop upload, per-user storage quotas enforced at upload time.

### Role-Based & Per-File Access Control
Two roles (`admin`, `user`). Owners and admins grant `read` / `write` / `delete` on individual files; every action re-checks permissions server-side so URL tampering cannot bypass them. Role transitions invalidate active sessions automatically.

### System Monitoring (admin)
Real-time CPU, memory, load, uptime, host disk usage, active sessions (30-min window), per-user storage breakdown, recent uploads feed, and a live external-storage panel showing USB mirror status.

### Backup & Restore (admin)
- Manual one-click backups (database + uploads → single zip)
- Cron-driven scheduled backups with calendar UI
- Point-in-time restore (rewinds DB and files atomically)
- Auto-rotation: keeps the last 10 automatic backups
- Optional USB mirror tier (see below)

### Security Posture
| Layer | Mitigation |
|---|---|
| Auth | bcrypt password hashing, PHP server-side sessions |
| Brute force | 5 failed attempts / 5-min lockout per IP |
| SQL injection | PDO prepared statements everywhere |
| XSS | `htmlspecialchars` on all rendered output |
| Command injection | `escapeshellarg` on every shell call |
| Network | MySQL not exposed to host (internal docker network only) |
| Session hijack | Role-transition session invalidation |

See [docs/SECURITY.md](docs/SECURITY.md) for the layered defense breakdown.

### Logs (admin)
Apache access log, PHP error log, and backup operation log, all viewable in-app.

---

## Setup

### Prerequisites
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows / macOS) or Docker Engine + Docker Compose (Linux)
- ~2 GB free disk space
- A web browser

No PHP, MySQL, or Apache installation required — everything runs in containers.

### 1. Clone the repository
```bash
git clone <repo-url>
cd NAS
```

### 2. Create the `.env` file in the project root
```bash
MYSQL_ROOT_PASSWORD=changeme_root
MYSQL_DATABASE=nas_db
MYSQL_USER=nas_user
MYSQL_PASSWORD=changeme_user
```
> Pick your own passwords. This file is gitignored — never commit it.

### 3. Start the stack
```bash
docker compose up -d
```
First run takes a few minutes (downloading images, initializing the database).

### 4. Verify everything is running
```bash
docker compose ps
```
You should see four containers: `nas-web`, `nas-db`, `nas-phpmyadmin`, `nas-tunnel`.

### 5. Open the app
Visit **http://localhost:8080** and log in with the seeded admin account:

| Username | Password |
|----------|----------|
| `admin`  | `admin123` |

---

## Service URLs

| Service             | URL                          | Purpose |
|---------------------|------------------------------|---------|
| NAS Web App         | http://localhost:8080        | Main interface |
| phpMyAdmin          | http://localhost:8081        | DB admin (use `nas_user` + your `.env` password) |
| Public tunnel URL   | see "Remote Access" below    | HTTPS access from anywhere |

---

## Remote Access (Cloudflare Tunnel)

The `nas-tunnel` container automatically exposes the NAS to a public HTTPS URL on every startup. **No router config, no port forwarding, no Cloudflare account required.**

### Get the current URL

**PowerShell (Windows):**
```powershell
docker logs nas-tunnel | Select-String "trycloudflare.com"
```

**Bash (macOS/Linux/Git Bash):**
```bash
docker logs nas-tunnel 2>&1 | grep -oE "https://[a-zA-Z0-9-]+\.trycloudflare\.com" | head -1
```

### Notes
- The URL **changes every time** the tunnel container is restarted.
- To stop public access without taking down the rest of the stack:
  ```bash
  docker compose stop tunnel
  ```
- To restart and get a new URL:
  ```bash
  docker compose up -d tunnel
  ```

---

## Optional: USB Backup Mirror (Windows)

Plug in any USB drive and have the NAS automatically mirror every backup to it within ~3 seconds of creation — the same "secondary backup destination" pattern commercial NAS products call **External Storage**.

**Fully optional.** Without a USB plugged in, the External Storage panel shows "Disconnected" and nothing else changes.

### Setup (~1 minute)

1. **Plug in your USB drive.** Note the drive letter Windows assigns (e.g. `D:`, `E:`, `F:`).

2. **Edit the watcher path.** Open [scripts/mirror_watcher.ps1](scripts/mirror_watcher.ps1) and change line 9 if your drive isn't `D:`:
   ```powershell
   [string]$Target = "D:\nas-backups"   # change D: to your drive letter
   ```

3. **Run the install script** in PowerShell (no admin needed):
   ```powershell
   .\scripts\install_usb_sync.ps1
   ```

The script registers a hidden Windows scheduled task that starts at user logon, polls every 3 seconds, and mirrors any new backup zip from `external_backups/` to the USB drive. Confirm by visiting the **Monitor** page — the **External Storage** panel should show a green dot, capacity gauge, and live file count.

### To remove later
```powershell
.\scripts\uninstall_usb_sync.ps1
```

### What gets mirrored
Two things, continuously, append-only (never deletes):

1. **`D:\nas-backups\`** — every backup ZIP as soon as it's created
2. **`D:\nas-users\u_<hash>\`** — every user's uploaded files, one folder per user, named by a hash of the user ID so physical theft of the drive doesn't reveal who owns what. The hash → username mapping lives only on the NAS host (in `external_backups/.user_manifest.json`), never on the USB.

### Physical security recommendation
For a production deployment, **turn on BitLocker** on the USB drive — right-click the drive in File Explorer → *"Turn on BitLocker"* → set a passphrase. The entire drive becomes AES-256 encrypted at the block level. Combined with the hashed folder names:
- **Drive lost or stolen** → raw bytes unreadable without the passphrase
- **Drive found plugged in** → folders are anonymous, no way to tell who owns what

### Notes
- **File system:** if your USB is formatted as **FAT32**, large backups (>4 GB) will fail to copy. Reformat as **exFAT** or **NTFS**.
- **Drive letter changes:** Windows usually keeps the same letter for the same physical port. If it shifts, re-edit `mirror_watcher.ps1` and re-run `install_usb_sync.ps1`.
- **Unplug-safe:** if you yank the USB, the watcher logs a "paused" message and waits. Plug it back in and it resumes within 3 seconds. The NAS itself is unaffected.
- **Linux/Mac users:** the watcher is Windows-only (Task Scheduler + robocopy). The Linux equivalent is a small bash script + cron + `rsync` — easy to port.

---

## Project Structure

```
NAS/
├── docker-compose.yml         # All four containers (web, db, phpmyadmin, tunnel)
├── .env                       # DB credentials (gitignored — you create this)
├── README.md
├── web/
│   ├── Dockerfile             # PHP 8.2 + Apache + cron + zip image
│   └── start.sh               # Container entrypoint (starts cron + Apache)
├── www/                       # Application code (PHP)
│   ├── index.php              # File manager
│   ├── login.php              # Auth + rate limiting
│   ├── register.php           # Self-registration
│   ├── profile.php            # Change own username/password
│   ├── users.php              # User admin
│   ├── permissions.php        # Per-file ACL
│   ├── monitor.php            # System dashboard
│   ├── backup.php             # Backup management
│   ├── cron_backup.php        # Standalone script run by cron
│   ├── logs.php               # Log viewer
│   ├── auth.php / db.php      # Shared helpers
│   └── action_*.php           # POST handlers (upload, delete, rename, etc.)
├── sql/
│   └── init.sql               # Schema, runs on first DB start
├── uploads/                   # User files (gitignored, bind-mounted)
├── external_backups/          # Backup archives (gitignored, bind-mounted)
├── docs/
│   ├── ARCHITECTURE.md        # System overview
│   ├── BACKEND.md             # PHP layer
│   ├── FRONTEND.md            # UI layer
│   └── SECURITY.md            # Defense layers
├── scripts/                   # Optional Windows host-side helpers (USB mirror)
│   ├── install_usb_sync.ps1     # Register the watcher as a logon task
│   ├── uninstall_usb_sync.ps1   # Remove the task
│   ├── mirror_watcher.ps1       # Long-running mirror daemon (3s polling)
│   ├── mirror_watcher_launcher.vbs  # Hidden launcher (no console window)
│   └── mirror_to_usb.ps1        # One-shot manual mirror
└── tests/
    ├── unit_test.php          # 45 DB / business-logic tests
    ├── e2e_test.sh            # 30 HTTP end-to-end tests
    └── TESTING.md             # How to run them
```

---

## Running the Tests

### Unit tests (database / business logic — 45 tests)
```bash
docker cp tests/unit_test.php nas-web:/tmp/unit_test.php
docker exec nas-web php /tmp/unit_test.php
```

### End-to-end tests (HTTP requests against the live stack — 30 tests)
```bash
bash tests/e2e_test.sh
```
> Requires `curl` and `bash`. On Windows use Git Bash or WSL.

Both should report `0 failed`.

---

## Common Operations

### Stop everything
```bash
docker compose down
```

### Stop and **wipe the database** (destructive)
```bash
docker compose down -v
```
Use this for a clean reset. The seeded admin account is recreated on next startup.

### Rebuild after editing the Dockerfile
```bash
docker compose up -d --build
```

### View live logs
```bash
docker compose logs -f web      # Apache + PHP
docker compose logs -f db       # MySQL
docker compose logs -f tunnel   # Cloudflare tunnel
```

### Make a manual backup right now (no UI)
```bash
docker exec nas-web php /var/www/html/cron_backup.php
```

---

## Troubleshooting

**"Connection refused" on first start**
MySQL takes ~10 seconds to initialize on first run. Wait, then refresh.

**`.env` is missing or wrong**
Check `docker compose ps` — if the `db` container keeps restarting, check `docker logs nas-db` for password mismatch errors.

**Tunnel URL doesn't work**
The URL takes ~5 seconds to become reachable after container start. If still failing, run `docker logs nas-tunnel` to confirm the URL was issued.

**Port 8080 / 8081 already in use**
Edit the host-side ports in [docker-compose.yml](docker-compose.yml) (e.g. `"9080:80"`).

**Lost admin password**
Reset via phpMyAdmin (http://localhost:8081) — open the `users` table and update the row's `password` field with a new bcrypt hash:
```bash
docker exec nas-web php -r "echo password_hash('newpass', PASSWORD_BCRYPT);"
```

---
