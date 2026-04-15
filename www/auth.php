<?php
// auth.php — include at the top of every protected page

session_start();
// db.php must be loaded at top-level so $pdo lives in global scope and is
// reachable from validate_session_version() via `global $pdo`.
require_once __DIR__ . '/db.php';

// Force-logout if the user's session_version in the DB no longer matches the
// one cached in their session. This is how role changes take effect on
// active sessions - when an admin edits someone's role, the DB's
// session_version bumps, and their next request here fails the check.
function validate_session_version(): void {
    if (!isset($_SESSION['user_id'])) return;
    global $pdo;
    $stmt = $pdo->prepare('SELECT role, session_version FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        // User deleted out from under the session
        session_destroy();
        header('Location: /login.php?reason=deleted');
        exit;
    }
    // Sessions created before session_version tracking existed don't have the
    // field cached. Treat "unset" as 0 so they still get validated against
    // the DB. If the DB has bumped past 0 (any role change), they're out.
    $cached = (int)($_SESSION['session_version'] ?? 0);
    if ((int)$row['session_version'] !== $cached) {
        session_destroy();
        header('Location: /login.php?reason=role_changed');
        exit;
    }
    // Refresh role cache in case it was updated without version bump
    $_SESSION['role'] = $row['role'];
}

function require_login(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
    validate_session_version();
}

function require_admin(): void {
    require_login();
    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Access denied.');
    }
}

function current_user(): array {
    return [
        'id'       => $_SESSION['user_id']  ?? null,
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role']     ?? 'user',
    ];
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}
