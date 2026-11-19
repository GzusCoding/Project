<?php

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query("PRAGMA table_info(\"{$table}\")") as $row) {
        $columns[] = $row["name"];
    }

    return $columns;
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $stmt->execute(["name" => $table]);

    return $stmt->fetchColumn() !== false;
}

function data_digest(PDO $pdo, string $table, array $columns): string
{
    $quotedColumns = array_map(
        static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
        $columns
    );
    $sql = "SELECT " . implode(", ", $quotedColumns)
        . " FROM \"{$table}\" ORDER BY \"id\"";
    $digest = hash_init("sha256");

    foreach ($pdo->query($sql) as $row) {
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column];
        }
        hash_update(
            $digest,
            json_encode(
                $values,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ) . "\n"
        );
    }

    return hash_final($digest);
}

function snapshot(PDO $pdo, array $userColumns, array $productColumns): array
{
    return [
        "users" => [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            "digest" => data_digest($pdo, "users", $userColumns)
        ],
        "products" => [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
            "digest" => data_digest($pdo, "products", $productColumns)
        ]
    ];
}

function image_files(PDO $pdo, string $root): array
{
    $images = [];
    foreach ($pdo->query("SELECT image FROM products WHERE image IS NOT NULL ORDER BY id") as $row) {
        $path = $row["image"];
        $absolutePath = is_string($path) && $path !== ""
            ? realpath($root . DIRECTORY_SEPARATOR . $path)
            : false;
        $isWithinProject = $absolutePath !== false
            && str_starts_with($absolutePath, $root . DIRECTORY_SEPARATOR);

        if ($isWithinProject && is_file($absolutePath)) {
            $hash = hash_file("sha256", $absolutePath);
            if ($hash === false) {
                throw new RuntimeException("An existing product image could not be read for verification.");
            }
            $images[] = [$path, $hash];
        } else {
            $images[] = [$path, null];
        }
    }

    return $images;
}

function verify_image_files(PDO $pdo, string $root, array $expected): void
{
    $actual = image_files($pdo, $root);
    if ($actual !== $expected) {
        throw new RuntimeException("Product image paths or existing image files changed.");
    }
}

function verify_defaults(PDO $pdo): void
{
    $defaults = [];
    foreach ($pdo->query("PRAGMA table_info(products)") as $row) {
        $defaults[$row["name"]] = $row["dflt_value"];
    }

    if (($defaults["transaction_type"] ?? null) !== "'buy'") {
        throw new RuntimeException("The transaction_type default is not buy.");
    }
    if (($defaults["status"] ?? null) !== "'active'") {
        throw new RuntimeException("The status default is not active.");
    }
}

function verify_constraints(PDO $pdo): void
{
    $ownerId = $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    if ($ownerId === false) {
        throw new RuntimeException("Cannot verify product defaults without an existing user.");
    }

    $pdo->exec("SAVEPOINT product_migration_validation");
    try {
        $insert = $pdo->prepare(
            "INSERT INTO products (user_id, name, price) VALUES (:user_id, :name, 0)"
        );
        $insert->execute([
            "user_id" => $ownerId,
            "name" => "__product_migration_validation__"
        ]);
        $productId = $pdo->lastInsertId();
        $stmt = $pdo->prepare(
            "SELECT category, transaction_type, item_condition, status
             FROM products WHERE id = :id"
        );
        $stmt->execute(["id" => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $product === false
            || $product["category"] !== null
            || $product["transaction_type"] !== "buy"
            || $product["item_condition"] !== null
            || $product["status"] !== "active"
        ) {
            throw new RuntimeException("New-product defaults did not match the migration requirements.");
        }

        foreach ([
            ["transaction_type", "invalid"],
            ["item_condition", "invalid"],
            ["status", "invalid"]
        ] as [$column, $value]) {
            try {
                $update = $pdo->prepare(
                    "UPDATE products SET \"{$column}\" = :value WHERE id = :id"
                );
                $update->execute(["value" => $value, "id" => $productId]);
                throw new RuntimeException("The {$column} allowed-values constraint is missing.");
            } catch (PDOException) {
                // The invalid value must be rejected by the column constraint.
            }
        }

        $pdo->exec("ROLLBACK TO SAVEPOINT product_migration_validation");
        $pdo->exec("RELEASE SAVEPOINT product_migration_validation");
    } catch (Throwable $e) {
        $pdo->exec("ROLLBACK TO SAVEPOINT product_migration_validation");
        $pdo->exec("RELEASE SAVEPOINT product_migration_validation");
        throw $e;
    }
}

function foreign_key_violations(PDO $pdo): array
{
    return $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC);
}

function verify_integrity(PDO $pdo): void
{
    $result = $pdo->query("PRAGMA integrity_check")->fetchColumn();
    if ($result !== "ok") {
        throw new RuntimeException("SQLite integrity_check did not return ok.");
    }
}

$driver = getenv("DB_DRIVER") ?: "sqlite";
$scriptDirectory = realpath(__DIR__);
$projectRoot = $scriptDirectory !== false
    && is_file($scriptDirectory . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite")
    ? $scriptDirectory
    : realpath(dirname(__DIR__));
$expectedPath = $projectRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$configuredPath = getenv("DB_PATH") ?: $expectedPath;
$resolvedPath = realpath($configuredPath);

fwrite(STDOUT, "Selected driver: {$driver}\n");
fwrite(STDOUT, "Selected database path: " . ($resolvedPath ?: $configuredPath) . "\n");

if ($driver !== "sqlite") {
    abort_migration("this migration supports only the inspected SQLite database.");
}
if ($resolvedPath === false || $resolvedPath !== $expectedPath || !is_file($resolvedPath)) {
    abort_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");

    if (!table_exists($pdo, "users") || !table_exists($pdo, "products")) {
        abort_migration("the expected users or products table is missing.");
    }

    $originalUserColumns = table_columns($pdo, "users");
    $originalProductColumns = table_columns($pdo, "products");
    $requiredProductColumns = [
        "id", "user_id", "name", "description", "price", "image", "created_at"
    ];
    if (
        array_diff($requiredProductColumns, $originalProductColumns) !== []
        || !in_array("id", $originalUserColumns, true)
    ) {
        abort_migration("the existing schema does not match the inspected project database.");
    }

    if (table_exists($pdo, "schema_migrations")) {
        $migrationColumns = table_columns($pdo, "schema_migrations");
        if (!in_array("version", $migrationColumns, true) || !in_array("applied_at", $migrationColumns, true)) {
            abort_migration("schema_migrations exists with an unexpected schema.");
        }
        $alreadyApplied = $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 1"
        )->fetchColumn();
        if ((int) $alreadyApplied > 0) {
            verify_defaults($pdo);
            verify_integrity($pdo);
            if (foreign_key_violations($pdo) !== []) {
                abort_migration("foreign_key_check reported violations.");
            }
            fwrite(STDOUT, "Migration version 1 is already recorded; no database changes made.\n");
            exit(0);
        }
    }

    $userColumnsForSnapshot = $originalUserColumns;
    $productColumnsForSnapshot = $originalProductColumns;
    $before = snapshot($pdo, $userColumnsForSnapshot, $productColumnsForSnapshot);
    $imagesBefore = image_files($pdo, $projectRoot);

    fwrite(STDOUT, "Type APPLY PRODUCT MIGRATION 1 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals("APPLY PRODUCT MIGRATION 1", trim($confirmation))) {
        abort_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        abort_migration("could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        abort_migration("the backup directory could not be secured.");
    }

    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-"
        . gmdate("Ymd\THis\Z") . "-" . bin2hex(random_bytes(6)) . ".sqlite";
    $reservation = fopen($backupPath, "x");
    if ($reservation === false) {
        abort_migration("could not reserve a unique backup filename.");
    }
    fclose($reservation);
    chmod($backupPath, 0600);

    try {
        $source = new SQLite3($resolvedPath, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($backupPath, SQLITE3_OPEN_READWRITE);
        $source->busyTimeout(5000);
        $destination->busyTimeout(5000);
        if (!$source->backup($destination)) {
            throw new RuntimeException("SQLite online backup failed.");
        }
        $source->close();
        $destination->close();

        $backupPdo = new PDO("sqlite:" . $backupPath);
        $backupPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $backupPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        verify_integrity($backupPdo);
        $backupSnapshot = snapshot($backupPdo, $userColumnsForSnapshot, $productColumnsForSnapshot);
        if ($backupSnapshot !== $before) {
            throw new RuntimeException("The backup contents do not match the inspected database.");
        }
        $backupPdo = null;
    } catch (Throwable $e) {
        if (is_file($backupPath)) {
            unlink($backupPath);
        }
        throw $e;
    }

    fwrite(STDOUT, "Verified backup: {$backupPath}\n");

    $inMigration = false;
    try {
        $pdo->exec("BEGIN IMMEDIATE");
        $inMigration = true;

        if (snapshot($pdo, $userColumnsForSnapshot, $productColumnsForSnapshot) !== $before) {
            throw new RuntimeException("The database changed after backup; no migration was applied.");
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $alreadyApplied = $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 1"
        )->fetchColumn();
        if ((int) $alreadyApplied > 0) {
            $pdo->exec("ROLLBACK");
            $inMigration = false;
            fwrite(STDOUT, "Migration version 1 is already recorded; no database changes made.\n");
            exit(0);
        }

        $columns = table_columns($pdo, "products");
        $addColumns = [
            "category" => "ALTER TABLE products ADD COLUMN category TEXT NULL",
            "transaction_type" => "ALTER TABLE products ADD COLUMN transaction_type TEXT NOT NULL DEFAULT 'buy' CHECK (transaction_type IN ('buy', 'exchange', 'both'))",
            "item_condition" => "ALTER TABLE products ADD COLUMN item_condition TEXT NULL CHECK (item_condition IS NULL OR item_condition IN ('new', 'like_new', 'used'))",
            "status" => "ALTER TABLE products ADD COLUMN status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'reserved', 'sold', 'inactive'))"
        ];
        foreach ($addColumns as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec($sql);
            }
        }

        $pdo->exec("UPDATE products SET transaction_type = 'both'");
        $pdo->exec(
            "INSERT INTO schema_migrations (version) VALUES (1)"
        );

        verify_defaults($pdo);
        verify_constraints($pdo);
        verify_integrity($pdo);
        if (foreign_key_violations($pdo) !== []) {
            throw new RuntimeException("foreign_key_check reported violations.");
        }
        if (snapshot($pdo, $userColumnsForSnapshot, $productColumnsForSnapshot) !== $before) {
            throw new RuntimeException("Existing user or product data changed unexpectedly.");
        }
        verify_image_files($pdo, $projectRoot, $imagesBefore);

        $versionCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 1"
        )->fetchColumn();
        if ($versionCount !== 1) {
            throw new RuntimeException("Migration version 1 was not recorded exactly once.");
        }

        $pdo->exec("COMMIT");
        $inMigration = false;
    } catch (Throwable $e) {
        if ($inMigration) {
            $pdo->exec("ROLLBACK");
        }
        throw $e;
    }

    verify_integrity($pdo);
    if (foreign_key_violations($pdo) !== []) {
        throw new RuntimeException("Post-migration foreign_key_check reported violations.");
    }
    if (snapshot($pdo, $userColumnsForSnapshot, $productColumnsForSnapshot) !== $before) {
        throw new RuntimeException("Post-migration user or product preservation check failed.");
    }
    verify_image_files($pdo, $projectRoot, $imagesBefore);
    verify_defaults($pdo);

    $finalCounts = [
        "users" => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        "products" => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn()
    ];

    fwrite(STDOUT, "Migration version 1 applied and verified.\n");
    fwrite(STDOUT, "Users: {$before["users"]["count"]} before, {$finalCounts["users"]} after.\n");
    fwrite(STDOUT, "Products: {$before["products"]["count"]} before, {$finalCounts["products"]} after.\n");
    fwrite(STDOUT, "Original user/product fields and product image files verified unchanged.\n");
    fwrite(STDOUT, "Foreign keys, SQLite integrity, defaults, constraints, and migration version verified.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    if (isset($backupPath) && is_file($backupPath)) {
        fwrite(STDERR, "Verified backup retained at: {$backupPath}\n");
    }
    exit(1);
}
