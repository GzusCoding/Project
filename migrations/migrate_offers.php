<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_offers_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function offers_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $stmt->execute(["name" => $table]);

    return $stmt->fetchColumn() !== false;
}

function offers_object_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE name = :name"
    );
    $stmt->execute(["name" => $name]);

    return $stmt->fetchColumn() !== false;
}

function offers_table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query("PRAGMA table_info(\"{$table}\")") as $row) {
        $columns[] = $row["name"];
    }

    return $columns;
}

function offers_table_sql(array $currencyExponents): string
{
    $currencyRules = [];
    foreach ($currencyExponents as $currency => $exponent) {
        $currencyRules[] = "(cash_currency = '{$currency}' AND currency_exponent = {$exponent})";
    }

    return "CREATE TABLE offers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        creator_id INTEGER NOT NULL,
        recipient_id INTEGER NOT NULL,
        cash_payer_id INTEGER NOT NULL,
        offered_product_id INTEGER NOT NULL,
        target_product_id INTEGER NOT NULL,
        cash_amount_minor INTEGER NOT NULL
            CHECK (typeof(cash_amount_minor) = 'integer' AND cash_amount_minor >= 0),
        cash_currency TEXT NULL,
        currency_exponent INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'pending'
            CHECK (status IN ('pending', 'accepted', 'rejected', 'cancelled', 'superseded', 'expired')),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME NULL,
        CHECK (creator_id <> recipient_id),
        CHECK (cash_payer_id = creator_id),
        CHECK (offered_product_id <> target_product_id),
        CHECK (
            (cash_amount_minor = 0 AND cash_currency IS NULL AND currency_exponent IS NULL)
            OR
            (
                cash_amount_minor > 0
                AND typeof(currency_exponent) = 'integer'
                AND (" . implode(" OR ", $currencyRules) . ")
            )
        ),
        FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (cash_payer_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (offered_product_id) REFERENCES products(id) ON DELETE RESTRICT,
        FOREIGN KEY (target_product_id) REFERENCES products(id) ON DELETE RESTRICT
    )";
}

function offers_expected_indexes(): array
{
    return [
        "idx_offers_creator_status" => ["creator_id", "status"],
        "idx_offers_recipient_status" => ["recipient_id", "status"],
        "idx_offers_offered_product_status" => ["offered_product_id", "status"],
        "idx_offers_target_product_status" => ["target_product_id", "status"],
        "idx_offers_created_at" => ["created_at"]
    ];
}

function offers_verify_schema(PDO $pdo, array $currencyExponents): void
{
    if (!offers_table_exists($pdo, "offers")) {
        throw new RuntimeException("The offers table is missing.");
    }

    $expectedColumns = [
        "id", "creator_id", "recipient_id", "cash_payer_id",
        "offered_product_id", "target_product_id", "cash_amount_minor",
        "cash_currency", "currency_exponent", "status", "created_at",
        "updated_at", "responded_at"
    ];
    if (offers_table_columns($pdo, "offers") !== $expectedColumns) {
        throw new RuntimeException("The offers table has an unexpected column definition.");
    }

    $tableSql = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'offers'"
    )->fetchColumn();
    $normalizeSql = static fn (string $sql): string => (string) preg_replace(
        "/\\s+/",
        " ",
        trim($sql)
    );
    if (
        !is_string($tableSql)
        || $normalizeSql($tableSql) !== $normalizeSql(offers_table_sql($currencyExponents))
    ) {
        throw new RuntimeException("The offers table constraints do not match this migration.");
    }

    foreach (offers_expected_indexes() as $name => $expectedColumns) {
        $index = $pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = :name"
        );
        $index->execute(["name" => $name]);
        if ($index->fetchColumn() === false) {
            throw new RuntimeException("Required index {$name} is missing.");
        }

        $indexColumns = [];
        foreach ($pdo->query("PRAGMA index_info(\"{$name}\")") as $row) {
            $indexColumns[] = $row["name"];
        }
        if ($indexColumns !== $expectedColumns) {
            throw new RuntimeException("Index {$name} has unexpected columns.");
        }
    }
}

function offers_data_digest(PDO $pdo, string $table, array $columns): string
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

function offers_snapshot(PDO $pdo, array $userColumns, array $productColumns): array
{
    return [
        "users" => [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            "digest" => offers_data_digest($pdo, "users", $userColumns)
        ],
        "products" => [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
            "digest" => offers_data_digest($pdo, "products", $productColumns)
        ]
    ];
}

function offers_verify_database(PDO $pdo, array $currencyExponents): void
{
    offers_verify_schema($pdo, $currencyExponents);
    $versionCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 2"
    )->fetchColumn();
    if ($versionCount !== 1) {
        throw new RuntimeException("Migration version 2 is not recorded exactly once.");
    }
    if ($pdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
        throw new RuntimeException("SQLite integrity_check did not return ok.");
    }
    if ($pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []) {
        throw new RuntimeException("foreign_key_check reported violations.");
    }
}

$currencyExponents = [
    "AUD" => 2,
    "BHD" => 3,
    "CAD" => 2,
    "CHF" => 2,
    "CNY" => 2,
    "EUR" => 2,
    "GBP" => 2,
    "HKD" => 2,
    "INR" => 2,
    "JPY" => 0,
    "KRW" => 0,
    "KWD" => 3,
    "NZD" => 2,
    "SGD" => 2,
    "USD" => 2,
    "VND" => 0
];

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
    abort_offers_migration("this migration supports only SQLite.");
}
if ($projectRoot === false || $resolvedPath === false || $resolvedPath !== $expectedPath || !is_file($resolvedPath)) {
    abort_offers_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_offers_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 1) {
        abort_offers_migration("SQLite foreign-key enforcement could not be enabled.");
    }

    if (!offers_table_exists($pdo, "users") || !offers_table_exists($pdo, "products")) {
        abort_offers_migration("the expected users or products table is missing.");
    }

    $userColumns = offers_table_columns($pdo, "users");
    $productColumns = offers_table_columns($pdo, "products");
    if (
        !in_array("id", $userColumns, true)
        || array_diff(
            ["id", "user_id", "name", "description", "price", "image", "created_at"],
            $productColumns
        ) !== []
    ) {
        abort_offers_migration("the existing users or products schema is unexpected.");
    }

    if (offers_table_exists($pdo, "schema_migrations")) {
        $migrationColumns = offers_table_columns($pdo, "schema_migrations");
        if (
            !in_array("version", $migrationColumns, true)
            || !in_array("applied_at", $migrationColumns, true)
        ) {
            abort_offers_migration("schema_migrations has an unexpected schema.");
        }
        $applied = (int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 2"
        )->fetchColumn();
        if ($applied > 0) {
            offers_verify_database($pdo, $currencyExponents);
            fwrite(STDOUT, "Migration version 2 is already recorded; no database changes made.\n");
            exit(0);
        }
    }

    if (
        offers_object_exists($pdo, "offers")
        || array_filter(
            array_keys(offers_expected_indexes()),
            static fn (string $name): bool => offers_object_exists($pdo, $name)
        ) !== []
    ) {
        abort_offers_migration("offers migration objects exist without migration version 2.");
    }

    $before = offers_snapshot($pdo, $userColumns, $productColumns);
    fwrite(STDOUT, "Type APPLY OFFERS MIGRATION 2 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals("APPLY OFFERS MIGRATION 2", trim($confirmation))) {
        abort_offers_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        abort_offers_migration("could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        abort_offers_migration("the backup directory could not be secured.");
    }

    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-"
        . gmdate("Ymd\THis\Z") . "-" . bin2hex(random_bytes(6)) . ".sqlite";
    $reservation = fopen($backupPath, "x");
    if ($reservation === false) {
        abort_offers_migration("could not reserve a unique backup filename.");
    }
    fclose($reservation);
    if (!chmod($backupPath, 0600)) {
        unlink($backupPath);
        abort_offers_migration("the backup file could not be secured.");
    }

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
        $backupSnapshot = offers_snapshot($backupPdo, $userColumns, $productColumns);
        $backupIntegrity = $backupPdo->query("PRAGMA integrity_check")->fetchColumn();
        if ($backupSnapshot !== $before || $backupIntegrity !== "ok") {
            throw new RuntimeException("The backup did not verify against the inspected database.");
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
        if (offers_snapshot($pdo, $userColumns, $productColumns) !== $before) {
            throw new RuntimeException("users or products changed after backup; no migration was applied.");
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $versionCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 2"
        )->fetchColumn();
        if ($versionCount !== 0) {
            throw new RuntimeException("Migration version 2 was recorded concurrently.");
        }

        $pdo->exec(offers_table_sql($currencyExponents));
        foreach (offers_expected_indexes() as $name => $columns) {
            $pdo->exec(
                "CREATE INDEX {$name} ON offers (" . implode(", ", $columns) . ")"
            );
        }
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (2)");

        offers_verify_database($pdo, $currencyExponents);
        if (offers_snapshot($pdo, $userColumns, $productColumns) !== $before) {
            throw new RuntimeException("Existing user or product data changed unexpectedly.");
        }

        $pdo->exec("COMMIT");
        $inMigration = false;
    } catch (Throwable $e) {
        if ($inMigration) {
            $pdo->exec("ROLLBACK");
        }
        throw $e;
    }

    offers_verify_database($pdo, $currencyExponents);
    if (offers_snapshot($pdo, $userColumns, $productColumns) !== $before) {
        throw new RuntimeException("Post-migration user or product preservation check failed.");
    }

    fwrite(STDOUT, "Migration version 2 applied and verified.\n");
    fwrite(STDOUT, "Users and products were verified unchanged.\n");
    fwrite(STDOUT, "Offers constraints, indexes, foreign keys, and integrity verified.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    if (isset($backupPath) && is_file($backupPath)) {
        fwrite(STDERR, "Verified backup retained at: {$backupPath}\n");
    }
    exit(1);
}
