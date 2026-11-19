<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "payment-migration-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$created = false;

function payment_migration_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function payment_migration_test_run(string $script, string $databasePath, ?string $input, string $driver = "sqlite"): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $script],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
        dirname($script),
        [
            "DB_DRIVER" => $driver,
            "DB_PATH" => $databasePath,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start the disposable payment migration.");
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

function payment_migration_test_reject(PDO $pdo, callable $operation, string $message): void
{
    $pdo->exec("SAVEPOINT payment_constraint");
    try {
        $operation();
    } catch (PDOException) {
        $pdo->exec("ROLLBACK TO payment_constraint");
        $pdo->exec("RELEASE payment_constraint");
        return;
    }
    $pdo->exec("ROLLBACK TO payment_constraint");
    $pdo->exec("RELEASE payment_constraint");
    throw new RuntimeException($message);
}

function payment_migration_test_snapshot(PDO $pdo): array
{
    $result = [];
    foreach ([
        "exchange_transactions" => ["id", "offer_id", "payment_method", "payment_status", "status", "created_at"],
        "product_reservations" => ["product_id", "transaction_id", "reserved_at"],
        "chat_rooms" => ["id", "exchange_transaction_id", "creator_id", "recipient_id", "created_at", "closed_at"],
        "chat_messages" => ["id", "room_id", "sender_id", "message_body", "created_at"]
    ] as $table => $columns) {
        $result[$table] = $pdo->query(
            "SELECT " . implode(", ", $columns) . " FROM {$table} ORDER BY " . $columns[0]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    return $result;
}

function payment_migration_test_cleanup(string $root): void
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
        throw new RuntimeException("Could not create an isolated payment migration directory.");
    }
    $created = true;
    $scriptNames = [
        "migrate_offers.php",
        "migrate_exchange_transactions.php",
        "migrate_chat_messages.php",
        "migrate_payment_evidence.php"
    ];
    foreach ($scriptNames as $script) {
        if (!copy(
            $projectRoot . DIRECTORY_SEPARATOR . "migrations" . DIRECTORY_SEPARATOR . $script,
            $temporaryRoot . DIRECTORY_SEPARATOR . $script
        )) {
            throw new RuntimeException("Could not copy {$script} into the isolated test directory.");
        }
    }

    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec(
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            role TEXT NOT NULL DEFAULT 'user'
        )"
    );
    $pdo->exec(
        "CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            price REAL NOT NULL,
            image TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            category TEXT,
            transaction_type TEXT NOT NULL DEFAULT 'buy',
            item_condition TEXT,
            status TEXT NOT NULL DEFAULT 'active',
            FOREIGN KEY (user_id) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec("INSERT INTO schema_migrations (version) VALUES (1)");
    $pdo->exec(
        "INSERT INTO users (id, name, email, password, role) VALUES
            (1, 'Creator', 'creator@example.test', 'unused', 'user'),
            (2, 'Recipient', 'recipient@example.test', 'unused', 'user'),
            (3, 'Admin', 'admin@example.test', 'unused', 'admin'),
            (4, 'Unrelated', 'unrelated@example.test', 'unused', 'user')"
    );
    $pdo->exec(
        "INSERT INTO products (id, user_id, name, price, transaction_type) VALUES
            (10, 1, 'Offered item', 1, 'exchange'),
            (20, 2, 'Requested item', 1, 'both')"
    );
    [$exit, $stdout, $stderr] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_offers.php",
        $databasePath,
        "APPLY OFFERS MIGRATION 2\n"
    );
    payment_migration_test_assert($exit === 0, "Version 2 fixture migration failed: {$stdout}{$stderr}");

    $pdo->exec(
        "INSERT INTO offers (
            creator_id, recipient_id, cash_payer_id, offered_product_id, target_product_id,
            cash_amount_minor, cash_currency, currency_exponent, status
         ) VALUES
            (1, 2, 1, 10, 20, 0, NULL, NULL, 'accepted'),
            (1, 2, 1, 10, 20, 500, 'USD', 2, 'accepted')"
    );
    [$exit, $stdout, $stderr] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_exchange_transactions.php",
        $databasePath,
        "APPLY EXCHANGE TRANSACTION MIGRATION 3\n"
    );
    payment_migration_test_assert($exit === 0, "Version 3 fixture migration failed: {$stdout}{$stderr}");

    $transactionInsert = $pdo->prepare(
        "INSERT INTO exchange_transactions (
            offer_id, creator_id, recipient_id, offered_product_id, target_product_id,
            cash_payer_id, cash_amount_minor, cash_currency, currency_exponent,
            payment_status, status, created_at, updated_at
         ) VALUES (
            :offer_id, 1, 2, 10, 20, 1, :amount, :currency, :exponent,
            :payment_status, 'in_progress', '2026-01-02 03:04:05', '2026-01-02 03:04:06'
         )"
    );
    $transactionInsert->execute([
        "offer_id" => 1,
        "amount" => 0,
        "currency" => null,
        "exponent" => null,
        "payment_status" => "not_required"
    ]);
    $zeroTransactionId = (int) $pdo->lastInsertId();
    $transactionInsert->execute([
        "offer_id" => 2,
        "amount" => 500,
        "currency" => "USD",
        "exponent" => 2,
        "payment_status" => "awaiting_method"
    ]);
    $positiveTransactionId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO product_reservations (product_id, transaction_id) VALUES (10, :transaction_id)"
    )->execute(["transaction_id" => $zeroTransactionId]);
    foreach ([$zeroTransactionId, $positiveTransactionId] as $transactionId) {
        $pdo->prepare(
            "INSERT INTO chat_rooms (exchange_transaction_id, creator_id, recipient_id)
             VALUES (:transaction_id, 1, 2)"
        )->execute(["transaction_id" => $transactionId]);
    }
    [$exit, $stdout, $stderr] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_chat_messages.php",
        $databasePath,
        "APPLY CHAT MESSAGES MIGRATION 4\n"
    );
    payment_migration_test_assert($exit === 0, "Version 4 fixture migration failed: {$stdout}{$stderr}");
    $pdo->exec(
        "INSERT INTO chat_messages (room_id, sender_id, message_body)
         SELECT id, 1, 'Historical transaction chat'
         FROM chat_rooms ORDER BY id LIMIT 1"
    );
    $before = payment_migration_test_snapshot($pdo);

    [$wrongDriver, , $wrongDriverError] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        null,
        "mysql"
    );
    payment_migration_test_assert(
        $wrongDriver !== 0 && str_contains($wrongDriverError, "supports only SQLite"),
        "Version 5 accepted a non-SQLite driver."
    );
    [$wrongPath, , $wrongPathError] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath . ".wrong",
        null
    );
    payment_migration_test_assert(
        $wrongPath !== 0 && str_contains($wrongPathError, "not the expected project database"),
        "Version 5 accepted an unexpected path."
    );
    [$wrongConfirmation, , $wrongConfirmationError] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        "APPLY CHAT MESSAGES MIGRATION 4\n"
    );
    payment_migration_test_assert(
        $wrongConfirmation !== 0 && str_contains($wrongConfirmationError, "confirmation did not match"),
        "Version 5 accepted the wrong confirmation phrase."
    );

    $pdo->exec(
        "CREATE TRIGGER reject_payment_migration
         BEFORE INSERT ON schema_migrations
         WHEN NEW.version = 5
         BEGIN
             SELECT RAISE(ABORT, 'forced migration rollback test');
         END"
    );
    [$rollbackExit, $rollbackOut, $rollbackError] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        "APPLY PAYMENT EVIDENCE MIGRATION 5\n"
    );
    payment_migration_test_assert(
        $rollbackExit !== 0 && str_contains($rollbackError, "forced migration rollback test"),
        "The forced migration failure did not propagate: {$rollbackExit} {$rollbackOut}{$rollbackError}"
    );
    payment_migration_test_assert(
        payment_migration_test_snapshot($pdo) === $before
            && (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 5")->fetchColumn() === 0
            && $pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'payment_evidence'"
            )->fetchColumn() === false
            && (int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() === 1,
        "A failed migration did not roll back all schema/data changes."
    );
    $pdo->exec("DROP TRIGGER reject_payment_migration");

    $pdo->prepare(
        "UPDATE exchange_transactions SET payment_status = 'pending' WHERE id = :id"
    )->execute(["id" => $positiveTransactionId]);
    [$legacyExit, , $legacyError] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        null
    );
    payment_migration_test_assert(
        $legacyExit !== 0
            && str_contains($legacyError, "legacy or inconsistent payment values")
            && (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 5")->fetchColumn() === 0,
        "Migration silently remapped a legacy payment value or changed the schema."
    );
    $pdo->prepare(
        "UPDATE exchange_transactions SET payment_status = 'awaiting_method' WHERE id = :id"
    )->execute(["id" => $positiveTransactionId]);

    [$exit, $stdout, $stderr] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        "APPLY PAYMENT EVIDENCE MIGRATION 5\n"
    );
    payment_migration_test_assert($exit === 0, "Version 5 migration failed: {$stdout}{$stderr}");
    payment_migration_test_assert(
        str_contains($stdout, "Migration version 5 applied and verified.")
            && str_contains($stdout, "Backup retained at:")
            && payment_migration_test_snapshot($pdo) === $before,
        "Migration did not preserve the prior transaction, reservation, room, and chat data."
    );
    payment_migration_test_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 5")->fetchColumn() === 1
            && $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "Migration version or integrity checks failed."
    );
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
                "on_delete" => strtoupper($foreignKey["on_delete"])
            ];
        }
        ksort($actualForeignKeys);
        $expected = [];
        foreach ($expectedForeignKeys as $column => $referencedTable) {
            $expected[$column] = ["table" => $referencedTable, "on_delete" => "RESTRICT"];
        }
        ksort($expected);
        payment_migration_test_assert(
            $actualForeignKeys === $expected,
            "The {$table} table is missing restrictive foreign keys."
        );
    }

    $positiveRow = $pdo->query(
        "SELECT * FROM exchange_transactions WHERE id = {$positiveTransactionId}"
    )->fetch(PDO::FETCH_ASSOC);
    payment_migration_test_assert(
        $positiveRow["payment_status"] === "awaiting_method"
            && $positiveRow["status"] === "in_progress"
            && $positiveRow["payment_method"] === null,
        "Existing transaction values were changed during the rebuild."
    );
    payment_migration_test_reject(
        $pdo,
        static fn () => $pdo->exec(
            "UPDATE exchange_transactions SET payment_status = 'pending' WHERE id = {$positiveTransactionId}"
        ),
        "A removed payment status was accepted."
    );
    payment_migration_test_reject(
        $pdo,
        static fn () => $pdo->exec(
            "UPDATE exchange_transactions SET status = 'unknown' WHERE id = {$zeroTransactionId}"
        ),
        "An unsupported transaction status was accepted."
    );
    payment_migration_test_reject(
        $pdo,
        static fn () => $pdo->exec(
            "UPDATE exchange_transactions SET status = 'disputed' WHERE id = {$positiveTransactionId}"
        ),
        "A dispute status without a dispute audit record was accepted."
    );

    $pdo->prepare(
        "UPDATE exchange_transactions
         SET payment_method = 'promptpay', payment_status = 'awaiting_payment'
         WHERE id = :id"
    )->execute(["id" => $positiveTransactionId]);
    $insertEvidence = $pdo->prepare(
        "INSERT INTO payment_evidence (
            transaction_id, submitted_by, payment_method, amount_minor, currency,
            currency_exponent, evidence_storage_key, status
         ) VALUES (:transaction_id, :submitted_by, :method, :amount, :currency,
                   :exponent, :key, 'submitted')"
    );
    $evidenceValues = [
        "transaction_id" => $positiveTransactionId,
        "submitted_by" => 1,
        "method" => "promptpay",
        "amount" => 500,
        "currency" => "USD",
        "exponent" => 2,
        "key" => str_repeat("a", 64)
    ];
    payment_migration_test_reject(
        $pdo,
        static function () use ($pdo, $positiveTransactionId): void {
            $pdo->exec(
                "INSERT INTO payment_evidence (
                    transaction_id, submitted_by, payment_method, amount_minor, currency,
                    currency_exponent, evidence_storage_key, status, verified_by, verified_at
                 ) VALUES ({$positiveTransactionId}, 1, 'promptpay', 500, 'USD', 2,
                           '" . str_repeat("b", 64) . "', 'verified', 2, CURRENT_TIMESTAMP)"
            );
        },
        "A submitter directly created pre-verified evidence."
    );
    $insertEvidence->execute($evidenceValues);
    $evidenceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "UPDATE exchange_transactions SET payment_status = 'submitted' WHERE id = :id"
    )->execute(["id" => $positiveTransactionId]);
    payment_migration_test_reject(
        $pdo,
        static fn () => $pdo->exec(
            "UPDATE exchange_transactions SET payment_method = 'bank_transfer' WHERE id = {$positiveTransactionId}"
        ),
        "A submitted payment method could be changed."
    );
    payment_migration_test_reject(
        $pdo,
        static function () use ($insertEvidence, $evidenceValues): void {
            $invalid = $evidenceValues;
            $invalid["submitted_by"] = 2;
            $insertEvidence->execute($invalid);
        },
        "A non-creator evidence submitter was accepted."
    );
    payment_migration_test_reject(
        $pdo,
        static function () use ($insertEvidence, $evidenceValues): void {
            $invalid = $evidenceValues;
            $invalid["amount"] = 499;
            $insertEvidence->execute($invalid);
        },
        "Mismatched evidence amount was accepted."
    );
    $pdo->prepare(
        "UPDATE payment_evidence
         SET status = 'verified', verified_by = 2, verified_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    )->execute(["id" => $evidenceId]);
    $pdo->prepare(
        "UPDATE exchange_transactions SET payment_status = 'verified' WHERE id = :id"
    )->execute(["id" => $positiveTransactionId]);
    payment_migration_test_reject(
        $pdo,
        static fn () => $pdo->exec("DELETE FROM payment_evidence WHERE id = {$evidenceId}"),
        "Historical payment evidence could be deleted."
    );

    $pdo->prepare(
        "UPDATE exchange_transactions SET status = 'in_progress' WHERE id = :id"
    )->execute(["id" => $zeroTransactionId]);
    $pdo->prepare(
        "INSERT INTO transaction_disputes (transaction_id, reported_by, reason)
         VALUES (:transaction_id, 1, 'Preservation test dispute')"
    )->execute(["transaction_id" => $zeroTransactionId]);
    $disputeId = (int) $pdo->lastInsertId();
    payment_migration_test_reject(
        $pdo,
        static function () use ($pdo, $positiveTransactionId): void {
            $pdo->prepare(
                "INSERT INTO transaction_disputes (transaction_id, reported_by, reason)
                 VALUES (:transaction_id, 4, 'Not a participant')"
            )->execute(["transaction_id" => $positiveTransactionId]);
        },
        "A non-participant dispute reporter was accepted."
    );
    payment_migration_test_reject(
        $pdo,
        static function () use ($pdo, $positiveTransactionId): void {
            $pdo->exec(
                "INSERT INTO transaction_disputes (
                    transaction_id, reported_by, reason, resolved_by, resolution,
                    resolution_reason, resolved_at
                 ) VALUES ({$positiveTransactionId}, 1, 'Forged resolution', 3,
                           'in_progress', 'Not an admin action', CURRENT_TIMESTAMP)"
            );
        },
        "A participant directly created a pre-resolved dispute."
    );
    $pdo->prepare(
        "UPDATE exchange_transactions SET status = 'disputed' WHERE id = :id"
    )->execute(["id" => $zeroTransactionId]);
    payment_migration_test_reject(
        $pdo,
        static fn () => $pdo->exec(
            "UPDATE exchange_transactions SET status = 'completed' WHERE id = {$zeroTransactionId}"
        ),
        "A disputed transaction advanced without Admin resolution."
    );
    $pdo->prepare(
        "UPDATE transaction_disputes
         SET resolved_by = 3, resolution = 'in_progress',
             resolution_reason = 'Audit verification', resolved_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    )->execute(["id" => $disputeId]);
    payment_migration_test_assert(
        $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "Payment/dispute constraints left an integrity or foreign-key violation."
    );

    [$rerunExit, $rerunOut, $rerunErr] = payment_migration_test_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        null
    );
    payment_migration_test_assert(
        $rerunExit === 0
            && str_contains($rerunOut, "already recorded; no database changes made.")
            && $rerunErr === "",
        "Version 5 rerun was not a clean no-op: {$rerunOut}{$rerunErr}"
    );

    fwrite(STDOUT, "Payment evidence migration disposable SQLite tests passed.\n");
} finally {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo = null;
    }
    if ($created) {
        payment_migration_test_cleanup($temporaryRoot);
    }
}
