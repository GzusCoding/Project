<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "handover-migration-test-" . bin2hex(random_bytes(8));
$appRoot = $temporaryRoot . DIRECTORY_SEPARATOR . "app";
$databasePath = $appRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$created = false;

function handover_migration_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function handover_migration_test_run(string $script, string $databasePath, ?string $input, string $driver = "sqlite"): array
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
        throw new RuntimeException("Could not start a disposable migration.");
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

function handover_migration_test_normalize_sql(string $script, array $definitions): array
{
    $runner = <<<'PHP'
register_shutdown_function(static function () use ($argv): void {
    $definitions = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $normalized = [];
    foreach ($definitions as $name => $definition) {
        $normalized[$name] = handover_migration_normalize_sql($definition);
    }
    echo json_encode($normalized, JSON_THROW_ON_ERROR);
});
require $argv[1];
PHP;
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, "-r", $runner, $script, json_encode($definitions, JSON_THROW_ON_ERROR)],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
        dirname($script),
        [
            "DB_DRIVER" => "mysql",
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start the SQL normalization check.");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $normalized = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    if ($exit === 0 || !is_array($normalized) || !str_contains($stderr, "supports only SQLite")) {
        throw new RuntimeException("The isolated SQL normalization check did not run as expected.");
    }

    return $normalized;
}

function handover_migration_test_reject(PDO $pdo, callable $operation, string $message): void
{
    $pdo->exec("SAVEPOINT handover_constraint");
    try {
        $operation();
    } catch (PDOException) {
        $pdo->exec("ROLLBACK TO handover_constraint");
        $pdo->exec("RELEASE handover_constraint");
        return;
    }
    $pdo->exec("ROLLBACK TO handover_constraint");
    $pdo->exec("RELEASE handover_constraint");
    throw new RuntimeException($message);
}

function handover_migration_test_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach ([
        "users" => ["id", "name", "email"],
        "products" => ["id", "user_id", "name", "status"],
        "offers" => ["id", "creator_id", "recipient_id", "status"],
        "exchange_transactions" => [
            "id", "offer_id", "creator_id", "recipient_id", "status", "payment_status"
        ],
        "product_reservations" => ["product_id", "transaction_id", "reserved_at"],
        "chat_rooms" => ["id", "exchange_transaction_id", "creator_id", "recipient_id"],
        "chat_messages" => ["id", "room_id", "sender_id", "message_body"],
        "payment_evidence" => ["id", "transaction_id", "submitted_by", "status"],
        "transaction_disputes" => ["id", "transaction_id", "reported_by", "reason"]
    ] as $table => $columns) {
        $snapshot[$table] = $pdo->query(
            "SELECT " . implode(", ", $columns) . " FROM {$table} ORDER BY " . $columns[0]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    return $snapshot;
}

function handover_migration_test_schema_snapshot(PDO $pdo): array
{
    return $pdo->query(
        "SELECT type, name, tbl_name, sql
         FROM sqlite_master
         WHERE name NOT LIKE 'sqlite_%'
           AND name NOT IN (
               'handover_confirmations',
               'idx_handover_confirmations_confirmer_time',
               'handover_confirmations_insert_guard',
               'handover_confirmations_update_guard',
               'handover_confirmations_no_delete',
               'exchange_transactions_handover_completion_guard',
               'exchange_transactions_handover_completion_insert_guard'
           )
         ORDER BY type, name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function handover_migration_test_trigger_snapshot(PDO $pdo): array
{
    return $pdo->query(
        "SELECT name, tbl_name, sql
         FROM sqlite_master
         WHERE type = 'trigger'
         ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function handover_migration_test_cleanup(string $root): void
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
    if (!mkdir($temporaryRoot, 0700) || !mkdir($appRoot, 0700)) {
        throw new RuntimeException("Could not create an isolated SQLite fixture.");
    }
    $created = true;
    foreach ([
        "migrate_offers.php",
        "migrate_exchange_transactions.php",
        "migrate_chat_messages.php",
        "migrate_payment_evidence.php",
        "migrate_handover_confirmations.php"
    ] as $script) {
        if (!copy($projectRoot . DIRECTORY_SEPARATOR . $script, $appRoot . DIRECTORY_SEPARATOR . $script)) {
            throw new RuntimeException("Could not copy {$script} into the fixture.");
        }
    }

    $normalizedSql = handover_migration_test_normalize_sql(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        [
            "outside_whitespace_a" =>
                "CREATE   TRIGGER sample\n BEFORE\tUPDATE ON sample_table BEGIN SELECT 1; END",
            "outside_whitespace_b" =>
                "CREATE TRIGGER sample BEFORE UPDATE ON sample_table BEGIN SELECT 1; END",
            "message_whitespace" => "SELECT 'handover  confirmations\n are immutable' AS message",
            "escaped_quotes" => "SELECT 'it''s  exact', \"a\"\"  b\", `t``  b`, [col  name]"
        ]
    );
    handover_migration_test_assert(
        $normalizedSql["outside_whitespace_a"] === $normalizedSql["outside_whitespace_b"],
        "Whitespace outside SQL literals did not normalize equivalently."
    );
    handover_migration_test_assert(
        $normalizedSql["message_whitespace"] === "SELECT 'handover  confirmations\n are immutable' AS message",
        "Whitespace inside a SQL string literal was altered."
    );
    handover_migration_test_assert(
        $normalizedSql["escaped_quotes"] === "SELECT 'it''s  exact', \"a\"\"  b\", `t``  b`, [col  name]",
        "Escaped quotes or quoted identifiers were corrupted during normalization."
    );

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
    [$exit, $stdout, $stderr] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_offers.php",
        $databasePath,
        "APPLY OFFERS MIGRATION 2\n"
    );
    handover_migration_test_assert($exit === 0, "Version 2 fixture failed: {$stdout}{$stderr}");
    [$exit, $stdout, $stderr] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_exchange_transactions.php",
        $databasePath,
        "APPLY EXCHANGE TRANSACTION MIGRATION 3\n"
    );
    handover_migration_test_assert($exit === 0, "Version 3 fixture failed: {$stdout}{$stderr}");

    $pdo->exec(
        "INSERT INTO offers (
            creator_id, recipient_id, cash_payer_id, offered_product_id, target_product_id,
            cash_amount_minor, cash_currency, currency_exponent, status
         ) VALUES
            (1, 2, 1, 10, 20, 0, NULL, NULL, 'accepted'),
            (1, 2, 1, 10, 20, 0, NULL, NULL, 'accepted'),
            (1, 2, 1, 10, 20, 0, NULL, NULL, 'accepted')"
    );
    $offerIds = $pdo->query("SELECT id FROM offers ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $insertTransaction = $pdo->prepare(
        "INSERT INTO exchange_transactions (
            offer_id, creator_id, recipient_id, offered_product_id, target_product_id,
            cash_payer_id, cash_amount_minor, payment_status, status
         ) VALUES (:offer_id, 1, 2, 10, 20, 1, 0, 'not_required', :status)"
    );
    foreach (array_values($offerIds) as $index => $offerId) {
        $insertTransaction->execute([
            "offer_id" => $offerId,
            "status" => $index === 2 ? "completed" : "in_progress"
        ]);
    }
    $transactionIds = $pdo->query("SELECT id FROM exchange_transactions ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare(
        "INSERT INTO product_reservations (product_id, transaction_id) VALUES (10, :transaction_id)"
    )->execute(["transaction_id" => $transactionIds[0]]);
    $pdo->prepare(
        "INSERT INTO chat_rooms (exchange_transaction_id, creator_id, recipient_id)
         VALUES (:transaction_id, 1, 2)"
    )->execute(["transaction_id" => $transactionIds[0]]);
    [$exit, $stdout, $stderr] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_chat_messages.php",
        $databasePath,
        "APPLY CHAT MESSAGES MIGRATION 4\n"
    );
    handover_migration_test_assert($exit === 0, "Version 4 fixture failed: {$stdout}{$stderr}");
    $pdo->exec(
        "INSERT INTO chat_messages (room_id, sender_id, message_body)
         SELECT id, 1, 'Existing transaction chat' FROM chat_rooms"
    );
    [$exit, $stdout, $stderr] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        "APPLY PAYMENT EVIDENCE MIGRATION 5\n"
    );
    handover_migration_test_assert($exit === 0, "Version 5 fixture failed: {$stdout}{$stderr}");
    $before = handover_migration_test_snapshot($pdo);
    $beforeSchema = handover_migration_test_schema_snapshot($pdo);
    $beforeTriggers = handover_migration_test_trigger_snapshot($pdo);

    [$wrongDriver, , $wrongDriverError] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        null,
        "mysql"
    );
    handover_migration_test_assert(
        $wrongDriver !== 0 && str_contains($wrongDriverError, "supports only SQLite"),
        "Version 6 accepted a non-SQLite driver."
    );
    [$wrongConfirmation, , $wrongConfirmationError] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        "APPLY PAYMENT EVIDENCE MIGRATION 5\n"
    );
    handover_migration_test_assert(
        $wrongConfirmation !== 0 && str_contains($wrongConfirmationError, "confirmation did not match"),
        "Version 6 accepted an incorrect confirmation phrase."
    );

    $pdo->exec(
        "CREATE TRIGGER reject_handover_migration
         BEFORE INSERT ON schema_migrations
         WHEN NEW.version = 6
         BEGIN
             SELECT RAISE(ABORT, 'forced v6 rollback test');
         END"
    );
    [$rollbackExit, , $rollbackError] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        "APPLY HANDOVER CONFIRMATIONS MIGRATION 6\n"
    );
    handover_migration_test_assert(
        $rollbackExit !== 0 && str_contains($rollbackError, "forced v6 rollback test"),
        "The forced migration failure was not reported."
    );
    handover_migration_test_assert(
        handover_migration_test_snapshot($pdo) === $before
            && (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 6")->fetchColumn() === 0
            && $pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'handover_confirmations'"
            )->fetchColumn() === false
            && (int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() === 1,
        "The failed v6 migration did not roll back cleanly."
    );
    $pdo->exec("DROP TRIGGER reject_handover_migration");

    [$exit, $stdout, $stderr] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        "APPLY HANDOVER CONFIRMATIONS MIGRATION 6\n"
    );
    $afterTriggers = handover_migration_test_trigger_snapshot($pdo);
    $beforeTriggerNames = array_column($beforeTriggers, "name");
    $preservedTriggers = array_values(array_filter(
        $afterTriggers,
        static fn (array $trigger): bool => in_array($trigger["name"], $beforeTriggerNames, true)
    ));
    $addedTriggers = array_values(array_filter(
        $afterTriggers,
        static fn (array $trigger): bool => !in_array($trigger["name"], $beforeTriggerNames, true)
    ));
    $addedTriggerNames = array_column($addedTriggers, "name");
    sort($addedTriggerNames);
    $expectedAddedTriggerNames = [
        "handover_confirmations_insert_guard",
        "handover_confirmations_no_delete",
        "handover_confirmations_update_guard"
    ];
    sort($expectedAddedTriggerNames);
    handover_migration_test_assert($exit === 0, "Version 6 migration failed: {$stdout}{$stderr}");
    handover_migration_test_assert(
        str_contains($stdout, "Migration version 6 applied and verified.")
            && str_contains($stdout, "Backup retained at:")
            && handover_migration_test_snapshot($pdo) === $before
            && handover_migration_test_schema_snapshot($pdo) === $beforeSchema
            && $preservedTriggers === $beforeTriggers
            && $addedTriggerNames === $expectedAddedTriggerNames
            && count(array_filter(
                $addedTriggers,
                static fn (array $trigger): bool => $trigger["tbl_name"] !== "handover_confirmations"
            )) === 0
            && (int) $pdo->query("SELECT COUNT(*) FROM handover_confirmations")->fetchColumn() === 0
            && $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "Version 6 failed preservation, trigger inventory, no-backfill, backup, or integrity checks."
    );

    $expectedTypes = [
        "creator_handed_over_offered_item" => 1,
        "recipient_received_offered_item" => 2,
        "recipient_handed_over_requested_item" => 2,
        "creator_received_requested_item" => 1
    ];
    $transactionId = (int) $transactionIds[0];
    $insertConfirmation = $pdo->prepare(
        "INSERT INTO handover_confirmations (transaction_id, confirmation_type, confirmer_id)
         VALUES (:transaction_id, :type, :confirmer_id)"
    );
    handover_migration_test_reject(
        $pdo,
        static function () use ($insertConfirmation, $transactionId): void {
            $insertConfirmation->execute([
                "transaction_id" => $transactionId,
                "type" => "unknown",
                "confirmer_id" => 1
            ]);
        },
        "An invalid confirmation type was accepted."
    );
    handover_migration_test_reject(
        $pdo,
        static function () use ($insertConfirmation, $transactionId): void {
            $insertConfirmation->execute([
                "transaction_id" => $transactionId,
                "type" => "recipient_received_offered_item",
                "confirmer_id" => 1
            ]);
        },
        "A confirmation by the wrong participant was accepted."
    );
    $insertConfirmation = $pdo->prepare(
        "INSERT INTO handover_confirmations (transaction_id, confirmation_type, confirmer_id)
         VALUES (:transaction_id, :type, :confirmer_id)"
    );
    foreach ($expectedTypes as $type => $confirmerId) {
        $insertConfirmation->execute([
            "transaction_id" => $transactionId,
            "type" => $type,
            "confirmer_id" => $confirmerId
        ]);
    }
    handover_migration_test_reject(
        $pdo,
        static function () use ($insertConfirmation, $transactionId): void {
            $insertConfirmation->execute([
                "transaction_id" => $transactionId,
                "type" => "creator_handed_over_offered_item",
                "confirmer_id" => 1
            ]);
        },
        "A duplicate confirmation type was accepted."
    );
    handover_migration_test_reject(
        $pdo,
        static function () use ($pdo, $transactionId): void {
            $pdo->exec(
                "UPDATE handover_confirmations SET confirmer_id = 2
                 WHERE transaction_id = {$transactionId} AND confirmation_type = 'creator_handed_over_offered_item'"
            );
        },
        "A confirmation record was mutable."
    );
    handover_migration_test_reject(
        $pdo,
        static function () use ($pdo, $transactionId): void {
            $pdo->exec(
                "DELETE FROM handover_confirmations
                 WHERE transaction_id = {$transactionId} AND confirmation_type = 'creator_handed_over_offered_item'"
            );
        },
        "A confirmation record could be deleted."
    );

    $incompleteId = (int) $transactionIds[1];
    $pdo->prepare(
        "INSERT INTO transaction_disputes (transaction_id, reported_by, reason)
         VALUES (:transaction_id, 1, 'Test dispute')"
    )->execute(["transaction_id" => $incompleteId]);
    $pdo->prepare(
        "UPDATE exchange_transactions SET status = 'disputed' WHERE id = :id"
    )->execute(["id" => $incompleteId]);
    handover_migration_test_reject(
        $pdo,
        static function () use ($insertConfirmation, $incompleteId): void {
            $insertConfirmation->execute([
                "transaction_id" => $incompleteId,
                "type" => "creator_handed_over_offered_item",
                "confirmer_id" => 1
            ]);
        },
        "A disputed transaction accepted a new confirmation."
    );
    $completedId = (int) $transactionIds[2];
    handover_migration_test_reject(
        $pdo,
        static function () use ($insertConfirmation, $completedId): void {
            $insertConfirmation->execute([
                "transaction_id" => $completedId,
                "type" => "creator_handed_over_offered_item",
                "confirmer_id" => 1
            ]);
        },
        "A historical completed transaction accepted a new confirmation."
    );

    [$rerunExit, $rerunOutput, $rerunError] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        null
    );
    handover_migration_test_assert(
        $rerunExit === 0
            && str_contains($rerunOutput, "already recorded; no database changes made")
            && $rerunError === "",
        "Version 6 rerun was not a verified no-op."
    );
    handover_migration_test_assert(
        $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'trigger'
               AND name IN (
                   'exchange_transactions_handover_completion_guard',
                   'exchange_transactions_handover_completion_insert_guard'
               )"
        )->fetchColumn() == 0,
        "Version 6 created a transaction-completion trigger."
    );

    $pdo->exec("DROP TRIGGER handover_confirmations_update_guard");
    $pdo->exec(
        "CREATE TRIGGER handover_confirmations_update_guard
         BEFORE   UPDATE ON handover_confirmations
         FOR EACH ROW
         BEGIN
             SELECT RAISE(ABORT, 'handover confirmations are immutable');
         END"
    );
    [$whitespaceTriggerExit, $whitespaceTriggerOutput, $whitespaceTriggerError] =
        handover_migration_test_run(
            $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
            $databasePath,
            null
        );
    handover_migration_test_assert(
        $whitespaceTriggerExit === 0
            && str_contains($whitespaceTriggerOutput, "already recorded; no database changes made")
            && $whitespaceTriggerError === "",
        "A trigger differing only in outside-literal whitespace failed verification."
    );

    $pdo->exec("DROP TRIGGER handover_confirmations_update_guard");
    $pdo->exec(
        "CREATE TRIGGER handover_confirmations_update_guard
         BEFORE UPDATE ON handover_confirmations
         FOR EACH ROW
         BEGIN
             SELECT RAISE(ABORT, 'handover  confirmations are immutable');
         END"
    );
    [$literalWhitespaceExit, , $literalWhitespaceError] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        null
    );
    handover_migration_test_assert(
        $literalWhitespaceExit !== 0
            && str_contains($literalWhitespaceError, "missing, altered, or unexpected triggers"),
        "A trigger with only altered error-message whitespace passed verification."
    );

    $pdo->exec("DROP TRIGGER handover_confirmations_update_guard");
    $pdo->exec(
        "CREATE TRIGGER handover_confirmations_update_guard
         BEFORE UPDATE ON handover_confirmations
         FOR EACH ROW
         BEGIN
             SELECT RAISE(ABORT, 'handover confirmations are immutable');
         END"
    );
    $pdo->exec(
        "CREATE TRIGGER arbitrary_handover_trigger
         BEFORE INSERT ON handover_confirmations
         FOR EACH ROW
         BEGIN
             SELECT RAISE(ABORT, 'unexpected handover trigger');
         END"
    );
    [$unexpectedTriggerExit, , $unexpectedTriggerError] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        null
    );
    handover_migration_test_assert(
        $unexpectedTriggerExit !== 0
            && str_contains($unexpectedTriggerError, "missing, altered, or unexpected triggers"),
        "An arbitrarily named unexpected handover trigger passed rerun verification."
    );
    $pdo->exec("DROP TRIGGER arbitrary_handover_trigger");

    $pdo->exec("DROP TRIGGER handover_confirmations_update_guard");
    [$missingTriggerExit, , $missingTriggerError] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        null
    );
    handover_migration_test_assert(
        $missingTriggerExit !== 0
            && str_contains($missingTriggerError, "missing, altered, or unexpected triggers"),
        "A missing handover trigger passed rerun verification."
    );
    $pdo->exec(
        "CREATE TRIGGER handover_confirmations_update_guard
         BEFORE UPDATE ON handover_confirmations
         FOR EACH ROW
         BEGIN
             SELECT RAISE(ABORT, 'altered handover trigger');
         END"
    );
    [$alteredTriggerExit, , $alteredTriggerError] = handover_migration_test_run(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        null
    );
    handover_migration_test_assert(
        $alteredTriggerExit !== 0
            && str_contains($alteredTriggerError, "missing, altered, or unexpected triggers"),
        "An altered handover trigger passed rerun verification."
    );
    fwrite(STDOUT, "Handover confirmation migration disposable SQLite tests passed.\n");
} finally {
    if ($created) {
        handover_migration_test_cleanup($temporaryRoot);
    }
}
