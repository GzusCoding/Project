<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_exchange_cash_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function exchange_cash_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $stmt->execute(["name" => $table]);

    return $stmt->fetchColumn() !== false;
}

function exchange_cash_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query("PRAGMA table_info(\"{$table}\")") as $row) {
        $columns[] = $row["name"];
    }

    return $columns;
}

function exchange_cash_snapshot(PDO $pdo, ?array $baselineColumns = null): array
{
    $snapshot = [];
    foreach ($pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if ($baselineColumns !== null && !isset($baselineColumns[$table])) {
            continue;
        }
        $columns = $baselineColumns[$table] ?? exchange_cash_columns($pdo, $table);
        $quotedColumns = implode(", ", array_map(
            static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
            $columns
        ));
        $snapshot[$table] = [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn(),
            "digest" => hash("sha256", implode("\n", array_map(
                static fn (array $row): string => json_encode(
                    array_values($row),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                ),
                $pdo->query("SELECT {$quotedColumns} FROM \"{$table}\" ORDER BY rowid")->fetchAll(PDO::FETCH_NUM)
            )))
        ];
    }

    return $snapshot;
}

function exchange_cash_replace_once(string $sql, string $search, string $replace, string $label): string
{
    if (substr_count($sql, $search) !== 1) {
        throw new RuntimeException("The expected {$label} definition was not found exactly once.");
    }

    return str_replace($search, $replace, $sql);
}

function exchange_cash_create_sql(PDO $pdo, string $table): string
{
    $stmt = $pdo->prepare(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $stmt->execute(["name" => $table]);
    $sql = $stmt->fetchColumn();
    if (!is_string($sql) || $sql === "") {
        throw new RuntimeException("The {$table} table definition is unavailable.");
    }

    return $sql;
}

function exchange_cash_object_sql(PDO $pdo, string $type, string $table): array
{
    $stmt = $pdo->prepare(
        "SELECT sql FROM sqlite_master
         WHERE type = :type AND tbl_name = :table AND sql IS NOT NULL
         ORDER BY name"
    );
    $stmt->execute(["type" => $type, "table" => $table]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function exchange_cash_rebuild_sql(string $sql, string $table, string $temporaryName): string
{
    $updated = preg_replace(
        '/\ACREATE TABLE "?'. preg_quote($table, '/') .'"?\s*\(/',
        "CREATE TABLE {$temporaryName} (",
        $sql,
        1,
        $count
    );
    if (!is_string($updated) || $count !== 1) {
        throw new RuntimeException("Could not prepare the {$table} table for rebuilding.");
    }

    return $updated;
}

function exchange_cash_copy_table(PDO $pdo, string $source, string $target, array $columns): void
{
    $quoted = implode(", ", array_map(
        static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
        $columns
    ));
    $pdo->exec(
        "INSERT INTO \"{$target}\" ({$quoted}) SELECT {$quoted} FROM \"{$source}\""
    );
}

function exchange_cash_verify(PDO $pdo): void
{
    $offerColumns = exchange_cash_columns($pdo, "offers");
    foreach (["offered_price_minor", "target_price_minor"] as $column) {
        if (!in_array($column, $offerColumns, true)) {
            throw new RuntimeException("The offers.{$column} column is missing.");
        }
    }
    foreach ([
        "idx_offers_creator_status",
        "idx_offers_recipient_status",
        "idx_offers_offered_product_status",
        "idx_offers_target_product_status",
        "idx_offers_created_at",
        "idx_exchange_transactions_creator_status",
        "idx_exchange_transactions_recipient_status",
        "idx_exchange_transactions_created_at"
    ] as $name) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :name"
        );
        $stmt->execute(["name" => $name]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Required index {$name} is missing.");
        }
    }

    $requiredTriggers = [
        "payment_evidence_insert_guard",
        "payment_evidence_update_guard",
        "payment_evidence_no_delete",
        "exchange_transactions_payment_guard",
        "exchange_transactions_dispute_guard",
        "exchange_completion_guard"
    ];
    foreach ($requiredTriggers as $name) {
        $stmt = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = :name"
        );
        $stmt->execute(["name" => $name]);
        $sql = $stmt->fetchColumn();
        if (!is_string($sql)) {
            throw new RuntimeException("Required trigger {$name} is missing.");
        }
        if ($name === "payment_evidence_insert_guard" && !str_contains($sql, "cash_payer_id = NEW.submitted_by")) {
            throw new RuntimeException("Payment evidence is not restricted to the designated payer.");
        }
        if ($name === "payment_evidence_update_guard" && !str_contains($sql, "NEW.verified_by IN (creator_id, recipient_id)")) {
            throw new RuntimeException("Payment evidence review is not restricted to the other participant.");
        }
        if ($name === "exchange_transactions_payment_guard"
            && (!str_contains($sql, "submitted_by = OLD.cash_payer_id")
                || !str_contains($sql, "verified_by IN (OLD.creator_id, OLD.recipient_id)"))) {
            throw new RuntimeException("Payment status transitions do not enforce the designated payer and reviewer.");
        }
    }

    $versionCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 12"
    )->fetchColumn();
    if ($versionCount !== 1) {
        throw new RuntimeException("Migration version 12 is not recorded exactly once.");
    }
    if ($pdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
        throw new RuntimeException("SQLite integrity_check did not return ok.");
    }
    if ($pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []) {
        throw new RuntimeException("foreign_key_check reported violations.");
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
    abort_exchange_cash_migration("this migration supports only SQLite.");
}
if ($projectRoot === false || $resolvedPath === false || $resolvedPath !== $expectedPath || !is_file($resolvedPath)) {
    abort_exchange_cash_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_exchange_cash_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 1) {
        abort_exchange_cash_migration("SQLite foreign-key enforcement could not be enabled.");
    }
    foreach (["schema_migrations", "offers", "exchange_transactions", "payment_evidence"] as $table) {
        if (!exchange_cash_table_exists($pdo, $table)) {
            abort_exchange_cash_migration("the expected {$table} table is missing.");
        }
    }
    $versionEleven = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 11"
    )->fetchColumn();
    $versionTwelve = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 12"
    )->fetchColumn();
    if ($versionTwelve === 1) {
        exchange_cash_verify($pdo);
        fwrite(STDOUT, "Migration version 12 is already applied and verified.\n");
        exit(0);
    }
    if ($versionEleven !== 1 || $versionTwelve !== 0) {
        abort_exchange_cash_migration("version 11 must be recorded once and version 12 must not be recorded.");
    }
    foreach ([
        "offers" => [
            "id", "creator_id", "recipient_id", "cash_payer_id", "offered_product_id",
            "target_product_id", "cash_amount_minor", "cash_currency", "currency_exponent"
        ],
        "exchange_transactions" => [
            "id", "offer_id", "creator_id", "recipient_id", "cash_payer_id",
            "cash_amount_minor", "cash_currency", "currency_exponent", "payment_method",
            "payment_status", "status"
        ]
    ] as $table => $required) {
        if (array_diff($required, exchange_cash_columns($pdo, $table)) !== []) {
            abort_exchange_cash_migration("the {$table} table does not match the expected version 11 schema.");
        }
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        abort_exchange_cash_migration("the backup directory could not be created.");
    }
    if (!chmod($backupDirectory, 0700)) {
        abort_exchange_cash_migration("the backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-v12-" . gmdate("Ymd\THis\Z") . "-" . bin2hex(random_bytes(5));
    $source = new SQLite3($resolvedPath, SQLITE3_OPEN_READONLY);
    $backup = new SQLite3($backupPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    if (!$source->backup($backup)) {
        $source->close();
        $backup->close();
        abort_exchange_cash_migration("SQLite could not create the pre-migration backup.");
    }
    $source->close();
    $backup->close();
    if (!chmod($backupPath, 0600)) {
        abort_exchange_cash_migration("the pre-migration backup could not be secured.");
    }
    $backupCheck = new SQLite3($backupPath, SQLITE3_OPEN_READONLY);
    $backupIntegrity = $backupCheck->querySingle("PRAGMA integrity_check");
    $backupCheck->close();
    if ($backupIntegrity !== "ok") {
        abort_exchange_cash_migration("the pre-migration backup did not pass integrity_check.");
    }

    $before = exchange_cash_snapshot($pdo);
    $baselineColumns = [];
    foreach ($before as $table => $_snapshot) {
        $baselineColumns[$table] = exchange_cash_columns($pdo, $table);
    }
    $offerOldColumns = exchange_cash_columns($pdo, "offers");
    $offerIndexes = exchange_cash_object_sql($pdo, "index", "offers");
    $transactionOldColumns = exchange_cash_columns($pdo, "exchange_transactions");
    $transactionIndexes = exchange_cash_object_sql($pdo, "index", "exchange_transactions");
    $allTriggers = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $newOfferSql = exchange_cash_create_sql($pdo, "offers");
    $newOfferSql = exchange_cash_replace_once(
        $newOfferSql,
        "CHECK (cash_payer_id = creator_id)",
        "CHECK (cash_payer_id IN (creator_id, recipient_id))",
        "offer cash payer constraint"
    );
    $newOfferSql = exchange_cash_replace_once(
        $newOfferSql,
        "(cash_currency = 'VND' AND currency_exponent = 0))",
        "(cash_currency = 'VND' AND currency_exponent = 0) OR (cash_currency = 'THB' AND currency_exponent = 2))",
        "offer currency rule"
    );
    $newOfferSql = exchange_cash_replace_once(
        $newOfferSql,
        "responded_at DATETIME NULL,",
        "responded_at DATETIME NULL,\n        offered_price_minor INTEGER NULL,\n        target_price_minor INTEGER NULL,",
        "offer price snapshot columns"
    );
    $newOfferSql = exchange_cash_replace_once(
        $newOfferSql,
        "CHECK (creator_id <> recipient_id),",
        "CHECK (\n            (offered_price_minor IS NULL AND target_price_minor IS NULL)\n            OR (\n                typeof(offered_price_minor) = 'integer' AND offered_price_minor >= 0\n                AND typeof(target_price_minor) = 'integer' AND target_price_minor >= 0\n            )\n        ),\n        CHECK (creator_id <> recipient_id),",
        "offer price snapshot columns"
    );
    $newOfferSql = exchange_cash_rebuild_sql($newOfferSql, "offers", "offers_v12_new");

    $newTransactionSql = exchange_cash_create_sql($pdo, "exchange_transactions");
    $newTransactionSql = exchange_cash_replace_once(
        $newTransactionSql,
        "CHECK (cash_payer_id = creator_id)",
        "CHECK (cash_payer_id IN (creator_id, recipient_id))",
        "transaction cash payer constraint"
    );
    $newTransactionSql = exchange_cash_replace_once(
        $newTransactionSql,
        "(cash_currency IN ('BHD', 'KWD') AND currency_exponent = 3))",
        "(cash_currency IN ('BHD', 'KWD') AND currency_exponent = 3)\n                          OR (cash_currency = 'THB' AND currency_exponent = 2))",
        "transaction currency rule"
    );
    $newTransactionSql = exchange_cash_rebuild_sql(
        $newTransactionSql,
        "exchange_transactions",
        "exchange_transactions_v12_new"
    );

    $pdo->exec("PRAGMA foreign_keys = OFF");
    if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 0) {
        throw new RuntimeException("SQLite foreign-key enforcement could not be suspended for the table rebuild.");
    }
    $pdo->exec("BEGIN IMMEDIATE");
    try {
        if (exchange_cash_snapshot($pdo) !== $before) {
            throw new RuntimeException("Existing marketplace data changed after the backup.");
        }
        foreach ($allTriggers as $sql) {
            if (preg_match('/\ACREATE TRIGGER\s+"?([^"\s]+)"?/i', trim($sql), $matches) !== 1) {
                throw new RuntimeException("A database trigger has an unexpected definition.");
            }
            $pdo->exec('DROP TRIGGER "' . str_replace('"', '""', $matches[1]) . '"');
        }
        $pdo->exec($newOfferSql);
        exchange_cash_copy_table($pdo, "offers", "offers_v12_new", $offerOldColumns);
        $pdo->exec("DROP TABLE offers");
        $pdo->exec("ALTER TABLE offers_v12_new RENAME TO offers");

        $pdo->exec($newTransactionSql);
        exchange_cash_copy_table($pdo, "exchange_transactions", "exchange_transactions_v12_new", $transactionOldColumns);
        $pdo->exec("DROP TABLE exchange_transactions");
        $pdo->exec("ALTER TABLE exchange_transactions_v12_new RENAME TO exchange_transactions");

        foreach (array_merge($offerIndexes, $transactionIndexes) as $sql) {
            $pdo->exec($sql);
        }

        foreach ($allTriggers as $sql) {
            if (str_contains($sql, "CREATE TRIGGER payment_evidence_insert_guard")) {
                $sql = exchange_cash_replace_once(
                    $sql,
                    "creator_id = NEW.submitted_by",
                    "cash_payer_id = NEW.submitted_by",
                    "payment evidence payer guard"
                );
            } elseif (str_contains($sql, "CREATE TRIGGER payment_evidence_update_guard")) {
                $sql = exchange_cash_replace_once(
                    $sql,
                    "recipient_id = NEW.verified_by",
                    "NEW.verified_by IN (creator_id, recipient_id)\n                     AND cash_payer_id <> NEW.verified_by",
                    "payment evidence reviewer guard"
                );
            } elseif (str_contains($sql, "CREATE TRIGGER exchange_transactions_payment_guard")) {
                $sql = exchange_cash_replace_once(
                    $sql,
                    "submitted_by = OLD.creator_id",
                    "submitted_by = OLD.cash_payer_id",
                    "transaction payment submitter guard"
                );
                $sql = exchange_cash_replace_once(
                    $sql,
                    "verified_by = OLD.recipient_id",
                    "verified_by <> OLD.cash_payer_id\n                           AND verified_by IN (OLD.creator_id, OLD.recipient_id)",
                    "transaction payment reviewer guard"
                );
            }
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (12)");
        if (exchange_cash_snapshot($pdo, $baselineColumns) !== $before) {
            throw new RuntimeException("The migration changed pre-existing marketplace records.");
        }
        $pdo->exec("PRAGMA foreign_keys = ON");
        exchange_cash_verify($pdo);
        $pdo->exec("COMMIT");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec("ROLLBACK");
        }
        throw $e;
    } finally {
        $pdo->exec("PRAGMA foreign_keys = ON");
    }

    fwrite(STDOUT, "Migration version 12 applied and verified.\n");
    fwrite(STDOUT, "Automatic THB cash differences and payer/reviewer rules are ready.\n");
    fwrite(STDOUT, "Verified backup: {$backupPath}\n");
} catch (Throwable $e) {
    error_log("Exchange cash difference migration failed: " . $e->getMessage());
    abort_exchange_cash_migration($e->getMessage());
}
