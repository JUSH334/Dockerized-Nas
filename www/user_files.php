<?php
// user_files.php - JSON endpoint returning a single user's files and sharing
// relationships. Used by the Users page detail modal (admin-only).
require_once 'auth.php';
require_admin();
require_once 'db.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid user id']);
    exit;
}

function fmt_bytes(int $b): string {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1)    . ' MB';
    if ($b >= 1024)       return round($b / 1024, 1)       . ' KB';
    return $b . ' B';
}

function perms_label(int $r, int $w, int $d): string {
    $out = '';
    if ($r) $out .= 'R';
    if ($w) $out .= 'W';
    if ($d) $out .= 'D';
    return $out ?: '—';
}

// Target user
$stmt = $pdo->prepare('SELECT id, username, role, created_at, storage_quota FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'user not found']);
    exit;
}

// Files owned by this user
$owns = $pdo->prepare('
    SELECT id, filename, filepath, filesize, filetype, is_folder, parent_id, created_at
    FROM files
    WHERE owner_id = ?
    ORDER BY is_folder DESC, created_at DESC
');
$owns->execute([$id]);
$owned_files = $owns->fetchAll();

// For each owned file, collect who it's been shared with (excluding the owner)
$share_stmt = $pdo->prepare('
    SELECT p.file_id, u.id AS user_id, u.username, p.can_read, p.can_write, p.can_delete
    FROM permissions p
    JOIN users u ON p.user_id = u.id
    WHERE p.file_id IN (SELECT id FROM files WHERE owner_id = ?)
      AND u.id != ?
    ORDER BY u.username
');
$share_stmt->execute([$id, $id]);
$shared_map = [];
foreach ($share_stmt->fetchAll() as $s) {
    $shared_map[$s['file_id']][] = [
        'user_id'  => (int)$s['user_id'],
        'username' => $s['username'],
        'perms'    => perms_label((int)$s['can_read'], (int)$s['can_write'], (int)$s['can_delete']),
    ];
}

$owns_out = array_map(function($f) use ($shared_map) {
    $fid = (int)$f['id'];
    return [
        'id'         => $fid,
        'filename'   => $f['filename'],
        'filepath'   => $f['filepath'],
        'filesize'   => (int)$f['filesize'],
        'size_fmt'   => $f['is_folder'] ? '—' : fmt_bytes((int)$f['filesize']),
        'filetype'   => $f['filetype'] ?: '',
        'is_folder'  => (int)$f['is_folder'],
        'created_at' => $f['created_at'],
        'shared_with' => $shared_map[$fid] ?? [],
    ];
}, $owned_files);

// Files other users have shared WITH this user (read access they have that
// isn't implicit ownership)
$shared_with = $pdo->prepare('
    SELECT f.id, f.filename, f.filesize, f.filetype, f.is_folder, f.created_at,
           p.can_read, p.can_write, p.can_delete,
           ow.username AS owner_username
    FROM permissions p
    JOIN files f ON p.file_id = f.id
    JOIN users ow ON f.owner_id = ow.id
    WHERE p.user_id = ? AND f.owner_id != ?
    ORDER BY f.created_at DESC
');
$shared_with->execute([$id, $id]);
$shared_with_out = array_map(function($f) {
    return [
        'id'            => (int)$f['id'],
        'filename'      => $f['filename'],
        'size_fmt'      => $f['is_folder'] ? '—' : fmt_bytes((int)$f['filesize']),
        'is_folder'     => (int)$f['is_folder'],
        'created_at'    => $f['created_at'],
        'owner_username'=> $f['owner_username'],
        'perms'         => perms_label((int)$f['can_read'], (int)$f['can_write'], (int)$f['can_delete']),
    ];
}, $shared_with->fetchAll());

$total_size = array_sum(array_map(fn($f) => $f['is_folder'] ? 0 : $f['filesize'], $owned_files));
$file_count = count(array_filter($owned_files, fn($f) => !$f['is_folder']));
$folder_count = count(array_filter($owned_files, fn($f) => $f['is_folder']));

echo json_encode([
    'user' => [
        'id'            => (int)$user['id'],
        'username'      => $user['username'],
        'role'          => $user['role'],
        'created_at'    => $user['created_at'],
        'storage_quota' => $user['storage_quota'] ? (int)$user['storage_quota'] : null,
        'storage_used'  => $total_size,
        'storage_used_fmt' => fmt_bytes($total_size),
    ],
    'summary' => [
        'files'            => $file_count,
        'folders'          => $folder_count,
        'owned_count'      => $file_count + $folder_count,
        'shared_with_them' => count($shared_with_out),
        'shared_by_them'   => count(array_filter($owns_out, fn($f) => !empty($f['shared_with']))),
    ],
    'owned'       => $owns_out,
    'shared_with' => $shared_with_out,
]);
