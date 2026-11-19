<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_chat_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function chat_migration_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $stmt->execute(["name" => $table]);

    return $stmt->fetchColumn() !== false;
}

function chat_migration_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query("PRAGMA table_info(\"{$table}\")") as $row) {
        $columns[] = $row["name"];
    }

    return $columns;
}

function chat_migration_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach (["users", "exchange_transactions", "chat_rooms"] as $table) {
        $snapshot[$table] = hash("sha256", json_encode(
            $pdo->query("SELECT * FROM \"{$table}\" ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    return $snapshot;
}

function chat_migration_verify(PDO $pdo): void
{
    if (chat_migration_columns($pdo, "chat_messages") !== [
        "id", "room_id", "sender_id", "message_body", "created_at"
    ]) {
        throw new RuntimeException("chat_messages is missing or has an unexpected schema.");
    }

    $index = $pdo->prepare(
        "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_chat_messages_room_created_id'"
    );
    $index->execute();
    if ($index->fetchColumn() === false) {
        throw new RuntimeException("The chat message ordering index is missing.");
    }
    $indexColumns = [];
    foreach ($pdo->query('PRAGMA index_info("idx_chat_messages_room_created_id")') as $row) {
        $indexColumns[] = $row["name"];
    }
    if ($indexColumns !== ["room_id", "created_at", "id"]) {
        throw new RuntimeException("The chat message ordering index has unexpected columns.");
    }

    foreach (["chat_messages_sender_insert", "chat_messages_sender_update"] as $trigger) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'trigger' AND name = :name"
        );
        $stmt->execute(["name" => $trigger]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Required trigger {$trigger} is missing.");
        }
    }

    $foreignKeys = $pdo->query('PRAGMA foreign_key_list("chat_messages")')->fetchAll(PDO::FETCH_ASSOC);
    $foreignKeyMap = [];
    foreach ($foreignKeys as $foreignKey) {
        $foreignKeyMap[$foreignKey["from"]] = [
            "table" => $foreignKey["table"],
            "to" => $foreignKey["to"],
            "on_delete" => strtoupper($foreignKey["on_delete"])
        ];
    }
    ksort($foreignKeyMap);
    if ($foreignKeyMap !== [
        "room_id" => ["table" => "chat_rooms", "to" => "id", "on_delete" => "RESTRICT"],
        "sender_id" => ["table" => "users", "to" => "id", "on_delete" => "RESTRICT"]
    ]) {
        throw new RuntimeException("chat_messages foreign keys are missing or unexpected.");
    }

    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 4"
    )->fetchColumn() !== 1) {
        throw new RuntimeException("Migration version 4 is not recorded exactly once.");
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
    abort_chat_migration("this migration supports only SQLite.");
}
if ($projectRoot === false || $resolvedPath === false || $resolvedPath !== $expectedPath || !is_file($resolvedPath)) {
    abort_chat_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_chat_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    if ((int) $pdo->query("PRAGMA foreign_keys")->fetchColumn() !== 1) {
        abort_chat_migration("SQLite foreign-key enforcement could not be enabled.");
    }
    foreach (["users", "exchange_transactions", "chat_rooms", "schema_migrations"] as $table) {
        if (!chat_migration_table_exists($pdo, $table)) {
            abort_chat_migration("the expected {$table} table is missing.");
        }
    }
    $requiredColumns = [
        "users" => ["id"],
        "exchange_transactions" => ["id", "creator_id", "recipient_id", "status"],
        "chat_rooms" => ["id", "exchange_transaction_id", "creator_id", "recipient_id"],
        "schema_migrations" => ["version", "applied_at"]
    ];
    foreach ($requiredColumns as $table => $columns) {
        if (array_diff($columns, chat_migration_columns($pdo, $table)) !== []) {
            abort_chat_migration("the existing {$table} schema is unexpected.");
        }
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 3")->fetchColumn() !== 1) {
        abort_chat_migration("migration version 3 must be applied before this migration.");
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 4")->fetchColumn() > 0) {
        chat_migration_verify($pdo);
        fwrite(STDOUT, "Migration version 4 is already recorded; no database changes made.\n");
        exit(0);
    }
    if (chat_migration_table_exists($pdo, "chat_messages")) {
        abort_chat_migration("chat_messages exists without migration version 4.");
    }
    foreach (["idx_chat_messages_room_created_id", "chat_messages_sender_insert", "chat_messages_sender_update"] as $name) {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE name = :name");
        $stmt->execute(["name" => $name]);
        if ($stmt->fetchColumn() !== false) {
            abort_chat_migration("migration object {$name} already exists.");
        }
    }

    $before = chat_migration_snapshot($pdo);
    fwrite(STDOUT, "Type APPLY CHAT MESSAGES MIGRATION 4 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals("APPLY CHAT MESSAGES MIGRATION 4", trim($confirmation))) {
        abort_chat_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        abort_chat_migration("could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        abort_chat_migration("the backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-" . gmdate("Ymd\THis\Z") . "-"
        . bin2hex(random_bytes(6)) . ".sqlite";
    $reservation = fopen($backupPath, "x");
    if ($reservation === false) {
        abort_chat_migration("could not reserve a unique backup filename.");
    }
    fclose($reservation);
    if (!chmod($backupPath, 0600)) {
        unlink($backupPath);
        abort_chat_migration("the backup file could not be secured.");
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
            chat_migration_snapshot($backupPdo) !== $before
            || $backupPdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
        ) {
            throw new RuntimeException("The backup did not verify against the inspected database.");
        }
    } catch (Throwable $e) {
        if (is_file($backupPath) && !unlink($backupPath)) {
            error_log("Failed to remove invalid chat migration backup.");
        }
        throw $e;
    }

    $inMigration = false;
    try {
        $pdo->exec("BEGIN IMMEDIATE");
        $inMigration = true;
        if (chat_migration_snapshot($pdo) !== $before) {
            throw new RuntimeException("Existing marketplace data changed after backup.");
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 4")->fetchColumn() !== 0) {
            throw new RuntimeException("Migration version 4 was recorded concurrently.");
        }

        $pdo->exec(
            "CREATE TABLE chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room_id INTEGER NOT NULL,
                sender_id INTEGER NOT NULL,
                message_body TEXT NOT NULL
                    CHECK (length(trim(message_body)) > 0 AND length(message_body) <= 2000),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE RESTRICT,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE INDEX idx_chat_messages_room_created_id
             ON chat_messages (room_id, created_at, id)"
        );
        $pdo->exec(
            "CREATE TRIGGER chat_messages_sender_insert
             BEFORE INSERT ON chat_messages
             FOR EACH ROW
             WHEN NOT EXISTS (
                 SELECT 1 FROM chat_rooms
                 WHERE id = NEW.room_id
                   AND (creator_id = NEW.sender_id OR recipient_id = NEW.sender_id)
             )
             BEGIN
                 SELECT RAISE(ABORT, 'chat message sender is not a room participant');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER chat_messages_sender_update
             BEFORE UPDATE OF room_id, sender_id ON chat_messages
             FOR EACH ROW
             WHEN NOT EXISTS (
                 SELECT 1 FROM chat_rooms
                 WHERE id = NEW.room_id
                   AND (creator_id = NEW.sender_id OR recipient_id = NEW.sender_id)
             )
             BEGIN
                 SELECT RAISE(ABORT, 'chat message sender is not a room participant');
             END"
        );
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (4)");
        chat_migration_verify($pdo);
        if (chat_migration_snapshot($pdo) !== $before) {
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

    chat_migration_verify($pdo);
    if (chat_migration_snapshot($pdo) !== $before) {
        throw new RuntimeException("Post-migration preservation check failed.");
    }
    fwrite(STDOUT, "Migration version 4 applied and verified.\n");
    fwrite(STDOUT, "Chat message table, participant triggers, indexes, foreign keys, and integrity verified.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    if (isset($backupPath) && is_file($backupPath)) {
        fwrite(STDERR, "Verified backup retained at: {$backupPath}\n");
    }
    exit(1);
}
