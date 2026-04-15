# NAS Web Server — Presentation Reference

Group 4 · CS 5531: Advanced Operating Systems

This is a live reference for the in-class presentation. For each slide:
- **Slide content** — what to put on the slide (keep bullets short)
- **Talking points** — what to say out loud while it's up

---

## Slide 1 — Title

**NAS Web Server**
CS 5531: Advanced Operating Systems · Group 4

> Quick intro — name the project, name the team. ~10 seconds.

---

## Slide 2 — Meet the team

- **Josh Castro** — Backend & Server
- **Michael Karr** — Backend & Server
- **Bosia N'dri** — Frontend & Server

> One-sentence each: "I worked on X."

---

## Slide 3 — What is a NAS Web Server?

A self-hosted file storage platform running entirely in Docker.

### Talking points
*"Think of it as a self-hosted Google Drive. Users upload files, organize them in folders, share them. Admins on top of that get monitoring, backups, user management, and per-file access control. The whole thing runs in Docker, so it's reproducible anywhere — clone the repo, run one command, you have a working NAS."*

---

## Slide 4 — Tech Stack

**Frontend:** HTML, CSS, JavaScript
**Backend:** PHP 8.2 + Apache 2.4
**Database:** MySQL 8.0
**Infrastructure:** Docker Compose

### Talking points
*"Classic LAMP stack — Linux, Apache, MySQL, PHP — running in containers instead of installed on the host. Four containers in total: one for the web app, one for MySQL, one for phpMyAdmin, and one for the public tunnel. Docker means our laptops, our teammate's laptop, and the demo machine all behave identically — no `it works on my machine` problems."*

---

## Slide 5 — NAS CloudFlare

### Slide content
- **Public HTTPS access without port forwarding**
- Outbound tunnel from `cloudflared` container → Cloudflare edge
- Visitors hit a `*.trycloudflare.com` URL
- No router config, no public IP exposed, free HTTPS
- Stop with `docker compose stop tunnel`

### Talking points
*"The project required port forwarding for remote access. The traditional approach — opening a port on the router and pointing a DDNS hostname at our public IP — has problems: it exposes our home IP, requires router admin access, and doesn't work behind CGNAT (common on mobile hotspots and dorm Wi-Fi)."*

*"Instead, we used Cloudflare Tunnel. The way it works: a tiny container called `cloudflared` makes an outbound connection to Cloudflare's servers and keeps it open — like leaving a phone line off the hook. When someone visits our public URL, Cloudflare receives the request first and pushes it back down that already-open connection to our NAS."*

*"Why this is better for us: zero router config, the URL has automatic HTTPS, our home IP stays hidden, and it works from any network. We can take it down instantly with one Docker command. The textbook port-forward solves the same problem, but with more setup and more attack surface."*

---

## Slide 6 — Database Schema

### Slide content
**4 tables** (with arrows showing foreign keys):
- `users` — id, username, password (bcrypt), email, **role**, **storage_quota**, **session_version**, last_login
- `files` — id, **owner_id**, filename, filepath, filesize, filetype, **is_folder**, **parent_id**
- `permissions` — id, **file_id**, **user_id**, can_read, can_write, can_delete
- `backups` — id, filename, filepath, filesize, **created_by**, created_at

**Relationships:** users → files (1:N) · files → files (parent_id, self-reference for folders) · files → permissions (1:N) · users → permissions (1:N)

### Talking points
*"Four tables. The interesting design choices:"*

*"**`files` is self-referencing** — a folder is just a file row with `is_folder=1`, and other files point to it via `parent_id`. This means we get unlimited folder nesting for free, no special tree table needed."*

*"**`permissions` is a separate table, not columns on `files`** — because permissions are many-to-many. One file can have permissions for multiple users, and one user can have permissions on many files. Putting it in its own table keeps the schema clean and lets us add new permission types later without an `ALTER TABLE`."*

*"**Cascade deletes everywhere** — if a user is deleted, their files cascade. If a folder is deleted, every file inside it cascades. We never have orphan rows."*

*"**`storage_quota` is nullable** — null means unlimited, a number means the cap in bytes. The upload handler checks this before accepting a file and rejects with a friendly message if exceeded."*

*"**`session_version` is the bit that closes a real security hole** — when an admin demotes someone, we bump this column. Every protected page load compares the session's cached version against the DB. Mismatch → force-logout on the next request. Without this, a demoted admin keeps admin rights until they manually sign out."*

---

## Slide 7 — Key Features (overview grid)

Four boxes — File Mgmt, User Mgmt, Permissions, Monitoring & Backups.

### Talking points
*"Brief tour of what we built. Each of these gets its own slide next, so I'll go quick. File management is the user-facing core. User management and permissions are the admin-facing access controls. Monitoring and backups are operational features for keeping the system healthy."*

---

## Slide 8 — File Management

### Slide content
- Upload (drag-and-drop or button), download, rename, delete
- Nested folders with breadcrumb navigation
- Per-user upload directories: `/uploads/<user_id>/`
- Storage quota enforced at upload time
- Files stored on host disk via Docker bind mount → survive container rebuilds

> Fix typo on the slide: "File Managment" → "File Management"

### Talking points
*"Files are stored in two places that have to stay in sync: the actual file on disk, and a database row holding metadata (owner, size, parent folder, MIME type). The database is the source of truth — every action goes through it."*

*"**Why per-user upload directories?** Each user's files live under `/uploads/<their_id>/`, so even if two users upload a file with the same name, they don't collide. It also makes per-user backups and quota calculations trivial — just look at one folder."*

*"**Why bind-mount to the host?** The `uploads/` folder lives on our actual computer, mounted into the container. If the container crashes or gets rebuilt, the files are still there. We learned this the hard way — early on we had files inside the container and lost them all on a rebuild."*

*"Folders aren't real folders on disk — they're database rows. This sounds weird but it lets us do things like rename a folder instantly (just update one row) instead of moving every file underneath."*

---

## Slide 9 — User Management

### Slide content
- Two roles: `admin` and `user`
- Self-registration (locks new accounts to `user` role)
- Profile page: change own username/password
- Admin can: create, edit, delete users · set roles · set storage quotas
- bcrypt password hashing
- Login rate limiting: 5 failed attempts / 5 min lockout per IP
- **Click a user row → detail modal** showing their files + who each file is shared with
- **Role transition safeguards**: self-demotion blocked, last-admin protected, session invalidated on next request after role change

### Talking points
*"The role split is intentionally simple — admin and user. Everything role-related goes through `require_admin()` at the top of protected pages, so adding a new admin-only feature is a one-line gate."*

*"**Why bcrypt?** It's slow on purpose. If our database ever leaks, bcrypt's per-hash work factor means an attacker can only test a few thousand passwords per second instead of billions. We never store, log, or transmit plaintext passwords."*

*"**Self-registration was a deliberate choice** — anyone can sign up, but the role is hardcoded to `user`. Admins are only created by other admins, so an attacker can't grant themselves admin via the registration form."*

*"**Rate limiting matters because the site is public** through the tunnel. Without it, someone could try a million passwords against `admin`. With it, they get five tries every five minutes per IP — brute force becomes computationally pointless."*

*"**Role changes take effect immediately.** If an admin demotes someone, their next click redirects to the login screen with a 'your role was changed' notice. We do this with a `session_version` column — each role change bumps the DB value, and `auth.php` compares the session's cached version to the DB on every request. No waiting for sessions to expire, no gap where a demoted admin can still do damage."*

*"**We refuse to leave the system without an admin.** You can't demote yourself. You can't delete the last admin. You can't demote the last admin. The system enforces 'at least one admin must exist' as a hard invariant — otherwise no one would be able to manage backups, users, or restore anything."*

*"**Click any user row** and you get a detail modal with two tabs: what they own (and who each file is shared with), and what's shared with them. Makes 'who has access to what?' a single click instead of a database query."*

---

## Slide 10 — Permissions

### Slide content
- Two layers: **page-level** (role) + **per-file** (granular)
- Per-file flags: `can_read`, `can_write`, `can_delete`
- Owner always has full access (implicit, not stored)
- Admins can override anyone's permissions
- Checked on every action — URL tampering doesn't bypass

### Talking points
*"The brief asked for read/write/edit/admin. We went further: every file or folder has its own access list, separate from the user's role."*

*"**Two-layer model.** Layer one is page-level: only admins see the Users page, only admins manage backups. Layer two is per-resource: even within the file manager, user A can give user B read-only access to one folder without sharing anything else. Like Google Drive's share button."*

*"**Why owners aren't in the permissions table.** We could store an explicit row saying 'owner X has full access to file Y' — but that's redundant with the `owner_id` column on `files`. So our permission check is: 'are you the owner OR an admin OR do you have an explicit row?' Three short conditions covering every case."*

*"**Defense in depth.** The UI hides delete buttons users can't click, but we don't trust the UI. Every action handler — upload, rename, delete, download — re-checks permissions server-side. Even if someone crafts a custom URL to delete a file they don't own, the check rejects it."*

---

## Slide 11 — Monitoring & Backups

### Slide content
**Monitor (live, 3-second polling — no refresh needed)**
- Uptime, load average, CPU, memory
- Per-volume disk gauges (Uploads + Backups) — real host disks, not container overlay
- Active sessions (last 30 min) · Recent uploads feed · Per-user storage
- **External Storage panel** — USB drive as a first-class peripheral (capacity gauge, role, files mirrored, last sync, orphaned archives)
- Expandable Show Charts panel — rolling CPU/Memory/Load sparklines

**Backup (admin-only)**
- Manual "Create Backup" — one-click ZIP (DB dump + every uploaded file)
- Cron-driven schedule via UI calendar/time picker
- Restore = wipe + reimport (atomic rollback)
- Auto-rotation: keeps last 10 scheduled backups
- Stored in bind-mounted `external_backups/` — survives container rebuilds
- **"Push All to USB"** button for on-demand mirroring
- Live dual counter in the hero: `Local` vs `On USB`. When append-only preserves a deleted file, the two numbers visibly diverge

**USB Archive (host-side, optional)**
- Windows watcher (runs at logon, hidden) polls every 3 s and mirrors:
  - `external_backups/*.zip` → `D:\nas-backups\`
  - `uploads/<user_id>/` → `D:\nas-users\u_<sha256(salt + user_id)[:12]>\`
- **Append-only** (`robocopy /E`) — deletes in the NAS never touch the USB
- Hashed folder names — physical theft reveals no usernames
- Deleted users become "orphaned archives" — forensic retention, counted in the UI

### Talking points
*"**Two features that work together**: monitoring tells you when something needs attention, backups give you the option to recover when something breaks. Awareness and insurance."*

*"**Real disk usage, not the container's.** Earlier our disk gauge read the container's root filesystem — which is a Docker overlay layer and means nothing. We pointed it at the actual mount where uploads live, so the percentage reflects the real disk a real admin would care about."*

*"**Source of truth is the database.** 'Files Stored Size' isn't from walking the filesystem — it's a SUM query on the files table. Faster, and it can never disagree with the per-user breakdown shown lower on the page."*

*"**Everything on the monitor updates live.** We poll a tiny JSON endpoint every 3 seconds and update values in place — same pattern Synology DSM and AWS Console use. No full page reloads, no interruption if you're mid-click."*

*"**Why one ZIP for backups?** A backup includes the database AND the files. If we backed up only files, we'd lose ownership and permissions. If we backed up only the database, we'd have records pointing at files that no longer exist. Bundling them means restore is one atomic operation — you rewind to that exact moment."*

*"**Auto-rotation prevents disk fill.** Scheduled backups run every hour or whatever the admin sets. If we never deleted old ones, the disk fills and the next backup fails silently. We keep the last 10, delete older ones."*

*"**Why backups live outside the container.** They're bind-mounted to a folder on our host PC. Containers are designed to be disposable — you can rebuild them anytime. If backups lived inside the container, the very thing meant to protect us would die with the container. Putting them outside means a `docker compose down -v` doesn't destroy our recovery point. The backup outlives the thing it's backing up — which is the whole point."*

*"**The USB drive is a true backup, not a sync target.** We chose append-only semantics deliberately — when an admin deletes a file in the NAS, the USB keeps it. Mirror mode would propagate the delete and defeat the purpose of a backup. This is the difference between 'sync' and 'backup': Synology calls these separate features for a reason, and we picked the one that protects against user error."*

*"**Physical theft privacy.** USB folders are named with a 12-character hash — `u_db4ec44fb938` not `admin`. The hash-to-username map is on the NAS host only, never on the USB. Combined with an optional BitLocker layer, the drive reveals nothing if lost. Synology and QNAP both offer this, plus the encryption-at-rest layer — we've mirrored the same model."*

*"**We treat the USB as a peripheral, not extra disk space.** The monitor page has a dedicated 'External Storage' panel — not a stat card stuffed into the corner. Device identity, capacity gauge, role, sync activity, orphaned archive count. That's how a real NAS surfaces external storage."*

---

## Slide 12 — Demonstration

> Live demo. See "Demo Script" section below for the order to click through.

---

## Slide 13 — Testing

**75 automated tests (45 unit + 30 end-to-end), all passing.**

### Talking points
*"Two layers of automated testing. Unit tests run inside the container against the live database — they cover schema, CRUD, cascade behavior, and the default admin seed. End-to-end tests run from outside as HTTP requests — they simulate a real user logging in, navigating pages, uploading files, getting blocked from admin pages, and so on. Both suites self-clean: every test inserts and removes its own data, so they can run repeatedly without polluting state. We re-run the full suite before any commit to main."*

---

## Slide 14 — Roadmap

### Slide content
**Short-term**
- Two-factor authentication (TOTP)
- Per-action audit log (who renamed/deleted what)
- File previews (images, PDFs) in browser
- Public share links with expiry

**Medium-term**
- Stable Cloudflare named tunnel (custom subdomain)
- Off-site backup target (Backblaze B2 or Cloudflare R2)
- Mobile-friendly responsive UI

**Long-term**
- Real-time collaboration (websockets)
- File versioning (keep N revisions)
- Multi-server replication

### Talking points
*"What we'd add next, ranked by impact-vs-effort."*

*"**TOTP two-factor** — biggest security win for the smallest effort. The Google Authenticator standard is well-documented, and we already have rate limiting as the foundation."*

*"**Audit log** — right now Apache logs every page hit, but we don't have a clean answer to 'who deleted this folder.' One small `audit_log` table and a helper called from every action handler would close that."*

*"**File previews** — the biggest UX gap. Right now you have to download a file to see it. Inline image and PDF previews are mostly a frontend change."*

*"**Off-site backups** — currently backups live on the same machine as the data, which means a disk failure could lose both. Pushing zips to Backblaze B2 (free 10 GB tier) gives us 3-2-1 backup compliance: 3 copies, 2 different media, 1 offsite."*

*"Long-term we'd look at versioning and replication — both are huge undertakings that real NAS products like Synology took years to build."*

---

## Slide 15 — Thank you

> Open for questions.

---

# Demo Script (for slide 12)

Have these tabs/windows open before starting:
1. Browser tab on `http://localhost:8080` (logged out)
2. Browser tab on the public tunnel URL (logged out)
3. Terminal with `nmap -p 3306,8080 localhost` typed but not run
4. Phone with the tunnel URL ready to refresh (proves it's actually internet-facing)

### Order of clicks
1. **Local login** as admin → land on file manager. Show file list, drag a file to upload, watch it appear.
2. **Create a folder**, navigate into it, upload a file inside.
3. **Open Permissions** for one file → grant a regular user read-only.
4. **Log out, log in as that regular user** → show they can see but not delete the shared file.
5. **Log back in as admin → Users page**. Point out the **USB Archive column** — each user's hashed folder. Click a user row → **detail modal** opens showing their files + sharing relationships.
6. **Monitor page**. Point out: live dot in the hero, **External Storage panel** with capacity + mirror stats, click **Show Charts** to reveal the sparklines. Scroll down, point out Active Sessions (you should see yourself), last backup timestamp.
7. **Backup page** → show the dual Local/On USB counter. Click **Create Backup** → watch both counts tick up within seconds. Then (optional) delete that backup locally and show that USB count stays high — append-only in action.
8. **File Explorer (if USB plugged in)** → open `D:\nas-users\` on the other half of the screen. Show opaque hash folders. Point out the manifest maps back to usernames only on the host.
9. **Switch to terminal** → run `nmap -p 3306,8080 localhost`. Show 8080 open, 3306 closed. Explain firewall design.
10. **Role-change demo**: create a test user, open their tab in incognito, log them in. Then demote → their next click bounces to `/login.php?reason=role_changed`.
11. **Phone demo** → refresh the tunnel URL, log in. Proves the public URL works from off-network.

Total demo time target: ~6 minutes (more features to show).

---

# Anticipated Questions

### "What happens if MySQL dies during a backup?"
*"The `mysqldump` command fails with a non-zero exit code, the backup script exits before zipping, and no database row is inserted — so a corrupt backup never appears in the backup list. Worst case the user sees an error, retries, and gets a clean backup."*

### "Can you scale this to 1000 users?"
*"Not without changes. The page-render queries that walk every file would need pagination, and the per-user storage SUM would need an index. But the schema itself scales fine — we'd optimize the read paths, not the data model."*

### "Why didn't you use a real port forward?"
*"Three reasons: it requires router admin access we may not have on a school network, it doesn't work behind CGNAT which many ISPs use, and it exposes our home IP. Cloudflare Tunnel solves the same requirement — public HTTPS access — without those downsides. We have notes on what the textbook port-forward setup would look like if a teacher wants to see we understand the alternative."*

### "How is CPU/memory accurate when running in a container?"
*"They reflect what the server process can see — the same numbers a Linux server would report on bare metal. For our use case (is the NAS overloaded, do we have memory headroom) that's the right scope. A real production NAS would also expose host-level metrics via a sidecar agent, but that's out of scope for this project."*

### "What's stopping someone from uploading a malicious PHP file and executing it?"
*"Two things. First, uploads are stored under `/var/www/uploads/`, which Apache is configured to never execute as PHP. Second, the URL path users see is the database `filename`, not a real filesystem path — Apache never serves the upload path directly. So even a `.php` file in the upload folder is treated as a static download, not code."*

### "What happens if I demote an active admin? Can they still access admin pages?"
*"No. The `users` table has a `session_version` column that gets incremented on every role change. Each session caches that value at login. `auth.php` compares cached vs DB on every protected request — mismatch triggers an instant `session_destroy()` and redirect to the login page with a 'your role was changed' notice. So the demoted admin's next click kicks them out, no waiting for a timeout."*

### "What if I accidentally demote the only admin?"
*"You can't. Both `action_user_edit.php` and `action_user_delete.php` count the number of admins before allowing a demotion or deletion. If removing this user would leave zero admins, the action is refused with an explicit error. You also can't demote yourself — that's a separate guard."*

### "Why are the USB folder names hashed?"
*"Privacy against physical theft. The folder name on the USB is `sha256(salt + user_id)[:12]` — the salt is stored only in a manifest file on the host, never copied to the USB. If someone finds the drive, they see anonymous folders with no way to tell who owns what. Synology and QNAP offer similar — 'opaque folder names' is a standard NAS privacy feature. For stronger protection you'd add BitLocker on top, which encrypts the raw bytes."*

### "What about files from deleted users — do they stay on the USB?"
*"Yes, intentionally. Append-only semantics apply at the user level too. When an admin deletes a user, their DB rows cascade and their host upload folder is wiped, but the USB folder stays as a forensic archive. The monitor page shows an 'Orphaned archives' count so admins can reclaim space explicitly if they want. This is how real NAS products handle deletion — retention over reactivity."*

### "Why did you choose append-only for the USB instead of a mirror?"
*"Because mirror mode isn't a backup. If I delete a file by mistake and the USB mirrors the delete, my backup is gone. Append-only means deletes in the NAS never propagate — the USB accumulates a forensic history. Synology's Hyper Backup and QNAP's HBS3 both default to versioned/append-only for exactly this reason. Mirror mode is called 'Sync' in those products and they explicitly warn it's not a backup."*

### "What's the difference between Uploads Size and Uploads Volume?"
*"Uploads Size is the total bytes users have stored — a sum of every file's size from the database. Uploads Volume is how full the underlying disk is, including everything else on that drive. You can have 50 MB of uploads on a disk that's 99% full of other things. The two answer different questions: 'how much have we stored' vs 'how much room is left'."*

### "How do you protect against SQL injection?"
*"Every query uses PDO prepared statements — values are bound separately from the query template, so input can never be interpreted as SQL. We have zero string-concatenated queries in the codebase."*

### "What if two users edit the same file at the same time?"
*"We don't currently support concurrent editing — files are uploaded as whole blobs, not edited in place. If two users upload a file with the same name to the same folder, the second one overwrites the first. Real conflict resolution would need versioning, which is on the roadmap."*

---

# Last-Minute Checklist

Before walking into the room:
- [ ] Containers running: `docker compose ps` shows all four healthy
- [ ] Tunnel URL works: open in incognito, log in successfully
- [ ] Default admin password changed (do this even if you change it back after — proves the workflow exists)
- [ ] Manual backup taken so the "Last Auto Backup" card has a recent value
- [ ] At least one regular user account exists for the permission demo
- [ ] At least one shared file with explicit permissions for the demo
- [ ] Terminal commands typed and ready (not yet run)
- [ ] Phone on cellular data, tunnel URL bookmarked
- [ ] Slides on a USB stick as backup in case the demo machine fails
