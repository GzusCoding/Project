<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "my-offers-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;
$createdTemporaryRoot = false;

function my_offers_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function my_offers_session(string $path, string $id, int $userId): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId];
    session_write_close();
}

function my_offers_request(string $baseUrl, string $path, ?string $sessionId): array
{
    $headers = ["Connection: close"];
    if ($sessionId !== null) {
        $headers[] = "Cookie: PHPSESSID=" . $sessionId;
    }
    $body = file_get_contents(
        $baseUrl . $path,
        false,
        stream_context_create([
            "http" => [
                "method" => "GET",
                "header" => $headers,
                "ignore_errors" => true,
                "timeout" => 5,
                "follow_location" => 0
            ]
        ])
    );
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

function my_offers_remove_tree(string $root): void
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
            price REAL NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'active',
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
        "INSERT INTO products (id, user_id, name, description)
         VALUES (:id, :user_id, :name, 'fixture description')"
    );
    foreach ([
        [10, 1, "Creator item"],
        [20, 2, "<script>alert(1)</script>"],
        [30, 3, "Unrelated item"]
    ] as [$id, $ownerId, $name]) {
        $productInsert->execute(["id" => $id, "user_id" => $ownerId, "name" => $name]);
    }

    $insertOffer = $pdo->prepare(
        "INSERT INTO offers (
            creator_id, recipient_id, cash_payer_id, offered_product_id,
            target_product_id, cash_amount_minor, cash_currency, currency_exponent,
            status, created_at
         ) VALUES (
            :creator_id, :recipient_id, :creator_id, :offered_product_id,
            :target_product_id, :amount, :currency, :exponent, :status, :created_at
         )"
    );
    $statusFixtures = [
        ["pending", 0, null, null],
        ["accepted", 125, "USD", 2],
        ["rejected", 10, "JPY", 0],
        ["cancelled", 1234, "EUR", 2],
        ["superseded", 8, "BHD", 3],
        ["expired", 5, "CAD", 2]
    ];
    foreach ($statusFixtures as $index => [$status, $amount, $currency, $exponent]) {
        $insertOffer->execute([
            "creator_id" => 1,
            "recipient_id" => 2,
            "offered_product_id" => 10,
            "target_product_id" => 20,
            "amount" => $amount,
            "currency" => $currency,
            "exponent" => $exponent,
            "status" => $status,
            "created_at" => sprintf("2026-01-01 00:00:%02d", $index)
        ]);
    }
    $insertOffer->execute([
        "creator_id" => 2,
        "recipient_id" => 1,
        "offered_product_id" => 20,
        "target_product_id" => 10,
        "amount" => 0,
        "currency" => null,
        "exponent" => null,
        "status" => "pending",
        "created_at" => "2026-01-02 00:00:00"
    ]);

    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve a local test port: " . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) $address, strrpos((string) $address, ":") + 1);
    $baseUrl = "http://127.0.0.1:" . $port;
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
    my_offers_assert($ready, "The isolated PHP test server did not start.");

    $creatorSession = bin2hex(random_bytes(16));
    $recipientSession = bin2hex(random_bytes(16));
    $unrelatedSession = bin2hex(random_bytes(16));
    my_offers_session($sessionPath, $creatorSession, 1);
    my_offers_session($sessionPath, $recipientSession, 2);
    my_offers_session($sessionPath, $unrelatedSession, 3);

    [$status, , $headers] = my_offers_request($baseUrl, "/my_offers.php", null);
    my_offers_assert($status === 302, "Guest access to offers was not denied.");
    my_offers_assert(
        count(array_filter($headers, static fn ($header) => stripos($header, "Location: login.php") === 0)) > 0,
        "Guest was not redirected to login."
    );

    [$status, $incoming] = my_offers_request(
        $baseUrl,
        "/my_offers.php?section=incoming&status=all",
        $recipientSession
    );
    my_offers_assert($status === 200, "Recipient could not load incoming offers.");
    foreach (["Pending", "Accepted", "Rejected", "Cancelled", "Superseded", "Expired"] as $label) {
        my_offers_assert(str_contains($incoming, $label), "Incoming list omitted status {$label}.");
    }
    my_offers_assert(str_contains($incoming, "None"), "Zero cash difference was not displayed.");
    my_offers_assert(str_contains($incoming, "1.25 USD"), "USD minor units were not formatted exactly.");
    my_offers_assert(str_contains($incoming, "10 JPY"), "Zero-exponent currency was not displayed.");
    my_offers_assert(str_contains($incoming, "12.34 EUR"), "Multi-currency amount was not displayed.");
    my_offers_assert(str_contains($incoming, "0.008 BHD"), "Three-decimal currency was not displayed.");
    my_offers_assert(
        str_contains($incoming, "Offer status: Accepted")
            && str_contains($incoming, "No transaction recorded (legacy)")
            && str_contains($incoming, "Transaction status: Not started")
            && str_contains($incoming, "Payment status: Not applicable (no transaction)"),
        "Offer, transaction, and payment states were not presented separately."
    );
    my_offers_assert(
        !str_contains($incoming, "<script>alert(1)</script>")
            && str_contains($incoming, "&lt;script&gt;alert(1)&lt;/script&gt;"),
        "Offer product name was not safely escaped."
    );
    preg_match_all('/<article class="offer">(.*?)<\/article>/s', $incoming, $incomingRows);
    my_offers_assert(
        count($incomingRows[1]) === 6
            && str_contains($incomingRows[1][0], "Expired")
            && str_contains($incomingRows[1][1], "Superseded"),
        "Incoming offers were not ordered newest first."
    );
    my_offers_assert(
        str_contains($incoming, 'name="section" value="incoming"')
            && str_contains($incoming, 'value="rejected"'),
        "The selected section or status filter was not preserved in the form."
    );

    [$status, $outgoing] = my_offers_request(
        $baseUrl,
        "/my_offers.php?section=outgoing&status=all",
        $creatorSession
    );
    my_offers_assert($status === 200 && str_contains($outgoing, "Creator item"), "Creator could not load outgoing offers.");
    my_offers_assert(
        str_contains($outgoing, "offer_detail.php?id=1&amp;section=outgoing&amp;status=all"),
        "Offer detail link did not preserve the current section and filter."
    );

    [$status, $filtered] = my_offers_request(
        $baseUrl,
        "/my_offers.php?section=incoming&status=rejected",
        $recipientSession
    );
    preg_match_all('/<article class="offer">(.*?)<\/article>/s', $filtered, $filteredRows);
    my_offers_assert(
        $status === 200
            && count($filteredRows[1]) === 1
            && str_contains($filteredRows[1][0], "Rejected"),
        "Status filtering did not limit the offer rows."
    );
    my_offers_assert(
        str_contains($filtered, 'name="section" value="incoming"')
            && str_contains($filtered, 'option value="rejected" selected'),
        "Selected status and section were not retained after filtering."
    );

    [$status, $empty] = my_offers_request(
        $baseUrl,
        "/my_offers.php?section=incoming",
        $unrelatedSession
    );
    my_offers_assert($status === 200 && str_contains($empty, "No incoming offers"), "Empty offer list was not handled.");
    my_offers_assert(!str_contains($empty, "Creator item"), "Unrelated user could see another user's offers.");
    [$status, $forgedUser] = my_offers_request(
        $baseUrl,
        "/my_offers.php?section=incoming&user_id=2",
        $unrelatedSession
    );
    my_offers_assert(
        $status === 200 && !str_contains($forgedUser, "Creator item"),
        "A URL-supplied user ID exposed another user's offers."
    );

    foreach (["section=incoming%27%20OR%201%3D1--", "status=pending%27%20OR%201%3D1--"] as $injectedFilter) {
        [$status] = my_offers_request($baseUrl, "/my_offers.php?" . $injectedFilter, $recipientSession);
        my_offers_assert($status === 400, "SQL-injected offer filter was not rejected.");
    }
    [$status, $detail] = my_offers_request(
        $baseUrl,
        "/offer_detail.php?id=1&section=outgoing&status=accepted",
        $creatorSession
    );
    my_offers_assert($status === 200, "Offer participant could not access detail.");
    my_offers_assert(
        str_contains($detail, "Back to my offers")
            && str_contains($detail, "section=outgoing&amp;status=accepted"),
        "Detail page did not preserve return section and filter."
    );
    my_offers_assert(
        !str_contains($detail, "<script>alert(1)</script>")
            && str_contains($detail, "&lt;script&gt;alert(1)&lt;/script&gt;"),
        "Offer detail did not safely escape product names."
    );
    [$status] = my_offers_request($baseUrl, "/offer_detail.php?id=1", $unrelatedSession);
    my_offers_assert($status === 404, "Non-participant could access offer details.");
    foreach (["0", "-1", "abc", "1%20OR%201%3D1--", "999999999999999999999999"] as $invalidId) {
        [$status] = my_offers_request(
            $baseUrl,
            "/offer_detail.php?id=" . $invalidId,
            $creatorSession
        );
        my_offers_assert($status === 404, "Invalid offer ID was not rejected: " . $invalidId);
    }

    fwrite(STDOUT, "My offers integration tests passed.\n");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($createdTemporaryRoot) {
        my_offers_remove_tree($temporaryRoot);
    }
}
