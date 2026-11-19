<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_admin_management_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function admin_management_columns(PDO $pdo, string $table): array
{
    return array_column(
        $pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
}

function admin_management_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach ([
        "users" => ["id", "name", "email", "role", "account_status"],
        "products" => ["id", "user_id", "category"],
        "categories" => ["category_code", "category_name", "description"]
    ] as $table => $columns) {
        if (array_diff($columns, admin_management_columns($pdo, $table)) !== []) {
            throw new RuntimeException("The {$table} table is missing required columns.");
        }
        $digest = hash_init("sha256");
        foreach ($pdo->query(
            "SELECT " . implode(", ", $columns) . " FROM \"{$table}\" ORDER BY 1"
        ) as $row) {
            hash_update(
                $digest,
                json_encode(
                    array_map(static fn (string $column) => $row[$column], $columns),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) . "\n"
            );
        }
        $snapshot[$table] = [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn(),
            "digest" => hash_final($digest)
        ];
    }

    return $snapshot;
}

function verify_admin_management_schema(PDO $pdo): void
{
    if (!in_array("category_name_th", admin_management_columns($pdo, "categories"), true)) {
        throw new RuntimeException("The categories table is missing category_name_th.");
    }
    if (array_diff(
        ["id", "user_id", "admin_id", "old_status", "new_status", "reason", "changed_at"],
        admin_management_columns($pdo, "admin_user_status_history")
    ) !== []) {
        throw new RuntimeException("The user status history table has an unexpected schema.");
    }
    $index = $pdo->query(
        "SELECT 1 FROM sqlite_master
         WHERE type = 'index' AND name = 'idx_admin_user_status_history_user_changed'"
    )->fetchColumn();
    if ($index === false) {
        throw new RuntimeException("The user status history index is missing.");
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 11"
    )->fetchColumn() !== 1) {
        throw new RuntimeException("Migration version 11 is not recorded exactly once.");
    }
}

$scriptDirectory = realpath(__DIR__);
$projectRoot = $scriptDirectory !== false
    && is_file($scriptDirectory . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite")
    ? $scriptDirectory
    : realpath(dirname(__DIR__));
$expectedPath = $projectRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$configuredPath = getenv("DB_PATH") ?: $expectedPath;
$resolvedPath = realpath($configuredPath);
fwrite(STDOUT, "Selected driver: " . (getenv("DB_DRIVER") ?: "sqlite") . "\n");
fwrite(STDOUT, "Selected database path: " . ($resolvedPath ?: $configuredPath) . "\n");
if ((getenv("DB_DRIVER") ?: "sqlite") !== "sqlite") {
    abort_admin_management_migration("this migration supports only SQLite.");
}
if (
    $projectRoot === false
    || $resolvedPath === false
    || $resolvedPath !== $expectedPath
    || !is_file($resolvedPath)
) {
    abort_admin_management_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_admin_management_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if (array_diff(
        ["version", "applied_at"],
        admin_management_columns($pdo, "schema_migrations")
    ) !== []) {
        abort_admin_management_migration("schema_migrations is missing or has an unexpected schema.");
    }
    $versionTenCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 10"
    )->fetchColumn();
    $versionElevenCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 11"
    )->fetchColumn();
    if ($versionElevenCount === 1) {
        verify_admin_management_schema($pdo);
        fwrite(STDOUT, "Migration version 11 is already applied and verified.\n");
        exit(0);
    }
    if ($versionTenCount !== 1 || $versionElevenCount !== 0) {
        abort_admin_management_migration("version 10 must be recorded once and version 11 must not be recorded.");
    }
    if (in_array("category_name_th", admin_management_columns($pdo, "categories"), true)) {
        abort_admin_management_migration("the categories table already contains category_name_th.");
    }
    if (
        $pdo->query(
            "SELECT 1 FROM sqlite_master
             WHERE type = 'table' AND name = 'admin_user_status_history'"
        )->fetchColumn() !== false
    ) {
        abort_admin_management_migration("admin_user_status_history already exists.");
    }
    $before = admin_management_snapshot($pdo);

    fwrite(STDOUT, "Type APPLY ADMIN MANAGEMENT MIGRATION 11 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals(
        "APPLY ADMIN MANAGEMENT MIGRATION 11",
        trim($confirmation)
    )) {
        abort_admin_management_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        throw new RuntimeException("Could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        throw new RuntimeException("The backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-v11-" . gmdate("Ymd\THis\Z") . "-"
        . bin2hex(random_bytes(6)) . ".sqlite";
    $reservation = fopen($backupPath, "x");
    if ($reservation === false) {
        throw new RuntimeException("Could not reserve a unique backup filename.");
    }
    fclose($reservation);
    if (!chmod($backupPath, 0600)) {
        throw new RuntimeException("The backup file could not be secured.");
    }
    try {
        $source = new SQLite3($resolvedPath, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($backupPath, SQLITE3_OPEN_READWRITE);
        try {
            $source->busyTimeout(5000);
            $destination->busyTimeout(5000);
            if (!$source->backup($destination)) {
                throw new RuntimeException("SQLite online backup failed.");
            }
        } finally {
            $source->close();
            $destination->close();
        }
        $backup = new PDO("sqlite:" . $backupPath);
        $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (
            admin_management_snapshot($backup) !== $before
            || $backup->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
            || $backup->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
        ) {
            throw new RuntimeException("The backup did not verify against the inspected database.");
        }
    } catch (Throwable $e) {
        if (is_file($backupPath) && !unlink($backupPath)) {
            error_log("Failed to remove invalid admin management migration backup.");
        }
        throw $e;
    }

    $pdo->exec("BEGIN IMMEDIATE");
    try {
        if (admin_management_snapshot($pdo) !== $before) {
            throw new RuntimeException("Marketplace data changed after the backup.");
        }
        if ((int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 10"
        )->fetchColumn() !== 1) {
            throw new RuntimeException("Migration version 10 changed after preflight.");
        }
        $pdo->exec(
            "ALTER TABLE categories ADD COLUMN category_name_th TEXT NULL
             CHECK (
                category_name_th IS NULL
                OR length(trim(category_name_th)) BETWEEN 1 AND 100
             )"
        );
        $pdo->exec(
            "UPDATE categories
             SET category_name_th = CASE category_code
                 WHEN 'electronics' THEN 'อุปกรณ์อิเล็กทรอนิกส์'
                 WHEN 'fashion' THEN 'แฟชั่น'
                 WHEN 'home_living' THEN 'บ้านและของใช้'
                 WHEN 'books' THEN 'หนังสือ'
                 WHEN 'sports_outdoors' THEN 'กีฬาและกิจกรรมกลางแจ้ง'
                 WHEN 'games_collectibles' THEN 'เกมและของสะสม'
                 WHEN 'other' THEN 'อื่น ๆ'
                 ELSE NULL
             END"
        );
        $pdo->exec(
            "CREATE TABLE admin_user_status_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                admin_id INTEGER NOT NULL,
                old_status TEXT NOT NULL CHECK (old_status IN ('active', 'suspended')),
                new_status TEXT NOT NULL CHECK (new_status IN ('active', 'suspended')),
                reason TEXT NOT NULL CHECK (length(trim(reason)) BETWEEN 1 AND 2000),
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CHECK (old_status <> new_status),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE INDEX idx_admin_user_status_history_user_changed
             ON admin_user_status_history (user_id, changed_at DESC, id DESC)"
        );
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (11)");
        verify_admin_management_schema($pdo);
        if (
            admin_management_snapshot($pdo) !== $before
            || $pdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
            || $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
        ) {
            throw new RuntimeException("Post-migration verification failed.");
        }
        $pdo->exec("COMMIT");
    } catch (Throwable $e) {
        $pdo->exec("ROLLBACK");
        throw $e;
    }
    fwrite(STDOUT, "Migration version 11 applied and verified.\n");
    fwrite(STDOUT, "Category translations and user status history are ready.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
