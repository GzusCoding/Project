<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "buy-order-workflow-test-" . bin2hex(random_bytes(8));
$appRoot = $temporaryRoot . DIRECTORY_SEPARATOR . "app";
$databasePath = $appRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$evidencePath = $temporaryRoot . DIRECTORY_SEPARATOR . "private-evidence";
$paymentSlipPath = $temporaryRoot . DIRECTORY_SEPARATOR . "payment-slip.png";
$invalidPaymentPath = $temporaryRoot . DIRECTORY_SEPARATOR . "invalid-payment.txt";
$chatImagePath = $temporaryRoot . DIRECTORY_SEPARATOR . "chat-image.png";
$oversizedChatImagePath = $temporaryRoot . DIRECTORY_SEPARATOR . "oversized-chat-image.png";
$chatImageDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . "private-chat-images";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;
$created = false;

function buy_order_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function buy_order_test_cleanup(string $root): void
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

function buy_order_test_migration(string $script, string $databasePath, ?string $confirmation): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $script],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
        dirname($script),
        [
            "DB_DRIVER" => "sqlite",
            "DB_PATH" => $databasePath,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start the isolated buy-order migration.");
    }
    if ($confirmation !== null) {
        fwrite($pipes[0], $confirmation . "\n");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

function buy_order_test_session(string $path, string $id, int $userId, string $csrf): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId, "csrf_token" => $csrf];
    session_write_close();
}

function buy_order_test_request(
    string $baseUrl,
    string $path,
    ?string $sessionId,
    ?array $form = null,
    ?string $uploadPath = null,
    string $uploadField = "evidence_file",
    string $uploadFileName = "slip.png",
    string $uploadMimeType = "image/png"
): array {
    $headers = ["Connection: close"];
    if ($sessionId !== null) {
        $headers[] = "Cookie: PHPSESSID=" . $sessionId;
    }
    $options = [
        "http" => [
            "method" => $form === null ? "GET" : "POST",
            "header" => $headers,
            "ignore_errors" => true,
            "timeout" => 5,
            "follow_location" => 0
        ]
    ];
    if ($form !== null && $uploadPath === null) {
        $options["http"]["header"][] = "Content-Type: application/x-www-form-urlencoded";
        $options["http"]["content"] = http_build_query($form);
    } elseif ($form !== null) {
        $boundary = "----BuyOrderTest" . bin2hex(random_bytes(8));
        $body = "";
        foreach ($form as $name => $value) {
            $body .= "--{$boundary}\r\n"
                . 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n"
                . $value . "\r\n";
        }
        $body .= "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="' . $uploadField . '"; filename="' . $uploadFileName . '"' . "\r\n"
            . "Content-Type: {$uploadMimeType}\r\n\r\n"
            . file_get_contents($uploadPath) . "\r\n"
            . "--{$boundary}--\r\n";
        $options["http"]["header"][] = "Content-Type: multipart/form-data; boundary={$boundary}";
        $options["http"]["content"] = $body;
    }
    $body = @file_get_contents($baseUrl . $path, false, stream_context_create($options));
    $headers = $http_response_header ?? [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('/\AHTTP\/\S+ ([0-9]{3})/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    return [$status, is_string($body) ? $body : "", $headers];
}

try {
    if (
        !mkdir($temporaryRoot, 0700)
        || !mkdir($appRoot, 0700)
        || !mkdir($sessionPath, 0700)
        || !mkdir($evidencePath, 0700)
    ) {
        throw new RuntimeException("Could not create isolated buy-order test directories.");
    }
    $png = base64_decode(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXioAAAAASUVORK5CYII=",
        true
    );
    if (
        !is_string($png)
        || file_put_contents($paymentSlipPath, $png) === false
        || file_put_contents($chatImagePath, $png) === false
        || file_put_contents($oversizedChatImagePath, str_repeat("x", 5 * 1024 * 1024 + 1)) === false
    ) {
        throw new RuntimeException("Could not create the isolated payment-slip fixture.");
    }
    if (file_put_contents($invalidPaymentPath, "not a payment image") === false) {
        throw new RuntimeException("Could not create the invalid payment-slip fixture.");
    }
    $created = true;
    if (!mkdir($appRoot . DIRECTORY_SEPARATOR . "includes", 0700)) {
        throw new RuntimeException("Could not create isolated shared-code directory.");
    }
    foreach ([
        "migrate_buy_orders.php", "migrate_reports.php", "migrate_buy_order_chats.php",
        "migrate_chat_images.php",
        "db.php", "security.php", "i18n.php", "buy_order_support.php",
        "category_support.php",
        "create_order.php", "order_action.php", "buy_order.php", "my_orders.php",
        "product_detail.php", "review_action.php", "report_action.php",
        "admin_report_action.php", "admin.php", "register.php",
        "handover_support.php", "handover_action.php", "exchange_completion_support.php",
        "notification_support.php", "payment_support.php", "notifications.php",
        "notification_action.php", "my_reports.php", "user_profile.php", "buy_order_evidence.php",
        "chat_messages.php", "chat_image.php", "my_chats.php",
        "chat_media_support.php"
    ] as $file) {
        $isMigration = str_starts_with($file, "migrate_");
        $isSharedCode = in_array($file, [
            "db.php", "security.php", "i18n.php", "buy_order_support.php",
            "category_support.php",
            "handover_support.php", "exchange_completion_support.php",
            "notification_support.php", "payment_support.php", "chat_media_support.php"
        ], true);
        $sourceDirectory = $isMigration ? "migrations" : ($isSharedCode ? "includes" : null);
        $sourcePath = $projectRoot . DIRECTORY_SEPARATOR
            . ($sourceDirectory === null ? "" : $sourceDirectory . DIRECTORY_SEPARATOR)
            . $file;
        $targetPath = $appRoot . DIRECTORY_SEPARATOR
            . ($isSharedCode ? "includes" . DIRECTORY_SEPARATOR : "")
            . $file;
        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException("Could not copy {$file} into the isolated application.");
        }
    }

    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec(
        "CREATE TABLE schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
        "CREATE TABLE offers (
            id INTEGER PRIMARY KEY,
            creator_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            cash_payer_id INTEGER NOT NULL,
            offered_product_id INTEGER NOT NULL,
            target_product_id INTEGER NOT NULL,
            cash_amount_minor INTEGER NOT NULL,
            cash_currency TEXT,
            currency_exponent INTEGER,
            status TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME
        )"
    );
    $pdo->exec(
        "CREATE TABLE exchange_transactions (
            id INTEGER PRIMARY KEY,
            offer_id INTEGER NOT NULL,
            creator_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            offered_product_id INTEGER NOT NULL,
            target_product_id INTEGER NOT NULL,
            cash_payer_id INTEGER NOT NULL,
            cash_amount_minor INTEGER NOT NULL,
            cash_currency TEXT,
            currency_exponent INTEGER,
            payment_method TEXT,
            payment_status TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            cancelled_at DATETIME
        )"
    );
    $pdo->exec(
        "CREATE TABLE chat_rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exchange_transaction_id INTEGER NOT NULL UNIQUE,
            creator_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            CHECK (creator_id <> recipient_id),
            FOREIGN KEY (exchange_transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT,
            FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            message_body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE RESTRICT,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "CREATE TABLE product_reservations (
            product_id INTEGER PRIMARY KEY,
            transaction_id INTEGER NOT NULL,
            reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "CREATE TABLE handover_confirmations (
            id INTEGER PRIMARY KEY,
            transaction_id INTEGER NOT NULL,
            confirmation_type TEXT NOT NULL,
            confirmer_id INTEGER NOT NULL,
            confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (transaction_id, confirmation_type)
        )"
    );
    $pdo->exec(
        "CREATE TABLE transaction_disputes (
            id INTEGER PRIMARY KEY,
            transaction_id INTEGER NOT NULL,
            reported_by INTEGER NOT NULL,
            reason TEXT NOT NULL,
            reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_by INTEGER,
            resolution TEXT,
            resolution_reason TEXT,
            resolved_at DATETIME
        )"
    );
    $pdo->exec(
        "INSERT INTO users (id, name, email, password)
         VALUES
            (1, 'Seller', 'seller@example.test', 'unused'),
            (2, 'Buyer', 'buyer@example.test', 'unused'),
            (3, 'Other', 'other@example.test', 'unused')"
    );
    $pdo->exec(
        "INSERT INTO products (
            id, user_id, name, price, category, transaction_type, item_condition, status
         ) VALUES
            (101, 1, 'Available item', 42.50, 'other', 'buy', 'used', 'active'),
            (102, 1, 'Exchange only', 10.00, 'other', 'exchange', 'used', 'active'),
            (103, 1, 'Other item', 19.99, 'other', 'both', 'used', 'active'),
            (104, 1, 'Seller profile item', 12.00, 'other', 'buy', 'used', 'active')"
    );
    $pdo->exec(
        "INSERT INTO schema_migrations (version) VALUES (1), (2), (3), (4), (5), (6)"
    );
    $pdo = null;

    [$migrationExit, $migrationOut, $migrationError] = buy_order_test_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_buy_orders.php",
        $databasePath,
        "APPLY BUY ORDERS MIGRATION 7"
    );
    buy_order_test_assert(
        $migrationExit === 0 && str_contains($migrationOut, "applied and verified"),
        "The v7 migration failed: {$migrationError}"
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() === 7
            && in_array(
                "phone",
                array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), "name"),
                true
            )
            && in_array(
                "address",
                array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), "name"),
                true
            ),
        "The v7 migration did not create the Word user/contact and category structures."
    );
    try {
        $pdo->exec(
            "INSERT INTO products (
                id, user_id, name, price, category, transaction_type, item_condition, status
             ) VALUES (999, 1, 'Invalid category', 1, 'unknown', 'buy', 'used', 'active')"
        );
        throw new RuntimeException("An unsupported product category was accepted.");
    } catch (PDOException) {
    }
    $pdo = null;
    [$rerunExit, $rerunOut, $rerunError] = buy_order_test_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_buy_orders.php",
        $databasePath,
        null
    );
    buy_order_test_assert(
        $rerunExit === 0 && str_contains($rerunOut, "already applied and verified"),
        "A verified migration rerun failed: {$rerunError}"
    );
    [$reportsMigrationExit, $reportsMigrationOut, $reportsMigrationError] = buy_order_test_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_reports.php",
        $databasePath,
        "APPLY REPORTS MIGRATION 8"
    );
    buy_order_test_assert(
        $reportsMigrationExit === 0 && str_contains($reportsMigrationOut, "applied and verified"),
        "The v8 reports migration failed: {$reportsMigrationError}"
    );
    [$reportsRerunExit, $reportsRerunOut, $reportsRerunError] = buy_order_test_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_reports.php",
        $databasePath,
        null
    );
    buy_order_test_assert(
        $reportsRerunExit === 0 && str_contains($reportsRerunOut, "already applied and verified"),
        "A verified reports migration rerun failed: {$reportsRerunError}"
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "INSERT INTO buy_orders (
            buyer_id, seller_id, product_id, product_name, price_minor, payment_method, created_at
         ) VALUES (2, 1, 104, 'Existing order before chat migration', 1234, 'promptpay', '2026-01-01 00:00:00')"
    );
    $legacyOrderId = (int) $pdo->lastInsertId();
    $pdo = null;
    [$chatMigrationExit, $chatMigrationOut, $chatMigrationError] = buy_order_test_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_buy_order_chats.php",
        $databasePath,
        "APPLY BUY ORDER CHATS MIGRATION 9"
    );
    buy_order_test_assert(
        $chatMigrationExit === 0 && str_contains($chatMigrationOut, "applied and verified"),
        "The v9 buy-order chat migration failed: {$chatMigrationError}"
    );
    [$chatMigrationRerunExit, $chatMigrationRerunOut, $chatMigrationRerunError] = buy_order_test_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_buy_order_chats.php",
        $databasePath,
        null
    );
    buy_order_test_assert(
        $chatMigrationRerunExit === 0 && str_contains($chatMigrationRerunOut, "already applied and verified"),
        "A verified buy-order chat migration rerun failed: {$chatMigrationRerunError}"
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM buy_order_chat_rooms
             WHERE buy_order_id = {$legacyOrderId} AND buyer_id = 2 AND seller_id = 1"
        )->fetchColumn() === 1,
        "The v9 migration did not create a room for an existing purchase order."
    );
    $pdo = null;
    [$imageMigrationExit, $imageMigrationOut, $imageMigrationError] = buy_order_test_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_chat_images.php",
        $databasePath,
        "APPLY CHAT IMAGES MIGRATION 10"
    );
    buy_order_test_assert(
        $imageMigrationExit === 0 && str_contains($imageMigrationOut, "applied and verified"),
        "The v10 chat image migration failed: {$imageMigrationError}"
    );
    [$imageMigrationRerunExit, $imageMigrationRerunOut, $imageMigrationRerunError] = buy_order_test_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_chat_images.php",
        $databasePath,
        null
    );
    buy_order_test_assert(
        $imageMigrationRerunExit === 0
            && str_contains($imageMigrationRerunOut, "already applied and verified"),
        "A verified chat image migration rerun failed: {$imageMigrationRerunError}"
    );

    if (!mkdir($appRoot . DIRECTORY_SEPARATOR . "assets", 0700)
        || !mkdir($appRoot . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "js", 0700)
        || !copy(
            $projectRoot . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "js" . DIRECTORY_SEPARATOR . "chat.js",
            $appRoot . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "js" . DIRECTORY_SEPARATOR . "chat.js"
        )
    ) {
        throw new RuntimeException("Could not copy the chat client into the isolated application.");
    }

    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
    $pdo->exec("UPDATE users SET role = 'admin' WHERE id = 3");
    $pdo->exec(
        "INSERT INTO users (id, name, email, password, phone, address, role)
         VALUES (4, 'New member', 'new@example.test', 'unused', '0812345678', 'Campus address', 'user')"
    );
    $pdo->exec(
        "INSERT INTO products (
            id, user_id, name, price, category, transaction_type, item_condition, status
         ) VALUES
            (105, 1, 'Offered exchange item', 15.00, 'other', 'exchange', 'used', 'reserved'),
            (106, 2, 'Requested exchange item', 14.00, 'other', 'exchange', 'used', 'reserved')"
    );
    $pdo->exec(
        "INSERT INTO offers (
            id, creator_id, recipient_id, cash_payer_id, offered_product_id,
            target_product_id, cash_amount_minor, status
         ) VALUES (1, 1, 2, 1, 105, 106, 0, 'accepted')"
    );
    $pdo->exec(
        "INSERT INTO exchange_transactions (
            id, offer_id, creator_id, recipient_id, offered_product_id,
            target_product_id, cash_payer_id, cash_amount_minor,
            payment_status, status
         ) VALUES (1, 1, 1, 2, 105, 106, 1, 0, 'not_required', 'in_progress')"
    );
    $pdo->exec(
        "INSERT INTO chat_rooms (exchange_transaction_id, creator_id, recipient_id)
         VALUES (1, 1, 2)"
    );
    $pdo->exec(
        "INSERT INTO product_reservations (product_id, transaction_id)
         VALUES (103, 99), (105, 1), (106, 1)"
    );
    $pdo = null;

    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve a local test port: " . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) $address, strrpos((string) $address, ":") + 1);
    $baseUrl = "http://127.0.0.1:" . $port;
    $pipes = [];
    $server = proc_open(
        [
            PHP_BINARY,
            "-d",
            "session.save_path=" . $sessionPath,
            "-d",
            "upload_max_filesize=6M",
            "-d",
            "post_max_size=8M",
            "-S",
            "127.0.0.1:" . $port,
            "-t",
            $appRoot
        ],
        [0 => ["pipe", "r"], 1 => ["file", $serverLog, "a"], 2 => ["file", $serverLog, "a"]],
        $pipes,
        $appRoot,
        [
            "DB_DRIVER" => "sqlite",
            "DB_PATH" => $databasePath,
            "PAYMENT_EVIDENCE_DIR" => $evidencePath,
            "CHAT_MEDIA_DIR" => $chatImageDirectory,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    if (!is_resource($server)) {
        throw new RuntimeException("Could not start the isolated PHP test server.");
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen("127.0.0.1", $port, $probeErrno, $probeError, 0.1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    buy_order_test_assert($ready, "The isolated PHP server did not start.");

    $buyerSession = bin2hex(random_bytes(16));
    $buyerCsrf = bin2hex(random_bytes(32));
    $sellerSession = bin2hex(random_bytes(16));
    $sellerCsrf = bin2hex(random_bytes(32));
    $otherSession = bin2hex(random_bytes(16));
    $otherCsrf = bin2hex(random_bytes(32));
    $adminSession = bin2hex(random_bytes(16));
    $adminCsrf = bin2hex(random_bytes(32));
    $registrationSession = bin2hex(random_bytes(16));
    $registrationCsrf = bin2hex(random_bytes(32));
    buy_order_test_session($sessionPath, $buyerSession, 2, $buyerCsrf);
    buy_order_test_session($sessionPath, $sellerSession, 1, $sellerCsrf);
    buy_order_test_session($sessionPath, $otherSession, 3, $otherCsrf);
    buy_order_test_session($sessionPath, $adminSession, 3, $adminCsrf);
    buy_order_test_session($sessionPath, $registrationSession, 3, $registrationCsrf);

    [$detailStatus, $detailBody] = buy_order_test_request(
        $baseUrl,
        "/product_detail.php?id=101",
        $buyerSession
    );
    buy_order_test_assert(
        $detailStatus === 200 && str_contains($detailBody, 'action="create_order.php"'),
        "An available buy listing did not expose the purchase workflow."
    );
    [$registrationStatus, $registrationBody] = buy_order_test_request(
        $baseUrl,
        "/register.php",
        $registrationSession,
        [
            "csrf_token" => $registrationCsrf,
            "name" => "New member",
            "email" => "registered@example.test",
            "phone" => "0812345678",
            "address" => "Dormitory",
            "password" => "secure-password",
            "confirm_password" => "secure-password"
        ]
    );
    buy_order_test_assert(
        $registrationStatus === 200 && str_contains($registrationBody, "สมัครสมาชิกสำเร็จ"),
        "Registration did not accept and store the Word contact fields."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $registeredContact = $pdo->query(
        "SELECT phone, address FROM users WHERE email = 'registered@example.test'"
    )->fetch(PDO::FETCH_ASSOC);
    buy_order_test_assert(
        $registeredContact !== false
            && $registeredContact["phone"] === "0812345678"
            && $registeredContact["address"] === "Dormitory",
        "Registration did not persist phone and address."
    );
    $pdo = null;
    [$exchangeStatus] = buy_order_test_request(
        $baseUrl,
        "/create_order.php",
        $buyerSession,
        [
            "csrf_token" => $buyerCsrf,
            "product_id" => "102",
            "payment_method" => "promptpay"
        ]
    );
    buy_order_test_assert($exchangeStatus === 409, "An exchange-only product could be purchased.");
    [$reservedStatus] = buy_order_test_request(
        $baseUrl,
        "/create_order.php",
        $sellerSession,
        [
            "csrf_token" => $sellerCsrf,
            "product_id" => "103",
            "payment_method" => "promptpay"
        ]
    );
    buy_order_test_assert($reservedStatus === 403, "A user could buy their own product.");

    [$createStatus, , $createHeaders] = buy_order_test_request(
        $baseUrl,
        "/create_order.php",
        $buyerSession,
        [
            "csrf_token" => $buyerCsrf,
            "product_id" => "101",
            "payment_method" => "bank_transfer"
        ]
    );
    $location = "";
    foreach ($createHeaders as $header) {
        if (stripos($header, "Location:") === 0) {
            $location = trim(substr($header, strlen("Location:")));
        }
    }
    buy_order_test_assert(
        $createStatus === 303 && preg_match('/\Abuy_order\.php\?id=([1-9][0-9]*)\z/', $location, $match) === 1,
        "A valid purchase did not create an order and redirect to its details."
    );
    $orderId = (int) $match[1];
    [$prematureReviewStatus] = buy_order_test_request(
        $baseUrl,
        "/review_action.php",
        $buyerSession,
        [
            "csrf_token" => $buyerCsrf,
            "order_id" => (string) $orderId,
            "rating" => "5",
            "comment" => "Good transaction"
        ]
    );
    buy_order_test_assert(
        $prematureReviewStatus === 403,
        "A review was accepted before the order completed."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare(
        "SELECT buyer_id, seller_id, product_id, price_minor, payment_method, status
         FROM buy_orders WHERE id = :id"
    );
    $stmt->execute(["id" => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    buy_order_test_assert(
        $order !== false
            && (int) $order["buyer_id"] === 2
            && (int) $order["seller_id"] === 1
            && (int) $order["product_id"] === 101
            && (int) $order["price_minor"] === 4250
            && $order["payment_method"] === "bank_transfer"
            && $order["status"] === "in_progress"
            && $pdo->query("SELECT status FROM products WHERE id = 101")->fetchColumn() === "reserved",
        "The order snapshot or product reservation was incorrect."
    );
    buy_order_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM notifications
             WHERE recipient_id = 1 AND notification_type = 'buy_order_created'
               AND reference_type = 'buy_order' AND reference_id = {$orderId}"
        )->fetchColumn() === 1,
        "Creating a buy order did not notify its seller."
    );
    buy_order_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM buy_order_chat_rooms
             WHERE buy_order_id = {$orderId} AND buyer_id = 2 AND seller_id = 1"
        )->fetchColumn() === 1,
        "Creating a buy order did not create its private chat room."
    );
    $stmt->closeCursor();
    unset($stmt);
    $pdo = null;

    [$duplicateStatus] = buy_order_test_request(
        $baseUrl,
        "/create_order.php",
        $otherSession,
        [
            "csrf_token" => $otherCsrf,
            "product_id" => "101",
            "payment_method" => "promptpay"
        ]
    );
    buy_order_test_assert($duplicateStatus === 409, "A reserved product accepted a second buy order.");
    [$privateStatus] = buy_order_test_request(
        $baseUrl,
        "/buy_order.php?id=" . $orderId,
        $otherSession
    );
    buy_order_test_assert($privateStatus === 404, "A nonparticipant could view a buy order.");
    [$chatPageStatus, $chatPage] = buy_order_test_request(
        $baseUrl,
        "/buy_order.php?id=" . $orderId,
        $buyerSession
    );
    buy_order_test_assert(
        $chatPageStatus === 200
            && str_contains($chatPage, 'data-context-type="order"')
            && str_contains($chatPage, 'src="assets/js/chat.js"')
            && str_contains($chatPage, 'name="image_file"')
            && str_contains($chatPage, "image/webp"),
        "The buy order details page did not expose its chat and image client."
    );
    [$initialChatStatus, $initialChatBody] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php?order_id=" . $orderId,
        $buyerSession
    );
    $initialChat = json_decode($initialChatBody, true, 512, JSON_THROW_ON_ERROR);
    buy_order_test_assert(
        $initialChatStatus === 200
            && $initialChat["order_id"] === $orderId
            && $initialChat["messages"] === []
            && $initialChat["can_send"],
        "A buyer could not open the newly created purchase chat."
    );
    [$orderChatSendStatus, $orderChatSendBody] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php",
        $buyerSession,
        [
            "order_id" => (string) $orderId,
            "message_body" => "Can we arrange delivery?",
            "csrf_token" => $buyerCsrf
        ]
    );
    $orderChatSent = json_decode($orderChatSendBody, true, 512, JSON_THROW_ON_ERROR);
    buy_order_test_assert(
        $orderChatSendStatus === 201
            && $orderChatSent["message"]["message_body"] === "Can we arrange delivery?",
        "The buyer could not send a message in the purchase chat."
    );
    [$sellerChatStatus, $sellerChatBody] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php?order_id=" . $orderId,
        $sellerSession
    );
    $sellerChat = json_decode($sellerChatBody, true, 512, JSON_THROW_ON_ERROR);
    buy_order_test_assert(
        $sellerChatStatus === 200
            && count($sellerChat["messages"]) === 1
            && $sellerChat["messages"][0]["message_body"] === "Can we arrange delivery?",
        "The seller could not receive the buyer's message."
    );
    [$privateChatStatus] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php?order_id=" . $orderId,
        $otherSession
    );
    buy_order_test_assert($privateChatStatus === 404, "A nonparticipant accessed a purchase chat.");
    [$chatInboxStatus, $chatInboxBody] = buy_order_test_request(
        $baseUrl,
        "/my_chats.php",
        $sellerSession
    );
    buy_order_test_assert(
        $chatInboxStatus === 200
            && str_contains($chatInboxBody, "รายการซื้อ #" . $orderId)
            && str_contains($chatInboxBody, "Can we arrange delivery?")
            && str_contains($chatInboxBody, 'href="buy_order.php?id=' . $orderId . '"'),
        "The seller's chat inbox did not list the purchase conversation."
    );
    [$orderImageSendStatus, $orderImageSendBody] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php",
        $buyerSession,
        [
            "order_id" => (string) $orderId,
            "message_body" => "",
            "csrf_token" => $buyerCsrf
        ],
        $chatImagePath,
        "image_file",
        "product.png"
    );
    $orderImageMessage = json_decode($orderImageSendBody, true, 512, JSON_THROW_ON_ERROR);
    buy_order_test_assert(
        $orderImageSendStatus === 201
            && $orderImageMessage["message"]["message_body"] === "[image]"
            && str_contains($orderImageMessage["message"]["image_url"], "chat_image.php?order_id={$orderId}"),
        "The buyer could not send an image-only message in the purchase chat."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare(
        "SELECT image_storage_key, image_mime_type
         FROM buy_order_chat_messages WHERE id = :id"
    );
    $stmt->execute(["id" => $orderImageMessage["message"]["id"]]);
    $storedImage = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    unset($stmt);
    $pdo = null;
    $storedImagePath = is_array($storedImage)
        ? $chatImageDirectory . DIRECTORY_SEPARATOR . $storedImage["image_storage_key"] . ".image"
        : "";
    buy_order_test_assert(
        is_array($storedImage)
            && $storedImage["image_mime_type"] === "image/png"
            && is_file($storedImagePath)
            && (fileperms($storedImagePath) & 0777) === 0600
            && file_get_contents($storedImagePath) === file_get_contents($chatImagePath),
        "The chat image was not stored privately with validated image metadata."
    );
    [$orderImageReadStatus, $orderImageBody, $orderImageHeaders] = buy_order_test_request(
        $baseUrl,
        "/" . $orderImageMessage["message"]["image_url"],
        $sellerSession
    );
    buy_order_test_assert(
        $orderImageReadStatus === 200
            && $orderImageBody === file_get_contents($chatImagePath)
            && in_array("Content-Type: image/png", $orderImageHeaders, true)
            && in_array("X-Content-Type-Options: nosniff", $orderImageHeaders, true),
        "The seller could not securely retrieve the purchase chat image ({$orderImageReadStatus}): "
            . json_encode($orderImageHeaders) . " body=" . substr($orderImageBody, 0, 100)
            . "\n" . file_get_contents($serverLog)
    );
    [$privateOrderImageStatus] = buy_order_test_request(
        $baseUrl,
        "/" . $orderImageMessage["message"]["image_url"],
        $otherSession
    );
    buy_order_test_assert($privateOrderImageStatus === 404, "A nonparticipant retrieved a purchase chat image.");
    [$invalidImageStatus] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php",
        $buyerSession,
        [
            "order_id" => (string) $orderId,
            "message_body" => "",
            "csrf_token" => $buyerCsrf
        ],
        $invalidPaymentPath,
        "image_file",
        "fake.png"
    );
    [$oversizedImageStatus] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php",
        $buyerSession,
        [
            "order_id" => (string) $orderId,
            "message_body" => "",
            "csrf_token" => $buyerCsrf
        ],
        $oversizedChatImagePath,
        "image_file",
        "too-large.png"
    );
    buy_order_test_assert(
        $invalidImageStatus === 400 && $oversizedImageStatus === 413,
        "An invalid or oversized chat image was accepted."
    );
    [$exchangeImageSendStatus, $exchangeImageSendBody] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php",
        $sellerSession,
        [
            "transaction_id" => "1",
            "message_body" => "Here is the requested exchange photo.",
            "csrf_token" => $sellerCsrf
        ],
        $chatImagePath,
        "image_file",
        "exchange.png"
    );
    buy_order_test_assert(
        $exchangeImageSendStatus === 201,
        "Exchange image send failed ({$exchangeImageSendStatus}): {$exchangeImageSendBody}\n"
            . file_get_contents($serverLog)
    );
    $exchangeImageMessage = json_decode($exchangeImageSendBody, true, 512, JSON_THROW_ON_ERROR);
    [$exchangeImageReadStatus, $exchangeImageBody] = buy_order_test_request(
        $baseUrl,
        "/" . $exchangeImageMessage["message"]["image_url"],
        $buyerSession
    );
    [$privateExchangeImageStatus] = buy_order_test_request(
        $baseUrl,
        "/" . $exchangeImageMessage["message"]["image_url"],
        $otherSession
    );
    buy_order_test_assert(
        $exchangeImageSendStatus === 201
            && $exchangeImageReadStatus === 200
            && $exchangeImageBody === file_get_contents($chatImagePath)
            && $privateExchangeImageStatus === 404,
        "Exchange chat image send, receive, or participant privacy failed."
    );
    [$imageInboxStatus, $imageInboxBody] = buy_order_test_request(
        $baseUrl,
        "/my_chats.php",
        $sellerSession
    );
    buy_order_test_assert(
        $imageInboxStatus === 200 && str_contains($imageInboxBody, "รูปภาพ"),
        "The chat inbox did not show an image message preview."
    );

    $action = static fn (
        string $session,
        string $csrf,
        string $name
    ): array => buy_order_test_request(
        $baseUrl,
        "/order_action.php",
        $session,
        ["csrf_token" => $csrf, "order_id" => (string) $orderId, "action" => $name]
    );
    [$earlyReceiptStatus] = $action($buyerSession, $buyerCsrf, "receive");
    buy_order_test_assert($earlyReceiptStatus === 409, "The buyer could confirm receipt before dispatch.");
    [$wrongDispatchStatus] = $action($buyerSession, $buyerCsrf, "dispatch");
    buy_order_test_assert($wrongDispatchStatus === 403, "A buyer could confirm seller dispatch.");
    [$earlyDispatchStatus] = $action($sellerSession, $sellerCsrf, "dispatch");
    buy_order_test_assert(
        $earlyDispatchStatus === 409,
        "The seller could dispatch before confirming the buyer's payment."
    );
    [$invalidSlipStatus] = buy_order_test_request(
        $baseUrl,
        "/order_action.php",
        $buyerSession,
        ["csrf_token" => $buyerCsrf, "order_id" => (string) $orderId, "action" => "submit_payment"],
        $invalidPaymentPath
    );
    buy_order_test_assert($invalidSlipStatus === 400, "A non-image payment slip was accepted.");
    [$slipStatus, $slipBody] = buy_order_test_request(
        $baseUrl,
        "/order_action.php",
        $buyerSession,
        ["csrf_token" => $buyerCsrf, "order_id" => (string) $orderId, "action" => "submit_payment"],
        $paymentSlipPath
    );
    buy_order_test_assert(
        $slipStatus === 200
            && json_decode($slipBody, true, 512, JSON_THROW_ON_ERROR)["payment_status"] === "submitted",
        "The buyer could not submit payment evidence."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $paymentEvidenceId = (int) $pdo->query(
        "SELECT id FROM buy_order_payment_evidence WHERE order_id = {$orderId}"
    )->fetchColumn();
    buy_order_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM notifications
             WHERE recipient_id = 1 AND notification_type = 'buy_payment_submitted'
               AND reference_id = {$orderId}"
        )->fetchColumn() === 1,
        "Payment evidence submission did not notify the seller."
    );
    $pdo = null;
    [$forbiddenEvidenceStatus] = buy_order_test_request(
        $baseUrl,
        "/buy_order_evidence.php?id=" . $paymentEvidenceId,
        $otherSession
    );
    [$buyerEvidenceStatus] = buy_order_test_request(
        $baseUrl,
        "/buy_order_evidence.php?id=" . $paymentEvidenceId,
        $buyerSession
    );
    buy_order_test_assert(
        $forbiddenEvidenceStatus === 404 && $buyerEvidenceStatus === 200,
        "Payment evidence access was not restricted to transaction participants."
    );
    [$rejectStatus] = $action($sellerSession, $sellerCsrf, "reject_payment");
    buy_order_test_assert($rejectStatus === 400, "The seller rejected payment without a reason.");
    [$rejectStatus, $rejectBody] = buy_order_test_request(
        $baseUrl,
        "/order_action.php",
        $sellerSession,
        [
            "csrf_token" => $sellerCsrf,
            "order_id" => (string) $orderId,
            "action" => "reject_payment",
            "review_note" => "The transfer amount does not match."
        ]
    );
    buy_order_test_assert(
        $rejectStatus === 200
            && json_decode($rejectBody, true, 512, JSON_THROW_ON_ERROR)["payment_status"] === "rejected",
        "The seller could not reject a payment with a reason."
    );
    [$resubmitStatus] = buy_order_test_request(
        $baseUrl,
        "/order_action.php",
        $buyerSession,
        ["csrf_token" => $buyerCsrf, "order_id" => (string) $orderId, "action" => "submit_payment"],
        $paymentSlipPath
    );
    buy_order_test_assert($resubmitStatus === 200, "The buyer could not resubmit rejected payment evidence.");
    [$verifyStatus, $verifyBody] = $action($sellerSession, $sellerCsrf, "verify_payment");
    buy_order_test_assert(
        $verifyStatus === 200
            && json_decode($verifyBody, true, 512, JSON_THROW_ON_ERROR)["payment_status"] === "verified",
        "The seller could not verify the resubmitted payment."
    );
    [$sellerNotificationStatus, $sellerNotifications] = buy_order_test_request(
        $baseUrl,
        "/notifications.php",
        $sellerSession
    );
    buy_order_test_assert(
        $sellerNotificationStatus === 200
            && str_contains($sellerNotifications, "New purchase order")
            && str_contains($sellerNotifications, "Payment submitted for review"),
        "The seller could not view purchase and payment notifications."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM buy_order_payment_evidence
             WHERE order_id = {$orderId} AND status = 'rejected'"
        )->fetchColumn() === 1
            && $pdo->query(
                "SELECT status FROM buy_order_payment_evidence WHERE order_id = {$orderId}
                 ORDER BY id DESC LIMIT 1"
            )->fetchColumn() === "verified",
        "The payment review history was not retained."
    );
    $pdo = null;
    [$dispatchStatus, $dispatchBody] = $action($sellerSession, $sellerCsrf, "dispatch");
    buy_order_test_assert(
        $dispatchStatus === 200,
        "The seller could not confirm dispatch ({$dispatchStatus}): {$dispatchBody}\n"
            . (is_file($serverLog) ? file_get_contents($serverLog) : "")
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        $pdo->query("SELECT status FROM buy_orders WHERE id = {$orderId}")->fetchColumn() === "in_progress"
            && $pdo->query("SELECT status FROM products WHERE id = 101")->fetchColumn() === "reserved",
        "Seller dispatch completed the order or released the reservation."
    );
    $pdo = null;
    [$receiptStatus, $receiptBody] = $action($buyerSession, $buyerCsrf, "receive");
    buy_order_test_assert(
        $receiptStatus === 200
            && json_decode($receiptBody, true, 512, JSON_THROW_ON_ERROR)["status"] === "completed",
        "The buyer could not complete the order after confirming receipt."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        $pdo->query("SELECT status FROM buy_orders WHERE id = {$orderId}")->fetchColumn() === "completed"
            && $pdo->query("SELECT status FROM products WHERE id = 101")->fetchColumn() === "sold"
            && (int) $pdo->query(
                "SELECT COUNT(*) FROM buy_order_confirmations WHERE order_id = {$orderId}"
            )->fetchColumn() === 2
            && (int) $pdo->query(
                "SELECT COUNT(*) FROM notifications
                 WHERE notification_type = 'review_requested'
                   AND reference_type = 'buy_order' AND reference_id = {$orderId}"
            )->fetchColumn() === 2
            && $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "The two confirmations did not atomically complete the order and mark the listing sold."
    );
    $pdo = null;
    [$readonlyChatStatus] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php",
        $sellerSession,
        [
            "order_id" => (string) $orderId,
            "message_body" => "This should be read-only.",
            "csrf_token" => $sellerCsrf
        ]
    );
    [$completedChatStatus, $completedChatBody] = buy_order_test_request(
        $baseUrl,
        "/chat_messages.php?order_id=" . $orderId,
        $buyerSession
    );
    $completedChat = json_decode($completedChatBody, true, 512, JSON_THROW_ON_ERROR);
    buy_order_test_assert(
        $readonlyChatStatus === 409
            && $completedChatStatus === 200
            && !$completedChat["can_send"]
            && count($completedChat["messages"]) === 2
            && $completedChat["messages"][0]["message_body"] === "Can we arrange delivery?"
            && str_contains($completedChat["messages"][1]["image_url"], "chat_image.php?order_id={$orderId}"),
        "The completed order chat was not preserved as read-only history."
    );
    [$reviewStatus, $reviewBody] = buy_order_test_request(
        $baseUrl,
        "/review_action.php",
        $buyerSession,
        [
            "csrf_token" => $buyerCsrf,
            "order_id" => (string) $orderId,
            "rating" => "5",
            "comment" => "Good transaction"
        ]
    );
    buy_order_test_assert(
        $reviewStatus === 201
            && json_decode($reviewBody, true, 512, JSON_THROW_ON_ERROR)["success"] === true,
        "A participant could not review a completed order."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM notifications
             WHERE recipient_id = 1 AND notification_type = 'review_received'
               AND reference_type = 'buy_order' AND reference_id = {$orderId}"
        )->fetchColumn() === 1,
        "Submitting a review did not notify its recipient."
    );
    $pdo = null;
    foreach ([
        [$sellerSession, $sellerCsrf, "creator_handed_over_offered_item"],
        [$buyerSession, $buyerCsrf, "recipient_received_offered_item"],
        [$buyerSession, $buyerCsrf, "recipient_handed_over_requested_item"]
    ] as [$sessionId, $csrf, $confirmationType]) {
        [$handoverStatus, $handoverBody] = buy_order_test_request(
            $baseUrl,
            "/handover_action.php",
            $sessionId,
            [
                "csrf_token" => $csrf,
                "transaction_id" => "1",
                "confirmation_type" => $confirmationType
            ]
        );
        buy_order_test_assert(
            $handoverStatus === 200,
            "An eligible exchange handover could not be recorded ({$handoverStatus}): {$handoverBody}\n"
                . (is_file($serverLog) ? file_get_contents($serverLog) : "")
        );
    }
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        $pdo->query("SELECT status FROM exchange_transactions WHERE id = 1")->fetchColumn() === "in_progress",
        "An exchange completed before all four handover confirmations."
    );
    $pdo = null;
    [$lastHandoverStatus, $lastHandoverBody] = buy_order_test_request(
        $baseUrl,
        "/handover_action.php",
        $sellerSession,
        [
            "csrf_token" => $sellerCsrf,
            "transaction_id" => "1",
            "confirmation_type" => "creator_received_requested_item"
        ]
    );
    buy_order_test_assert(
        $lastHandoverStatus === 200
            && json_decode($lastHandoverBody, true, 512, JSON_THROW_ON_ERROR)["completed"] === true,
        "The exchange did not complete after both parties recorded all four handovers."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        $pdo->query("SELECT status FROM exchange_transactions WHERE id = 1")->fetchColumn() === "completed"
            && $pdo->query("SELECT user_id || ':' || status FROM products WHERE id = 105")->fetchColumn() === "2:inactive"
            && $pdo->query("SELECT user_id || ':' || status FROM products WHERE id = 106")->fetchColumn() === "1:inactive"
            && (int) $pdo->query("SELECT COUNT(*) FROM product_reservations WHERE transaction_id = 1")->fetchColumn() === 0
            && (int) $pdo->query(
                "SELECT COUNT(*) FROM notifications
                 WHERE notification_type = 'review_requested'
                   AND reference_type = 'exchange_transaction' AND reference_id = 1"
            )->fetchColumn() === 2,
        "Exchange completion did not transfer both items and release reservations."
    );
    $pdo = null;
    [$exchangeReviewStatus] = buy_order_test_request(
        $baseUrl,
        "/review_action.php",
        $buyerSession,
        [
            "csrf_token" => $buyerCsrf,
            "exchange_transaction_id" => "1",
            "rating" => "4",
            "comment" => "Exchange completed successfully."
        ]
    );
    buy_order_test_assert(
        $exchangeReviewStatus === 201,
        "A participant could not review a completed exchange."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM notifications
             WHERE recipient_id = 1 AND notification_type = 'review_received'
               AND reference_type = 'exchange_transaction' AND reference_id = 1"
        )->fetchColumn() === 1,
        "An exchange review did not notify its recipient."
    );
    $pdo = null;
    [$duplicateReviewStatus] = buy_order_test_request(
        $baseUrl,
        "/review_action.php",
        $buyerSession,
        [
            "csrf_token" => $buyerCsrf,
            "order_id" => (string) $orderId,
            "rating" => "1",
            "comment" => "Duplicate review"
        ]
    );
    buy_order_test_assert(
        $duplicateReviewStatus === 409,
        "A participant could submit more than one review for the same order."
    );
    [$profileStatus, $profileBody] = buy_order_test_request(
        $baseUrl,
        "/product_detail.php?id=104",
        $buyerSession
    );
    buy_order_test_assert(
        $profileStatus === 200
            && str_contains($profileBody, "คะแนนเฉลี่ย 4.5/5")
            && str_contains($profileBody, "(2 รีวิว)"),
        "The seller's average review score was not visible on product details."
    );
    [$publicProfileStatus, $publicProfileBody] = buy_order_test_request(
        $baseUrl,
        "/user_profile.php?id=1",
        null
    );
    buy_order_test_assert(
        $publicProfileStatus === 200
            && str_contains($publicProfileBody, "คะแนนเฉลี่ย 4.5/5")
            && str_contains($publicProfileBody, "Good transaction")
            && str_contains($publicProfileBody, "Exchange completed successfully."),
        "The seller profile did not show the average score and received reviews."
    );
    [$reportStatus, $reportBody] = buy_order_test_request(
        $baseUrl,
        "/report_action.php",
        $buyerSession,
        [
            "csrf_token" => $buyerCsrf,
            "product_id" => "104",
            "report_type" => "listing",
            "reason" => "The listing information is misleading."
        ]
    );
    buy_order_test_assert(
        $reportStatus === 201
            && json_decode($reportBody, true, 512, JSON_THROW_ON_ERROR)["success"] === true,
        "A user could not submit a listing report."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        $pdo->query("SELECT status FROM products WHERE id = 104")->fetchColumn() === "active"
            && $pdo->query("SELECT account_status FROM users WHERE id = 1")->fetchColumn() === "active",
        "Submitting a report automatically punished a user or removed a listing."
    );
    $pdo = null;
    [$selfReportStatus] = buy_order_test_request(
        $baseUrl,
        "/report_action.php",
        $sellerSession,
        [
            "csrf_token" => $sellerCsrf,
            "product_id" => "104",
            "report_type" => "listing",
            "reason" => "Self report"
        ]
    );
    buy_order_test_assert($selfReportStatus === 403, "A user could report their own listing.");
    [$adminStatus, $adminBody] = buy_order_test_request(
        $baseUrl,
        "/admin.php",
        $adminSession
    );
    buy_order_test_assert(
        $adminStatus === 200 && str_contains($adminBody, "The listing information is misleading."),
        "The submitted report did not appear in the administrator review queue."
    );
    [$decisionStatus, $decisionBody] = buy_order_test_request(
        $baseUrl,
        "/admin_report_action.php",
        $adminSession,
        [
            "csrf_token" => $adminCsrf,
            "report_id" => (string) json_decode($reportBody, true, 512, JSON_THROW_ON_ERROR)["report_id"],
            "admin_action" => "remove_listing",
            "admin_reason" => "The listing was confirmed to violate marketplace rules."
        ]
    );
    buy_order_test_assert(
        $decisionStatus === 200
            && json_decode($decisionBody, true, 512, JSON_THROW_ON_ERROR)["status"] === "action_taken",
        "The administrator could not remove a reported listing."
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $reportId = (int) json_decode($reportBody, true, 512, JSON_THROW_ON_ERROR)["report_id"];
    $reportNotificationId = (int) $pdo->query(
        "SELECT id FROM notifications
         WHERE recipient_id = 2 AND notification_type = 'report_decision'
           AND reference_type = 'content_report' AND reference_id = {$reportId}"
    )->fetchColumn();
    buy_order_test_assert(
        $pdo->query("SELECT status FROM products WHERE id = 104")->fetchColumn() === "inactive"
            && $reportNotificationId > 0
            && $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "The report decision did not preserve data integrity."
    );
    $pdo = null;
    [$myReportsStatus, $myReportsBody] = buy_order_test_request(
        $baseUrl,
        "/my_reports.php",
        $buyerSession
    );
    [$otherReportsStatus, $otherReportsBody] = buy_order_test_request(
        $baseUrl,
        "/my_reports.php",
        $otherSession
    );
    buy_order_test_assert(
        $myReportsStatus === 200
            && str_contains($myReportsBody, "The listing was confirmed to violate marketplace rules.")
            && $otherReportsStatus === 200
            && !str_contains($otherReportsBody, "The listing information is misleading."),
        "A user could not view their report decision or another user's report."
    );
    [$otherMarkStatus] = buy_order_test_request(
        $baseUrl,
        "/notification_action.php",
        $otherSession,
        ["csrf_token" => $otherCsrf, "notification_id" => (string) $reportNotificationId]
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        $otherMarkStatus === 303
            && $pdo->query(
                "SELECT read_at FROM notifications WHERE id = {$reportNotificationId}"
            )->fetchColumn() === null,
        "Another user could mark the reporter's notification as read."
    );
    $pdo = null;
    [$markStatus] = buy_order_test_request(
        $baseUrl,
        "/notification_action.php",
        $buyerSession,
        ["csrf_token" => $buyerCsrf, "notification_id" => (string) $reportNotificationId]
    );
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    buy_order_test_assert(
        $markStatus === 303
            && $pdo->query(
                "SELECT read_at FROM notifications WHERE id = {$reportNotificationId}"
            )->fetchColumn() !== null,
        "A report owner could not mark their notification as read."
    );
    $pdo = null;
    [$behaviorReportStatus, $behaviorReportBody] = buy_order_test_request(
        $baseUrl,
        "/report_action.php",
        $buyerSession,
        [
            "csrf_token" => $buyerCsrf,
            "product_id" => "103",
            "report_type" => "behavior",
            "reason" => "The seller used inappropriate language."
        ]
    );
    buy_order_test_assert(
        $behaviorReportStatus === 201,
        "A user could not report behavior connected to a listing."
    );
    [$suspendStatus] = buy_order_test_request(
        $baseUrl,
        "/admin_report_action.php",
        $adminSession,
        [
            "csrf_token" => $adminCsrf,
            "report_id" => (string) json_decode($behaviorReportBody, true, 512, JSON_THROW_ON_ERROR)["report_id"],
            "admin_action" => "suspend_account",
            "admin_reason" => "The report was reviewed and the behavior was confirmed."
        ]
    );
    buy_order_test_assert($suspendStatus === 200, "The administrator could not suspend a reported account.");
    [$suspendedStatus] = buy_order_test_request(
        $baseUrl,
        "/my_orders.php",
        $sellerSession
    );
    buy_order_test_assert($suspendedStatus === 403, "A suspended account could continue using protected workflows.");
    [$suspendedReviewStatus] = buy_order_test_request(
        $baseUrl,
        "/review_action.php",
        $sellerSession,
        [
            "csrf_token" => $sellerCsrf,
            "order_id" => (string) $orderId,
            "rating" => "5",
            "comment" => "Review from suspended account."
        ]
    );
    buy_order_test_assert(
        $suspendedReviewStatus === 401,
        "A suspended account could submit a review ({$suspendedReviewStatus})."
    );
    [$ordersStatus, $ordersBody] = buy_order_test_request(
        $baseUrl,
        "/my_orders.php",
        $buyerSession
    );
    buy_order_test_assert(
        $ordersStatus === 200 && str_contains($ordersBody, "Available item"),
        "The buyer could not find the order in their order list."
    );

    echo "Buy order workflow tests passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($created) {
        buy_order_test_cleanup($temporaryRoot);
    }
}
