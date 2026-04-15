<?php
// action_user_edit.php
require_once 'auth.php';
require_admin();
require_once 'db.php';
require_once 'usb_manifest.php';

$current  = current_user();
$id       = (int)($_POST['id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email']    ?? '') ?: null;
$password = $_POST['password'] ?? '';
$role     = in_array($_POST['role'] ?? '', ['admin','user']) ? $_POST['role'] : 'user';

// Quota: convert MB to bytes; empty/0 = unlimited (NULL)
$quota_mb = trim($_POST['storage_quota_mb'] ?? '');
$quota    = ($quota_mb !== '' && (int)$quota_mb > 0) ? (int)$quota_mb * 1048576 : null;

if (!$id || $username === '') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request.'];
    header('Location: /users.php'); exit;
}

// Fetch current role of the target so we can detect role transitions
$existing = $pdo->prepare('SELECT role FROM users WHERE id = ?');
$existing->execute([$id]);
$target = $existing->fetch();
if (!$target) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'User not found.'];
    header('Location: /users.php'); exit;
}

// Guard 1: admins cannot demote themselves (footgun - would lock them out
// on next login and leave them helpless until another admin promotes them).
if ($id === $current['id'] && $target['role'] === 'admin' && $role !== 'admin') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "You can't demote yourself. Ask another admin to do it."];
    header('Location: /users.php'); exit;
}

// Guard 2: never leave the system with zero admins. Block the demotion of
// the only remaining admin so the NAS always has at least one administrator
// who can manage users, take backups, restore, etc.
if ($target['role'] === 'admin' && $role !== 'admin') {
    $admin_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($admin_count <= 1) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Cannot demote the last admin. Promote another user to admin first.'];
        header('Location: /users.php'); exit;
    }
}

// Check duplicate username (excluding this user)
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
$stmt->execute([$username, $id]);
if ($stmt->fetch()) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Username \"$username\" is already taken."];
    header('Location: /users.php'); exit;
}

if ($password !== '') {
    if (strlen($password) < 8) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Password must be at least 8 characters.'];
        header('Location: /users.php'); exit;
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE users SET username=?, email=?, password=?, role=?, storage_quota=? WHERE id=?')
        ->execute([$username, $email, $hash, $role, $quota, $id]);
} else {
    $pdo->prepare('UPDATE users SET username=?, email=?, role=?, storage_quota=? WHERE id=?')
        ->execute([$username, $email, $role, $quota, $id]);
}

// If the role actually changed, bump session_version so the target user's
// active sessions get force-logged-out on their next request (see auth.php's
// validate_session_version). This closes the "demoted admin continues
// acting as admin until they log out" gap.
if ($target['role'] !== $role) {
    $pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')
        ->execute([$id]);
    // If the acting admin's own role changed somehow, keep their current
    // session valid by syncing the cache (prevents immediate self-logout).
    if ($id === $current['id']) {
        $_SESSION['session_version'] = (int)$pdo->query("SELECT session_version FROM users WHERE id = $id")->fetchColumn();
        $_SESSION['role'] = $role;
    }
}

// Refresh manifest so username changes flow through to the UI side of the
// USB archive display. (Hash stays stable - it's keyed by user_id + salt.)
update_user_manifest($pdo);

$_SESSION['flash'] = ['type' => 'success', 'msg' => "User \"$username\" updated."];
header('Location: /users.php');
