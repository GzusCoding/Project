<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "exchange-migration-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$created = false;

function exchange_migration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function exchange_migration_run(
    string $script,
    string $databasePath,
    ?string $input,
    string $driver = "sqlite"
): array
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
        throw new RuntimeException("Could not start the disposable migration.");
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

function exchange_migration_reject(PDO $pdo, callable $operation, string $message): void
{
    $pdo->exec("SAVEPOINT exchange_constraint");
    try {
        $operation();
    } catch (PDOException) {
        $pdo->exec("ROLLBACK TO exchange_constraint");
        $pdo->exec("RELEASE exchange_constraint");
        return;
    }
    $pdo->exec("ROLLBACK TO exchange_constraint");
    $pdo->exec("RELEASE exchange_constraint");
    throw new RuntimeException($message);
}

function exchange_migration_cleanup(string $root): void
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
        throw new RuntimeException("Could not create a disposable migration directory.");
    }
    $created = true;
    foreach (["migrate_offers.php", "migrate_exchange_transactions.php"] as $script) {
        if (!copy(
            $projectRoot . DIRECTORY_SEPARATOR . "migrations" . DIRECTORY_SEPARATOR . $script,
            $temporaryRoot . DIRECTORY_SEPARATOR . $script
        )) {
            throw new RuntimeException("Could not copy {$script} into the disposable directory.");
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
        "INSERT INTO users (id, name, email, password) VALUES
            (1, 'Creator', 'creator@example.test', 'unused'),
            (2, 'Recipient', 'recipient@example.test', 'unused')"
    );
    $pdo->exec(
        "INSERT INTO products (id, user_id, name, price, transaction_type) VALUES
            (10, 1, 'Offered item', 1, 'exchange'),
            (20, 2, 'Requested item', 1, 'both')"
    );

    [$v2Exit, $v2Out, $v2Err] = exchange_migration_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_offers.php",
        $databasePath,
        "APPLY OFFERS MIGRATION 2\n"
    );
    exchange_migration_assert($v2Exit === 0, "Disposable version 2 migration failed: {$v2Out}{$v2Err}");
    $pdo->exec(
        "INSERT INTO offers (
            creator_id, recipient_id, cash_payer_id, offered_product_id, target_product_id,
            cash_amount_minor, cash_currency, currency_exponent, status
         ) VALUES (1, 2, 1, 10, 20, 0, NULL, NULL, 'accepted')"
    );
    $offerId = (int) $pdo->lastInsertId();
    $legacyOfferCount = (int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn();

    [$wrongDriver, , $wrongDriverError] = exchange_migration_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_exchange_transactions.php",
        $databasePath,
        null,
        "mysql"
    );
    exchange_migration_assert(
        $wrongDriver !== 0 && str_contains($wrongDriverError, "supports only SQLite"),
        "Version 3 migration did not refuse a non-SQLite driver."
    );
    [$wrongPath, , $wrongPathError] = exchange_migration_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_exchange_transactions.php",
        $databasePath . ".wrong",
        null
    );
    exchange_migration_assert(
        $wrongPath !== 0 && str_contains($wrongPathError, "not the expected project database"),
        "Version 3 migration did not refuse an unexpected database path."
    );
    [$wrongConfirmation, , $wrongConfirmationError] = exchange_migration_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_exchange_transactions.php",
        $databasePath,
        "APPLY OFFERS MIGRATION 2\n"
    );
    exchange_migration_assert(
        $wrongConfirmation !== 0 && str_contains($wrongConfirmationError, "confirmation did not match"),
        "Version 3 migration accepted the wrong confirmation phrase."
    );

    [$exit, $stdout, $stderr] = exchange_migration_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_exchange_transactions.php",
        $databasePath,
        "APPLY EXCHANGE TRANSACTION MIGRATION 3\n"
    );
    exchange_migration_assert($exit === 0, "Version 3 migration failed: {$stdout}{$stderr}");
    exchange_migration_assert(
        str_contains($stdout, "Migration version 3 applied and verified.")
            && str_contains($stdout, "Backup retained at:"),
        "Migration did not report a verified backup and successful application."
    );
    exchange_migration_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 3")->fetchColumn() === 1,
        "Migration version 3 was not recorded exactly once."
    );
    exchange_migration_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn() === $legacyOfferCount
            && (int) $pdo->query("SELECT COUNT(*) FROM exchange_transactions")->fetchColumn() === 0,
        "Migration fabricated transaction records or changed historical offers."
    );

    $expectedColumns = [
        "exchange_transactions" => [
            "id", "offer_id", "creator_id", "recipient_id", "offered_product_id",
            "target_product_id", "cash_payer_id", "cash_amount_minor", "cash_currency",
            "currency_exponent", "payment_method", "payment_status", "status",
            "created_at", "updated_at", "completed_at", "cancelled_at"
        ],
        "product_reservations" => ["product_id", "transaction_id", "reserved_at"],
        "chat_rooms" => [
            "id", "exchange_transaction_id", "creator_id", "recipient_id", "created_at", "closed_at"
        ]
    ];
    foreach ($expectedColumns as $table => $columns) {
        exchange_migration_assert(
            array_column($pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC), "name") === $columns,
            "Unexpected column list for {$table}."
        );
    }
    $base = [
        "offer_id" => $offerId,
        "creator_id" => 1,
        "recipient_id" => 2,
        "offered_product_id" => 10,
        "target_product_id" => 20,
        "cash_payer_id" => 1,
        "cash_amount_minor" => 0,
        "cash_currency" => null,
        "currency_exponent" => null,
        "payment_status" => "not_required",
        "status" => "in_progress"
    ];
    $insertSql =
        "INSERT INTO exchange_transactions (
            offer_id, creator_id, recipient_id, offered_product_id, target_product_id,
            cash_payer_id, cash_amount_minor, cash_currency, currency_exponent,
            payment_status, status
        ) VALUES (
            :offer_id, :creator_id, :recipient_id, :offered_product_id, :target_product_id,
            :cash_payer_id, :cash_amount_minor, :cash_currency, :currency_exponent,
            :payment_status, :status
        )";
    $insertTransaction = static function (array $values) use ($pdo, $insertSql): void {
        $pdo->prepare($insertSql)->execute($values);
    };
    $insertTransaction($base);
    $transactionId = (int) $pdo->lastInsertId();
    exchange_migration_reject(
        $pdo,
        static fn () => $insertTransaction($base),
        "A duplicate offer transaction was accepted."
    );
    foreach ([
        [array_merge($base, ["cash_payer_id" => 2]), "A non-creator payer was accepted."],
        [array_merge($base, ["recipient_id" => 1]), "Identical participants were accepted."],
        [array_merge($base, ["target_product_id" => 10]), "Identical products were accepted."],
        [array_merge($base, ["cash_amount_minor" => 1, "cash_currency" => "ZZZ", "currency_exponent" => 2, "payment_status" => "awaiting_method"]), "Unsupported currency was accepted."],
        [array_merge($base, ["cash_amount_minor" => 1, "cash_currency" => "JPY", "currency_exponent" => 2, "payment_status" => "awaiting_method"]), "Mismatched currency exponent was accepted."],
        [array_merge($base, ["cash_amount_minor" => 1, "cash_currency" => "USD", "currency_exponent" => 2, "payment_status" => "not_required"]), "Positive cash without a payment requirement was accepted."],
        [array_merge($base, ["cash_amount_minor" => "1.5"]), "Non-integer minor units were accepted."]
    ] as [$values, $message]) {
        exchange_migration_reject($pdo, static fn () => $insertTransaction($values), $message);
    }

    $positiveOffer = $pdo->prepare(
        "INSERT INTO offers (
            creator_id, recipient_id, cash_payer_id, offered_product_id, target_product_id,
            cash_amount_minor, cash_currency, currency_exponent
         ) VALUES (1, 2, 1, 10, 20, 100, 'USD', 2)"
    );
    $positiveOffer->execute();
    $positiveOfferId = (int) $pdo->lastInsertId();
    $positiveTransaction = $base;
    $positiveTransaction["offer_id"] = $positiveOfferId;
    $positiveTransaction["cash_amount_minor"] = 100;
    $positiveTransaction["cash_currency"] = "USD";
    $positiveTransaction["currency_exponent"] = 2;
    $positiveTransaction["payment_status"] = "awaiting_method";
    $insertTransaction($positiveTransaction);

    $reservation = $pdo->prepare(
        "INSERT INTO product_reservations (product_id, transaction_id) VALUES (10, :transaction_id)"
    );
    $reservation->execute(["transaction_id" => $transactionId]);
    exchange_migration_reject(
        $pdo,
        static fn () => $pdo->exec("DELETE FROM exchange_transactions WHERE id = {$transactionId}"),
        "A transaction with a product reservation was deletable."
    );
    exchange_migration_reject(
        $pdo,
        static fn () => $pdo->exec("DELETE FROM offers WHERE id = {$offerId}"),
        "An offer referenced by a transaction was deletable."
    );
    $pdo->exec(
        "INSERT INTO chat_rooms (exchange_transaction_id, creator_id, recipient_id)
         VALUES ({$transactionId}, 1, 2)"
    );
    exchange_migration_reject(
        $pdo,
        static fn () => $pdo->exec(
            "INSERT INTO chat_rooms (exchange_transaction_id, creator_id, recipient_id)
             VALUES ({$transactionId}, 1, 2)"
        ),
        "More than one chat room was allowed for a transaction."
    );
    exchange_migration_assert(
        $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "Integrity or foreign-key checks failed after schema tests."
    );
    [$rerunExit, $rerunOut, $rerunErr] = exchange_migration_run(
        $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_exchange_transactions.php",
        $databasePath,
        null
    );
    exchange_migration_assert(
        $rerunExit === 0
            && str_contains($rerunOut, "already recorded; no database changes made.")
            && $rerunErr === "",
        "Version 3 rerun was not a clean no-op: {$rerunOut}{$rerunErr}"
    );
    fwrite(STDOUT, "Exchange transaction migration disposable SQLite tests passed.\n");
} finally {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo = null;
    }
    if ($created) {
        exchange_migration_cleanup($temporaryRoot);
    }
}
