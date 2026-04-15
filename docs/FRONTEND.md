# NAS Web Server — Frontend Documentation

## Overview

The frontend is server-rendered PHP with embedded HTML, CSS, and JavaScript. There is no separate frontend framework — each `.php` file contains the backend logic at the top and the full HTML page below. This is the traditional LAMP stack approach.

## Design System

### Theme

The interface uses a dark theme with the following color palette:

| Variable | Hex | Usage |
|---|---|---|
| `--bg` | `#0d0f14` | Page background |
| `--surface` | `#161920` | Cards, panels, nav bar |
| `--surface2` | `#1d2029` | Hover states, secondary surfaces |
| `--border` | `#2a2d38` | Borders, dividers |
| `--accent` | `#4fffb0` | Primary actions, active states, success |
| `--accent2` | `#00bfff` | Gradients, secondary accent |
| `--text` | `#e8eaf0` | Primary text |
| `--muted` | `#6b7080` | Secondary text, labels |
| `--danger` | `#ff4f6a` | Destructive actions, errors |
| `--warn` | `#ffb84f` | Warnings, caution states |
| `--radius` | `6px` | Border radius for buttons, inputs |

### Typography

| Font | Usage | Source |
|---|---|---|
| DM Sans (300, 400, 500) | Body text, labels, buttons | Google Fonts |
| Space Mono (400, 700) | Logo, monospace values, badges, code | Google Fonts |

### Common Components

**Buttons:**

| Class | Appearance | Usage |
|---|---|---|
| `.btn-primary` | Green background (`--accent`), dark text | Primary actions (Create, Upload, Save) |
| `.btn-secondary` | Dark background with border | Secondary actions (Cancel, New Folder) |
| `.btn-danger` | Red tinted background | Destructive actions (Delete) |
| `.btn-warn` | Orange background | Caution actions (Restore) |
| `.btn-small` | Smaller padding | Inline actions in lists |
| `.btn-link` | Transparent with border | Tertiary actions (Download) |

**Form Inputs:**
- Dark background (`--bg`) with subtle border
- Green glow on focus (`--accent` border + box-shadow)
- Labels are uppercase, small, muted text

**Flash Messages:**
- `.flash.success` — green tinted background with accent border
- `.flash.error` — red tinted background with danger border

**Modals:**
- `.modal-backdrop` — fixed overlay with blur effect
- `.modal` — centered card with fade-up animation
- Closed by clicking backdrop or Cancel button

## Pages

### Login Page (`login.php`)

```
┌──────────────────────────────┐
│                              │
│      ┌──────────────┐        │
│      │  🖴 NAS       │        │
│      │               │        │
│      │  Welcome back │        │
│      │               │        │
│      │  [Username  ] │        │
│      │  [Password  ] │        │
│      │               │        │
│      │  [Sign In →]  │        │
│      │               │        │
│      │  NAS v1.0     │        │
│      └──────────────┘        │
│                              │
│    (animated grid + glow)    │
└──────────────────────────────┘
```

- Centered card layout with animated grid background
- Radial glow orb effect behind the card
- Error messages appear inline above the form
- Preserves username on failed login attempt

### File Manager (`index.php`)

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  👥 Users  📊 Monitor  ...  [Admin] │
├──────────────────────────────────────────────────────┤
│  ~ root / Documents                                   │
│                                                       │
│  Documents              [＋ New Folder] [↑ Upload]    │
│                                                       │
│  Name              Size      Owner     Modified       │
│  ─────────────────────────────────────────────────    │
│  📁 Photos          —       admin     Apr 9, 2026  ✏️🔒🗑│
│  📄 report.pdf    2.4 MB    admin     Apr 9, 2026  ⬇✏️🔒🗑│
│  🖼️ image.png     340 KB    admin     Apr 9, 2026  ⬇✏️🔒🗑│
│                                                       │
└──────────────────────────────────────────────────────┘
```

**Features:**
- Breadcrumb navigation (clickable path: `~ root / folder / subfolder`)
- Sortable file listing (folders first, then files alphabetically)
- Per-row actions: Download (files only), Rename, Permissions (admin), Delete
- New Folder modal — text input for folder name
- Upload modal — drag-and-drop zone with click-to-browse fallback
- Flash messages for success/error feedback

**Action Icons:**
| Icon | Action | Visibility |
|---|---|---|
| ⬇ | Download | Files only |
| ✏️ | Rename | Owner or admin |
| 🔒 | Permissions | Admin only |
| 🗑 | Delete | Owner or admin |

### User Management (`users.php`) — Admin Only

```
┌────────────────────────────────────────────────────────────────────┐
│  NAS   📁 Files  👥 Users  📊 Monitor  💾 Backups  ...            │
├────────────────────────────────────────────────────────────────────┤
│  Users (3)                                        [＋ New User]    │
│                                                                     │
│  User         Role  Storage         USB Archive      Joined   ...  │
│  ────────────────────────────────────────────────────────────────  │
│  [AD] admin   ADMIN 3.7 KB · unlim  ● u_db4ec44fb938 Jan 1    ✏️   │
│  [AL] alice   USER  120 KB / 500 MB ● u_a1b2c3d4e5f6 Mar 5    ✏️🗑 │
│  [BO] bob     USER  0 B · unlimited ○ u_f7g8h9i0j1k2 Apr 1    ✏️🗑 │
└────────────────────────────────────────────────────────────────────┘
```

**Features:**
- User table with avatar circles (first two letters of username)
- Role badges: green `ADMIN`, gray `USER`
- **USB Archive column** — each user's hashed USB folder name (`u_<12-hex>`):
  - Click-to-copy (flashes accent color briefly on copy)
  - Live status dot: green with glow when the watcher is actively mirroring and the user has uploads, grey when USB disconnected or the user has no uploads yet
- Create User modal: username, password (min 8 chars), role selector, optional storage quota (MB)
- Edit User modal: pre-filled fields, optional password change
- Delete: confirmation dialog
  - Cannot delete yourself
  - Cannot delete the last remaining admin (server-side guard)
- Self-demotion blocked server-side in `action_user_edit.php`
- Flash messages for all operations

### User Detail Modal — opens on row click

Click any user row (outside the inline action buttons and Archive pill) to open a wide modal showing the user's full file picture. Data is fetched from `/user_files.php?id=X`.

```
┌────────────────────────────────────────────────────────────┐
│  [AL]  alice                                   [Close]     │
│        user · joined Mar 12                                 │
├────────────────────────────────────────────────────────────┤
│   12      2       4.1 MB     1            3                │
│   FILES   FOLDERS USED       SHARED OUT   SHARED WITH      │
├────────────────────────────────────────────────────────────┤
│  [ Files they own ]  [ Shared with them ]                  │
├────────────────────────────────────────────────────────────┤
│  File                     Size     Added      Shared with  │
│  ────────────────────────────────────────────────          │
│  [DIR] Photos             —        Mar 12     —            │
│  [IMG] vacation.jpg       2.1 MB   Mar 15     bob · R      │
│  [PDF] report.pdf         340 KB   Mar 14     —            │
└────────────────────────────────────────────────────────────┘
```

- Summary row — big Space-Mono numbers + uppercase labels (same pattern as the monitor hero)
- Two tabs: **Files they own** (with "Shared with" per row) and **Shared with them** (with owner + permission pill)
- **Admin targets** always show both tabs. If the admin has a leftover `permissions` row from before their promotion, it's shown with an info banner — *"these explicit shares are redundant for admins who have role-level access."* Empty states also explain the admin-access model.
- Icons follow the monitor page's file-icon pattern (`IMG`, `VID`, `PDF`, `DIR`, etc.)
- Share relationships render as small Space-Mono chips; permissions use accent-colored pills (`R`, `RW`, `RWD`)

### System Monitor (`monitor.php`) — Admin Only

```
┌───────────────────────────────────────────────────────────────┐
│  System Health             ● live                    10h 22m  │
│                                                      UPTIME   │
├───────────────────────────────────────────────────────────────┤
│  Server: Uptime  LoadAvg          ActiveSess  Last AutoBackup │
│  Storage: Users  FilesStored      UploadsSize BackupsSize     │
│                                                               │
│  CPU Usage  2%    ██░░░░░░░░░░░    Memory   16%  ██░░░░░     │
│  Uploads Volume 98%  ████████░     Backups Volume 98% ████   │
│                                                  [Show Charts]│
│                                                               │
│  ┌─ External Storage ─────────────────────────────┐           │
│  │ 📀 USB Drive — D:\nas-backups    ● Connected   │           │
│  │  Capacity      ▓░░░░░░░░░  0% · 1.2 / 932 GB   │           │
│  │  Role:            Backup + per-user archive    │           │
│  │  Backups mirrored: 24                          │           │
│  │  User archives:    3 users · 47 files          │           │
│  │  User data size:   184 MB                      │           │
│  │  Orphaned archives: 1 user · 2 files · 16 KB   │           │
│  │  Last sync: 2s ago · Last write: 1s ago        │           │
│  └────────────────────────────────────────────────┘           │
│                                                               │
│  ┌─ Active Sessions ──┐  ┌─ Recent Uploads ───┐               │
│  │ [ADM] admin · 1m   │  │ [IMG] photo.jpg     │               │
│  │ [USR] alice · 5m   │  │ [PDF] report.pdf    │               │
│  └───────────────────┘  └────────────────────┘                │
│  ┌─ Storage by User ──────────────────────────┐               │
│  │ admin   3.7 KB · unlimited                 │               │
│  │ alice   120 KB / 500 MB  ████░░            │               │
│  └────────────────────────────────────────────┘               │
└───────────────────────────────────────────────────────────────┘
```

**Features:**
- **Live polling** — a 3-second background fetch to `/monitor_data.php` updates every `[data-m]` element in place; the backup page does the same with `/backup_data.php`. No page reloads.
- **Live dot** in the hero pulses accent-green; dims to grey if polling fails.
- Stat cards with animated fade-up entrance
- Gauge bars with color coding:
  - Green (`--accent`): 0–64%
  - Orange (`--warn`): 65–84%
  - Red (`--danger`): 85–100%
- **Separate Uploads Volume and Backups Volume gauges** when they're on different physical disks; otherwise a single shared gauge
- **External Storage panel** — dedicated first-class section for the USB mirror, modeled after Synology/QNAP "External Storage" UI:
  - Device identity (name + bind-mount path)
  - Live connection status dot + text ("Connected" / "Disconnected" / "Not configured")
  - Capacity gauge (colored same as the other volume gauges)
  - Property rows: role, backups mirrored, user archives (N users · N files), user data size, orphaned archives, last sync, last write, sync interval
  - Status dot pulses briefly on every write event (accent glow animation)
- **Show Charts toggle** — smooth slide-down panel with three 60px sparkline charts (CPU %, Memory %, Load 1m). Rolling 60-sample window (~3 min history). Charts rendered with Chart.js 4.4.
- **Active Sessions panel** — users with a login in the last 30 minutes. Rebuilt live on each poll tick.
- **Recent Uploads panel** — last 10 files with icon badges (IMG, VID, PDF, ZIP, etc.)
- **Per-user storage panel** — mini progress bars; no-quota users show "unlimited" text instead of a meaningless bar

### System Logs (`logs.php`) — Admin Only

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  📊 Monitor  📋 Logs  💾 Backups    │
├──────────────────────────────────────────────────────┤
│  System Logs                                          │
│                                                       │
│  [Apache Access] [Apache Error] [PHP] [Backup] [Sys] │
│                                          Show last [100▼]│
│  ┌──────────────────────────────────────────────┐    │
│  │ Apache Access Log          /var/log/apache2/ │    │
│  │                                              │    │
│  │ 172.18.0.1 - - [09/Apr/2026:21:16:51 +0000] │    │
│  │ "GET /index.php HTTP/1.1" 200 4523           │    │
│  │ 172.18.0.1 - - [09/Apr/2026:21:16:52 +0000] │    │
│  │ "POST /action_upload.php HTTP/1.1" 302 0     │    │
│  │ ...                                          │    │
│  └──────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────┘
```

**Features:**
- Tab navigation for different log sources
- Configurable line count (50, 100, 250, 500)
- Monospace font for log content (Space Mono)
- Scrollable log panel (max-height: 600px) with auto-scroll to bottom
- Custom scrollbar styling

### Backup & Restore (`backup.php`) — Admin Only

```
┌────────────────────────────────────────────────────────────────┐
│  Data Protection       ● USB mirror active · 24 file(s)        │
│                                                                 │
│                                   24     |    25               │
│                                   LOCAL  |    ON USB           │
├────────────────────────────────────────────────────────────────┤
│  ┌─ Create New Backup  [Create Backup] [Push All to USB] ──┐   │
│  └───────────────────────────────────────────────────────────┘   │
│  ┌─ Automatic Backups  [Daily ▼] [02:00 ▼] [Save] ────────┐   │
│  └───────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─ Saved Backups (24) ─────────────────────────────────────┐   │
│  │ 📦 nas_auto_backup_2026-04-15_02-37-45.zip               │   │
│  │   2.7 MB · Apr 15 · by system                            │   │
│  │                       [Download] [Restore] [Delete]      │   │
│  └──────────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────────┘
```

**Features:**
- **Dual count in hero** — `Local` (DB/disk files) and `On USB` (what's mirrored). Both update live via the 5-second poll. When the USB has preserved files from past deletions, the two numbers diverge visibly — proof that append-only is working.
- **USB mirror badge** — next to the page subtitle; pulsing green dot when active, grey when disconnected. Live.
- **Create Backup** — one-click manual backup creation
- **Push All to USB** — disabled/greyed when USB is disconnected. When clicked, drops a sync-request marker; watcher picks it up within 3 seconds and does a full mirror
- **Schedule selector** — frequency (Daily/Weekly/Monthly/Disabled) + time picker
- Backup list with file size, timestamp, creator
- Download, Restore, and Delete actions per backup
- Restore confirmation dialog (inline warning bar); append-only semantics mean restore-affected files stay on USB
- Delete confirmation (browser confirm dialog)

### Permissions (`permissions.php`) — Admin Only

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  👥 Users  📊 Monitor  ...          │
├──────────────────────────────────────────────────────┤
│  ← Back to files                                      │
│                                                       │
│  📄 report.pdf                                        │
│  Owner: admin  OWNER                                  │
│                                                       │
│  ┌─ User Permissions (1) ──────────────────────────┐ │
│  │ john       ☑ Read  ☐ Write  ☐ Delete  [Save][X] │ │
│  │────────────────────────────────────────────────  │ │
│  │ [Select user ▼]  ☑ Read ☐ Write ☐ Delete [Add]  │ │
│  └──────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────┘
```

**Features:**
- File/folder info header with owner badge
- Per-user permission rows with checkboxes (Read, Write, Delete)
- Save and Remove buttons per user
- Add new user with dropdown (excludes owner and already-assigned users)
- Back link returns to parent folder

## Navigation

The nav bar appears on every authenticated page:

```
[NAS logo]  [Files] [Users*] [Monitor*] [Logs*] [Backups*]  Hello, admin ADMIN [Sign out]
```

*Items marked with `*` are only visible to admin users.*

The active page is highlighted with the accent color (`--accent`).

## JavaScript

JavaScript is minimal and inline — the only external library is Chart.js (CDN, used only by the monitor page):

| Feature | Page | Purpose |
|---|---|---|
| `openModal(id)` / `closeModal(id)` | index.php, users.php | Toggle modal visibility |
| `openRename(id, name)` | index.php | Pre-fill rename modal |
| `openEdit(user)` | users.php | Pre-fill edit user modal from JSON |
| `openUserDetail(userId)` | users.php | Fetch `/user_files.php` and populate detail modal |
| `showDetailTab(tab)` | users.php | Tab switcher in detail modal |
| Archive-ID click-to-copy | users.php | Copy hash to clipboard + flash accent |
| Drag & drop | index.php | File upload drop zone |
| Live poll (monitor) | monitor.php | Every 3 s → fetch `/monitor_data.php`, update `[data-m]` elements, recolor gauges, rebuild Active Sessions list |
| Live poll (backup) | backup.php | Every 5 s → fetch `/backup_data.php`, update hero counts + USB badge |
| Chart.js sparklines | monitor.php | Rolling 60-sample window for CPU / Memory / Load |
| Backdrop click | index.php, users.php | Close modal on backdrop click |
| Auto-scroll | logs.php | Scroll log panel to bottom |
| Select redirect | logs.php | Change line count via dropdown |
| Confirm dialog | Various | Browser `confirm()` for destructive actions |

## Responsive Design

- Main content area: `max-width: 1100px`, centered
- Monitor gauge grid: `repeat(auto-fill, minmax(300px, 1fr))`
- Monitor bottom panels: 2-column grid, collapses to 1 column at 700px
- Stat cards: `repeat(auto-fill, minmax(180px, 1fr))`
- Toolbar: `flex-wrap: wrap` for small screens

## Animations

| Animation | Usage | Duration |
|---|---|---|
| `fadeUp` | Modal entrance, stat cards | 0.2–0.5s ease |
| Gauge bar fill | Monitor progress bars | 0.8s cubic-bezier |
| Button hover | All buttons | translateY(-1px), 0.1s |
| Input focus | All inputs | Border color + box-shadow, 0.2s |
| Nav link hover | Navigation | Color + background, 0.15s |
