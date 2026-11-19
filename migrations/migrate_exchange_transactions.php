<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_exchange_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function exchange_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $stmt->execute(["name" => $table]);

    return $stmt->fetchColumn() !== false;
}

function exchange_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query("PRAGMA table_info(\"{$table}\")") as $row) {
        $columns[] = $row["name"];
    }

    return $columns;
}

function exchange_currency_rules(array $currencyExponents): string
{
    $rules = [];
    foreach ($currencyExponents as $currency => $exponent) {
        $rules[] = "(cash_currency = '{$currency}' AND currency_exponent = {$exponent})";
    }

    return implode(" OR ", $rules);
}

function exchange_expected_objects(): array
{
    return [
        "idx_exchange_transactions_creator_status" => ["exchange_transactions", ["creator_id", "status"]],
        "idx_exchange_transactions_recipient_status" => ["exchange_transactions", ["recipient_id", "status"]],
        "idx_exchange_transactions_created_at" => ["exchange_transactions", ["created_at"]],
        "idx_product_reservations_transaction" => ["product_reservations", ["transaction_id"]],
        "idx_chat_rooms_creator" => ["chat_rooms", ["creator_id"]],
        "idx_chat_rooms_recipient" => ["chat_rooms", ["recipient_id"]]
    ];
}

function exchange_verify_schema(PDO $pdo): void
{
    $expectedColumns = [
        "exchange_transactions" => [
            "id", "offer_id", "creator_id", "recipient_id", "offered_product_id",
            "target_product_id", "cash_payer_id", "cash_amount_minor", "cash_currency",
            "currency_exponent", "payment_method", "payment_status", "status",
            "created_at", "updated_at", "completed_at", "cancelled_at"
        ],
        "product_reservations" => ["product_id", "transaction_id", "reserved_at"],
        "chat_rooms" => [
            "id", "exchange_transaction_id", "creator_id", "recipient_id",
            "created_at", "closed_at"
        ]
    ];
    foreach ($expectedColumns as $table => $columns) {
        if (!exchange_table_exists($pdo, $table) || exchange_columns($pdo, $table) !== $columns) {
            throw new RuntimeException("The {$table} table is missing or has an unexpected schema.");
        }
    }

    foreach (exchange_expected_objects() as $name => [$table, $expected]) {
        $index = $pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = :name"
        );
        $index->execute(["name" => $name]);
        if ($index->fetchColumn() === false) {
            throw new RuntimeException("Required index {$name} is missing.");
        }
        $actual = [];
        foreach ($pdo->query("PRAGMA index_info(\"{$name}\")") as $row) {
            $actual[] = $row["name"];
        }
        if ($actual !== $expected) {
            throw new RuntimeException("Index {$name} has unexpected columns.");
        }
    }

    $versionCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 3"
    )->fetchColumn();
    if ($versionCount !== 1) {
        throw new RuntimeException("Migration version 3 is not recorded exactly once.");
    }
    if ($pdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
        throw new RuntimeException("SQLite integrity_check did not return ok.");
    }
    if ($pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []) {
        throw new RuntimeException("foreign_key_check reported violations.");
    }
}

function exchange_data_digest(PDO $pdo, string $table, array $columns): string
{
    $quoted = array_map(
        static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
        $columns
    );
    $digest = hash_init("sha256");
    foreach ($pdo->query(
        "SELECT " . implode(", ", $quoted) . " FROM \"{$table}\" ORDER BY \"id\""
    ) as $row) {
        hash_update($digest, json_encode(
            array_map(static fn (string $column) => $row[$column], $columns),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) . "\n");
    }

    return hash_final($digest);
}

function exchange_snapshot(PDO $pdo): array
{
    $snapshots = [];
    foreach ([
        "users" => ["id", "name", "email", "password", "created_at"],
        "products" => [
            "id", "user_id", "name", "description", "price", "image",
            "created_at", "category", "transaction_type", "item_condition", "status"
        ],
        "offers" => [
            "id", "creator_id", "recipient_id", "cash_payer_id", "offered_product_id",
            "target_product_id", "cash_amount_minor", "cash_currency",
            "currency_exponent", "status", "created_at", "updated_at", "responded_at"
        ]
    ] as $table => $columns) {
        $available = exchange_columns($pdo, $table);
        $selected = array_values(array_intersect($columns, $available));
        $snapshots[$table] = [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn(),
            "digest" => exchange_data_digest($pdo, $table, $selected)
        ];
    }

    return $snapshots;
}

$currencyExponents = [
    "AUD" => 2, "BHD" => 3, "CAD" => 2, "CHF" => 2, "CNY" => 2, "EUR" => 2,
    "GBP" => 2, "HKD" => 2, "INR" => 2, "JPY" => 0, "KRW" => 0, "KWD" => 3,
    "NZD" => 2, "SGD" => 2, "USD" => 2, "VND" => 0
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
    abort_exchange_migration("this migration supports only SQLite.");
}
if ($projectRoot === false || $resolvedPath === false || $resolvedPath !== $expectedPath || !is_file($resolvedPath)) {
    abort_exchange_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_exchange_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 1) {
        abort_exchange_migration("SQLite foreign-key enforcement could not be enabled.");
    }
    foreach (["users", "products", "offers"] as $table) {
        if (!exchange_table_exists($pdo, $table)) {
            abort_exchange_migration("the expected {$table} table is missing.");
        }
    }
    $requiredColumns = [
        "users" => ["id"],
        "products" => [
            "id", "user_id", "name", "description", "price", "image", "created_at",
            "category", "transaction_type", "item_condition", "status"
        ],
        "offers" => [
            "id", "creator_id", "recipient_id", "cash_payer_id", "offered_product_id",
            "target_product_id", "cash_amount_minor", "cash_currency", "currency_exponent",
            "status", "created_at", "updated_at", "responded_at"
        ]
    ];
    foreach ($requiredColumns as $table => $columns) {
        if (array_diff($columns, exchange_columns($pdo, $table)) !== []) {
            abort_exchange_migration("the existing {$table} schema is unexpected.");
        }
    }
    if (
        !in_array("version", exchange_columns($pdo, "schema_migrations"), true)
        || !in_array("applied_at", exchange_columns($pdo, "schema_migrations"), true)
    ) {
        abort_exchange_migration("schema_migrations is missing or has an unexpected schema.");
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 2")->fetchColumn() !== 1) {
        abort_exchange_migration("migration version 2 must be applied before this migration.");
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 3")->fetchColumn() > 0) {
        exchange_verify_schema($pdo);
        fwrite(STDOUT, "Migration version 3 is already recorded; no database changes made.\n");
        exit(0);
    }

    foreach (["exchange_transactions", "product_reservations", "chat_rooms"] as $table) {
        if (exchange_table_exists($pdo, $table)) {
            abort_exchange_migration("{$table} exists without migration version 3.");
        }
    }
    foreach (array_keys(exchange_expected_objects()) as $name) {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE name = :name");
        $stmt->execute(["name" => $name]);
        if ($stmt->fetchColumn() !== false) {
            abort_exchange_migration("migration index {$name} already exists.");
        }
    }

    $before = exchange_snapshot($pdo);
    fwrite(STDOUT, "Type APPLY EXCHANGE TRANSACTION MIGRATION 3 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals("APPLY EXCHANGE TRANSACTION MIGRATION 3", trim($confirmation))) {
        abort_exchange_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        abort_exchange_migration("could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        abort_exchange_migration("the backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-" . gmdate("Ymd\THis\Z") . "-"
        . bin2hex(random_bytes(6)) . ".sqlite";
    $reservation = fopen($backupPath, "x");
    if ($reservation === false) {
        abort_exchange_migration("could not reserve a unique backup filename.");
    }
    fclose($reservation);
    if (!chmod($backupPath, 0600)) {
        unlink($backupPath);
        abort_exchange_migration("the backup file could not be secured.");
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
        if (
            exchange_snapshot($backupPdo) !== $before
            || $backupPdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
        ) {
            throw new RuntimeException("The backup did not verify against the inspected database.");
        }
    } catch (Throwable $e) {
        if (is_file($backupPath) && !unlink($backupPath)) {
            error_log("Failed to remove invalid exchange migration backup.");
        }
        throw $e;
    }

    $inMigration = false;
    try {
        $pdo->exec("BEGIN IMMEDIATE");
        $inMigration = true;
        if (exchange_snapshot($pdo) !== $before) {
            throw new RuntimeException("Existing marketplace data changed after backup.");
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 3")->fetchColumn() !== 0) {
            throw new RuntimeException("Migration version 3 was recorded concurrently.");
        }

        $currencyRules = exchange_currency_rules($currencyExponents);
        $pdo->exec(
            "CREATE TABLE exchange_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                offer_id INTEGER NOT NULL UNIQUE,
                creator_id INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                offered_product_id INTEGER NOT NULL,
                target_product_id INTEGER NOT NULL,
                cash_payer_id INTEGER NOT NULL,
                cash_amount_minor INTEGER NOT NULL
                    CHECK (typeof(cash_amount_minor) = 'integer' AND cash_amount_minor >= 0),
                cash_currency TEXT NULL,
                currency_exponent INTEGER NULL,
                payment_method TEXT NULL,
                payment_status TEXT NOT NULL
                    CHECK (payment_status IN ('not_required', 'awaiting_method', 'pending', 'verified', 'failed', 'refunded')),
                status TEXT NOT NULL DEFAULT 'in_progress'
                    CHECK (status IN ('in_progress', 'completed', 'cancelled')),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                CHECK (creator_id <> recipient_id),
                CHECK (offered_product_id <> target_product_id),
                CHECK (cash_payer_id = creator_id),
                CHECK (
                    (cash_amount_minor = 0 AND cash_currency IS NULL AND currency_exponent IS NULL)
                    OR (
                        cash_amount_minor > 0
                        AND typeof(currency_exponent) = 'integer'
                        AND ({$currencyRules})
                    )
                ),
                CHECK (
                    (cash_amount_minor = 0 AND payment_status = 'not_required')
                    OR (cash_amount_minor > 0 AND payment_status <> 'not_required')
                ),
                FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE RESTRICT,
                FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (cash_payer_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (offered_product_id) REFERENCES products(id) ON DELETE RESTRICT,
                FOREIGN KEY (target_product_id) REFERENCES products(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE TABLE product_reservations (
                product_id INTEGER PRIMARY KEY,
                transaction_id INTEGER NOT NULL,
                reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                FOREIGN KEY (transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE TABLE chat_rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                exchange_transaction_id INTEGER NOT NULL UNIQUE,
                creator_id INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME NULL,
                CHECK (creator_id <> recipient_id),
                FOREIGN KEY (exchange_transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT,
                FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT
            )"
        );
        foreach (exchange_expected_objects() as $name => [$table, $columns]) {
            $pdo->exec("CREATE INDEX {$name} ON {$table} (" . implode(", ", $columns) . ")");
        }
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (3)");
        exchange_verify_schema($pdo);
        if (exchange_snapshot($pdo) !== $before) {
            throw new RuntimeException("Existing marketplace data changed unexpectedly.");
        }
        $pdo->exec("COMMIT");
        $inMigration = false;
    } catch (Throwable $e) {
        if ($inMigration) {
            $pdo->exec("ROLLBACK");
        }
        throw $e;
    }

    exchange_verify_schema($pdo);
    if (exchange_snapshot($pdo) !== $before) {
        throw new RuntimeException("Post-migration preservation check failed.");
    }
    fwrite(STDOUT, "Migration version 3 applied and verified.\n");
    fwrite(STDOUT, "Users, products, and historical offers were verified unchanged.\n");
    fwrite(STDOUT, "Exchange tables, indexes, foreign keys, and integrity verified.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    if (isset($backupPath) && is_file($backupPath)) {
        fwrite(STDERR, "Verified backup retained at: {$backupPath}\n");
    }
    exit(1);
}
