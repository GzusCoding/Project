<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "offers-migration-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$migrationPath = $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_offers.php";
$createdTemporaryRoot = false;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_rejected(PDO $pdo, callable $operation, string $message): void
{
    $pdo->exec("SAVEPOINT offers_constraint_test");
    try {
        $operation();
    } catch (PDOException) {
        $pdo->exec("ROLLBACK TO offers_constraint_test");
        $pdo->exec("RELEASE offers_constraint_test");
        return;
    }

    $pdo->exec("ROLLBACK TO offers_constraint_test");
    $pdo->exec("RELEASE offers_constraint_test");
    throw new RuntimeException($message);
}

function execute_migration(
    string $scriptPath,
    string $databasePath,
    ?string $input,
    string $driver = "sqlite"
): array
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
            "DB_DRIVER" => $driver,
            "DB_PATH" => $databasePath,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start the offers migration process.");
    }

    if ($input !== null) {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
}

function insert_offer(PDO $pdo, array $values): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO offers (
            creator_id, recipient_id, cash_payer_id, offered_product_id,
            target_product_id, cash_amount_minor, cash_currency,
            currency_exponent, status
        ) VALUES (
            :creator_id, :recipient_id, :cash_payer_id, :offered_product_id,
            :target_product_id, :cash_amount_minor, :cash_currency,
            :currency_exponent, :status
        )"
    );
    $stmt->execute($values);
}

function remove_temporary_tree(string $root): void
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
        throw new RuntimeException("Could not create the disposable database directory.");
    }
    $createdTemporaryRoot = true;
    if (!copy(
        $projectRoot . DIRECTORY_SEPARATOR . "migrations" . DIRECTORY_SEPARATOR . "migrate_offers.php",
        $migrationPath
    )) {
        throw new RuntimeException("Could not prepare an isolated migration copy.");
    }

    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    assert_true((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() === 1, "Test foreign keys must be enabled.");
    $pdo->exec(
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            role TEXT NOT NULL DEFAULT 'user'
                CHECK (role IN ('user', 'admin'))
        )"
    );
    $pdo->exec(
        "CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            price REAL NOT NULL,
            image TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            category TEXT NULL,
            transaction_type TEXT NOT NULL DEFAULT 'buy'
                CHECK (transaction_type IN ('buy', 'exchange', 'both')),
            item_condition TEXT NULL
                CHECK (item_condition IS NULL OR item_condition IN ('new', 'like_new', 'used')),
            status TEXT NOT NULL DEFAULT 'active'
                CHECK (status IN ('active', 'reserved', 'sold', 'inactive')),
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
            (1, 'Fixture One', 'one@example.test', 'unused'),
            (2, 'Fixture Two', 'two@example.test', 'unused')"
    );
    $pdo->exec(
        "INSERT INTO products (id, user_id, name, price) VALUES
            (10, 1, 'Fixture offered product', 1),
            (20, 2, 'Fixture target product', 1),
            (30, 2, 'Fixture other product', 1)"
    );
    $beforeRows = [
        "users" => $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        "products" => $pdo->query("SELECT * FROM products ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)
    ];

    [$driverExitCode, $driverStdout, $driverStderr] = execute_migration(
        $migrationPath,
        $databasePath,
        null,
        "mysql"
    );
    assert_true(
        $driverExitCode !== 0 && str_contains($driverStderr, "supports only SQLite"),
        "Migration did not refuse an unexpected database driver."
    );
    [$pathExitCode, $pathStdout, $pathStderr] = execute_migration(
        $migrationPath,
        $databasePath . ".unexpected",
        null
    );
    assert_true(
        $pathExitCode !== 0 && str_contains($pathStderr, "not the expected project database"),
        "Migration did not refuse an unexpected database path."
    );
    [$confirmationExitCode, $confirmationStdout, $confirmationStderr] = execute_migration(
        $migrationPath,
        $databasePath,
        "NOT THE CONFIRMATION PHRASE\n"
    );
    assert_true(
        $confirmationExitCode !== 0 && str_contains($confirmationStderr, "confirmation did not match"),
        "Migration did not require explicit confirmation."
    );
    assert_true(
        !$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'offers'")->fetchColumn()
            && !is_dir($temporaryRoot . DIRECTORY_SEPARATOR . ".database-backups"),
        "A refused migration changed the schema or created a backup."
    );

    [$exitCode, $stdout, $stderr] = execute_migration(
        $migrationPath,
        $databasePath,
        "APPLY OFFERS MIGRATION 2\n"
    );
    assert_true($exitCode === 0, "Migration failed: " . $stdout . $stderr);
    assert_true(str_contains($stdout, "Migration version 2 applied and verified."), "Migration did not report success.");
    assert_true(str_contains($stdout, "Verified backup:"), "Migration did not verify a backup.");

    $columns = $pdo->query("PRAGMA table_info(offers)")->fetchAll(PDO::FETCH_ASSOC);
    assert_true(
        array_column($columns, "name") === [
            "id", "creator_id", "recipient_id", "cash_payer_id",
            "offered_product_id", "target_product_id", "cash_amount_minor",
            "cash_currency", "currency_exponent", "status", "created_at",
            "updated_at", "responded_at"
        ],
        "Offers columns do not match the migration."
    );
    $columnByName = [];
    foreach ($columns as $column) {
        $columnByName[$column["name"]] = $column;
    }
    foreach ([
        "creator_id" => ["INTEGER", 1, null],
        "recipient_id" => ["INTEGER", 1, null],
        "cash_payer_id" => ["INTEGER", 1, null],
        "offered_product_id" => ["INTEGER", 1, null],
        "target_product_id" => ["INTEGER", 1, null],
        "cash_amount_minor" => ["INTEGER", 1, null],
        "cash_currency" => ["TEXT", 0, null],
        "currency_exponent" => ["INTEGER", 0, null],
        "status" => ["TEXT", 1, "'pending'"],
        "created_at" => ["DATETIME", 1, "CURRENT_TIMESTAMP"],
        "updated_at" => ["DATETIME", 1, "CURRENT_TIMESTAMP"],
        "responded_at" => ["DATETIME", 0, null]
    ] as $name => [$type, $notNull, $default]) {
        assert_true(
            $columnByName[$name]["type"] === $type
                && (int) $columnByName[$name]["notnull"] === $notNull
                && $columnByName[$name]["dflt_value"] === $default,
            "Unexpected definition for offers.{$name}."
        );
    }
    $indexes = [
        "idx_offers_creator_status" => ["creator_id", "status"],
        "idx_offers_recipient_status" => ["recipient_id", "status"],
        "idx_offers_offered_product_status" => ["offered_product_id", "status"],
        "idx_offers_target_product_status" => ["target_product_id", "status"],
        "idx_offers_created_at" => ["created_at"]
    ];
    foreach ($indexes as $name => $expectedColumns) {
        $actualColumns = [];
        foreach ($pdo->query("PRAGMA index_info(\"{$name}\")") as $row) {
            $actualColumns[] = $row["name"];
        }
        assert_true($actualColumns === $expectedColumns, "Index {$name} is missing or incorrect.");
    }
    $foreignKeys = $pdo->query("PRAGMA foreign_key_list(offers)")->fetchAll(PDO::FETCH_ASSOC);
    assert_true(count($foreignKeys) === 5, "Offers should have five foreign keys.");
    foreach ($foreignKeys as $foreignKey) {
        assert_true($foreignKey["on_delete"] === "RESTRICT", "Foreign keys must use ON DELETE RESTRICT.");
        assert_true(
            ($foreignKey["table"] === "users" && $foreignKey["to"] === "id")
                || ($foreignKey["table"] === "products" && $foreignKey["to"] === "id"),
            "Offers has a foreign key to an unexpected target."
        );
    }

    $baseOffer = [
        "creator_id" => 1,
        "recipient_id" => 2,
        "cash_payer_id" => 1,
        "offered_product_id" => 10,
        "target_product_id" => 20,
        "status" => "pending"
    ];
    insert_offer($pdo, $baseOffer + [
        "cash_amount_minor" => 250,
        "cash_currency" => "USD",
        "currency_exponent" => 2
    ]);
    insert_offer($pdo, $baseOffer + [
        "cash_amount_minor" => 7,
        "cash_currency" => "JPY",
        "currency_exponent" => 0
    ]);
    insert_offer($pdo, $baseOffer + [
        "cash_amount_minor" => 1250,
        "cash_currency" => "KWD",
        "currency_exponent" => 3
    ]);
    insert_offer($pdo, $baseOffer + [
        "cash_amount_minor" => 0,
        "cash_currency" => null,
        "currency_exponent" => null
    ]);
    foreach (["accepted", "rejected", "cancelled", "superseded", "expired"] as $status) {
        insert_offer($pdo, array_merge($baseOffer, [
            "cash_amount_minor" => 0,
            "cash_currency" => null,
            "currency_exponent" => null,
            "status" => $status
        ]));
    }
    assert_true(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM offers
             WHERE creator_id = 1 AND recipient_id = 2
               AND offered_product_id = 10 AND target_product_id = 20
               AND status = 'pending'"
        )->fetchColumn() === 4,
        "Multiple pending offers for the same ordered product pair must be allowed."
    );

    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "cash_amount_minor" => 0,
            "cash_currency" => null,
            "currency_exponent" => null,
            "status" => "unknown"
        ])),
        "Invalid status was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "cash_amount_minor" => -1,
            "cash_currency" => "USD",
            "currency_exponent" => 2
        ])),
        "Negative cash amount was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "cash_amount_minor" => 1.5,
            "cash_currency" => "USD",
            "currency_exponent" => 2
        ])),
        "Non-integer cash amount was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "recipient_id" => 1,
            "cash_amount_minor" => 0,
            "cash_currency" => null,
            "currency_exponent" => null
        ])),
        "Identical creator and recipient were accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "offered_product_id" => 20,
            "cash_amount_minor" => 0,
            "cash_currency" => null,
            "currency_exponent" => null
        ])),
        "Identical offered and target products were accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "creator_id" => 999,
            "cash_payer_id" => 999,
            "cash_amount_minor" => 0,
            "cash_currency" => null,
            "currency_exponent" => null
        ])),
        "Unknown creator foreign key was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "target_product_id" => 999,
            "cash_amount_minor" => 0,
            "cash_currency" => null,
            "currency_exponent" => null
        ])),
        "Unknown product foreign key was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "cash_payer_id" => 2,
            "cash_amount_minor" => 0,
            "cash_currency" => null,
            "currency_exponent" => null
        ])),
        "A payer other than the creator was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "cash_amount_minor" => 100,
            "cash_currency" => "ZZZ",
            "currency_exponent" => 2
        ])),
        "Unsupported currency code was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "cash_amount_minor" => 100,
            "cash_currency" => "JPY",
            "currency_exponent" => 2
        ])),
        "Currency exponent mismatch was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "cash_amount_minor" => 100,
            "cash_currency" => null,
            "currency_exponent" => null
        ])),
        "Positive cash amount without currency metadata was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => insert_offer($pdo, array_merge($baseOffer, [
            "cash_amount_minor" => 0,
            "cash_currency" => "USD",
            "currency_exponent" => 2
        ])),
        "Zero cash amount with currency metadata was accepted."
    );
    assert_rejected(
        $pdo,
        static fn () => $pdo->exec("DELETE FROM users WHERE id = 1"),
        "Referenced user deletion was not restricted."
    );
    assert_rejected(
        $pdo,
        static fn () => $pdo->exec("DELETE FROM products WHERE id = 10"),
        "Referenced product deletion was not restricted."
    );

    assert_true(
        (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 2")->fetchColumn() === 1,
        "Migration version 2 must be recorded once."
    );
    assert_true(
        $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok",
        "Database integrity check failed."
    );
    assert_true(
        $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "Database foreign_key_check reported violations."
    );
    assert_true(
        $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $beforeRows["users"]
            && $pdo->query("SELECT * FROM products ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $beforeRows["products"],
        "Migration or constraint tests changed users or products."
    );

    $offerCountBeforeRerun = (int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn();
    $versionCountBeforeRerun = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 2")->fetchColumn();
    $backupCountBeforeRerun = count(glob($temporaryRoot . DIRECTORY_SEPARATOR . ".database-backups" . DIRECTORY_SEPARATOR . "*.sqlite") ?: []);
    [$rerunExitCode, $rerunStdout, $rerunStderr] = execute_migration($migrationPath, $databasePath, null);
    assert_true($rerunExitCode === 0, "Migration rerun failed: " . $rerunStdout . $rerunStderr);
    assert_true(str_contains($rerunStdout, "already recorded; no database changes made."), "Rerun was not a no-op.");
    assert_true((int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn() === $offerCountBeforeRerun, "Rerun changed offers.");
    assert_true((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 2")->fetchColumn() === $versionCountBeforeRerun, "Rerun duplicated the version.");
    assert_true(
        count(glob($temporaryRoot . DIRECTORY_SEPARATOR . ".database-backups" . DIRECTORY_SEPARATOR . "*.sqlite") ?: []) === $backupCountBeforeRerun,
        "Rerun created an unnecessary backup."
    );

    echo "Offers migration disposable SQLite tests passed.\n";
} finally {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo = null;
    }
    if ($createdTemporaryRoot) {
        remove_temporary_tree($temporaryRoot);
    }
}
