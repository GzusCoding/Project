<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "admin-management-migration-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$migrationPath = $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_admin_management.php";

function admin_migration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function admin_migration_run(string $scriptPath, string $databasePath, ?string $input): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $scriptPath],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ],
        $pipes,
        dirname($scriptPath),
        [
            "DB_DRIVER" => "sqlite",
            "DB_PATH" => $databasePath,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start the admin migration process.");
    }
    if ($input !== null) {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

function admin_migration_remove_tree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}

try {
    if (!mkdir($temporaryRoot, 0700)) {
        throw new RuntimeException("Could not create an isolated migration directory.");
    }
    if (!copy(
        $projectRoot . DIRECTORY_SEPARATOR . "migrations" . DIRECTORY_SEPARATOR
            . "migrate_admin_management.php",
        $migrationPath
    )) {
        throw new RuntimeException("Could not copy the admin migration.");
    }
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            role TEXT NOT NULL,
            account_status TEXT NOT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            category TEXT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE categories (
            category_code TEXT PRIMARY KEY,
            category_name TEXT NOT NULL UNIQUE,
            description TEXT NULL
        )"
    );
    $pdo->exec(
        "INSERT INTO users (id, name, email, role, account_status)
         VALUES (1, 'Fixture user', 'fixture@example.test', 'user', 'active')"
    );
    $pdo->exec(
        "INSERT INTO products (id, user_id, category) VALUES (10, 1, 'other')"
    );
    $pdo->exec(
        "INSERT INTO categories (category_code, category_name, description)
         VALUES ('other', 'Other', NULL)"
    );
    for ($version = 1; $version <= 10; $version++) {
        $stmt = $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (:version)");
        $stmt->execute(["version" => $version]);
    }
    $pdo = null;

    [$exitCode, , $stderr] = admin_migration_run($migrationPath, $databasePath, "wrong\n");
    admin_migration_assert($exitCode === 1, "The migration must reject an incorrect confirmation.");
    $pdo = new PDO("sqlite:" . $databasePath);
    admin_migration_assert(
        !in_array(
            "category_name_th",
            array_column($pdo->query("PRAGMA table_info(categories)")->fetchAll(PDO::FETCH_ASSOC), "name"),
            true
        ),
        "An unconfirmed migration must not change the category schema."
    );
    $pdo = null;

    [$exitCode, $stdout, $stderr] = admin_migration_run(
        $migrationPath,
        $databasePath,
        "APPLY ADMIN MANAGEMENT MIGRATION 11\n"
    );
    admin_migration_assert($exitCode === 0, "Migration 11 failed: " . $stderr);
    admin_migration_assert(
        str_contains($stdout, "Migration version 11 applied and verified."),
        "Migration 11 did not report successful verification."
    );

    $pdo = new PDO("sqlite:" . $databasePath);
    $category = $pdo->query(
        "SELECT category_name, category_name_th FROM categories WHERE category_code = 'other'"
    )->fetch(PDO::FETCH_ASSOC);
    admin_migration_assert(
        $category === ["category_name" => "Other", "category_name_th" => "อื่น ๆ"],
        "Migration 11 did not preserve and localize the existing category."
    );
    admin_migration_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 1
            && (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() === 1,
        "Migration 11 changed existing marketplace records."
    );
    admin_migration_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 11")->fetchColumn() === 1
            && $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "Migration 11 failed schema or integrity verification."
    );
    admin_migration_assert(
        count(glob($temporaryRoot . DIRECTORY_SEPARATOR . ".database-backups" . DIRECTORY_SEPARATOR . "*.sqlite")) === 1,
        "Migration 11 must retain its verified backup."
    );
    $pdo = null;

    [$exitCode, $stdout, $stderr] = admin_migration_run($migrationPath, $databasePath, null);
    admin_migration_assert(
        $exitCode === 0 && str_contains($stdout, "already applied and verified"),
        "Migration 11 must be safely repeatable: " . $stderr
    );

    echo "Admin management migration tests passed.\n";
} finally {
    admin_migration_remove_tree($temporaryRoot);
}
