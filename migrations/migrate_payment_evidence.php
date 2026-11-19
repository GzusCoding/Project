<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_payment_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function payment_migration_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $stmt->execute(["name" => $table]);

    return $stmt->fetchColumn() !== false;
}

function payment_migration_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query("PRAGMA table_info(\"{$table}\")") as $row) {
        $columns[] = $row["name"];
    }

    return $columns;
}

function payment_migration_digest(PDO $pdo, string $table, array $columns): string
{
    $quoted = array_map(
        static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
        $columns
    );
    $order = in_array("id", $columns, true) ? '"id"' : '"' . $columns[0] . '"';
    $digest = hash_init("sha256");
    foreach ($pdo->query(
        "SELECT " . implode(", ", $quoted) . " FROM \"{$table}\" ORDER BY {$order}"
    ) as $row) {
        hash_update($digest, json_encode(
            array_map(static fn (string $column) => $row[$column], $columns),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) . "\n");
    }

    return hash_final($digest);
}

function payment_migration_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach ([
        "users" => ["id", "name", "email", "password", "created_at", "role"],
        "products" => [
            "id", "user_id", "name", "description", "price", "image",
            "created_at", "category", "transaction_type", "item_condition", "status"
        ],
        "offers" => [
            "id", "creator_id", "recipient_id", "cash_payer_id", "offered_product_id",
            "target_product_id", "cash_amount_minor", "cash_currency",
            "currency_exponent", "status", "created_at", "updated_at", "responded_at"
        ],
        "exchange_transactions" => [
            "id", "offer_id", "creator_id", "recipient_id", "offered_product_id",
            "target_product_id", "cash_payer_id", "cash_amount_minor", "cash_currency",
            "currency_exponent", "payment_method", "payment_status", "status",
            "created_at", "updated_at", "completed_at", "cancelled_at"
        ],
        "product_reservations" => ["product_id", "transaction_id", "reserved_at"],
        "chat_rooms" => [
            "id", "exchange_transaction_id", "creator_id", "recipient_id", "created_at", "closed_at"
        ],
        "chat_messages" => ["id", "room_id", "sender_id", "message_body", "created_at"]
    ] as $table => $columns) {
        if (!payment_migration_table_exists($pdo, $table)) {
            continue;
        }
        $available = payment_migration_columns($pdo, $table);
        $selected = array_values(array_intersect($columns, $available));
        $snapshot[$table] = [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn(),
            "digest" => payment_migration_digest($pdo, $table, $selected)
        ];
    }

    return $snapshot;
}

function payment_migration_verify(PDO $pdo): void
{
    $expectedColumns = [
        "payment_evidence" => [
            "id", "transaction_id", "submitted_by", "payment_method", "amount_minor",
            "currency", "currency_exponent", "evidence_storage_key", "status",
            "submitted_at", "verified_by", "verified_at", "review_note"
        ],
        "transaction_disputes" => [
            "id", "transaction_id", "reported_by", "reason", "reported_at",
            "resolved_by", "resolution", "resolution_reason", "resolved_at"
        ]
    ];
    foreach ($expectedColumns as $table => $columns) {
        if (!payment_migration_table_exists($pdo, $table) || payment_migration_columns($pdo, $table) !== $columns) {
            throw new RuntimeException("The {$table} table is missing or has an unexpected schema.");
        }
    }

    $expectedIndexes = [
        "idx_exchange_transactions_creator_status" => ["exchange_transactions", ["creator_id", "status"]],
        "idx_exchange_transactions_recipient_status" => ["exchange_transactions", ["recipient_id", "status"]],
        "idx_exchange_transactions_created_at" => ["exchange_transactions", ["created_at"]],
        "idx_payment_evidence_transaction_status" => ["payment_evidence", ["transaction_id", "status", "id"]],
        "idx_payment_evidence_submitted_by" => ["payment_evidence", ["submitted_by", "submitted_at"]],
        "idx_transaction_disputes_transaction" => ["transaction_disputes", ["transaction_id", "reported_at"]],
        "idx_transaction_disputes_open" => ["transaction_disputes", ["transaction_id"]]
    ];
    foreach ($expectedIndexes as $name => [$table, $columns]) {
        $stmt = $pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = :name"
        );
        $stmt->execute(["name" => $name]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Required index {$name} is missing.");
        }
        $actual = [];
        foreach ($pdo->query("PRAGMA index_info(\"{$name}\")") as $row) {
            $actual[] = $row["name"];
        }
        if ($actual !== $columns) {
            throw new RuntimeException("Index {$name} has unexpected columns.");
        }
        $tableForIndex = $pdo->prepare(
            "SELECT tbl_name FROM sqlite_master WHERE type = 'index' AND name = :name"
        );
        $tableForIndex->execute(["name" => $name]);
        if ($tableForIndex->fetchColumn() !== $table) {
            throw new RuntimeException("Index {$name} belongs to an unexpected table.");
        }
    }
    $requiredTriggers = [
        "payment_evidence_insert_guard",
        "payment_evidence_update_guard",
        "payment_evidence_no_delete",
        "exchange_transactions_payment_guard",
        "exchange_transactions_dispute_guard",
        "transaction_disputes_insert_guard",
        "transaction_disputes_update_guard",
        "transaction_disputes_no_delete"
    ];
    foreach ($requiredTriggers as $name) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'trigger' AND name = :name"
        );
        $stmt->execute(["name" => $name]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Required trigger {$name} is missing.");
        }
    }
    foreach ([
        "payment_evidence" => [
            "transaction_id" => "exchange_transactions",
            "submitted_by" => "users",
            "verified_by" => "users"
        ],
        "transaction_disputes" => [
            "transaction_id" => "exchange_transactions",
            "reported_by" => "users",
            "resolved_by" => "users"
        ]
    ] as $table => $expectedForeignKeys) {
        $actualForeignKeys = [];
        foreach ($pdo->query("PRAGMA foreign_key_list(\"{$table}\")") as $foreignKey) {
            $actualForeignKeys[$foreignKey["from"]] = [
                "table" => $foreignKey["table"],
                "to" => $foreignKey["to"],
                "on_delete" => strtoupper($foreignKey["on_delete"])
            ];
        }
        ksort($actualForeignKeys);
        $expected = [];
        foreach ($expectedForeignKeys as $column => $referencedTable) {
            $expected[$column] = [
                "table" => $referencedTable,
                "to" => "id",
                "on_delete" => "RESTRICT"
            ];
        }
        ksort($expected);
        if ($actualForeignKeys !== $expected) {
            throw new RuntimeException("The {$table} table has unexpected restrictive foreign keys.");
        }
    }

    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 5"
    )->fetchColumn() !== 1) {
        throw new RuntimeException("Migration version 5 is not recorded exactly once.");
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
    abort_payment_migration("this migration supports only SQLite.");
}
if ($projectRoot === false || $resolvedPath === false || $resolvedPath !== $expectedPath || !is_file($resolvedPath)) {
    abort_payment_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_payment_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 1) {
        abort_payment_migration("SQLite foreign-key enforcement could not be enabled.");
    }
    foreach ([
        "users", "exchange_transactions", "product_reservations",
        "chat_rooms", "chat_messages", "schema_migrations"
    ] as $table) {
        if (!payment_migration_table_exists($pdo, $table)) {
            abort_payment_migration("the expected {$table} table is missing.");
        }
    }
    $requiredColumns = [
        "users" => ["id", "role"],
        "exchange_transactions" => [
            "id", "offer_id", "creator_id", "recipient_id", "offered_product_id",
            "target_product_id", "cash_payer_id", "cash_amount_minor", "cash_currency",
            "currency_exponent", "payment_method", "payment_status", "status",
            "created_at", "updated_at", "completed_at", "cancelled_at"
        ],
        "product_reservations" => ["product_id", "transaction_id", "reserved_at"],
        "chat_rooms" => [
            "id", "exchange_transaction_id", "creator_id", "recipient_id", "created_at", "closed_at"
        ],
        "chat_messages" => ["id", "room_id", "sender_id", "message_body", "created_at"],
        "schema_migrations" => ["version", "applied_at"]
    ];
    foreach ($requiredColumns as $table => $columns) {
        if (array_diff($columns, payment_migration_columns($pdo, $table)) !== []) {
            abort_payment_migration("the existing {$table} schema is unexpected.");
        }
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 4")->fetchColumn() !== 1) {
        abort_payment_migration("migration version 4 must be applied before this migration.");
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 5")->fetchColumn() > 0) {
        payment_migration_verify($pdo);
        fwrite(STDOUT, "Migration version 5 is already recorded; no database changes made.\n");
        exit(0);
    }
    foreach (["payment_evidence", "transaction_disputes"] as $table) {
        if (payment_migration_table_exists($pdo, $table)) {
            abort_payment_migration("{$table} exists without migration version 5.");
        }
    }

    $unsupported = (int) $pdo->query(
        "SELECT COUNT(*) FROM exchange_transactions
         WHERE status NOT IN ('in_progress', 'completed', 'cancelled')
            OR payment_status NOT IN (
                'not_required', 'awaiting_method', 'awaiting_payment',
                'submitted', 'verified', 'rejected'
            )
            OR (cash_amount_minor = 0 AND (
                payment_method IS NOT NULL OR payment_status <> 'not_required'
            ))
            OR (status = 'completed' AND cash_amount_minor > 0 AND payment_status <> 'verified')
            OR (cash_amount_minor > 0 AND (
                payment_status NOT IN (
                    'awaiting_method', 'awaiting_payment', 'submitted', 'verified', 'rejected'
                )
                OR
                (payment_status = 'awaiting_method' AND payment_method IS NOT NULL)
                OR (
                    payment_status <> 'awaiting_method'
                    AND (
                        payment_method IS NULL
                        OR payment_method NOT IN ('promptpay', 'bank_transfer', 'cash_on_handover')
                    )
                )
            ))"
    )->fetchColumn();
    if ($unsupported !== 0) {
        abort_payment_migration(
            "existing exchange transactions contain legacy or inconsistent payment values; "
            . "no values can be changed without violating the preservation requirement."
        );
    }

    $existingObjects = $pdo->query(
        "SELECT type, name, sql FROM sqlite_master
         WHERE tbl_name = 'exchange_transactions'
           AND type IN ('index', 'trigger')
           AND sql IS NOT NULL
         ORDER BY type, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ([
        "idx_payment_evidence_transaction_status",
        "idx_payment_evidence_submitted_by",
        "idx_transaction_disputes_transaction",
        "idx_transaction_disputes_open",
        "payment_evidence_insert_guard",
        "payment_evidence_update_guard",
        "payment_evidence_no_delete",
        "exchange_transactions_payment_guard",
        "exchange_transactions_dispute_guard",
        "transaction_disputes_insert_guard",
        "transaction_disputes_update_guard",
        "transaction_disputes_no_delete"
    ] as $objectName) {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE name = :name");
        $stmt->execute(["name" => $objectName]);
        if ($stmt->fetchColumn() !== false) {
            abort_payment_migration("migration object {$objectName} already exists.");
        }
    }

    $before = payment_migration_snapshot($pdo);
    $sequenceStmt = $pdo->prepare(
        "SELECT seq FROM sqlite_sequence WHERE name = 'exchange_transactions'"
    );
    $sequenceStmt->execute();
    $oldSequence = $sequenceStmt->fetchColumn();
    $sequenceStmt->closeCursor();
    $sequence = $oldSequence === false ? 0 : (int) $oldSequence;

    fwrite(STDOUT, "Type APPLY PAYMENT EVIDENCE MIGRATION 5 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals("APPLY PAYMENT EVIDENCE MIGRATION 5", trim($confirmation))) {
        abort_payment_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        abort_payment_migration("could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        abort_payment_migration("the backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-" . gmdate("Ymd\THis\Z") . "-"
        . bin2hex(random_bytes(6)) . ".sqlite";
    $reservation = fopen($backupPath, "x");
    if ($reservation === false) {
        abort_payment_migration("could not reserve a unique backup filename.");
    }
    fclose($reservation);
    if (!chmod($backupPath, 0600)) {
        unlink($backupPath);
        abort_payment_migration("the backup file could not be secured.");
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
            payment_migration_snapshot($backupPdo) !== $before
            || $backupPdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
            || $backupPdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
        ) {
            throw new RuntimeException("The backup did not verify against the inspected database.");
        }
    } catch (Throwable $e) {
        if (is_file($backupPath) && !unlink($backupPath)) {
            error_log("Failed to remove invalid payment migration backup.");
        }
        throw $e;
    }

    $foreignKeysDisabled = false;
    $inMigration = false;
    try {
        $pdo->exec("PRAGMA foreign_keys = OFF");
        $foreignKeysDisabled = true;
        if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 0) {
            throw new RuntimeException("SQLite foreign keys could not be disabled for the table rebuild.");
        }
        $pdo->exec("BEGIN IMMEDIATE");
        $inMigration = true;
        if (payment_migration_snapshot($pdo) !== $before) {
            throw new RuntimeException("Existing marketplace data changed after backup.");
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 5")->fetchColumn() !== 0) {
            throw new RuntimeException("Migration version 5 was recorded concurrently.");
        }

        $pdo->exec(
            "CREATE TABLE exchange_transactions_new (
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
                payment_method TEXT NULL
                    CHECK (payment_method IS NULL OR payment_method IN (
                        'promptpay', 'bank_transfer', 'cash_on_handover'
                    )),
                payment_status TEXT NOT NULL
                    CHECK (payment_status IN (
                        'not_required', 'awaiting_method', 'awaiting_payment',
                        'submitted', 'verified', 'rejected'
                    )),
                status TEXT NOT NULL DEFAULT 'in_progress'
                    CHECK (status IN ('in_progress', 'disputed', 'completed', 'cancelled')),
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
                        AND ((cash_currency IN ('JPY', 'KRW', 'VND') AND currency_exponent = 0)
                          OR (cash_currency IN (
                                'AUD', 'CAD', 'CHF', 'CNY', 'EUR', 'GBP', 'HKD',
                                'INR', 'NZD', 'SGD', 'USD'
                             ) AND currency_exponent = 2)
                          OR (cash_currency IN ('BHD', 'KWD') AND currency_exponent = 3))
                    )
                ),
                CHECK (
                    (cash_amount_minor = 0 AND payment_method IS NULL AND payment_status = 'not_required')
                    OR (
                        cash_amount_minor > 0
                        AND payment_status = 'awaiting_method'
                        AND payment_method IS NULL
                    )
                    OR (
                        cash_amount_minor > 0
                        AND payment_status IN ('awaiting_payment', 'submitted', 'verified', 'rejected')
                        AND payment_method IN ('promptpay', 'bank_transfer', 'cash_on_handover')
                    )
                ),
                CHECK (status <> 'completed' OR payment_status IN ('not_required', 'verified')),
                FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE RESTRICT,
                FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (cash_payer_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (offered_product_id) REFERENCES products(id) ON DELETE RESTRICT,
                FOREIGN KEY (target_product_id) REFERENCES products(id) ON DELETE RESTRICT
            )"
        );
        $transactionColumns = [
            "id", "offer_id", "creator_id", "recipient_id", "offered_product_id",
            "target_product_id", "cash_payer_id", "cash_amount_minor", "cash_currency",
            "currency_exponent", "payment_method", "payment_status", "status",
            "created_at", "updated_at", "completed_at", "cancelled_at"
        ];
        $quotedColumns = implode(", ", array_map(
            static fn (string $column): string => '"' . $column . '"',
            $transactionColumns
        ));
        $pdo->exec(
            "INSERT INTO exchange_transactions_new ({$quotedColumns})
             SELECT {$quotedColumns} FROM exchange_transactions"
        );
        $pdo->exec("DROP TABLE exchange_transactions");
        $pdo->exec("ALTER TABLE exchange_transactions_new RENAME TO exchange_transactions");
        foreach ($existingObjects as $object) {
            $pdo->exec($object["sql"]);
        }
        $pdo->exec(
            "UPDATE sqlite_sequence
             SET seq = MAX(seq, {$sequence})
             WHERE name = 'exchange_transactions'"
        );
        if ((int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_sequence WHERE name = 'exchange_transactions'"
        )->fetchColumn() === 0 && $sequence > 0) {
            $insertSequence = $pdo->prepare(
                "INSERT INTO sqlite_sequence (name, seq) VALUES ('exchange_transactions', :seq)"
            );
            $insertSequence->execute(["seq" => $sequence]);
        }

        $pdo->exec(
            "CREATE TABLE payment_evidence (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_id INTEGER NOT NULL,
                submitted_by INTEGER NOT NULL,
                payment_method TEXT NOT NULL
                    CHECK (payment_method IN ('promptpay', 'bank_transfer', 'cash_on_handover')),
                amount_minor INTEGER NOT NULL
                    CHECK (typeof(amount_minor) = 'integer' AND amount_minor > 0),
                currency TEXT NOT NULL CHECK (length(currency) = 3),
                currency_exponent INTEGER NOT NULL CHECK (typeof(currency_exponent) = 'integer'),
                evidence_storage_key TEXT NULL
                    CHECK (
                        evidence_storage_key IS NULL
                        OR (
                            length(evidence_storage_key) = 64
                            AND evidence_storage_key NOT GLOB '*[^0-9a-f]*'
                        )
                    ),
                status TEXT NOT NULL CHECK (status IN ('submitted', 'verified', 'rejected')),
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                verified_by INTEGER NULL,
                verified_at DATETIME NULL,
                review_note TEXT NULL CHECK (review_note IS NULL OR length(review_note) <= 2000),
                CHECK (
                    (payment_method = 'cash_on_handover' AND evidence_storage_key IS NULL)
                    OR (payment_method IN ('promptpay', 'bank_transfer') AND evidence_storage_key IS NOT NULL)
                ),
                CHECK (
                    (status = 'submitted' AND verified_by IS NULL AND verified_at IS NULL AND review_note IS NULL)
                    OR (
                        status = 'verified' AND verified_by IS NOT NULL
                        AND verified_at IS NOT NULL AND review_note IS NULL
                    )
                    OR (
                        status = 'rejected' AND verified_by IS NOT NULL
                        AND verified_at IS NOT NULL AND length(trim(review_note)) > 0
                    )
                ),
                FOREIGN KEY (transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT,
                FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE INDEX idx_payment_evidence_transaction_status
             ON payment_evidence (transaction_id, status, id)"
        );
        $pdo->exec(
            "CREATE INDEX idx_payment_evidence_submitted_by
             ON payment_evidence (submitted_by, submitted_at)"
        );
        $pdo->exec(
            "CREATE TRIGGER payment_evidence_insert_guard
             BEFORE INSERT ON payment_evidence
             FOR EACH ROW
             WHEN NEW.status <> 'submitted'
               OR NEW.verified_by IS NOT NULL
               OR NEW.verified_at IS NOT NULL
               OR NEW.review_note IS NOT NULL
               OR NOT EXISTS (
                 SELECT 1 FROM exchange_transactions
                 WHERE id = NEW.transaction_id
                   AND creator_id = NEW.submitted_by
                   AND status = 'in_progress'
                   AND payment_method = NEW.payment_method
                   AND cash_amount_minor = NEW.amount_minor
                   AND cash_currency = NEW.currency
                   AND currency_exponent = NEW.currency_exponent
                   AND payment_status IN ('awaiting_payment', 'rejected')
             )
               OR EXISTS (
                   SELECT 1 FROM payment_evidence
                   WHERE transaction_id = NEW.transaction_id AND status = 'submitted'
               )
               OR (
                   EXISTS (
                       SELECT 1 FROM exchange_transactions
                       WHERE id = NEW.transaction_id AND payment_status = 'rejected'
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM payment_evidence
                       WHERE transaction_id = NEW.transaction_id
                         AND id = (
                             SELECT MAX(id) FROM payment_evidence
                             WHERE transaction_id = NEW.transaction_id
                         )
                         AND status = 'rejected'
                   )
               )
             BEGIN
                 SELECT RAISE(ABORT, 'payment evidence does not match an eligible transaction');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER payment_evidence_update_guard
             BEFORE UPDATE ON payment_evidence
             FOR EACH ROW
             WHEN OLD.status <> 'submitted'
               OR NEW.id <> OLD.id
               OR NEW.transaction_id <> OLD.transaction_id
               OR NEW.submitted_by <> OLD.submitted_by
               OR NEW.payment_method <> OLD.payment_method
               OR NEW.amount_minor <> OLD.amount_minor
               OR NEW.currency <> OLD.currency
               OR NEW.currency_exponent <> OLD.currency_exponent
               OR NEW.evidence_storage_key IS NOT OLD.evidence_storage_key
               OR NEW.submitted_at <> OLD.submitted_at
               OR NEW.status NOT IN ('verified', 'rejected')
               OR NEW.verified_by IS NULL
               OR NEW.verified_at IS NULL
               OR (NEW.status = 'rejected' AND length(trim(COALESCE(NEW.review_note, ''))) = 0)
               OR (NEW.status = 'verified' AND NEW.review_note IS NOT NULL)
               OR NOT EXISTS (
                   SELECT 1 FROM exchange_transactions
                   WHERE id = OLD.transaction_id
                     AND recipient_id = NEW.verified_by
                     AND status = 'in_progress'
                     AND payment_status = 'submitted'
               )
             BEGIN
                 SELECT RAISE(ABORT, 'payment evidence review is not permitted');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER payment_evidence_no_delete
             BEFORE DELETE ON payment_evidence
             FOR EACH ROW
             BEGIN
                 SELECT RAISE(ABORT, 'payment evidence is retained for audit');
             END"
        );

        $pdo->exec(
            "CREATE TABLE transaction_disputes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_id INTEGER NOT NULL,
                reported_by INTEGER NOT NULL,
                reason TEXT NOT NULL CHECK (length(trim(reason)) BETWEEN 1 AND 2000),
                reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_by INTEGER NULL,
                resolution TEXT NULL CHECK (resolution IS NULL OR resolution IN ('in_progress', 'cancelled')),
                resolution_reason TEXT NULL CHECK (
                    resolution_reason IS NULL OR length(trim(resolution_reason)) BETWEEN 1 AND 2000
                ),
                resolved_at DATETIME NULL,
                CHECK (
                    (resolved_by IS NULL AND resolution IS NULL AND resolution_reason IS NULL AND resolved_at IS NULL)
                    OR (
                        resolved_by IS NOT NULL AND resolution IS NOT NULL
                        AND length(trim(resolution_reason)) > 0 AND resolved_at IS NOT NULL
                    )
                ),
                FOREIGN KEY (transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT,
                FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE UNIQUE INDEX idx_transaction_disputes_open
             ON transaction_disputes (transaction_id)
             WHERE resolved_at IS NULL"
        );
        $pdo->exec(
            "CREATE INDEX idx_transaction_disputes_transaction
             ON transaction_disputes (transaction_id, reported_at)"
        );
        $pdo->exec(
            "CREATE TRIGGER transaction_disputes_insert_guard
             BEFORE INSERT ON transaction_disputes
             FOR EACH ROW
             WHEN NOT EXISTS (
                 SELECT 1 FROM exchange_transactions
                 WHERE id = NEW.transaction_id
                   AND status = 'in_progress'
                   AND (creator_id = NEW.reported_by OR recipient_id = NEW.reported_by)
             )
               OR NEW.resolved_by IS NOT NULL
               OR NEW.resolution IS NOT NULL
               OR NEW.resolution_reason IS NOT NULL
               OR NEW.resolved_at IS NOT NULL
             BEGIN
                 SELECT RAISE(ABORT, 'dispute reporter is not an eligible participant');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER transaction_disputes_update_guard
             BEFORE UPDATE ON transaction_disputes
             FOR EACH ROW
             WHEN OLD.resolved_at IS NOT NULL
               OR NEW.id <> OLD.id
               OR NEW.transaction_id <> OLD.transaction_id
               OR NEW.reported_by <> OLD.reported_by
               OR NEW.reason <> OLD.reason
               OR NEW.reported_at <> OLD.reported_at
               OR NEW.resolved_by IS NULL
               OR NEW.resolution NOT IN ('in_progress', 'cancelled')
               OR length(trim(COALESCE(NEW.resolution_reason, ''))) = 0
               OR NEW.resolved_at IS NULL
               OR NOT EXISTS (
                   SELECT 1 FROM users
                   WHERE id = NEW.resolved_by AND role = 'admin'
               )
               OR NOT EXISTS (
                   SELECT 1 FROM exchange_transactions
                   WHERE id = OLD.transaction_id AND status = 'disputed'
               )
             BEGIN
                 SELECT RAISE(ABORT, 'dispute resolution is not permitted');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER transaction_disputes_no_delete
             BEFORE DELETE ON transaction_disputes
             FOR EACH ROW
             BEGIN
                 SELECT RAISE(ABORT, 'dispute records are retained for audit');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER exchange_transactions_payment_guard
             BEFORE UPDATE OF payment_method, payment_status ON exchange_transactions
             FOR EACH ROW
             WHEN NOT (
                 (NEW.payment_method IS OLD.payment_method
                  AND NEW.payment_status IS OLD.payment_status)
                 OR (
                     NEW.status = 'in_progress'
                     AND
                     OLD.payment_status = 'awaiting_method'
                     AND OLD.payment_method IS NULL
                     AND NEW.payment_status = 'awaiting_payment'
                     AND NEW.payment_method IN ('promptpay', 'bank_transfer', 'cash_on_handover')
                 )
                 OR (
                     NEW.status = 'in_progress'
                     AND
                     OLD.payment_status IN ('awaiting_payment', 'rejected')
                     AND NEW.payment_status = 'submitted'
                     AND NEW.payment_method IS OLD.payment_method
                     AND EXISTS (
                         SELECT 1 FROM payment_evidence
                         WHERE transaction_id = OLD.id
                           AND status = 'submitted'
                           AND submitted_by = OLD.creator_id
                           AND payment_method = NEW.payment_method
                     )
                 )
                 OR (
                     NEW.status = 'in_progress'
                     AND
                     OLD.payment_status = 'submitted'
                     AND NEW.payment_status IN ('verified', 'rejected')
                     AND NEW.payment_method IS OLD.payment_method
                     AND EXISTS (
                         SELECT 1 FROM payment_evidence
                         WHERE transaction_id = OLD.id
                           AND status = NEW.payment_status
                           AND verified_by = OLD.recipient_id
                     )
                 )
             )
             BEGIN
                 SELECT RAISE(ABORT, 'payment status transition is not permitted');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER exchange_transactions_dispute_guard
             BEFORE UPDATE OF status ON exchange_transactions
             FOR EACH ROW
             WHEN (
                 OLD.status = 'in_progress'
                 AND NEW.status = 'disputed'
                 AND NOT EXISTS (
                     SELECT 1 FROM transaction_disputes
                     WHERE transaction_id = OLD.id AND resolved_at IS NULL
                 )
             )
               OR (
                 OLD.status = 'disputed'
                 AND (
                     NEW.status NOT IN ('in_progress', 'cancelled')
                     OR NOT EXISTS (
                         SELECT 1 FROM transaction_disputes
                         WHERE transaction_id = OLD.id
                           AND resolved_at IS NOT NULL
                           AND resolution = NEW.status
                     )
                 )
               )
             BEGIN
                 SELECT RAISE(ABORT, 'transaction status transition requires dispute audit');
             END"
        );
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (5)");
        if ($pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []) {
            throw new RuntimeException("foreign_key_check reported violations during migration.");
        }
        if ($pdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
            throw new RuntimeException("SQLite integrity_check did not return ok during migration.");
        }
        if (payment_migration_snapshot($pdo) !== $before) {
            throw new RuntimeException("Existing marketplace data changed unexpectedly.");
        }
        $pdo->exec("COMMIT");
        $inMigration = false;
        $pdo->exec("PRAGMA foreign_keys = ON");
        $foreignKeysDisabled = false;
        if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 1) {
            throw new RuntimeException("SQLite foreign-key enforcement could not be restored.");
        }
    } catch (Throwable $e) {
        if ($inMigration) {
            $pdo->exec("ROLLBACK");
        }
        if ($foreignKeysDisabled) {
            $pdo->exec("PRAGMA foreign_keys = ON");
        }
        throw $e;
    }

    payment_migration_verify($pdo);
    if (payment_migration_snapshot($pdo) !== $before) {
        throw new RuntimeException("Post-migration preservation check failed.");
    }
    fwrite(STDOUT, "Migration version 5 applied and verified.\n");
    fwrite(STDOUT, "Transactions, reservations, chat rooms, messages, and historical values were preserved.\n");
    fwrite(STDOUT, "Payment evidence, dispute audit, indexes, triggers, foreign keys, and integrity verified.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    if (isset($backupPath) && is_file($backupPath)) {
        fwrite(STDERR, "Verified backup retained at: {$backupPath}\n");
    }
    exit(1);
}
