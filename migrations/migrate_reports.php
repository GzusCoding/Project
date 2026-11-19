<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_reports_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function reports_object_exists(PDO $pdo, string $type, string $name): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name"
    );
    $stmt->execute(["type" => $type, "name" => $name]);

    return $stmt->fetchColumn() !== false;
}

function reports_digest(PDO $pdo, string $table, array $columns): string
{
    $quoted = array_map(
        static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
        $columns
    );
    $digest = hash_init("sha256");
    foreach ($pdo->query(
        "SELECT " . implode(", ", $quoted) . " FROM \"{$table}\" ORDER BY 1"
    ) as $row) {
        hash_update(
            $digest,
            json_encode(
                array_map(static fn (string $column) => $row[$column], $columns),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ) . "\n"
        );
    }

    return hash_final($digest);
}

function reports_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach ([
        "users" => ["id", "name", "email", "password", "created_at", "phone", "address"],
        "products" => [
            "id", "user_id", "name", "description", "price", "image",
            "created_at", "category", "transaction_type", "item_condition", "status"
        ]
    ] as $table => $columns) {
        $available = array_column(
            $pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC),
            "name"
        );
        if (array_diff($columns, $available) !== []) {
            throw new RuntimeException("The {$table} table does not match the v7 schema.");
        }
        $snapshot[$table] = [
            "count" => (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn(),
            "digest" => reports_digest($pdo, $table, $columns)
        ];
    }

    return $snapshot;
}

function reports_trigger_sql(): array
{
    return [
        "content_reports_insert_guard" => "CREATE TRIGGER content_reports_insert_guard
            BEFORE INSERT ON content_reports
            FOR EACH ROW
            WHEN NEW.reporter_id = NEW.reported_user_id
              OR (
                  NEW.product_id IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM products
                      WHERE id = NEW.product_id
                        AND user_id = NEW.reported_user_id
                        AND status IN ('active', 'reserved')
                  )
              )
            BEGIN
                SELECT RAISE(ABORT, 'report target is not eligible');
            END",
        "content_reports_update_guard" => "CREATE TRIGGER content_reports_update_guard
            BEFORE UPDATE ON content_reports
            FOR EACH ROW
            WHEN OLD.status <> 'pending'
              OR NEW.status NOT IN ('dismissed', 'action_taken')
              OR NEW.reporter_id <> OLD.reporter_id
              OR NEW.reported_user_id <> OLD.reported_user_id
              OR NEW.product_id IS NOT OLD.product_id
              OR NEW.report_type <> OLD.report_type
              OR NEW.reason <> OLD.reason
              OR NEW.created_at <> OLD.created_at
              OR NEW.admin_id IS NULL
              OR length(trim(COALESCE(NEW.admin_reason, ''))) = 0
              OR NEW.reviewed_at IS NULL
              OR (
                  (NEW.status = 'dismissed' AND NEW.admin_action <> 'dismiss')
                  OR
                  (NEW.status = 'action_taken' AND NEW.admin_action NOT IN ('remove_listing', 'suspend_account'))
                  OR
                  (NEW.admin_action = 'remove_listing' AND NEW.product_id IS NULL)
              )
            BEGIN
                SELECT RAISE(ABORT, 'invalid report decision');
            END",
        "content_reports_no_delete" => "CREATE TRIGGER content_reports_no_delete
            BEFORE DELETE ON content_reports
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'report history is retained');
            END"
    ];
}

function verify_reports_schema(PDO $pdo): void
{
    $tables = ["content_reports"];
    foreach ($tables as $table) {
        if (!reports_object_exists($pdo, "table", $table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }
    $userColumns = array_column(
        $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
    if (!in_array("account_status", $userColumns, true)) {
        throw new RuntimeException("The user account status column is missing.");
    }
    foreach (reports_trigger_sql() as $name => $sql) {
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
            throw new RuntimeException("Required trigger {$name} is missing or altered.");
        }
    }
    foreach ([
        "idx_content_reports_status_created" => ["status", "created_at"],
        "idx_content_reports_target_created" => ["reported_user_id", "created_at"]
    ] as $name => $expectedColumns) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :name"
        );
        $stmt->execute(["name" => $name]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Required index {$name} is missing.");
        }
        $actual = array_column(
            $pdo->query("PRAGMA index_info(\"{$name}\")")->fetchAll(PDO::FETCH_ASSOC),
            "name"
        );
        if ($actual !== $expectedColumns) {
            throw new RuntimeException("Index {$name} has unexpected columns.");
        }
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 8"
    )->fetchColumn() !== 1) {
        throw new RuntimeException("Migration version 8 is not recorded exactly once.");
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
    abort_reports_migration("this migration supports only SQLite.");
}
if (
    $projectRoot === false
    || $resolvedPath === false
    || $resolvedPath !== $expectedPath
    || !is_file($resolvedPath)
) {
    abort_reports_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_reports_migration("PDO SQLite and SQLite3 backup support are required.");
}

try {
    $pdo = new PDO("sqlite:" . $resolvedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    $migrationColumns = array_column(
        $pdo->query("PRAGMA table_info(schema_migrations)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
    if (!in_array("version", $migrationColumns, true)) {
        abort_reports_migration("schema_migrations is missing or has an unexpected schema.");
    }
    $versionSevenCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 7"
    )->fetchColumn();
    $versionEightCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 8"
    )->fetchColumn();
    if ($versionEightCount === 1) {
        verify_reports_schema($pdo);
        fwrite(STDOUT, "Migration version 8 is already applied and verified.\n");
        exit(0);
    }
    if ($versionSevenCount !== 1 || $versionEightCount !== 0) {
        abort_reports_migration("version 7 must be recorded once and version 8 must not be recorded.");
    }
    $before = reports_snapshot($pdo);
    if (in_array("account_status", array_column(
        $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    ), true)) {
        abort_reports_migration("the users table already contains account_status.");
    }
    foreach ([
        "content_reports",
        "content_reports_insert_guard",
        "content_reports_update_guard",
        "content_reports_no_delete",
        "idx_content_reports_status_created",
        "idx_content_reports_target_created"
    ] as $name) {
        if (
            reports_object_exists($pdo, "table", $name)
            || reports_object_exists($pdo, "index", $name)
            || reports_object_exists($pdo, "trigger", $name)
        ) {
            abort_reports_migration("migration object {$name} already exists.");
        }
    }

    fwrite(STDOUT, "Type APPLY REPORTS MIGRATION 8 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals(
        "APPLY REPORTS MIGRATION 8",
        trim($confirmation)
    )) {
        abort_reports_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        throw new RuntimeException("Could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        throw new RuntimeException("The backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-v8-" . gmdate("Ymd\THis\Z") . "-"
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
        reports_snapshot($backup) !== $before
        || $backup->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
        || $backup->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
    ) {
        throw new RuntimeException("The backup did not verify against the inspected database.");
    }

    $pdo->exec("BEGIN IMMEDIATE");
    try {
        if (reports_snapshot($pdo) !== $before) {
            throw new RuntimeException("Marketplace data changed after the backup.");
        }
        if ((int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 7"
        )->fetchColumn() !== 1) {
            throw new RuntimeException("Migration version 7 changed after preflight.");
        }
        $pdo->exec(
            "ALTER TABLE users ADD COLUMN account_status TEXT NOT NULL DEFAULT 'active'
             CHECK (account_status IN ('active', 'suspended'))"
        );
        $pdo->exec(
            "CREATE TABLE content_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reporter_id INTEGER NOT NULL,
                reported_user_id INTEGER NOT NULL,
                product_id INTEGER NULL,
                report_type TEXT NOT NULL CHECK (report_type IN ('listing', 'behavior')),
                reason TEXT NOT NULL CHECK (
                    length(trim(reason)) BETWEEN 1 AND 2000
                    AND length(reason) <= 8000
                ),
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'dismissed', 'action_taken')),
                admin_id INTEGER NULL,
                admin_action TEXT NULL CHECK (
                    admin_action IS NULL
                    OR admin_action IN ('dismiss', 'remove_listing', 'suspend_account')
                ),
                admin_reason TEXT NULL CHECK (
                    admin_reason IS NULL OR length(trim(admin_reason)) BETWEEN 1 AND 2000
                ),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                CHECK (reporter_id <> reported_user_id),
                CHECK (
                    (report_type = 'listing' AND product_id IS NOT NULL)
                    OR (report_type = 'behavior' AND product_id IS NULL)
                ),
                CHECK (
                    (status = 'pending' AND admin_id IS NULL AND admin_action IS NULL
                     AND admin_reason IS NULL AND reviewed_at IS NULL)
                    OR (status <> 'pending' AND admin_id IS NOT NULL AND admin_action IS NOT NULL
                        AND admin_reason IS NOT NULL AND reviewed_at IS NOT NULL)
                ),
                FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE INDEX idx_content_reports_status_created
             ON content_reports (status, created_at)"
        );
        $pdo->exec(
            "CREATE INDEX idx_content_reports_target_created
             ON content_reports (reported_user_id, created_at)"
        );
        foreach (reports_trigger_sql() as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (8)");
        verify_reports_schema($pdo);
        if (
            reports_snapshot($pdo) !== $before
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
    fwrite(STDOUT, "Migration version 8 applied and verified.\n");
    fwrite(STDOUT, "Existing user and product records were preserved.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
