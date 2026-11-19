<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_buy_order_chat_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function buy_order_chat_object_exists(PDO $pdo, string $type, string $name): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name"
    );
    $stmt->execute(["type" => $type, "name" => $name]);

    return $stmt->fetchColumn() !== false;
}

function buy_order_chat_table_columns(PDO $pdo, string $table): array
{
    return array_column(
        $pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
}

function buy_order_chat_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach (["users", "buy_orders", "exchange_transactions", "chat_rooms", "chat_messages"] as $table) {
        $columns = buy_order_chat_table_columns($pdo, $table);
        if ($columns === []) {
            throw new RuntimeException("The {$table} table is missing.");
        }
        $quotedColumns = array_map(
            static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
            $columns
        );
        $digest = hash_init("sha256");
        foreach ($pdo->query(
            "SELECT " . implode(", ", $quotedColumns) . " FROM \"{$table}\" ORDER BY 1"
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

function buy_order_chat_trigger_sql(): array
{
    return [
        "buy_order_chat_room_insert_guard" => "CREATE TRIGGER buy_order_chat_room_insert_guard
            BEFORE INSERT ON buy_order_chat_rooms
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM buy_orders
                WHERE id = NEW.buy_order_id
                  AND buyer_id = NEW.buyer_id
                  AND seller_id = NEW.seller_id
            )
            BEGIN
                SELECT RAISE(ABORT, 'buy order chat participants do not match the order');
            END",
        "buy_order_chat_room_update_guard" => "CREATE TRIGGER buy_order_chat_room_update_guard
            BEFORE UPDATE OF buy_order_id, buyer_id, seller_id ON buy_order_chat_rooms
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM buy_orders
                WHERE id = NEW.buy_order_id
                  AND buyer_id = NEW.buyer_id
                  AND seller_id = NEW.seller_id
            )
            BEGIN
                SELECT RAISE(ABORT, 'buy order chat participants do not match the order');
            END",
        "buy_order_chat_message_insert_guard" => "CREATE TRIGGER buy_order_chat_message_insert_guard
            BEFORE INSERT ON buy_order_chat_messages
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM buy_order_chat_rooms
                WHERE id = NEW.room_id
                  AND (buyer_id = NEW.sender_id OR seller_id = NEW.sender_id)
            )
            BEGIN
                SELECT RAISE(ABORT, 'chat message sender is not a room participant');
            END",
        "buy_order_chat_message_update_guard" => "CREATE TRIGGER buy_order_chat_message_update_guard
            BEFORE UPDATE OF room_id, sender_id ON buy_order_chat_messages
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM buy_order_chat_rooms
                WHERE id = NEW.room_id
                  AND (buyer_id = NEW.sender_id OR seller_id = NEW.sender_id)
            )
            BEGIN
                SELECT RAISE(ABORT, 'chat message sender is not a room participant');
            END"
    ];
}

function verify_buy_order_chat_schema(PDO $pdo): void
{
    $expectedColumns = [
        "buy_order_chat_rooms" => [
            "id", "buy_order_id", "buyer_id", "seller_id", "created_at", "closed_at"
        ],
        "buy_order_chat_messages" => [
            "id", "room_id", "sender_id", "message_body", "created_at"
        ]
    ];
    foreach ($expectedColumns as $table => $columns) {
        if (!buy_order_chat_object_exists($pdo, "table", $table)
            || buy_order_chat_table_columns($pdo, $table) !== $columns
        ) {
            throw new RuntimeException("The {$table} table is missing or has an unexpected schema.");
        }
    }
    foreach ([
        "idx_buy_order_chat_rooms_buyer" => ["buyer_id"],
        "idx_buy_order_chat_rooms_seller" => ["seller_id"],
        "idx_buy_order_chat_messages_room_created_id" => ["room_id", "created_at", "id"]
    ] as $name => $columns) {
        if (!buy_order_chat_object_exists($pdo, "index", $name)
            || array_column($pdo->query("PRAGMA index_info(\"{$name}\")")->fetchAll(PDO::FETCH_ASSOC), "name") !== $columns
        ) {
            throw new RuntimeException("The {$name} index is missing or has unexpected columns.");
        }
    }
    foreach (buy_order_chat_trigger_sql() as $name => $sql) {
        $stmt = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = :name"
        );
        $stmt->execute(["name" => $name]);
        $actual = $stmt->fetchColumn();
        $normalize = static fn (string $value): string => (string) preg_replace(
            "/\\s+/",
            " ",
            trim($value)
        );
        if (!is_string($actual) || $normalize($actual) !== $normalize($sql)) {
            throw new RuntimeException("The {$name} trigger is missing or altered.");
        }
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 9"
    )->fetchColumn() !== 1) {
        throw new RuntimeException("Migration version 9 is not recorded exactly once.");
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM buy_order_chat_rooms"
    )->fetchColumn() !== (int) $pdo->query("SELECT COUNT(*) FROM buy_orders")->fetchColumn()) {
        throw new RuntimeException("Not every purchase order has a chat room.");
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*)
         FROM buy_order_chat_rooms AS rooms
         INNER JOIN buy_orders ON buy_orders.id = rooms.buy_order_id
         WHERE buy_orders.buyer_id <> rooms.buyer_id
            OR buy_orders.seller_id <> rooms.seller_id"
    )->fetchColumn() !== 0) {
        throw new RuntimeException("A purchase chat room has mismatched participants.");
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
    abort_buy_order_chat_migration("this migration supports only SQLite.");
}
if (
    $projectRoot === false
    || $resolvedPath === false
    || $resolvedPath !== $expectedPath
    || !is_file($resolvedPath)
) {
    abort_buy_order_chat_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_buy_order_chat_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if (!in_array("version", buy_order_chat_table_columns($pdo, "schema_migrations"), true)) {
        abort_buy_order_chat_migration("schema_migrations is missing or has an unexpected schema.");
    }
    $versionEightCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 8"
    )->fetchColumn();
    $versionNineCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 9"
    )->fetchColumn();
    if ($versionNineCount === 1) {
        verify_buy_order_chat_schema($pdo);
        fwrite(STDOUT, "Migration version 9 is already applied and verified.\n");
        exit(0);
    }
    if ($versionEightCount !== 1 || $versionNineCount !== 0) {
        abort_buy_order_chat_migration("version 8 must be recorded once and version 9 must not be recorded.");
    }
    foreach ([
        "users" => ["id", "name"],
        "buy_orders" => ["id", "buyer_id", "seller_id", "status", "created_at"],
        "exchange_transactions" => ["id", "creator_id", "recipient_id"],
        "chat_rooms" => ["id", "exchange_transaction_id", "creator_id", "recipient_id"],
        "chat_messages" => ["id", "room_id", "sender_id", "message_body", "created_at"]
    ] as $table => $requiredColumns) {
        if (array_diff($requiredColumns, buy_order_chat_table_columns($pdo, $table)) !== []) {
            abort_buy_order_chat_migration("the {$table} schema is incomplete.");
        }
    }
    $before = buy_order_chat_snapshot($pdo);
    foreach ([
        "buy_order_chat_rooms", "buy_order_chat_messages",
        "buy_order_chat_room_insert_guard", "buy_order_chat_room_update_guard",
        "buy_order_chat_message_insert_guard", "buy_order_chat_message_update_guard",
        "idx_buy_order_chat_rooms_buyer", "idx_buy_order_chat_rooms_seller",
        "idx_buy_order_chat_messages_room_created_id"
    ] as $object) {
        if (buy_order_chat_object_exists($pdo, "table", $object)
            || buy_order_chat_object_exists($pdo, "index", $object)
            || buy_order_chat_object_exists($pdo, "trigger", $object)
        ) {
            abort_buy_order_chat_migration("the {$object} object already exists before migration.");
        }
    }

    fwrite(STDOUT, "Type APPLY BUY ORDER CHATS MIGRATION 9 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals(
        "APPLY BUY ORDER CHATS MIGRATION 9",
        trim($confirmation)
    )) {
        abort_buy_order_chat_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        throw new RuntimeException("Could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        throw new RuntimeException("The backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-v9-" . gmdate("Ymd\THis\Z") . "-"
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
        buy_order_chat_snapshot($backup) !== $before
        || $backup->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
        || $backup->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
    ) {
        throw new RuntimeException("The backup did not verify against the inspected database.");
    }

    $pdo->exec("BEGIN IMMEDIATE");
    try {
        if (buy_order_chat_snapshot($pdo) !== $before) {
            throw new RuntimeException("Marketplace data changed after the backup.");
        }
        if ((int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 8"
        )->fetchColumn() !== 1) {
            throw new RuntimeException("Migration version 8 changed after preflight.");
        }
        $pdo->exec(
            "CREATE TABLE buy_order_chat_rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                buy_order_id INTEGER NOT NULL UNIQUE,
                buyer_id INTEGER NOT NULL,
                seller_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME NULL,
                CHECK (buyer_id <> seller_id),
                FOREIGN KEY (buy_order_id) REFERENCES buy_orders(id) ON DELETE RESTRICT,
                FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE TABLE buy_order_chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room_id INTEGER NOT NULL,
                sender_id INTEGER NOT NULL,
                message_body TEXT NOT NULL
                    CHECK (length(trim(message_body)) > 0 AND length(message_body) <= 2000),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (room_id) REFERENCES buy_order_chat_rooms(id) ON DELETE RESTRICT,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE INDEX idx_buy_order_chat_rooms_buyer
             ON buy_order_chat_rooms (buyer_id)"
        );
        $pdo->exec(
            "CREATE INDEX idx_buy_order_chat_rooms_seller
             ON buy_order_chat_rooms (seller_id)"
        );
        $pdo->exec(
            "CREATE INDEX idx_buy_order_chat_messages_room_created_id
             ON buy_order_chat_messages (room_id, created_at, id)"
        );
        foreach (buy_order_chat_trigger_sql() as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec(
            "INSERT INTO buy_order_chat_rooms (buy_order_id, buyer_id, seller_id, created_at)
             SELECT id, buyer_id, seller_id, created_at
             FROM buy_orders
             ORDER BY id"
        );
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (9)");
        verify_buy_order_chat_schema($pdo);
        if (
            buy_order_chat_snapshot($pdo) !== $before
            || $pdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
            || $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
        ) {
            throw new RuntimeException("Post-migration verification failed.");
        }
        $pdo->exec("COMMIT");
    } catch (Throwable $e) {
        $pdo->exec("ROLLBACK");
        throw $e;
    }
    fwrite(STDOUT, "Migration version 9 applied and verified.\n");
    fwrite(STDOUT, "A private chat room was created for each existing purchase order.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
