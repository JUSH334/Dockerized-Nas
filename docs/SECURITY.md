# NAS Security Model

This document explains the layered defenses protecting the NAS web server.
Each layer addresses a different class of threat — together they cover the
"firewall and user permissions" requirement of the project brief.

---

## 1. Network layer (firewall)

The Docker Compose stack runs four containers: `nas-web`, `nas-db`,
`nas-phpmyadmin`, and `nas-tunnel`. Containers communicate over a private
Docker network that is **not reachable from the host or the internet**.

| Service | Internal port | Host port | Public via tunnel |
|---|---|---|---|
| nas-web | 80 | **8080** | Yes (HTTPS) |
| nas-db (MySQL) | 3306 | *not exposed* | No |
| nas-phpmyadmin | 80 | 8081 | No |
| nas-tunnel | — | — | Outbound only |

**Why MySQL is not exposed to the host.** Earlier versions mapped
`3306:3306`, which let any program on the LAN connect to the database
directly. We removed that mapping (`expose:` instead of `ports:`),
so MySQL is now reachable only by the web and phpmyadmin containers
through the internal Docker bridge. This is the same effect a
host firewall would have, achieved at the container layer.

**Verification:**
```
nmap -p 3306 localhost   # → 3306/tcp closed
```

**Cloudflare Tunnel** opens an *outbound* connection to Cloudflare's edge
network — no inbound port is opened on the router. This means:
- Your home IP address is never exposed to visitors
- HTTPS is terminated by Cloudflare with a valid certificate
- The tunnel can be torn down instantly with `docker compose stop tunnel`

---

## 2. Authentication layer

- **Passwords are bcrypt-hashed** (`password_hash()` / `password_verify()`).
  Plaintext passwords are never stored or logged.
- **Sessions** are PHP server-side, identified by a random session ID cookie.
- **Login rate limiting**: 5 failed attempts from the same IP within 5
  minutes triggers a lockout. Tracked per-IP in `/tmp/nas_login_attempts/`
  and cleared on any successful login.
  - Defends against brute-force attempts on the publicly tunneled URL.
- **Default admin credentials** (`admin / admin123`) MUST be changed
  before any public deployment. Change via `/profile.php` after login.
- **Session invalidation on role change.** The `users` table has a
  `session_version INT` column (default 0). Each login caches the current
  value into `$_SESSION['session_version']`. When an admin changes a
  target user's role, the row's version is incremented. `auth.php`'s
  `validate_session_version()` runs on every protected request and
  compares session-cached vs DB. A mismatch triggers immediate
  `session_destroy()` and redirects to `/login.php?reason=role_changed`
  — closing the "demoted admin keeps admin rights until logout" window.
  User deletion is handled the same way with `reason=deleted`.
- **Role-change guards** (in `action_user_edit.php` and
  `action_user_delete.php`):
  - An admin cannot demote themselves (would lock them out).
  - The last remaining admin cannot be demoted or deleted (system must
    always have at least one administrator).

---

## 3. Authorization layer (user permissions)

Two complementary mechanisms:

### Role-based (page-level)
Defined in `auth.php`:
- `require_login()` — gates every protected page.
- `require_admin()` — restricts user management, monitoring, logs,
  and backup pages to the `admin` role.

### File-based (per-resource)
Defined in `permissions.php` and the `permissions` table:
- Every file or folder has independent `can_read`, `can_write`,
  and `can_delete` flags per user.
- File owners and admins can grant/revoke permissions.
- Permission checks happen on every action (`action_*.php`) so
  URL-tampering does not bypass them.

### Storage quota (per-user resource limit)
- Admins can set a `storage_quota` (in MB) on each user.
- `action_upload.php` blocks uploads that would exceed the quota.
- Prevents a single user from filling the disk and DoS'ing others.

---

## 3.5 USB archive privacy

When the host-side USB mirror is running (optional feature; see
[ARCHITECTURE.md](ARCHITECTURE.md#usb-mirror-architecture-host-side)),
per-user files are mirrored to `D:\nas-users\u_<hash>\` where the hash
is the first 12 hex chars of `sha256(salt + user_id)`.

- **Folder obfuscation** — physical theft of the drive reveals anonymous
  `u_<hex>` folders. Without the manifest (host-only), there's no way to
  map a folder back to a specific user.
- **Salt stays on the host** — written once into
  `external_backups/.user_manifest.json` on the first run; never copied
  to the USB. Without it, even a captured list of user IDs can't be
  used to brute-force the mapping for specific users.
- **Recommended: BitLocker on the drive** — encrypts the raw bytes at
  the block level. Combined with the hashed folder names, this gives
  two independent privacy layers: without the passphrase, no files can
  be read at all; even with the passphrase, no usernames are revealed
  on-disk.
- **Forensic retention for deleted users** — when an admin deletes a
  user, their host-side uploads folder is wiped and their DB rows
  cascade, but the USB folder stays intact (append-only archive
  semantics). The watcher counts these folders as "orphaned archives"
  and surfaces the count on the Monitor page, so an admin can reclaim
  space explicitly if desired.

---

## 4. Application layer

- **PDO prepared statements** for every database query → SQL injection safe.
- **`htmlspecialchars()`** on every dynamic value rendered in HTML →
  XSS safe.
- **`escapeshellarg()`** on every value passed to `exec()` (backup, restore,
  rename) → command injection safe.
- **Apache `.htaccess`** denies direct access to sensitive files
  (`.env`, `*.sql`, log files).
- **File uploads** are stored under per-user directories
  (`/var/www/uploads/<user_id>/`) with the database holding the canonical
  filename — uploaded files are never executed by Apache.

---

## 5. Operational hardening checklist

Before exposing the NAS publicly via the tunnel:

- [ ] Change the default `admin` password
- [ ] Set a strong `MYSQL_ROOT_PASSWORD` and `MYSQL_PASSWORD` in `.env`
- [ ] Confirm `.env` is in `.gitignore` (it is)
- [ ] Confirm port 3306 is not exposed (`nmap -p 3306 localhost`)
- [ ] Take a manual backup (`/backup.php`) so a restore point exists
- [ ] Tear the tunnel down when not actively demoing
  (`docker compose stop tunnel`)

---

## What is intentionally out of scope

- **Two-factor authentication.** Would require TOTP libraries and QR
  enrollment — not in the project requirements.
- **HTTPS on the local port (`8080`).** Cloudflare Tunnel handles HTTPS
  for the public URL; locally we accept plain HTTP because traffic never
  leaves the host.
- **Audit log of every action.** Apache access logs and the application
  backup log cover the major actions; per-action audit (who renamed what)
  would need a dedicated `audit_log` table.
- **Host-level firewall (UFW / Windows Defender rules).** The Docker
  port mappings are the effective boundary for this stack — adding a
  second host firewall on top would not change which services are
  reachable.
