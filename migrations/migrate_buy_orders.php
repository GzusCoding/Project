<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

function abort_buy_orders_migration(string $message): never
{
    fwrite(STDERR, "Migration aborted: {$message}\n");
    exit(1);
}

function buy_orders_object_exists(PDO $pdo, string $type, string $name): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name"
    );
    $stmt->execute(["type" => $type, "name" => $name]);

    return $stmt->fetchColumn() !== false;
}

function buy_orders_digest(PDO $pdo, string $table, array $columns): string
{
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
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ) . "\n"
        );
    }

    return hash_final($digest);
}

function buy_orders_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach ([
        "users" => ["id", "name", "email", "password", "created_at"],
        "products" => [
            "id", "user_id", "name", "description", "price", "image",
            "created_at", "category", "transaction_type", "item_condition", "status"
        ],
        "offers" => [
            "id", "creator_id", "recipient_id", "cash_payer_id", "offered_product_id",
            "target_product_id", "cash_amount_minor", "cash_currency", "currency_exponent",
            "status", "created_at", "updated_at", "responded_at"
        ],
        "exchange_transactions" => [
            "id", "offer_id", "creator_id", "recipient_id", "offered_product_id",
            "target_product_id", "cash_payer_id", "cash_amount_minor", "cash_currency",
            "currency_exponent", "payment_method", "payment_status", "status", "created_at",
            "updated_at", "completed_at", "cancelled_at"
        ],
        "product_reservations" => ["product_id", "transaction_id", "reserved_at"],
        "handover_confirmations" => [
            "id", "transaction_id", "confirmation_type", "confirmer_id", "confirmed_at"
        ],
        "transaction_disputes" => [
            "id", "transaction_id", "reported_by", "reason", "reported_at",
            "resolved_by", "resolution", "resolution_reason", "resolved_at"
        ]
    ] as $table => $columns) {
        $available = array_column(
            $pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC),
            "name"
        );
        if (array_diff($columns, $available) !== []) {
            throw new RuntimeException("The {$table} table does not match the v6 migration schema.");
        }
        $count = (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
        $snapshot[$table] = [
            "count" => $count,
            "digest" => buy_orders_digest($pdo, $table, $columns)
        ];
    }

    return $snapshot;
}

function buy_orders_table_sql(): array
{
    return [
        "categories" => "CREATE TABLE categories (
            category_code TEXT PRIMARY KEY,
            category_name TEXT NOT NULL UNIQUE,
            description TEXT NULL
        )",
        "buy_orders" => "CREATE TABLE buy_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            buyer_id INTEGER NOT NULL,
            seller_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL CHECK (length(trim(product_name)) > 0),
            price_minor INTEGER NOT NULL
                CHECK (typeof(price_minor) = 'integer' AND price_minor >= 0),
            currency TEXT NOT NULL DEFAULT 'THB' CHECK (currency = 'THB'),
            payment_method TEXT NOT NULL CHECK (
                payment_method IN ('promptpay', 'bank_transfer', 'cash_on_handover')
            ),
            status TEXT NOT NULL DEFAULT 'in_progress'
                CHECK (status IN ('in_progress', 'completed')),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            CHECK (buyer_id <> seller_id),
            CHECK (
                (status = 'in_progress' AND completed_at IS NULL)
                OR (status = 'completed' AND completed_at IS NOT NULL)
            ),
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        )",
        "buy_order_confirmations" => "CREATE TABLE buy_order_confirmations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            confirmation_type TEXT NOT NULL CHECK (
                confirmation_type IN ('seller_dispatched', 'buyer_received')
            ),
            confirmer_id INTEGER NOT NULL,
            confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (order_id, confirmation_type),
            FOREIGN KEY (order_id) REFERENCES buy_orders(id) ON DELETE RESTRICT,
            FOREIGN KEY (confirmer_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
        "buy_order_payment_evidence" => "CREATE TABLE buy_order_payment_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            submitted_by INTEGER NOT NULL,
            payment_method TEXT NOT NULL CHECK (
                payment_method IN ('promptpay', 'bank_transfer', 'cash_on_handover')
            ),
            evidence_storage_key TEXT NULL CHECK (
                evidence_storage_key IS NULL
                OR (
                    length(evidence_storage_key) = 64
                    AND evidence_storage_key NOT GLOB '*[^0-9a-f]*'
                )
            ),
            status TEXT NOT NULL DEFAULT 'submitted'
                CHECK (status IN ('submitted', 'verified', 'rejected')),
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_by INTEGER NULL,
            verified_at DATETIME NULL,
            review_note TEXT NULL CHECK (
                review_note IS NULL OR length(trim(review_note)) BETWEEN 1 AND 2000
            ),
            CHECK (
                (payment_method = 'cash_on_handover' AND evidence_storage_key IS NULL)
                OR
                (payment_method IN ('promptpay', 'bank_transfer')
                    AND evidence_storage_key IS NOT NULL)
            ),
            CHECK (
                (status = 'submitted' AND verified_by IS NULL
                    AND verified_at IS NULL AND review_note IS NULL)
                OR
                (status = 'verified' AND verified_by IS NOT NULL
                    AND verified_at IS NOT NULL AND review_note IS NULL)
                OR
                (status = 'rejected' AND verified_by IS NOT NULL
                    AND verified_at IS NOT NULL AND review_note IS NOT NULL)
            ),
            FOREIGN KEY (order_id) REFERENCES buy_orders(id) ON DELETE RESTRICT,
            FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE RESTRICT
        )",
        "notifications" => "CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_id INTEGER NOT NULL,
            notification_type TEXT NOT NULL CHECK (
                notification_type IN (
                    'buy_order_created', 'buy_payment_submitted', 'buy_payment_verified',
                    'buy_payment_rejected', 'review_requested', 'review_received', 'report_decision'
                )
            ),
            reference_type TEXT NOT NULL CHECK (
                reference_type IN ('buy_order', 'exchange_transaction', 'content_report')
            ),
            reference_id INTEGER NOT NULL CHECK (reference_id > 0),
            title TEXT NOT NULL CHECK (length(trim(title)) BETWEEN 1 AND 200),
            message TEXT NOT NULL CHECK (length(trim(message)) BETWEEN 1 AND 1000),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            UNIQUE (recipient_id, notification_type, reference_type, reference_id),
            CHECK (
                (notification_type IN (
                    'buy_order_created', 'buy_payment_submitted', 'buy_payment_verified',
                    'buy_payment_rejected'
                ) AND reference_type = 'buy_order')
                OR
                (notification_type IN ('review_requested', 'review_received')
                    AND reference_type IN ('buy_order', 'exchange_transaction'))
                OR
                (notification_type = 'report_decision' AND reference_type = 'content_report')
            ),
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
        "reviews" => "CREATE TABLE reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NULL,
            exchange_transaction_id INTEGER NULL,
            reviewer_id INTEGER NOT NULL,
            reviewee_id INTEGER NOT NULL,
            rating INTEGER NOT NULL CHECK (typeof(rating) = 'integer' AND rating BETWEEN 1 AND 5),
            comment TEXT NOT NULL CHECK (
                length(trim(comment)) BETWEEN 1 AND 2000
                AND length(comment) <= 8000
            ),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK (reviewer_id <> reviewee_id),
            CHECK (
                (order_id IS NOT NULL AND exchange_transaction_id IS NULL)
                OR (order_id IS NULL AND exchange_transaction_id IS NOT NULL)
            ),
            FOREIGN KEY (order_id) REFERENCES buy_orders(id) ON DELETE RESTRICT,
            FOREIGN KEY (exchange_transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT,
            FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (reviewee_id) REFERENCES users(id) ON DELETE RESTRICT
        )"
    ];
}

function buy_orders_trigger_sql(): array
{
    return [
        "products_category_insert_guard" => "CREATE TRIGGER products_category_insert_guard
            BEFORE INSERT ON products
            FOR EACH ROW
            WHEN NEW.category IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM categories WHERE category_code = NEW.category
              )
            BEGIN
                SELECT RAISE(ABORT, 'unknown product category');
            END",
        "products_category_update_guard" => "CREATE TRIGGER products_category_update_guard
            BEFORE UPDATE OF category ON products
            FOR EACH ROW
            WHEN NEW.category IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM categories WHERE category_code = NEW.category
              )
            BEGIN
                SELECT RAISE(ABORT, 'unknown product category');
            END",
        "buy_orders_insert_guard" => "CREATE TRIGGER buy_orders_insert_guard
            BEFORE INSERT ON buy_orders
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1
                FROM products
                WHERE id = NEW.product_id
                  AND user_id = NEW.seller_id
                  AND status = 'active'
                  AND transaction_type IN ('buy', 'both')
            )
            OR EXISTS (
                SELECT 1 FROM product_reservations
                WHERE product_id = NEW.product_id
            )
            BEGIN
                SELECT RAISE(ABORT, 'product is not available for purchase');
            END",
        "buy_orders_update_guard" => "CREATE TRIGGER buy_orders_update_guard
            BEFORE UPDATE ON buy_orders
            FOR EACH ROW
            WHEN NEW.buyer_id <> OLD.buyer_id
              OR NEW.seller_id <> OLD.seller_id
              OR NEW.product_id <> OLD.product_id
              OR NEW.product_name <> OLD.product_name
              OR NEW.price_minor <> OLD.price_minor
              OR NEW.currency <> OLD.currency
              OR NEW.payment_method <> OLD.payment_method
              OR NEW.created_at <> OLD.created_at
              OR OLD.status <> 'in_progress'
              OR NEW.status <> 'completed'
              OR NEW.completed_at IS NULL
              OR (
                  SELECT COUNT(*) FROM buy_order_confirmations
                  WHERE order_id = OLD.id
              ) <> 2
              OR NOT EXISTS (
                  SELECT 1 FROM products
                  WHERE id = OLD.product_id AND status = 'sold'
              )
            BEGIN
                SELECT RAISE(ABORT, 'invalid buy order transition');
            END",
        "buy_orders_no_delete" => "CREATE TRIGGER buy_orders_no_delete
            BEFORE DELETE ON buy_orders
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'buy order history is retained');
            END",
        "buy_order_confirmations_insert_guard" => "CREATE TRIGGER buy_order_confirmations_insert_guard
            BEFORE INSERT ON buy_order_confirmations
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM buy_orders
                WHERE id = NEW.order_id
                  AND status = 'in_progress'
                  AND (
                      (NEW.confirmation_type = 'seller_dispatched' AND seller_id = NEW.confirmer_id)
                      OR
                      (
                          NEW.confirmation_type = 'buyer_received'
                          AND buyer_id = NEW.confirmer_id
                          AND EXISTS (
                              SELECT 1 FROM buy_order_confirmations
                              WHERE order_id = NEW.order_id
                                AND confirmation_type = 'seller_dispatched'
                          )
                      )
                  )
            )
            BEGIN
                SELECT RAISE(ABORT, 'confirmation is not permitted');
            END",
        "buy_order_confirmations_update_guard" => "CREATE TRIGGER buy_order_confirmations_update_guard
            BEFORE UPDATE ON buy_order_confirmations
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'buy order confirmations are immutable');
            END",
        "buy_order_confirmations_no_delete" => "CREATE TRIGGER buy_order_confirmations_no_delete
            BEFORE DELETE ON buy_order_confirmations
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'buy order confirmations are retained');
            END",
        "buy_order_payment_evidence_insert_guard" => "CREATE TRIGGER buy_order_payment_evidence_insert_guard
            BEFORE INSERT ON buy_order_payment_evidence
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM buy_orders
                WHERE id = NEW.order_id
                  AND buyer_id = NEW.submitted_by
                  AND payment_method = NEW.payment_method
                  AND status = 'in_progress'
            )
            OR (
                NEW.payment_method = 'cash_on_handover'
                AND NEW.evidence_storage_key IS NOT NULL
            )
            OR (
                NEW.payment_method IN ('promptpay', 'bank_transfer')
                AND NEW.evidence_storage_key IS NULL
            )
            OR EXISTS (
                SELECT 1 FROM buy_order_payment_evidence
                WHERE order_id = NEW.order_id AND status IN ('submitted', 'verified')
            )
            BEGIN
                SELECT RAISE(ABORT, 'payment evidence is not permitted');
            END",
        "buy_order_payment_evidence_update_guard" => "CREATE TRIGGER buy_order_payment_evidence_update_guard
            BEFORE UPDATE ON buy_order_payment_evidence
            FOR EACH ROW
            WHEN NEW.order_id <> OLD.order_id
              OR NEW.submitted_by <> OLD.submitted_by
              OR NEW.payment_method <> OLD.payment_method
              OR NEW.evidence_storage_key IS NOT OLD.evidence_storage_key
              OR NEW.submitted_at <> OLD.submitted_at
              OR OLD.status <> 'submitted'
              OR NEW.status NOT IN ('verified', 'rejected')
              OR NEW.verified_by IS NULL
              OR NEW.verified_at IS NULL
              OR NOT EXISTS (
                  SELECT 1 FROM buy_orders
                  WHERE id = OLD.order_id
                    AND seller_id = NEW.verified_by
                    AND status = 'in_progress'
              )
              OR (NEW.status = 'rejected' AND length(trim(COALESCE(NEW.review_note, ''))) = 0)
              OR (NEW.status = 'verified' AND NEW.review_note IS NOT NULL)
            BEGIN
                SELECT RAISE(ABORT, 'invalid payment evidence decision');
            END",
        "buy_order_payment_evidence_no_delete" => "CREATE TRIGGER buy_order_payment_evidence_no_delete
            BEFORE DELETE ON buy_order_payment_evidence
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'payment evidence history is retained');
            END",
        "notifications_update_guard" => "CREATE TRIGGER notifications_update_guard
            BEFORE UPDATE ON notifications
            FOR EACH ROW
            WHEN NEW.recipient_id <> OLD.recipient_id
              OR NEW.notification_type <> OLD.notification_type
              OR NEW.reference_type <> OLD.reference_type
              OR NEW.reference_id <> OLD.reference_id
              OR NEW.title <> OLD.title
              OR NEW.message <> OLD.message
              OR NEW.created_at <> OLD.created_at
              OR OLD.read_at IS NOT NULL
              OR NEW.read_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, 'notification content is immutable');
            END",
        "notifications_no_delete" => "CREATE TRIGGER notifications_no_delete
            BEFORE DELETE ON notifications
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'notification history is retained');
            END",
        "reviews_insert_guard" => "CREATE TRIGGER reviews_insert_guard
            BEFORE INSERT ON reviews
            FOR EACH ROW
            WHEN (
                NEW.order_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM buy_orders
                    WHERE id = NEW.order_id
                      AND status = 'completed'
                      AND (
                          (buyer_id = NEW.reviewer_id AND seller_id = NEW.reviewee_id)
                          OR
                          (seller_id = NEW.reviewer_id AND buyer_id = NEW.reviewee_id)
                      )
                )
            )
            OR (
                NEW.exchange_transaction_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM exchange_transactions
                    WHERE id = NEW.exchange_transaction_id
                      AND status = 'completed'
                      AND (
                          (creator_id = NEW.reviewer_id AND recipient_id = NEW.reviewee_id)
                          OR
                          (recipient_id = NEW.reviewer_id AND creator_id = NEW.reviewee_id)
                      )
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'review is not permitted');
            END",
        "reviews_update_guard" => "CREATE TRIGGER reviews_update_guard
            BEFORE UPDATE ON reviews
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'reviews are immutable');
            END",
        "reviews_no_delete" => "CREATE TRIGGER reviews_no_delete
            BEFORE DELETE ON reviews
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'reviews are retained');
            END",
        "exchange_completion_guard" => "CREATE TRIGGER exchange_completion_guard
            BEFORE UPDATE OF status ON exchange_transactions
            FOR EACH ROW
            WHEN OLD.status = 'in_progress'
              AND NEW.status = 'completed'
              AND (
                  NEW.completed_at IS NULL
                  OR (
                      SELECT COUNT(*) FROM handover_confirmations
                      WHERE transaction_id = OLD.id
                  ) <> 4
                  OR EXISTS (
                      SELECT 1 FROM transaction_disputes
                      WHERE transaction_id = OLD.id AND resolved_at IS NULL
                  )
                  OR (OLD.cash_amount_minor = 0 AND OLD.payment_status <> 'not_required')
                  OR (OLD.cash_amount_minor > 0 AND OLD.payment_status <> 'verified')
                  OR NOT EXISTS (
                      SELECT 1 FROM products
                      WHERE id = OLD.offered_product_id
                        AND user_id = OLD.recipient_id
                        AND status = 'inactive'
                  )
                  OR NOT EXISTS (
                      SELECT 1 FROM products
                      WHERE id = OLD.target_product_id
                        AND user_id = OLD.creator_id
                        AND status = 'inactive'
                  )
                  OR EXISTS (
                      SELECT 1 FROM product_reservations
                      WHERE product_id IN (OLD.offered_product_id, OLD.target_product_id)
                  )
              )
            BEGIN
                SELECT RAISE(ABORT, 'exchange completion requirements are not met');
            END"
    ];
}

function verify_buy_orders_schema(PDO $pdo): void
{
    foreach (array_keys(buy_orders_table_sql()) as $table) {
        if (!buy_orders_object_exists($pdo, "table", $table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }
    $userColumns = array_column(
        $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
    if (!in_array("phone", $userColumns, true) || !in_array("address", $userColumns, true)) {
        throw new RuntimeException("User contact columns are missing.");
    }
    foreach (buy_orders_trigger_sql() as $name => $sql) {
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
        "idx_buy_orders_buyer_created" => ["buy_orders", ["buyer_id", "created_at"]],
        "idx_buy_orders_seller_created" => ["buy_orders", ["seller_id", "created_at"]],
        "idx_buy_orders_product_active" => ["buy_orders", ["product_id"]],
        "idx_buy_order_confirmations_confirmer" => ["buy_order_confirmations", ["confirmer_id", "confirmed_at"]],
        "idx_buy_order_payment_evidence_order" => ["buy_order_payment_evidence", ["order_id", "id"]],
        "idx_notifications_recipient_read_created" => ["notifications", ["recipient_id", "read_at", "created_at"]],
        "idx_reviews_reviewee_created" => ["reviews", ["reviewee_id", "created_at"]],
        "idx_reviews_exchange_reviewer" => ["reviews", ["exchange_transaction_id", "reviewer_id"]],
        "idx_reviews_order_reviewer" => ["reviews", ["order_id", "reviewer_id"]]
    ] as $name => [$table, $expectedColumns]) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :name"
        );
        $stmt->execute(["name" => $name]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Required index {$name} is missing.");
        }
        $actualColumns = array_column(
            $pdo->query("PRAGMA index_info(\"{$name}\")")->fetchAll(PDO::FETCH_ASSOC),
            "name"
        );
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException("Index {$name} has unexpected columns.");
        }
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 7"
    )->fetchColumn() !== 1) {
        throw new RuntimeException("Migration version 7 is not recorded exactly once.");
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
    abort_buy_orders_migration("this migration supports only SQLite.");
}
if (
    $projectRoot === false
    || $resolvedPath === false
    || $resolvedPath !== $expectedPath
    || !is_file($resolvedPath)
) {
    abort_buy_orders_migration("the selected path is not the expected project database.");
}
if (!in_array("sqlite", PDO::getAvailableDrivers(), true) || !class_exists("SQLite3")) {
    abort_buy_orders_migration("PDO SQLite and SQLite3 backup support are required.");
}

$backupPath = null;
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
        abort_buy_orders_migration("schema_migrations is missing or has an unexpected schema.");
    }
    $versionSixCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 6"
    )->fetchColumn();
    $versionSevenCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE version = 7"
    )->fetchColumn();
    if ($versionSevenCount === 1) {
        verify_buy_orders_schema($pdo);
        fwrite(STDOUT, "Migration version 7 is already applied and verified.\n");
        exit(0);
    }
    if ($versionSevenCount !== 0 || $versionSixCount !== 1) {
        abort_buy_orders_migration("version 6 must be recorded once and version 7 must not be recorded.");
    }

    $before = buy_orders_snapshot($pdo);
    $userColumns = array_column(
        $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
    if (in_array("phone", $userColumns, true) || in_array("address", $userColumns, true)) {
        abort_buy_orders_migration("the users table already contains one of the new contact columns.");
    }
    $invalidCategories = (int) $pdo->query(
        "SELECT COUNT(*) FROM products
         WHERE category IS NOT NULL
           AND category NOT IN (
               'electronics', 'fashion', 'home_living', 'books',
               'sports_outdoors', 'games_collectibles', 'other'
           )"
    )->fetchColumn();
    if ($invalidCategories !== 0) {
        abort_buy_orders_migration("existing products contain an unsupported category.");
    }
    foreach (array_merge(
        array_keys(buy_orders_table_sql()),
        array_keys(buy_orders_trigger_sql()),
        [
            "idx_buy_orders_buyer_created",
            "idx_buy_orders_seller_created",
            "idx_buy_orders_product_active",
            "idx_buy_order_confirmations_confirmer",
            "idx_buy_order_payment_evidence_order",
            "idx_notifications_recipient_read_created",
            "idx_reviews_reviewee_created",
            "idx_reviews_exchange_reviewer",
            "idx_reviews_order_reviewer",
            "exchange_completion_guard"
        ]
    ) as $name) {
        if (buy_orders_object_exists($pdo, "table", $name)
            || buy_orders_object_exists($pdo, "index", $name)
            || buy_orders_object_exists($pdo, "trigger", $name)
        ) {
            abort_buy_orders_migration("migration object {$name} already exists.");
        }
    }

    fwrite(STDOUT, "Type APPLY BUY ORDERS MIGRATION 7 to create a backup and apply: ");
    $confirmation = fgets(STDIN);
    if ($confirmation === false || !hash_equals(
        "APPLY BUY ORDERS MIGRATION 7",
        trim($confirmation)
    )) {
        abort_buy_orders_migration("confirmation did not match.");
    }

    $backupDirectory = $projectRoot . DIRECTORY_SEPARATOR . ".database-backups";
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700)) {
        throw new RuntimeException("Could not create the private backup directory.");
    }
    if (!is_dir($backupDirectory) || !chmod($backupDirectory, 0700)) {
        throw new RuntimeException("The backup directory could not be secured.");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
        . "hybrid_marketplace.sqlite.backup-v7-" . gmdate("Ymd\THis\Z") . "-"
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
        buy_orders_snapshot($backup) !== $before
        || $backup->query("PRAGMA integrity_check")->fetchColumn() !== "ok"
        || $backup->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) !== []
    ) {
        throw new RuntimeException("The backup did not verify against the inspected database.");
    }

    $pdo->exec("BEGIN IMMEDIATE");
    try {
        if (buy_orders_snapshot($pdo) !== $before) {
            throw new RuntimeException("Marketplace data changed after the backup.");
        }
        if ((int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = 6"
        )->fetchColumn() !== 1) {
            throw new RuntimeException("Migration version 6 changed after preflight.");
        }
        $pdo->exec(
            "ALTER TABLE users ADD COLUMN phone TEXT NULL
             CHECK (phone IS NULL OR length(trim(phone)) BETWEEN 7 AND 32)"
        );
        $pdo->exec(
            "ALTER TABLE users ADD COLUMN address TEXT NULL
             CHECK (address IS NULL OR length(trim(address)) <= 500)"
        );
        foreach (buy_orders_table_sql() as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec(
            "INSERT INTO categories (category_code, category_name, description) VALUES
                ('electronics', 'Electronics', NULL),
                ('fashion', 'Fashion', NULL),
                ('home_living', 'Home & Living', NULL),
                ('books', 'Books', NULL),
                ('sports_outdoors', 'Sports & Outdoors', NULL),
                ('games_collectibles', 'Games & Collectibles', NULL),
                ('other', 'Other', NULL)"
        );
        $pdo->exec(
            "CREATE INDEX idx_buy_orders_buyer_created
             ON buy_orders (buyer_id, created_at)"
        );
        $pdo->exec(
            "CREATE INDEX idx_buy_orders_seller_created
             ON buy_orders (seller_id, created_at)"
        );
        $pdo->exec(
            "CREATE UNIQUE INDEX idx_buy_orders_product_active
             ON buy_orders (product_id) WHERE status = 'in_progress'"
        );
        $pdo->exec(
            "CREATE INDEX idx_buy_order_confirmations_confirmer
             ON buy_order_confirmations (confirmer_id, confirmed_at)"
        );
        $pdo->exec(
            "CREATE INDEX idx_buy_order_payment_evidence_order
             ON buy_order_payment_evidence (order_id, id)"
        );
        $pdo->exec(
            "CREATE INDEX idx_notifications_recipient_read_created
             ON notifications (recipient_id, read_at, created_at)"
        );
        $pdo->exec(
            "CREATE INDEX idx_reviews_reviewee_created
             ON reviews (reviewee_id, created_at)"
        );
        $pdo->exec(
            "CREATE UNIQUE INDEX idx_reviews_exchange_reviewer
             ON reviews (exchange_transaction_id, reviewer_id)
             WHERE exchange_transaction_id IS NOT NULL"
        );
        $pdo->exec(
            "CREATE UNIQUE INDEX idx_reviews_order_reviewer
             ON reviews (order_id, reviewer_id)
             WHERE order_id IS NOT NULL"
        );
        foreach (buy_orders_trigger_sql() as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO schema_migrations (version) VALUES (7)");
        verify_buy_orders_schema($pdo);
        if (
            buy_orders_snapshot($pdo) !== $before
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
    fwrite(STDOUT, "Migration version 7 applied and verified.\n");
    fwrite(STDOUT, "Existing users, products, offers, and exchange transactions were preserved.\n");
    fwrite(STDOUT, "Backup retained at: {$backupPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
