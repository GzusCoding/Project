<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_chat_image_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function chat_image_migration_columns(PDO $pdo, string $table): array
{
    return array_column(
        $pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
}

function chat_image_migration_object_exists(PDO $pdo, string $type, string $name): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name"
    );
    $stmt->execute(["type" => $type, "name" => $name]);

    return $stmt->fetchColumn() !== false;
}

function chat_image_migration_snapshot(PDO $pdo): array
{
    $tables = [
        "users" => ["id", "name"],
        "exchange_transactions" => ["id", "creator_id", "recipient_id", "status"],
        "chat_rooms" => ["id", "exchange_transaction_id", "creator_id", "recipient_id"],
        "buy_orders" => ["id", "buyer_id", "seller_id", "status"],
        "buy_order_chat_rooms" => ["id", "buy_order_id", "buyer_id", "seller_id"],
        "chat_messages" => ["id", "room_id", "sender_id", "message_body", "created_at"],
        "buy_order_chat_messages" => ["id", "room_id", "sender_id", "message_body", "created_at"]
    ];
    $snapshot = [];
    foreach ($tables as $table => $columns) {
        if (array_diff($columns, chat_image_migration_columns($pdo, $table)) !== []) {
            throw new RuntimeException("The {$table} table is missing required columns.");
        }
        $digest = hash_init("sha256");
        foreach ($pdo->query(
            "SELECT " . implode(", ", $columns) . " FROM \"{$table}\" ORDER BY 1"
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

function chat_image_migration_triggers(): array
{
    return [
        "chat_messages_image_pair_insert" => "CREATE TRIGGER chat_messages_image_pair_insert
            BEFORE INSERT ON chat_messages
            FOR EACH ROW
            WHEN (NEW.image_storage_key IS NULL) != (NEW.image_mime_type IS NULL)
            BEGIN
                SELECT RAISE(ABORT, 'chat image key and MIME type must be set together');
            END",
        "chat_messages_image_pair_update" => "CREATE TRIGGER chat_messages_image_pair_update
            BEFORE UPDATE OF image_storage_key, image_mime_type ON chat_messages
            FOR EACH ROW
            WHEN (NEW.image_storage_key IS NULL) != (NEW.image_mime_type IS NULL)
            BEGIN
                SELECT RAISE(ABORT, 'chat image key and MIME type must be set together');
            END",
        "buy_order_chat_messages_image_pair_insert" => "CREATE TRIGGER buy_order_chat_messages_image_pair_insert
            BEFORE INSERT ON buy_order_chat_messages
            FOR EACH ROW
            WHEN (NEW.image_storage_key IS NULL) != (NEW.image_mime_type IS NULL)
            BEGIN
                SELECT RAISE(ABORT, 'chat image key and MIME type must be set together');
            END",
        "buy_order_chat_messages_image_pair_update" => "CREATE TRIGGER buy_order_chat_messages_image_pair_update
            BEFORE UPDATE OF image_storage_key, image_mime_type ON buy_order_chat_messages
            FOR EACH ROW
            WHEN (NEW.image_storage_key IS NULL) != (NEW.image_mime_type IS NULL)
            BEGIN
                SELECT RAISE(ABORT, 'chat image key and MIME type must be set together');
            END"
    ];
}

function verify_chat_image_schema(PDO $pdo): void
{
    $expectedColumns = [
        "chat_messages" => [
            "id", "room_id", "sender_id", "message_body", "created_at",
            "image_storage_key", "image_mime_type"
        ],
        "buy_order_chat_messages" => [
            "id", "room_id", "sender_id", "message_body", "created_at",
            "image_storage_key", "image_mime_type"
        ]
    ];
    foreach ($expectedColumns as $table => $columns) {
        if (chat_image_migration_columns($pdo, $table) !== $columns) {
            throw new RuntimeException("The {$table} table has an unexpected schema.");
        }
        $schema = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '{$table}'"
        )->fetchColumn();
        if (
            !is_string($schema)
            || !str_contains($schema, "length(image_storage_key) = 64")
            || !str_contains($schema, "image_mime_type IN ('image/jpeg', 'image/png', 'image/webp')")
        ) {
            throw new RuntimeException("The {$table} image constraints are missing or altered.");
        }
    }
    foreach ([
        "idx_chat_messages_image_storage_key" => "chat_messages",
        "idx_buy_order_chat_messages_image_storage_key" => "buy_order_chat_messages"
    ] as $index => $table) {
        $stmt = $pdo->prepare(
            "SELECT tbl_name, sql FROM sqlite_master WHERE type = 'index' AND name = :name"
        );
        $stmt->execute(["name" => $index]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (
            $row === false
            || $row["tbl_name"] !== $table
            || !is_string($row["sql"])
            || !str_contains($row["sql"], "WHERE image_storage_key IS NOT NULL")
        ) {
            throw new RuntimeException("The {$index} index is missing or altered.");
        }
    }
    foreach (chat_image_migration_triggers() as $name => $sql) {
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
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 10"
    )->fetchColumn() !== 1) {
        throw new RuntimeException("Migration version 10 is not recorded exactly once.");
    }
    foreach (["chat_messages", "buy_order_chat_messages"] as $table) {
        if ((int) $pdo->query(
            "SELECT COUNT(*) FROM \"{$table}\"
             WHERE (image_storage_key IS NULL) != (image_mime_type IS NULL)"
        )->fetchColumn() !== 0) {
            throw new RuntimeException("The {$table} table contains mismatched image metadata.");
        }
    }
}

$scriptDirectory = realpath(__DIR__);
$projectRoot = $scriptDirectory !== false
    && is_file($scriptDirectory . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite")
    ? $scriptDirectory
    : realpath(dirname(__DIR__));
$expectedPath = $projectRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$configuredPath = getenv("DB_PATH") ?: $expectedPath;
$resolvedPath = realpath($configuredPath);
fwrite(STDOUT, "Selected driver: " . (getenv("DB_DRIVER") ?: "sqlite") . "\n");
fwrite(STDOUT, "Selected database path: " . ($resolvedPath ?: $configuredPath) . "\n");
if ((getenv("DB_DRIVER") ?: "sqlite") !== "sqlite") {
    abort_chat_image_migration("this migration supports only SQLite.");
}
if (
    $projectRoot === false
    || $resolvedPath === false
    || $resolvedPath !== $expectedPath
    || !is_file($resolvedPath)
) {
    abort_chat_image_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_chat_image_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if (array_diff(
        ["version", "applied_at"],
        chat_image_migration_columns($pdo, "schema_migrations")
    ) !== []) {
        abort_chat_image_migration("schema_migrations is missing or has an unexpected schema.");
    }
    $versionNineCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 9"
    )->fetchColumn();
    $versionTenCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 10"
    )->fetchColumn();
    if ($versionTenCount === 1) {
        verify_chat_image_schema($pdo);
        fwrite(STDOUT, "Migration version 10 is already applied and verified.\n");
        exit(0);
    }
    if ($versionNineCount !== 1 || $versionTenCount !== 0) {
        abort_chat_image_migration("version 9 must be recorded once and version 10 must not be recorded.");
    }
    foreach ([
        "chat_messages" => ["id", "room_id", "sender_id", "message_body", "created_at"],
        "buy_order_chat_messages" => ["id", "room_id", "sender_id", "message_body", "created_at"]
    ] as $table => $columns) {
        if (chat_image_migration_columns($pdo, $table) !== $columns) {
            abort_chat_image_migration("the {$table} table has an unexpected schema.");
        }
    }
    $objects = [
        "idx_chat_messages_image_storage_key",
        "idx_buy_order_chat_messages_image_storage_key",
        ...array_keys(chat_image_migration_triggers())
    ];
    foreach ($objects as $object) {
        foreach (["index", "trigger"] as $type) {
            if (chat_image_migration_object_exists($pdo, $type, $object)) {
                abort_chat_image_migration("the {$object} object already exists.");
            }
        }
    }
    $before = chat_image_migration_snapshot($pdo);
    fwrite(STDOUT, "Type APPLY CHAT IMAGES MIGRATION 10 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals(
        "APPLY CHAT IMAGES MIGRATION 10",
        trim($confirmation)
    )) {
        abort_chat_image_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        throw new RuntimeException("Could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        throw new RuntimeException("The backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-v10-" . gmdate("Ymd\THis\Z") . "-"
        . bin2hex(random_bytes(6)) . ".sqlite";
    $reservation = fopen($backupPath, "x");
    if ($reservation === false) {
        throw new RuntimeException("Could not reserve a unique backup filename.");
    }
    fclose($reservation);
    if (!chmod($backupPath, 0600)) {
        throw new RuntimeException("The backup file could not be secured.");
    }
    try {
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
            chat_image_migration_snapshot($backup) !== $before
            || $backup->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
            || $backup->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
        ) {
            throw new RuntimeException("The backup did not verify against the inspected database.");
        }
    } catch (Throwable $e) {
        if (is_file($backupPath) && !unlink($backupPath)) {
            error_log("Failed to remove invalid chat image migration backup.");
        }
        throw $e;
    }

    $pdo->exec("BEGIN IMMEDIATE");
    try {
        if (chat_image_migration_snapshot($pdo) !== $before) {
            throw new RuntimeException("Marketplace data changed after the backup.");
        }
        if ((int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 9"
        )->fetchColumn() !== 1) {
            throw new RuntimeException("Migration version 9 changed after preflight.");
        }
        foreach (["chat_messages", "buy_order_chat_messages"] as $table) {
            $pdo->exec(
                "ALTER TABLE \"{$table}\" ADD COLUMN image_storage_key TEXT NULL
                    CHECK (
                        image_storage_key IS NULL
                        OR (
                            length(image_storage_key) = 64
                            AND image_storage_key NOT GLOB '*[^0-9a-f]*'
                        )
                    )"
            );
            $pdo->exec(
                "ALTER TABLE \"{$table}\" ADD COLUMN image_mime_type TEXT NULL
                    CHECK (
                        image_mime_type IS NULL
                        OR image_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
                    )"
            );
            $pdo->exec(
                "CREATE UNIQUE INDEX idx_{$table}_image_storage_key
                 ON \"{$table}\" (image_storage_key)
                 WHERE image_storage_key IS NOT NULL"
            );
        }
        foreach (chat_image_migration_triggers() as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (10)");
        verify_chat_image_schema($pdo);
        if (
            chat_image_migration_snapshot($pdo) !== $before
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
    fwrite(STDOUT, "Migration version 10 applied and verified.\n");
    fwrite(STDOUT, "Chat image metadata is ready for exchange and purchase messages.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
