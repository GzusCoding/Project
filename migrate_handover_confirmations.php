<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_handover_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function handover_migration_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $stmt->execute(["name" => $table]);

    return $stmt->fetchColumn() !== false;
}

function handover_migration_normalize_sql(string $sql): string
{
    $normalized = "";
    $state = "outside";
    $quoteEnd = "";
    $pendingWhitespace = false;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];

        if ($state === "single_line_comment") {
            $normalized .= $character;
            if ($character === "\r") {
                if ($index + 1 < $length && $sql[$index + 1] === "\n") {
                    $normalized .= $sql[++$index];
                }
                $state = "outside";
            } elseif ($character === "\n") {
                $state = "outside";
            }
            continue;
        }

        if ($state === "block_comment") {
            $normalized .= $character;
            if (
                $character === "*"
                && $index + 1 < $length
                && $sql[$index + 1] === "/"
            ) {
                $normalized .= $sql[++$index];
                $state = "outside";
            }
            continue;
        }

        if ($state === "quoted") {
            $normalized .= $character;
            if ($character === $quoteEnd) {
                if (
                    $quoteEnd !== "]"
                    && $index + 1 < $length
                    && $sql[$index + 1] === $quoteEnd
                ) {
                    $normalized .= $sql[++$index];
                } else {
                    $state = "outside";
                }
            }
            continue;
        }

        if (ctype_space($character)) {
            $pendingWhitespace = $normalized !== "";
            continue;
        }

        if ($pendingWhitespace) {
            $normalized .= " ";
            $pendingWhitespace = false;
        }

        if (
            ($character === "-" && $index + 1 < $length && $sql[$index + 1] === "-")
            || ($character === "/" && $index + 1 < $length && $sql[$index + 1] === "*")
        ) {
            $normalized .= $character . $sql[++$index];
            $state = $character === "-" ? "single_line_comment" : "block_comment";
            continue;
        }

        $normalized .= $character;
        if ($character === "[") {
            $quoteEnd = "]";
            $state = "quoted";
        } elseif ($character === "'" || $character === '"' || $character === "`") {
            $quoteEnd = $character;
            $state = "quoted";
        }
    }

    return $normalized;
}

function handover_migration_table_definition(): string
{
    return "CREATE TABLE handover_confirmations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER NOT NULL,
        confirmation_type TEXT NOT NULL CHECK (confirmation_type IN (
            'creator_handed_over_offered_item',
            'recipient_received_offered_item',
            'recipient_handed_over_requested_item',
            'creator_received_requested_item'
        )),
        confirmer_id INTEGER NOT NULL,
        confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (transaction_id, confirmation_type),
        FOREIGN KEY (transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT,
        FOREIGN KEY (confirmer_id) REFERENCES users(id) ON DELETE RESTRICT
    )";
}

function handover_migration_index_definition(): string
{
    return "CREATE INDEX idx_handover_confirmations_confirmer_time
        ON handover_confirmations (confirmer_id, confirmed_at)";
}

function handover_migration_trigger_definitions(): array
{
    return [
        "handover_confirmations_insert_guard" => "CREATE TRIGGER handover_confirmations_insert_guard
            BEFORE INSERT ON handover_confirmations
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM exchange_transactions
                WHERE id = NEW.transaction_id
                  AND status = 'in_progress'
                  AND (
                      (NEW.confirmation_type = 'creator_handed_over_offered_item'
                       AND creator_id = NEW.confirmer_id)
                      OR
                      (NEW.confirmation_type = 'recipient_received_offered_item'
                       AND recipient_id = NEW.confirmer_id)
                      OR
                      (NEW.confirmation_type = 'recipient_handed_over_requested_item'
                       AND recipient_id = NEW.confirmer_id)
                      OR
                      (NEW.confirmation_type = 'creator_received_requested_item'
                       AND creator_id = NEW.confirmer_id)
                  )
            )
            BEGIN
                SELECT RAISE(ABORT, 'handover confirmer is not an eligible participant');
            END",
        "handover_confirmations_update_guard" => "CREATE TRIGGER handover_confirmations_update_guard
            BEFORE UPDATE ON handover_confirmations
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'handover confirmations are immutable');
            END",
        "handover_confirmations_no_delete" => "CREATE TRIGGER handover_confirmations_no_delete
            BEFORE DELETE ON handover_confirmations
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'handover confirmations are retained for audit');
            END"
    ];
}

function handover_migration_trigger_snapshot(PDO $pdo): array
{
    $triggers = [];
    foreach ($pdo->query(
        "SELECT name, tbl_name, sql FROM sqlite_master
         WHERE type = 'trigger'
         ORDER BY name"
    ) as $row) {
        if (!is_string($row["sql"])) {
            throw new RuntimeException("A trigger definition could not be read.");
        }
        $triggers[$row["name"]] = [
            "table" => $row["tbl_name"],
            "sql" => handover_migration_normalize_sql($row["sql"])
        ];
    }

    return $triggers;
}

function handover_migration_verify(PDO $pdo): void
{
    $tableDefinition = $pdo->query(
        "SELECT sql FROM sqlite_master
         WHERE type = 'table' AND name = 'handover_confirmations'"
    )->fetchColumn();
    if (
        !is_string($tableDefinition)
        || handover_migration_normalize_sql($tableDefinition)
            !== handover_migration_normalize_sql(handover_migration_table_definition())
    ) {
        throw new RuntimeException("The handover_confirmations table has an unexpected schema.");
    }
    $expectedColumns = [
        ["id", "INTEGER", 0, null, 1],
        ["transaction_id", "INTEGER", 1, null, 0],
        ["confirmation_type", "TEXT", 1, null, 0],
        ["confirmer_id", "INTEGER", 1, null, 0],
        ["confirmed_at", "DATETIME", 1, "CURRENT_TIMESTAMP", 0]
    ];
    $columns = array_map(
        static fn (array $row): array => [
            $row["name"],
            strtoupper((string) $row["type"]),
            (int) $row["notnull"],
            $row["dflt_value"],
            (int) $row["pk"]
        ],
        $pdo->query('PRAGMA table_info("handover_confirmations")')->fetchAll(PDO::FETCH_ASSOC)
    );
    if ($columns !== $expectedColumns) {
        throw new RuntimeException("The handover table columns or defaults are unexpected.");
    }

    $indexes = [];
    foreach ($pdo->query('PRAGMA index_list("handover_confirmations")') as $row) {
        $quotedName = '"' . str_replace('"', '""', (string) $row["name"]) . '"';
        $indexColumns = array_column(
            $pdo->query("PRAGMA index_info({$quotedName})")->fetchAll(PDO::FETCH_ASSOC),
            "name"
        );
        $indexes[$row["name"]] = [
            "unique" => (int) $row["unique"],
            "origin" => $row["origin"],
            "partial" => (int) $row["partial"],
            "columns" => $indexColumns
        ];
    }
    ksort($indexes);
    $expectedIndexes = [
        "idx_handover_confirmations_confirmer_time" => [
            "unique" => 0,
            "origin" => "c",
            "partial" => 0,
            "columns" => ["confirmer_id", "confirmed_at"]
        ],
        "sqlite_autoindex_handover_confirmations_1" => [
            "unique" => 1,
            "origin" => "u",
            "partial" => 0,
            "columns" => ["transaction_id", "confirmation_type"]
        ]
    ];
    ksort($expectedIndexes);
    if ($indexes !== $expectedIndexes) {
        throw new RuntimeException("The handover table indexes are missing or unexpected.");
    }
    $indexDefinition = $pdo->query(
        "SELECT sql FROM sqlite_master
         WHERE type = 'index' AND name = 'idx_handover_confirmations_confirmer_time'"
    )->fetchColumn();
    if (
        !is_string($indexDefinition)
        || handover_migration_normalize_sql($indexDefinition)
            !== handover_migration_normalize_sql(handover_migration_index_definition())
    ) {
        throw new RuntimeException("The handover confirmer index definition is unexpected.");
    }

    $expectedTriggers = [];
    foreach (handover_migration_trigger_definitions() as $name => $definition) {
        $expectedTriggers[$name] = [
            "table" => "handover_confirmations",
            "sql" => handover_migration_normalize_sql($definition)
        ];
    }
    ksort($expectedTriggers);
    $actualTriggers = [];
    foreach ($pdo->query(
        "SELECT name, tbl_name, sql FROM sqlite_master
         WHERE type = 'trigger' AND tbl_name = 'handover_confirmations'
         ORDER BY name"
    ) as $row) {
        if (!is_string($row["sql"])) {
            throw new RuntimeException("A handover trigger definition could not be read.");
        }
        $actualTriggers[$row["name"]] = [
            "table" => $row["tbl_name"],
            "sql" => handover_migration_normalize_sql($row["sql"])
        ];
    }
    if ($actualTriggers !== $expectedTriggers) {
        throw new RuntimeException(
            "The handover table has missing, altered, or unexpected triggers."
        );
    }
    foreach ([
        "exchange_transactions_handover_completion_guard",
        "exchange_transactions_handover_completion_insert_guard"
    ] as $trigger) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'trigger' AND name = :name"
        );
        $stmt->execute(["name" => $trigger]);
        if ($stmt->fetchColumn() !== false) {
            throw new RuntimeException("Out-of-scope trigger {$trigger} is present.");
        }
    }

    $foreignKeys = [];
    foreach ($pdo->query('PRAGMA foreign_key_list("handover_confirmations")') as $row) {
        $foreignKeys[$row["from"]] = [
            "table" => $row["table"],
            "to" => $row["to"],
            "on_update" => strtoupper($row["on_update"]),
            "on_delete" => strtoupper($row["on_delete"]),
            "match" => strtoupper($row["match"])
        ];
    }
    ksort($foreignKeys);
    $expectedForeignKeys = [
        "confirmer_id" => [
            "table" => "users",
            "to" => "id",
            "on_update" => "NO ACTION",
            "on_delete" => "RESTRICT",
            "match" => "NONE"
        ],
        "transaction_id" => [
            "table" => "exchange_transactions",
            "to" => "id",
            "on_update" => "NO ACTION",
            "on_delete" => "RESTRICT",
            "match" => "NONE"
        ]
    ];
    if ($foreignKeys !== $expectedForeignKeys) {
        throw new RuntimeException("The handover table has unexpected restrictive foreign keys.");
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 6")->fetchColumn() !== 1) {
        throw new RuntimeException("Migration version 6 is not recorded exactly once.");
    }
}

function handover_migration_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach ([
        "users", "products", "offers", "exchange_transactions",
        "product_reservations", "chat_rooms", "chat_messages",
        "payment_evidence", "transaction_disputes"
    ] as $table) {
        if (!handover_migration_table_exists($pdo, $table)) {
            continue;
        }
        $columns = [];
        foreach ($pdo->query("PRAGMA table_info(\"{$table}\")") as $column) {
            $columns[] = $column["name"];
        }
        if ($columns === []) {
            continue;
        }
        $orderColumn = in_array("id", $columns, true) ? "id" : $columns[0];
        $quotedColumns = implode(", ", array_map(
            static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
            $columns
        ));
        $digest = hash_init("sha256");
        foreach ($pdo->query(
            "SELECT {$quotedColumns} FROM \"{$table}\" ORDER BY \"{$orderColumn}\""
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

$driver = getenv("DB_DRIVER") ?: "sqlite";
$projectRoot = realpath(__DIR__);
$expectedPath = $projectRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$configuredPath = getenv("DB_PATH") ?: $expectedPath;
$resolvedPath = realpath($configuredPath);
if ($driver !== "sqlite") {
    abort_handover_migration("this migration supports only SQLite.");
}
if (
    $projectRoot === false
    || $resolvedPath === false
    || $resolvedPath !== $expectedPath
    || !is_file($resolvedPath)
) {
    abort_handover_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_handover_migration("PDO SQLite and SQLite3 backup support are required.");
}

$backupPath = null;
$backupVerified = false;
try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 1) {
        throw new RuntimeException("SQLite foreign-key enforcement could not be enabled.");
    }
    foreach (["schema_migrations", "users", "exchange_transactions"] as $table) {
        if (!handover_migration_table_exists($pdo, $table)) {
            throw new RuntimeException("The expected {$table} table is missing.");
        }
    }
    if (
        (int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 5"
        )->fetchColumn() !== 1
    ) {
        throw new RuntimeException("Migration version 5 must be applied exactly once.");
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 6")->fetchColumn() > 0) {
        handover_migration_verify($pdo);
        fwrite(STDOUT, "Migration version 6 is already recorded; no database changes made.\n");
        exit(0);
    }
    if (handover_migration_table_exists($pdo, "handover_confirmations")) {
        throw new RuntimeException("handover_confirmations exists without migration version 6.");
    }
    foreach (array_merge(
        ["idx_handover_confirmations_confirmer_time"],
        array_keys(handover_migration_trigger_definitions()),
        [
            "exchange_transactions_handover_completion_guard",
            "exchange_transactions_handover_completion_insert_guard"
        ]
    ) as $object) {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE name = :name");
        $stmt->execute(["name" => $object]);
        if ($stmt->fetchColumn() !== false) {
            throw new RuntimeException("Migration object {$object} already exists.");
        }
    }

    $before = handover_migration_snapshot($pdo);
    fwrite(STDOUT, "Type APPLY HANDOVER CONFIRMATIONS MIGRATION 6 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if (
        $confirmation === false
        || !hash_equals("APPLY HANDOVER CONFIRMATIONS MIGRATION 6", trim($confirmation))
    ) {
        abort_handover_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        throw new RuntimeException("Could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        throw new RuntimeException("The backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-v6-" . gmdate("Ymd\THis\Z") . "-"
        . bin2hex(random_bytes(6)) . ".sqlite";
    $reservation = fopen($backupPath, "x");
    if ($reservation === false) {
        throw new RuntimeException("Could not reserve a unique backup filename.");
    }
    fclose($reservation);
    if (!chmod($backupPath, 0600)) {
        throw new RuntimeException("The backup file could not be secured.");
    }

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
        handover_migration_snapshot($backup) !== $before
        || $backup->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
        || $backup->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
    ) {
        throw new RuntimeException("The backup did not verify against the inspected database.");
    }
    $backupVerified = true;

    $inMigration = false;
    try {
        $pdo->exec("BEGIN IMMEDIATE");
        $inMigration = true;
        if (handover_migration_snapshot($pdo) !== $before) {
            throw new RuntimeException("Existing marketplace data changed after backup.");
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 6")->fetchColumn() !== 0) {
            throw new RuntimeException("Migration version 6 was recorded concurrently.");
        }
        $beforeTriggers = handover_migration_trigger_snapshot($pdo);
        $pdo->exec(handover_migration_table_definition());
        $pdo->exec(handover_migration_index_definition());
        foreach (handover_migration_trigger_definitions() as $definition) {
            $pdo->exec($definition);
        }
        $expectedTriggerInventory = $beforeTriggers;
        foreach (handover_migration_trigger_definitions() as $name => $definition) {
            if (isset($expectedTriggerInventory[$name])) {
                throw new RuntimeException("Migration trigger {$name} already exists.");
            }
            $expectedTriggerInventory[$name] = [
                "table" => "handover_confirmations",
                "sql" => handover_migration_normalize_sql($definition)
            ];
        }
        ksort($expectedTriggerInventory);
        if (handover_migration_trigger_snapshot($pdo) !== $expectedTriggerInventory) {
            throw new RuntimeException("Migration created or altered an unexpected trigger.");
        }
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (6)");
        if ($pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []) {
            throw new RuntimeException("foreign_key_check reported violations during migration.");
        }
        if ($pdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
            throw new RuntimeException("SQLite integrity_check did not return ok during migration.");
        }
        if (handover_migration_snapshot($pdo) !== $before) {
            throw new RuntimeException("Existing marketplace data changed unexpectedly.");
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM handover_confirmations")->fetchColumn() !== 0) {
            throw new RuntimeException("Migration unexpectedly created handover confirmations.");
        }
        $pdo->exec("COMMIT");
        $inMigration = false;
    } catch (Throwable $e) {
        if ($inMigration) {
            $pdo->exec("ROLLBACK");
        }
        throw $e;
    }

    handover_migration_verify($pdo);
    if (handover_migration_snapshot($pdo) !== $before) {
        throw new RuntimeException("Post-migration preservation check failed.");
    }
    fwrite(STDOUT, "Migration version 6 applied and verified.\n");
    fwrite(STDOUT, "Existing data and chat were preserved; no confirmations were backfilled.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    if ($backupVerified && $backupPath !== null && is_file($backupPath)) {
        fwrite(STDERR, "Verified backup retained at: {$backupPath}\n");
    } elseif ($backupPath !== null && is_file($backupPath) && !unlink($backupPath)) {
        fwrite(STDERR, "Could not remove the incomplete backup file.\n");
    }
    exit(1);
}
