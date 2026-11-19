<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "offer-acceptance-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;
$secondServer = null;
$createdTemporaryRoot = false;

function offer_acceptance_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function offer_acceptance_session(string $path, string $id, int $userId, string $csrfToken): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId, "csrf_token" => $csrfToken];
    session_write_close();
}

function offer_acceptance_request(
    string $baseUrl,
    string $path,
    ?string $sessionId,
    ?array $form = null
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
    if ($form !== null) {
        $options["http"]["header"][] = "Content-Type: application/x-www-form-urlencoded";
        $options["http"]["content"] = http_build_query($form);
    }
    $body = @file_get_contents($baseUrl . $path, false, stream_context_create($options));
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/\AHTTP\/\S+ ([0-9]{3})/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    return [$status, is_string($body) ? $body : "", $responseHeaders];
}

function offer_acceptance_post(
    string $baseUrl,
    ?string $sessionId,
    string $csrfToken,
    int|string $offerId,
    array $fields = []
): array {
    return offer_acceptance_request(
        $baseUrl,
        "/offer_action.php",
        $sessionId,
        $fields + [
            "offer_id" => (string) $offerId,
            "action" => "accept",
            "csrf_token" => $csrfToken
        ]
    );
}

function offer_acceptance_race(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as $request) {
        $handle = curl_init($request["url"]);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($request["form"]),
            CURLOPT_COOKIE => "PHPSESSID=" . $request["session"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }

    do {
        $result = curl_multi_exec($multi, $running);
        if ($running > 0) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) {
                usleep(1000);
            }
        }
    } while ($running > 0 && $result === CURLM_OK);

    $statuses = [];
    foreach ($handles as $handle) {
        $statuses[] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    return $statuses;
}

function offer_acceptance_remove_tree(string $root): void
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
    if (!mkdir($temporaryRoot, 0700) || !mkdir($sessionPath, 0700)) {
        throw new RuntimeException("Could not create isolated test directories.");
    }
    $createdTemporaryRoot = true;

    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status TEXT NOT NULL CHECK (status IN ('active', 'reserved', 'inactive')),
            transaction_type TEXT NOT NULL CHECK (transaction_type IN ('buy', 'exchange', 'both')),
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
            cash_currency TEXT NULL,
            currency_exponent INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending', 'accepted', 'rejected', 'cancelled', 'superseded', 'expired')),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME NULL,
            CHECK (creator_id <> recipient_id),
            CHECK (cash_payer_id = creator_id),
            CHECK (offered_product_id <> target_product_id),
            FOREIGN KEY (creator_id) REFERENCES users(id),
            FOREIGN KEY (recipient_id) REFERENCES users(id),
            FOREIGN KEY (cash_payer_id) REFERENCES users(id),
            FOREIGN KEY (offered_product_id) REFERENCES products(id),
            FOREIGN KEY (target_product_id) REFERENCES products(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE exchange_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            offer_id INTEGER NOT NULL UNIQUE,
            creator_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            offered_product_id INTEGER NOT NULL,
            target_product_id INTEGER NOT NULL,
            cash_payer_id INTEGER NOT NULL,
            cash_amount_minor INTEGER NOT NULL,
            cash_currency TEXT NULL,
            currency_exponent INTEGER NULL,
            payment_method TEXT NULL,
            payment_status TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'in_progress',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "CREATE TABLE product_reservations (
            product_id INTEGER PRIMARY KEY,
            transaction_id INTEGER NOT NULL,
            reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
            FOREIGN KEY (transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT
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
            FOREIGN KEY (exchange_transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "INSERT INTO users (id, name, email, password) VALUES
            (1, 'Creator', 'creator@example.test', 'unused'),
            (2, 'Recipient', 'recipient@example.test', 'unused'),
            (3, 'Unrelated', 'unrelated@example.test', 'unused')"
    );
    $insertProduct = $pdo->prepare(
        "INSERT INTO products (id, user_id, name, description, price, status, transaction_type)
         VALUES (:id, :user_id, :name, :description, 1, :status, :transaction_type)"
    );
    foreach ([
        [10, 1, "Offered item", "Creator description", "active", "exchange"],
        [20, 2, "<script>alert(1)</script>", "<img src=x onerror=alert(1)>", "active", "both"],
        [30, 3, "Third item", "Third description", "active", "exchange"],
        [40, 3, "Fourth item", "Fourth description", "active", "both"],
        [50, 1, "Rollback offered", "Rollback description", "active", "exchange"],
        [60, 2, "Rollback target", "Rollback description", "active", "exchange"],
        [70, 2, "Inactive target", "Inactive description", "inactive", "exchange"],
        [80, 2, "Buy-only target", "Buy-only description", "active", "buy"],
        [90, 1, "Already reserved", "Reserved description", "reserved", "exchange"]
    ] as [$id, $ownerId, $name, $description, $status, $type]) {
        $insertProduct->execute([
            "id" => $id,
            "user_id" => $ownerId,
            "name" => $name,
            "description" => $description,
            "status" => $status,
            "transaction_type" => $type
        ]);
    }
    $insertOffer = $pdo->prepare(
        "INSERT INTO offers (
            id, creator_id, recipient_id, cash_payer_id, offered_product_id,
            target_product_id, cash_amount_minor, status, created_at, updated_at
        ) VALUES (
            :id, :creator, :recipient, :creator, :offered, :target, 0, :status,
            '2000-01-01 00:00:00', '2000-01-01 00:00:00'
        )"
    );
    foreach ([
        [1, 1, 2, 10, 20, "pending"],
        [2, 1, 3, 10, 30, "pending"],
        [3, 3, 2, 30, 20, "pending"],
        [4, 2, 3, 20, 30, "pending"],
        [5, 3, 1, 30, 10, "pending"],
        [6, 1, 2, 10, 30, "accepted"],
        [7, 3, 1, 30, 40, "pending"],
        [8, 1, 2, 30, 20, "pending"],
        [9, 1, 2, 10, 70, "pending"],
        [10, 1, 2, 10, 80, "pending"],
        [11, 1, 2, 50, 60, "pending"],
        [12, 1, 3, 90, 40, "pending"],
        [13, 1, 2, 50, 60, "pending"]
    ] as [$id, $creatorId, $recipientId, $offeredId, $targetId, $status]) {
        $insertOffer->execute([
            "id" => $id,
            "creator" => $creatorId,
            "recipient" => $recipientId,
            "offered" => $offeredId,
            "target" => $targetId,
            "status" => $status
        ]);
    }
    $pdo->exec(
        "UPDATE offers
         SET cash_amount_minor = 250, cash_currency = 'USD', currency_exponent = 2
         WHERE id = 1"
    );
    $productSnapshot = $pdo->query(
        "SELECT id, user_id, name, description, price, status, transaction_type
         FROM products ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);

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
            "-S",
            "127.0.0.1:" . $port,
            "-t",
            $projectRoot
        ],
        [0 => ["pipe", "r"], 1 => ["file", $serverLog, "a"], 2 => ["file", $serverLog, "a"]],
        $pipes,
        $projectRoot,
        [
            "DB_DRIVER" => "sqlite",
            "DB_PATH" => $databasePath,
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
    offer_acceptance_assert($ready, "The isolated PHP test server did not start.");

    $sessions = [];
    $tokens = [];
    foreach ([1, 2, 3] as $userId) {
        $sessions[$userId] = bin2hex(random_bytes(16));
        $tokens[$userId] = bin2hex(random_bytes(32));
        offer_acceptance_session($sessionPath, $sessions[$userId], $userId, $tokens[$userId]);
    }

    [$status] = offer_acceptance_post($baseUrl, null, "", 1);
    offer_acceptance_assert($status === 302, "A guest was not redirected to authentication.");
    [$status] = offer_acceptance_post($baseUrl, $sessions[1], $tokens[1], 1);
    offer_acceptance_assert($status === 303, "The creator's attempt did not fail safely.");
    offer_acceptance_assert(
        $pdo->query("SELECT status FROM offers WHERE id = 1")->fetchColumn() === "pending",
        "The creator accepted their own offer."
    );
    [$status] = offer_acceptance_post($baseUrl, $sessions[3], $tokens[3], 1);
    offer_acceptance_assert($status === 404, "An unrelated user accessed an offer.");
    [$status] = offer_acceptance_request(
        $baseUrl,
        "/offer_action.php?offer_id=1&action=accept",
        $sessions[2]
    );
    offer_acceptance_assert(
        $status === 405
            && $pdo->query("SELECT status FROM offers WHERE id = 1")->fetchColumn() === "pending",
        "A GET request changed an offer."
    );
    [$status] = offer_acceptance_post($baseUrl, $sessions[2], "invalid-token", 1);
    offer_acceptance_assert(
        $status === 400
            && $pdo->query("SELECT status FROM offers WHERE id = 1")->fetchColumn() === "pending",
        "An invalid CSRF token was accepted."
    );

    foreach ([8, 9, 10] as $invalidOfferId) {
        [$status] = offer_acceptance_post(
            $baseUrl,
            $sessions[2],
            $tokens[2],
            $invalidOfferId
        );
        offer_acceptance_assert($status === 303, "An invalid exchange did not fail safely.");
        offer_acceptance_assert(
            $pdo->query("SELECT status FROM offers WHERE id = " . $invalidOfferId)->fetchColumn() === "pending",
            "An invalid exchange offer changed status."
        );
        offer_acceptance_assert(
            $pdo->query(
                "SELECT id, user_id, name, description, price, status, transaction_type
                 FROM products ORDER BY id"
            )->fetchAll(PDO::FETCH_ASSOC) === $productSnapshot,
            "An invalid exchange changed product ownership or status."
        );
    }

    [$status] = offer_acceptance_post($baseUrl, $sessions[3], $tokens[3], 12);
    offer_acceptance_assert(
        $status === 303
            && $pdo->query("SELECT status FROM offers WHERE id = 12")->fetchColumn() === "pending",
        "A reserved product was accepted in an exchange."
    );
    $pdo->exec("UPDATE products SET user_id = 3 WHERE id = 50");
    [$status] = offer_acceptance_post($baseUrl, $sessions[2], $tokens[2], 11);
    offer_acceptance_assert(
        $status === 303
            && $pdo->query("SELECT status FROM offers WHERE id = 11")->fetchColumn() === "pending",
        "An offer with changed product ownership was accepted."
    );
    $pdo->exec("UPDATE products SET user_id = 1 WHERE id = 50");

    $failureTriggers = [
        "fail_transaction_insert" => [
            "BEFORE INSERT ON exchange_transactions WHEN NEW.offer_id = 11"
        ],
        "fail_reservation_insert" => [
            "BEFORE INSERT ON product_reservations WHEN NEW.product_id = 60"
        ],
        "fail_chat_room_insert" => [
            "BEFORE INSERT ON chat_rooms"
        ],
        "fail_competing_offer_supersede" => [
            "BEFORE UPDATE ON offers WHEN OLD.id <> 11 AND OLD.status = 'pending'"
        ]
    ];
    foreach ($failureTriggers as $name => [$triggerCondition]) {
        $pdo->exec(
            "CREATE TRIGGER {$name} {$triggerCondition}
             BEGIN SELECT RAISE(ABORT, 'injected acceptance failure'); END"
        );
        [$status] = offer_acceptance_post($baseUrl, $sessions[2], $tokens[2], 11);
        offer_acceptance_assert($status === 503, "Injected {$name} failure was not reported.");
        offer_acceptance_assert(
            $pdo->query("SELECT status FROM offers WHERE id = 11")->fetchColumn() === "pending"
                && (int) $pdo->query("SELECT COUNT(*) FROM exchange_transactions WHERE offer_id = 11")->fetchColumn() === 0
                && (int) $pdo->query("SELECT COUNT(*) FROM product_reservations WHERE product_id IN (50, 60)")->fetchColumn() === 0
                && (int) $pdo->query("SELECT COUNT(*) FROM chat_rooms")->fetchColumn() === 0
                && $pdo->query(
                    "SELECT id, user_id, status FROM products WHERE id IN (50, 60) ORDER BY id"
                )->fetchAll(PDO::FETCH_ASSOC) === [
                    ["id" => 50, "user_id" => 1, "status" => "active"],
                    ["id" => 60, "user_id" => 2, "status" => "active"]
                ],
            "Failure at {$name} did not roll back every acceptance write."
        );
        $pdo->exec("DROP TRIGGER {$name}");
    }

    $secondSocket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if ($secondSocket === false) {
        throw new RuntimeException("Could not reserve a second race-test port: " . $error);
    }
    $secondAddress = stream_socket_get_name($secondSocket, false);
    fclose($secondSocket);
    $secondPort = (int) substr((string) $secondAddress, strrpos((string) $secondAddress, ":") + 1);
    $secondServer = proc_open(
        [
            PHP_BINARY,
            "-d",
            "session.save_path=" . $sessionPath,
            "-S",
            "127.0.0.1:" . $secondPort,
            "-t",
            $projectRoot
        ],
        [0 => ["pipe", "r"], 1 => ["file", $serverLog, "a"], 2 => ["file", $serverLog, "a"]],
        $secondPipes,
        $projectRoot,
        [
            "DB_DRIVER" => "sqlite",
            "DB_PATH" => $databasePath,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    if (!is_resource($secondServer)) {
        throw new RuntimeException("Could not start the second isolated test server.");
    }
    fclose($secondPipes[0]);
    $secondReady = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen("127.0.0.1", $secondPort, $probeErrno, $probeError, 0.1);
        if ($probe !== false) {
            fclose($probe);
            $secondReady = true;
            break;
        }
        usleep(100000);
    }
    offer_acceptance_assert($secondReady, "The second test server did not start.");
    $offerCountBeforeRace = (int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn();
    $raceStatuses = offer_acceptance_race([
        [
            "url" => $baseUrl . "/offer_action.php",
            "session" => $sessions[2],
            "form" => ["offer_id" => "11", "action" => "accept", "csrf_token" => $tokens[2]]
        ],
        [
            "url" => "http://127.0.0.1:" . $secondPort . "/create_offer.php",
            "session" => $sessions[1],
            "form" => [
                "target_id" => "60",
                "offered_product_id" => "50",
                "cash_amount_minor" => "0",
                "cash_currency" => "USD",
                "csrf_token" => $tokens[1]
            ]
        ]
    ]);
    offer_acceptance_assert(
        $raceStatuses[0] === 303 && in_array($raceStatuses[1], [303, 404, 422], true),
        "Concurrent acceptance and offer creation did not complete safely."
    );
    offer_acceptance_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM exchange_transactions WHERE offer_id = 11")->fetchColumn() === 1
            && $pdo->query("SELECT payment_status FROM exchange_transactions WHERE offer_id = 11")->fetchColumn() === "not_required"
            && (int) $pdo->query("SELECT COUNT(*) FROM product_reservations WHERE product_id IN (50, 60)")->fetchColumn() === 2
            && $pdo->query("SELECT status FROM products WHERE id IN (50, 60) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
                === ["reserved", "reserved"]
            && (int) $pdo->query(
                "SELECT COUNT(*) FROM offers
                 WHERE status = 'pending'
                   AND (offered_product_id IN (50, 60) OR target_product_id IN (50, 60))"
            )->fetchColumn() === 0
            && (int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn() >= $offerCountBeforeRace,
        "The concurrent race left a pending offer on reserved products or a partial acceptance."
    );
    $offerCountAfterRace = (int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn();

    [$status, $creatorDetail] = offer_acceptance_request(
        $baseUrl,
        "/offer_detail.php?id=1",
        $sessions[1]
    );
    offer_acceptance_assert(
        $status === 200
            && !str_contains($creatorDetail, 'name="action" value="accept"')
            && !str_contains($creatorDetail, "<script>alert(1)</script>"),
        "The creator saw the accept action or unescaped product content."
    );
    [$status, $recipientDetail] = offer_acceptance_request(
        $baseUrl,
        "/offer_detail.php?id=1",
        $sessions[2]
    );
    offer_acceptance_assert(
        $status === 200
            && str_contains($recipientDetail, 'name="action" value="accept"')
            && str_contains($recipientDetail, 'onsubmit="return confirm(')
            && !str_contains($recipientDetail, "<script>alert(1)</script>")
            && !str_contains($recipientDetail, "<img src=x onerror=alert(1)>"),
        "The recipient's accept action or XSS protection is incorrect."
    );

    $parallelAcceptStatuses = offer_acceptance_race([
        [
            "url" => $baseUrl . "/offer_action.php",
            "session" => $sessions[2],
            "form" => ["offer_id" => "1", "action" => "accept", "csrf_token" => $tokens[2]]
        ],
        [
            "url" => "http://127.0.0.1:" . $secondPort . "/offer_action.php",
            "session" => $sessions[2],
            "form" => ["offer_id" => "1", "action" => "accept", "csrf_token" => $tokens[2]]
        ]
    ]);
    offer_acceptance_assert(
        $parallelAcceptStatuses === [303, 303],
        "Concurrent accept requests did not complete as safe redirects."
    );
    $acceptedOffer = $pdo->query("SELECT * FROM offers WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    offer_acceptance_assert(
        $acceptedOffer["status"] === "accepted"
            && $acceptedOffer["updated_at"] !== "2000-01-01 00:00:00"
            && $acceptedOffer["responded_at"] !== null,
        "The accepted offer state or timestamps are incorrect."
    );
    offer_acceptance_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM exchange_transactions WHERE offer_id = 1")->fetchColumn() === 1,
        "Concurrent acceptance created multiple transactions for one offer."
    );
    offer_acceptance_assert(
        $pdo->query("SELECT user_id, status FROM products WHERE id = 10")->fetch(PDO::FETCH_ASSOC)
            === ["user_id" => 1, "status" => "reserved"]
            && $pdo->query("SELECT user_id, status FROM products WHERE id = 20")->fetch(PDO::FETCH_ASSOC)
                === ["user_id" => 2, "status" => "reserved"],
        "Acceptance transferred ownership or failed to reserve both products."
    );
    $transaction = $pdo->query("SELECT * FROM exchange_transactions WHERE offer_id = 1")->fetch(PDO::FETCH_ASSOC);
    offer_acceptance_assert(
        $transaction !== false
            && (int) $transaction["cash_payer_id"] === 1
            && (int) $transaction["cash_amount_minor"] === 250
            && $transaction["cash_currency"] === "USD"
            && (int) $transaction["currency_exponent"] === 2
            && $transaction["status"] === "in_progress"
            && $transaction["payment_status"] === "awaiting_method"
            && $transaction["completed_at"] === null
            && $transaction["cancelled_at"] === null
            && (int) $pdo->query("SELECT COUNT(*) FROM product_reservations WHERE transaction_id = " . (int) $transaction["id"])->fetchColumn() === 2
            && (int) $pdo->query("SELECT COUNT(*) FROM chat_rooms WHERE exchange_transaction_id = " . (int) $transaction["id"])->fetchColumn() === 1,
        "Acceptance did not persist the required agreement snapshot, reservations, and chat room."
    );
    foreach ([2, 3, 4, 5, 8] as $competingId) {
        offer_acceptance_assert(
            $pdo->query("SELECT status FROM offers WHERE id = " . $competingId)->fetchColumn() === "superseded",
            "A competing offer using an exchanged product in either product column was not superseded."
        );
    }
    offer_acceptance_assert(
        $pdo->query("SELECT status FROM offers WHERE id = 6")->fetchColumn() === "accepted"
            && (int) $pdo->query("SELECT COUNT(*) FROM exchange_transactions WHERE offer_id = 6")->fetchColumn() === 0
            && $pdo->query("SELECT status FROM offers WHERE id = 7")->fetchColumn() === "pending"
            && $pdo->query("SELECT status FROM offers WHERE id = 13")->fetchColumn() === "superseded",
        "A non-pending or unrelated offer was changed."
    );
    offer_acceptance_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn() === $offerCountAfterRace,
        "Offer history was deleted."
    );
    [$status, $acceptedDetail] = offer_acceptance_request(
        $baseUrl,
        "/offer_detail.php?id=1",
        $sessions[2]
    );
    offer_acceptance_assert(
        $status === 200
            && str_contains($acceptedDetail, "Status: Accepted")
            && str_contains($acceptedDetail, "Transaction status: In progress")
            && str_contains($acceptedDetail, "Payment status: Awaiting payment method")
            && str_contains($acceptedDetail, "ownership has not changed")
            && str_contains($acceptedDetail, "exchange_transaction.php?id=")
            && substr_count($acceptedDetail, "Availability: Reserved") === 2
            && !str_contains($acceptedDetail, 'name="action" value="accept"'),
        "Accepted offer details did not distinguish offer, transaction, and payment status."
    );
    [$status, $acceptedOfferList] = offer_acceptance_request(
        $baseUrl,
        "/my_offers.php?section=incoming&status=accepted",
        $sessions[2]
    );
    offer_acceptance_assert(
        $status === 200
            && str_contains($acceptedOfferList, "Offer status: Accepted")
            && str_contains($acceptedOfferList, "Transaction status: In progress")
            && str_contains($acceptedOfferList, "Payment status: Awaiting payment method")
            && str_contains($acceptedOfferList, "exchange_transaction.php?id="),
        "The offers list did not show the transaction separately or link participant access."
    );
    [$status, $legacyDetail] = offer_acceptance_request(
        $baseUrl,
        "/offer_detail.php?id=6",
        $sessions[1]
    );
    offer_acceptance_assert(
        $status === 200
            && str_contains($legacyDetail, "No transaction recorded (legacy accepted offer)")
            && str_contains($legacyDetail, "No payment record (legacy history)"),
        "Legacy accepted offer history was not kept separate from transaction records."
    );
    [$status, $transactionDetail] = offer_acceptance_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . (int) $transaction["id"],
        $sessions[1]
    );
    offer_acceptance_assert(
        $status === 200
            && str_contains($transactionDetail, "Room #")
            && str_contains($transactionDetail, "ownership has not changed"),
        "A transaction participant could not access the transaction and chat room."
    );
    [$status] = offer_acceptance_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . (int) $transaction["id"],
        $sessions[3]
    );
    offer_acceptance_assert($status === 404, "An unrelated user accessed a transaction.");

    [$status] = offer_acceptance_post($baseUrl, $sessions[2], $tokens[2], 1);
    offer_acceptance_assert($status === 303, "A repeated acceptance did not fail safely.");
    offer_acceptance_assert(
        $pdo->query("SELECT user_id FROM products WHERE id = 10")->fetchColumn() === 1
            && $pdo->query("SELECT user_id FROM products WHERE id = 20")->fetchColumn() === 2
            && $pdo->query("SELECT status FROM offers WHERE id = 1")->fetchColumn() === "accepted",
        "A repeated acceptance transferred products again."
    );

    fwrite(STDOUT, "Offer acceptance integration tests passed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    if (isset($serverLog) && is_file($serverLog)) {
        fwrite(STDERR, file_get_contents($serverLog) ?: "");
    }
    $exitCode = 1;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_resource($secondServer)) {
        proc_terminate($secondServer);
        proc_close($secondServer);
    }
    if ($createdTemporaryRoot) {
        offer_acceptance_remove_tree($temporaryRoot);
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}
