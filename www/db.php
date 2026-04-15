<?php
// db.php — shared PDO connection
// Included by any page that needs the database

$host = 'db';  // Docker service name from docker-compose.yml
$db   = $_ENV['MYSQL_DATABASE'] ?? getenv('MYSQL_DATABASE') ?? 'nas_db';
$user = $_ENV['MYSQL_USER']     ?? getenv('MYSQL_USER')     ?? 'nas_user';
$pass = $_ENV['MYSQL_PASSWORD'] ?? getenv('MYSQL_PASSWORD') ?? '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed.']));
}

// Self-healing schema migrations for volumes initialized before these columns
// existed. Runs once per worker; the flag file keeps it cheap on hot paths.
$migrationsFlag = sys_get_temp_dir() . '/nas_schema_v1.ok';
if (!file_exists($migrationsFlag)) {
    try {
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('session_version', $cols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 0 AFTER last_login');
        }
        if (!in_array('storage_quota', $cols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN storage_quota BIGINT NULL AFTER role');
        }
        @touch($migrationsFlag);
    } catch (PDOException $e) {
        // Migration best-effort; app will still try to run.
    }
}
