<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "offer-action-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;
$createdTemporaryRoot = false;

function offer_action_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function offer_action_session(string $path, string $id, int $userId, string $csrfToken): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId, "csrf_token" => $csrfToken];
    session_write_close();
}

function offer_action_request(
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

function offer_action_post(
    string $baseUrl,
    ?string $sessionId,
    string $csrfToken,
    int|string $offerId,
    string $action,
    array $fields = []
): array {
    return offer_action_request(
        $baseUrl,
        "/offer_action.php",
        $sessionId,
        $fields + [
            "offer_id" => (string) $offerId,
            "action" => $action,
            "csrf_token" => $csrfToken
        ]
    );
}

function offer_action_remove_tree(string $root): void
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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            role TEXT NOT NULL DEFAULT 'user'
                CHECK (role IN ('user', 'admin'))
        )"
    );
    $pdo->exec(
        "CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            price REAL NOT NULL,
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
            id INTEGER PRIMARY KEY,
            offer_id INTEGER NOT NULL UNIQUE,
            status TEXT NOT NULL,
            payment_status TEXT NOT NULL
        )"
    );
    $pdo->exec(
        "INSERT INTO users (id, name, email, password) VALUES
            (1, 'Creator', 'creator@example.test', 'unused'),
            (2, 'Recipient', 'recipient@example.test', 'unused'),
            (3, 'Unrelated', 'unrelated@example.test', 'unused'),
            (4, 'Other creator', 'other@example.test', 'unused')"
    );
    $pdo->exec(
        "INSERT INTO products (id, user_id, name, description, price, status) VALUES
            (10, 1, 'Creator item', 'Creator description', 1, 'active'),
            (20, 2, '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', 1, 'active'),
            (30, 3, 'Unrelated item', 'Other description', 1, 'active'),
            (40, 4, 'Other item', 'Other description', 1, 'inactive')"
    );
    $insertOffer = $pdo->prepare(
        "INSERT INTO offers (
            id, creator_id, recipient_id, cash_payer_id, offered_product_id,
            target_product_id, cash_amount_minor, status, updated_at, responded_at
        ) VALUES (
            :id, :creator_id, :recipient_id, :creator_id, :offered_product_id,
            :target_product_id, 0, :status, '2000-01-01 00:00:00', NULL
        )"
    );
    foreach ([
        [1, 1, 2, 10, 20, "pending"],
        [2, 1, 2, 10, 20, "pending"],
        [3, 1, 2, 10, 20, "pending"],
        [4, 1, 2, 10, 20, "accepted"],
        [5, 1, 2, 10, 20, "rejected"],
        [6, 1, 2, 10, 20, "cancelled"],
        [7, 1, 2, 10, 20, "superseded"],
        [8, 1, 2, 10, 20, "expired"],
        [9, 4, 2, 40, 20, "pending"]
    ] as [$id, $creatorId, $recipientId, $offeredProductId, $targetProductId, $status]) {
        $insertOffer->execute([
            "id" => $id,
            "creator_id" => $creatorId,
            "recipient_id" => $recipientId,
            "offered_product_id" => $offeredProductId,
            "target_product_id" => $targetProductId,
            "status" => $status
        ]);
    }
    $productSnapshot = $pdo->query(
        "SELECT id, user_id, name, description, price, status FROM products ORDER BY id"
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
    offer_action_assert($ready, "The isolated PHP test server did not start.");

    $sessions = [];
    $tokens = [];
    foreach ([1, 2, 3, 4] as $userId) {
        $sessions[$userId] = bin2hex(random_bytes(16));
        $tokens[$userId] = bin2hex(random_bytes(32));
        offer_action_session($sessionPath, $sessions[$userId], $userId, $tokens[$userId]);
    }

    [$status] = offer_action_post($baseUrl, null, "", 1, "cancel");
    offer_action_assert($status === 302, "A guest was not redirected to authentication.");
    offer_action_assert(
        $pdo->query("SELECT status FROM offers WHERE id = 1")->fetchColumn() === "pending",
        "A guest changed an offer."
    );

    [$status] = offer_action_post($baseUrl, $sessions[1], $tokens[1], 1, "cancel", [
        "creator_id" => "2",
        "recipient_id" => "3",
        "status" => "accepted",
        "updated_at" => "1999-01-01",
        "responded_at" => "1999-01-01"
    ]);
    offer_action_assert($status === 303, "The offer creator could not cancel a pending offer.");
    $offer = $pdo->query("SELECT * FROM offers WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    offer_action_assert(
        $offer["status"] === "cancelled"
            && (int) $offer["creator_id"] === 1
            && (int) $offer["recipient_id"] === 2
            && $offer["updated_at"] !== "2000-01-01 00:00:00"
            && $offer["responded_at"] !== null,
        "Cancellation did not update only the expected status and timestamps."
    );
    [$status] = offer_action_post($baseUrl, $sessions[1], $tokens[1], 1, "cancel");
    offer_action_assert($status === 303, "A repeated request did not return a safe failure redirect.");
    offer_action_assert(
        $pdo->query("SELECT status FROM offers WHERE id = 1")->fetchColumn() === "cancelled",
        "A repeated request changed an already completed offer."
    );

    [$status] = offer_action_post($baseUrl, $sessions[2], $tokens[2], 2, "reject", [
        "creator_id" => "2",
        "recipient_id" => "1",
        "status" => "cancelled"
    ]);
    offer_action_assert($status === 303, "The offer recipient could not reject a pending offer.");
    offer_action_assert(
        $pdo->query("SELECT status FROM offers WHERE id = 2")->fetchColumn() === "rejected",
        "Rejection did not set the expected state."
    );
    [$status, $successDetail] = offer_action_request(
        $baseUrl,
        "/offer_detail.php?id=2",
        $sessions[2]
    );
    offer_action_assert(
        $status === 200
            && str_contains($successDetail, 'role="status"')
            && str_contains($successDetail, "The offer has been rejected."),
        "A successful action did not display clear feedback."
    );

    foreach ([
        [$sessions[1], $tokens[1], 3, "reject"],
        [$sessions[2], $tokens[2], 3, "cancel"]
    ] as [$session, $token, $offerId, $action]) {
        [$status] = offer_action_post($baseUrl, $session, $token, $offerId, $action);
        offer_action_assert($status === 303, "A participant's unauthorized action did not fail safely.");
        offer_action_assert(
            $pdo->query("SELECT status FROM offers WHERE id = 3")->fetchColumn() === "pending",
            "A participant performed the other participant's action."
        );
    }
    [$status, $failedActionDetail] = offer_action_request(
        $baseUrl,
        "/offer_detail.php?id=3",
        $sessions[1]
    );
    offer_action_assert(
        $status === 200
            && str_contains($failedActionDetail, 'role="alert"')
            && str_contains($failedActionDetail, "This offer could not be changed."),
        "A failed action did not display clear feedback."
    );
    foreach (["cancel", "reject"] as $action) {
        [$status] = offer_action_post($baseUrl, $sessions[3], $tokens[3], 3, $action);
        offer_action_assert($status === 404, "An unrelated user accessed an offer action.");
    }
    [$status] = offer_action_post($baseUrl, $sessions[3], $tokens[3], 9, "reject");
    offer_action_assert($status === 404, "An unrelated user changed an offer they did not create or receive.");

    foreach ([4, 5, 6, 7, 8] as $offerId) {
        $currentStatus = $pdo->query(
            "SELECT status FROM offers WHERE id = " . $offerId
        )->fetchColumn();
        [$status] = offer_action_post($baseUrl, $sessions[1], $tokens[1], $offerId, "cancel");
        offer_action_assert($status === 303, "A non-pending action did not fail safely.");
        offer_action_assert(
            $pdo->query("SELECT status FROM offers WHERE id = " . $offerId)->fetchColumn() === $currentStatus,
            "A non-pending offer changed state."
        );
    }

    [$status] = offer_action_request(
        $baseUrl,
        "/offer_action.php?offer_id=3&action=cancel",
        $sessions[1]
    );
    offer_action_assert(
        $status === 405
            && $pdo->query("SELECT status FROM offers WHERE id = 3")->fetchColumn() === "pending",
        "A GET request changed an offer."
    );
    [$status] = offer_action_post($baseUrl, $sessions[1], "invalid-token", 3, "cancel");
    offer_action_assert(
        $status === 400
            && $pdo->query("SELECT status FROM offers WHERE id = 3")->fetchColumn() === "pending",
        "An invalid CSRF token was accepted."
    );
    [$status] = offer_action_post($baseUrl, $sessions[1], $tokens[1], "3 OR 1=1", "cancel");
    offer_action_assert(
        $status === 400
            && $pdo->query("SELECT status FROM offers WHERE id = 3")->fetchColumn() === "pending",
        "An SQL injection offer ID was accepted."
    );
    [$status] = offer_action_post($baseUrl, $sessions[1], $tokens[1], 3, "cancel' OR '1'='1");
    offer_action_assert(
        $status === 400
            && $pdo->query("SELECT status FROM offers WHERE id = 3")->fetchColumn() === "pending",
        "An injected action was accepted."
    );

    [$status, $creatorDetail] = offer_action_request(
        $baseUrl,
        "/offer_detail.php?id=3",
        $sessions[1]
    );
    offer_action_assert(
        $status === 200
            && str_contains($creatorDetail, 'name="action" value="cancel"')
            && !str_contains($creatorDetail, 'name="action" value="reject"')
            && !str_contains($creatorDetail, "<script>alert(1)</script>")
            && !str_contains($creatorDetail, "<img src=x onerror=alert(1)>"),
        "Creator offer controls or HTML escaping are incorrect."
    );
    [$status, $recipientDetail] = offer_action_request(
        $baseUrl,
        "/offer_detail.php?id=3",
        $sessions[2]
    );
    offer_action_assert(
        $status === 200
            && str_contains($recipientDetail, 'name="action" value="reject"')
            && !str_contains($recipientDetail, 'name="action" value="cancel"'),
        "Recipient offer controls are incorrect."
    );
    [$status, $completedDetail] = offer_action_request(
        $baseUrl,
        "/offer_detail.php?id=4",
        $sessions[1]
    );
    offer_action_assert(
        $status === 200
            && !str_contains($completedDetail, 'name="action" value="cancel"')
            && !str_contains($completedDetail, 'name="action" value="reject"'),
        "Completed offers displayed a state-changing action."
    );

    offer_action_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn() === 9,
        "Offer history was deleted or altered."
    );
    offer_action_assert(
        $pdo->query(
            "SELECT id, user_id, name, description, price, status FROM products ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC) === $productSnapshot,
        "An offer action modified product data."
    );

    fwrite(STDOUT, "Offer action tests passed.\n");
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
    if ($createdTemporaryRoot) {
        offer_action_remove_tree($temporaryRoot);
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}
