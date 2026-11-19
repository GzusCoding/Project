<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "create-offer-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;
$createdTemporaryRoot = false;

function offer_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function offer_test_session(string $path, string $id, int $userId, string $csrfToken): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId, "csrf_token" => $csrfToken];
    session_write_close();
}

function offer_test_request(string $baseUrl, string $path, ?string $sessionId, ?array $form = null): array
{
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
    $body = file_get_contents($baseUrl . $path, false, stream_context_create($options));
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

function offer_test_post(
    string $baseUrl,
    string $sessionId,
    string $csrfToken,
    array $fields = [],
    string $targetId = "20"
): array {
    return offer_test_request(
        $baseUrl,
        "/create_offer.php",
        $sessionId,
        $fields + [
            "target_id" => $targetId,
            "offered_product_id" => "10",
            "cash_amount_minor" => "",
            "cash_currency" => "USD",
            "csrf_token" => $csrfToken
        ]
    );
}

function offer_test_remove_tree(string $root): void
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
        "CREATE TABLE offers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creator_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            cash_payer_id INTEGER NOT NULL,
            offered_product_id INTEGER NOT NULL,
            target_product_id INTEGER NOT NULL,
            cash_amount_minor INTEGER NOT NULL
                CHECK (typeof(cash_amount_minor) = 'integer' AND cash_amount_minor >= 0),
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
            CHECK (
                (cash_amount_minor = 0 AND cash_currency IS NULL AND currency_exponent IS NULL)
                OR (
                    cash_amount_minor > 0
                    AND typeof(currency_exponent) = 'integer'
                    AND (
                        (cash_currency = 'AUD' AND currency_exponent = 2)
                        OR (cash_currency = 'BHD' AND currency_exponent = 3)
                        OR (cash_currency = 'CAD' AND currency_exponent = 2)
                        OR (cash_currency = 'CHF' AND currency_exponent = 2)
                        OR (cash_currency = 'CNY' AND currency_exponent = 2)
                        OR (cash_currency = 'EUR' AND currency_exponent = 2)
                        OR (cash_currency = 'GBP' AND currency_exponent = 2)
                        OR (cash_currency = 'HKD' AND currency_exponent = 2)
                        OR (cash_currency = 'INR' AND currency_exponent = 2)
                        OR (cash_currency = 'JPY' AND currency_exponent = 0)
                        OR (cash_currency = 'KRW' AND currency_exponent = 0)
                        OR (cash_currency = 'KWD' AND currency_exponent = 3)
                        OR (cash_currency = 'NZD' AND currency_exponent = 2)
                        OR (cash_currency = 'SGD' AND currency_exponent = 2)
                        OR (cash_currency = 'USD' AND currency_exponent = 2)
                        OR (cash_currency = 'VND' AND currency_exponent = 0)
                    )
                )
            ),
            FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (cash_payer_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (offered_product_id) REFERENCES products(id) ON DELETE RESTRICT,
            FOREIGN KEY (target_product_id) REFERENCES products(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "CREATE TABLE product_reservations (
            product_id INTEGER PRIMARY KEY,
            transaction_id INTEGER,
            reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
            (3, 'Unrelated', 'unrelated@example.test', 'unused')"
    );
    $productInsert = $pdo->prepare(
        "INSERT INTO products
            (id, user_id, name, description, price, transaction_type, status)
         VALUES (:id, :user_id, :name, :description, 1, :transaction_type, :status)"
    );
    $products = [
        [10, 1, "Creator exchange", "Mine", "exchange", "active"],
        [11, 1, "Creator both", "Also mine", "both", "active"],
        [12, 1, "Creator buy-only", "Buy only", "buy", "active"],
        [13, 1, "Creator inactive", "Inactive", "exchange", "inactive"],
        [20, 2, "<script>alert(1)</script>", "<img src=x onerror=alert(1)>", "exchange", "active"],
        [21, 2, "Recipient both", "Target", "both", "active"],
        [22, 2, "Recipient buy-only", "Buy only target", "buy", "active"],
        [23, 2, "Recipient inactive", "Inactive target", "exchange", "inactive"],
        [30, 3, "Unrelated exchange", "Other owner", "exchange", "active"]
    ];
    foreach ($products as [$id, $ownerId, $name, $description, $type, $status]) {
        $productInsert->execute([
            "id" => $id,
            "user_id" => $ownerId,
            "name" => $name,
            "description" => $description,
            "transaction_type" => $type,
            "status" => $status
        ]);
    }
    $productSnapshot = $pdo->query(
        "SELECT id, user_id, name, transaction_type, status FROM products ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve a local test port: " . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) $address, strrpos((string) $address, ":") + 1);
    $baseUrl = "http://127.0.0.1:" . $port;
    $command = [
        PHP_BINARY,
        "-d",
        "session.save_path=" . $sessionPath,
        "-S",
        "127.0.0.1:" . $port,
        "-t",
        $projectRoot
    ];
    $pipes = [];
    $server = proc_open(
        $command,
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
    offer_test_assert($ready, "The isolated PHP test server did not start.");

    $creatorSession = bin2hex(random_bytes(16));
    $creatorCsrf = bin2hex(random_bytes(32));
    $recipientSession = bin2hex(random_bytes(16));
    $recipientCsrf = bin2hex(random_bytes(32));
    $unrelatedSession = bin2hex(random_bytes(16));
    offer_test_session($sessionPath, $creatorSession, 1, $creatorCsrf);
    offer_test_session($sessionPath, $recipientSession, 2, $recipientCsrf);
    offer_test_session($sessionPath, $unrelatedSession, 3, bin2hex(random_bytes(32)));

    [$status] = offer_test_request($baseUrl, "/create_offer.php?target_id=20", null);
    offer_test_assert($status === 302, "Guest offer creation was not denied.");

    [$status, $detailBody] = offer_test_request(
        $baseUrl,
        "/product_detail.php?id=20",
        $creatorSession
    );
    offer_test_assert($status === 200 && str_contains($detailBody, "Make an Offer"), "Eligible target omitted Make an Offer.");
    [$status, $buyDetail] = offer_test_request($baseUrl, "/product_detail.php?id=22", $creatorSession);
    offer_test_assert($status === 200 && !str_contains($buyDetail, "Make an Offer"), "Buy-only target exposed Make an Offer.");
    [$status, $ownDetail] = offer_test_request($baseUrl, "/product_detail.php?id=10", $creatorSession);
    offer_test_assert($status === 200 && !str_contains($ownDetail, "Make an Offer"), "Own product exposed Make an Offer.");

    [$status, $formBody] = offer_test_request(
        $baseUrl,
        "/create_offer.php?target_id=20",
        $creatorSession
    );
    offer_test_assert($status === 200 && str_contains($formBody, "Creator exchange"), "Offer form did not show an eligible owned product.");
    offer_test_assert(str_contains($formBody, "Creator both"), "Offer form omitted a both-eligible owned product.");
    offer_test_assert(
        !str_contains($formBody, "Creator buy-only")
            && !str_contains($formBody, "Creator inactive"),
        "Offer form exposed an ineligible owned product."
    );
    offer_test_assert(!str_contains($formBody, "<script>alert(1)</script>"), "Offer form did not escape target XSS content.");

    [$status, , $headers] = offer_test_post($baseUrl, $creatorSession, $creatorCsrf, [
        "cash_amount_minor" => "250",
        "cash_currency" => "USD",
        "creator_id" => "2",
        "recipient_id" => "3",
        "cash_payer_id" => "3",
        "status" => "accepted"
    ]);
    offer_test_assert($status === 303, "Valid offer creation failed.");
    $location = "";
    foreach ($headers as $header) {
        if (stripos($header, "Location:") === 0) {
            $location = trim(substr($header, strlen("Location:")));
        }
    }
    offer_test_assert(preg_match('/\Aoffer_detail\.php\?id=[1-9][0-9]*\z/', $location) === 1, "Successful offer did not link to its details.");
    $offerId = (int) substr($location, strrpos($location, "=") + 1);
    $offer = $pdo->query("SELECT * FROM offers WHERE id = " . $offerId)->fetch(PDO::FETCH_ASSOC);
    offer_test_assert(
        $offer !== false
            && (int) $offer["creator_id"] === 1
            && (int) $offer["recipient_id"] === 2
            && (int) $offer["cash_payer_id"] === 1
            && (int) $offer["offered_product_id"] === 10
            && (int) $offer["target_product_id"] === 20
            && (int) $offer["cash_amount_minor"] === 250
            && $offer["cash_currency"] === "USD"
            && (int) $offer["currency_exponent"] === 2
            && $offer["status"] === "pending",
        "Created offer fields do not match the approved rules."
    );

    [$status, $offerDetail] = offer_test_request($baseUrl, "/" . $location, $creatorSession);
    offer_test_assert($status === 200, "Creator could not view offer details.");
    offer_test_assert(
        str_contains($offerDetail, "exchange offer has been sent"),
        "Offer success message was missing."
    );
    offer_test_assert(!str_contains($offerDetail, "<script>alert(1)</script>"), "Offer details did not escape product XSS content.");
    [$status] = offer_test_request($baseUrl, "/" . $location, $recipientSession);
    offer_test_assert($status === 200, "Offer recipient could not view offer details.");
    [$status] = offer_test_request($baseUrl, "/" . $location, $unrelatedSession);
    offer_test_assert($status === 404, "Unrelated user could view offer details.");

    $invalidCases = [
        ["target_id" => "10", "offered_product_id" => "11"],
        ["offered_product_id" => "30"],
        ["offered_product_id" => "13"],
        ["offered_product_id" => "12"],
        ["offered_product_id" => "20"],
        ["offered_product_id" => "10", "cash_amount_minor" => "-1"],
        ["offered_product_id" => "10", "cash_amount_minor" => "1.5"],
        ["offered_product_id" => "10", "cash_amount_minor" => "999999999999999999999999"],
        ["offered_product_id" => "10", "cash_amount_minor" => ["1"]],
        ["offered_product_id" => "10", "cash_amount_minor" => "1", "cash_currency" => "ZZZ"],
        ["offered_product_id" => "10", "cash_amount_minor" => "1; DROP TABLE offers;--"],
        ["offered_product_id" => "10<script>"],
        ["offered_product_id" => "10 OR 1=1"],
        ["target_id" => "20 OR 1=1"],
        ["target_id" => "999"]
    ];
    foreach ($invalidCases as $invalidCase) {
        $targetId = $invalidCase["target_id"] ?? "20";
        [$status] = offer_test_post(
            $baseUrl,
            $creatorSession,
            $creatorCsrf,
            $invalidCase,
            $targetId
        );
        offer_test_assert(
            in_array($status, [404, 422], true),
            "Invalid offer input was not rejected: " . json_encode($invalidCase) . " returned HTTP " . $status
        );
    }
    [$status] = offer_test_request(
        $baseUrl,
        "/create_offer.php",
        $creatorSession,
        [
            "target_id" => "20",
            "offered_product_id" => "10",
            "cash_amount_minor" => "1",
            "cash_currency" => "USD"
        ]
    );
    offer_test_assert($status === 400, "Invalid CSRF token was not rejected.");

    [$status] = offer_test_post($baseUrl, $creatorSession, $creatorCsrf, [
        "cash_amount_minor" => "0",
        "cash_currency" => "BHD"
    ]);
    offer_test_assert($status === 303, "Zero-cash offer was rejected.");
    $zeroCash = $pdo->query("SELECT * FROM offers ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    offer_test_assert(
        (int) $zeroCash["cash_amount_minor"] === 0
            && $zeroCash["cash_currency"] === null
            && $zeroCash["currency_exponent"] === null,
        "Zero cash did not store NULL currency and exponent."
    );

    foreach ([
        ["amount" => "8", "currency" => "BHD", "exponent" => 3],
        ["amount" => "8", "currency" => "JPY", "exponent" => 0]
    ] as $currencyCase) {
        [$status] = offer_test_post($baseUrl, $creatorSession, $creatorCsrf, [
            "offered_product_id" => "11",
            "cash_amount_minor" => $currencyCase["amount"],
            "cash_currency" => $currencyCase["currency"]
        ], "21");
        offer_test_assert($status === 303, "Valid multi-currency offer was rejected.");
        $currencyOffer = $pdo->query("SELECT * FROM offers ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        offer_test_assert(
            $currencyOffer["cash_currency"] === $currencyCase["currency"]
                && (int) $currencyOffer["currency_exponent"] === $currencyCase["exponent"],
            "Currency exponent did not match Step 4.2 rules."
        );
    }

    [$status] = offer_test_post($baseUrl, $creatorSession, $creatorCsrf);
    offer_test_assert($status === 303, "A second pending offer for the same product pair was rejected.");
    $duplicateCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM offers
         WHERE creator_id = 1 AND recipient_id = 2
           AND offered_product_id = 10 AND target_product_id = 20
           AND status = 'pending'"
    )->fetchColumn();
    offer_test_assert($duplicateCount === 3, "Duplicate pending offers were not preserved.");

    [$status] = offer_test_request($baseUrl, "/create_offer.php?target_id=23", $creatorSession);
    offer_test_assert($status === 404, "Inactive target was accepted.");
    [$status] = offer_test_request($baseUrl, "/create_offer.php?target_id=22", $creatorSession);
    offer_test_assert($status === 404, "Buy-only target was accepted.");

    $pdo->exec("UPDATE products SET status = 'reserved' WHERE id = 20");
    [$status] = offer_test_request($baseUrl, "/product_detail.php?id=20", $creatorSession);
    offer_test_assert($status === 404, "A reserved target was displayed as an available listing.");
    [$status] = offer_test_request($baseUrl, "/create_offer.php?target_id=20", $creatorSession);
    offer_test_assert($status === 404, "A reserved target remained available for offer creation.");
    $pdo->exec("UPDATE products SET status = 'active' WHERE id = 20");
    $pdo->exec("UPDATE products SET status = 'reserved' WHERE id = 10");
    [$status, $reservedOfferForm] = offer_test_request(
        $baseUrl,
        "/create_offer.php?target_id=20",
        $creatorSession
    );
    offer_test_assert(
        $status === 200 && !str_contains($reservedOfferForm, "Creator exchange"),
        "A reserved owned product appeared as an offer choice."
    );
    [$status] = offer_test_post($baseUrl, $creatorSession, $creatorCsrf);
    offer_test_assert($status === 422, "A reserved owned product was offered.");
    $pdo->exec("UPDATE products SET status = 'active' WHERE id = 10");

    $currentProductSnapshot = $pdo->query(
        "SELECT id, user_id, name, transaction_type, status FROM products ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    offer_test_assert($currentProductSnapshot === $productSnapshot, "Offer creation changed existing product records.");

    $pdo->exec("DROP TABLE offers");
    [$status, $unavailableBody] = offer_test_request(
        $baseUrl,
        "/create_offer.php?target_id=20",
        $creatorSession
    );
    offer_test_assert($status === 503 && str_contains($unavailableBody, "temporarily unavailable"), "Missing migration was not handled safely.");

    fwrite(STDOUT, "Create offer integration tests passed.\n");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($createdTemporaryRoot) {
        offer_test_remove_tree($temporaryRoot);
    }
}
