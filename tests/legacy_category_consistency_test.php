<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$databasePath = tempnam(sys_get_temp_dir(), "legacy-category-db-");
$sessionDirectory = sys_get_temp_dir() . "/legacy-category-session-" . bin2hex(random_bytes(6));
$server = null;
$serverPipes = [];

function assert_contains(string $expected, string $actual, string $message): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException($message . " (missing: " . $expected . ")");
    }
}

function assert_not_contains(string $unexpected, string $actual, string $message): void
{
    if (str_contains($actual, $unexpected)) {
        throw new RuntimeException($message . " (unexpected: " . $unexpected . ")");
    }
}

function product_markup(string $html, string $heading): string
{
    $position = strpos($html, $heading);
    if ($position === false) {
        throw new RuntimeException("Rendered product not found: " . $heading);
    }

    $start = strrpos(substr($html, 0, $position), "<article ");
    $end = strpos($html, "</article>", $position);
    if ($start === false || $end === false) {
        throw new RuntimeException("Could not isolate rendered product: " . $heading);
    }

    return substr($html, $start, $end + strlen("</article>") - $start);
}

function request_page(int $port, string $path, string $sessionId): string
{
    $context = stream_context_create([
        "http" => [
            "header" => "Cookie: PHPSESSID=" . $sessionId . "\r\n",
            "ignore_errors" => true
        ]
    ]);
    $response = file_get_contents("http://127.0.0.1:" . $port . $path, false, $context);

    if (!is_string($response)) {
        throw new RuntimeException("HTTP request failed: " . $path);
    }

    return $response;
}

try {
    if ($databasePath === false || !mkdir($sessionDirectory, 0700)) {
        throw new RuntimeException("Could not create isolated test fixtures.");
    }

    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user'
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
            category TEXT,
            transaction_type TEXT NOT NULL,
            item_condition TEXT,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE product_reservations (
            product_id INTEGER PRIMARY KEY,
            transaction_id INTEGER,
            reserved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "INSERT INTO users (id, name, email, password) VALUES
            (1, 'Fixture Seller', 'fixture@example.test', 'not-used')"
    );
    $insert = $pdo->prepare(
        "INSERT INTO products
            (id, user_id, name, description, price, category, transaction_type, item_condition, status, created_at)
         VALUES
            (:id, 1, :name, :description, 10, :category, :transaction, 'used', 'active', :created_at)"
    );
    $fixtures = [
        [1, "Vintage Camera", "vintage camera", null, "buy"],
        [2, "Vintage Lamp", "vintage lamp", "other", "exchange"],
        [3, "Vintage Radio", "vintage radio", "electronics", "buy"]
    ];
    foreach ($fixtures as [$id, $name, $description, $category, $transaction]) {
        $insert->execute([
            "id" => $id,
            "name" => $name,
            "description" => $description,
            "category" => $category,
            "transaction" => $transaction,
            "created_at" => sprintf("2026-01-01 00:00:%02d", $id)
        ]);
    }
    $pdo = null;

    $sessionId = "legacycategorytest";
    ini_set("session.save_path", $sessionDirectory);
    session_id($sessionId);
    session_start();
    $_SESSION["user_id"] = 1;
    $_SESSION["user_name"] = "Fixture Seller";
    session_write_close();

    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve a local test port: " . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ":"), 1);

    $server = proc_open(
        [
            PHP_BINARY,
            "-d",
            "session.save_path=" . $sessionDirectory,
            "-S",
            "127.0.0.1:" . $port,
            "-t",
            $root
        ],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ],
        $serverPipes,
        null,
        ["DB_DRIVER" => "sqlite", "DB_PATH" => $databasePath, "PATH" => getenv("PATH") ?: ""]
    );
    if (!is_resource($server)) {
        throw new RuntimeException("Could not start the local PHP test server.");
    }

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @fsockopen("127.0.0.1", $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException("The local PHP test server did not start.");
    }

    $other = request_page($port, "/index.php?category=other", $sessionId);
    assert_contains("Vintage Camera", $other, "Other filter must include NULL category products.");
    assert_contains("Vintage Lamp", $other, "Other filter must include category=other products.");
    assert_not_contains("Vintage Radio", $other, "Other filter must exclude electronics.");
    assert_contains(
        '<span class="product-badge">Other</span>',
        product_markup($other, "<h3>Vintage Camera</h3>"),
        "Homepage must label NULL as Other."
    );

    $electronics = request_page($port, "/index.php?category=electronics", $sessionId);
    assert_contains("Vintage Radio", $electronics, "Electronics filter must include electronics.");
    assert_not_contains("Vintage Camera", $electronics, "Electronics filter must exclude NULL categories.");
    assert_not_contains("Vintage Lamp", $electronics, "Electronics filter must exclude other.");

    $combined = request_page(
        $port,
        "/index.php?search=vintage&transaction=buy",
        $sessionId
    );
    assert_contains("Vintage Camera", $combined, "Combined search and transaction filters must include matching NULL-category products.");
    assert_contains("Vintage Radio", $combined, "Combined search and transaction filters must include matching electronics.");
    assert_not_contains("Vintage Lamp", $combined, "Combined search and transaction filters must exclude exchanges.");

    $otherCombined = request_page(
        $port,
        "/index.php?category=other&search=vintage&transaction=buy",
        $sessionId
    );
    assert_contains("Vintage Camera", $otherCombined, "Combined Other, search, and transaction filters must include matching NULL products.");
    assert_not_contains("Vintage Lamp", $otherCombined, "Combined filters must exclude nonmatching transaction types.");
    assert_not_contains("Vintage Radio", $otherCombined, "Combined Other filter must exclude electronics.");

    $details = request_page($port, "/product_detail.php?id=1", $sessionId);
    assert_contains("Vintage Camera", $details, "Product detail fixture should render.");
    assert_contains('<span class="badge">Other</span>', $details, "Product detail must label NULL as Other.");

    $myProducts = request_page($port, "/my_products.php", $sessionId);
    assert_contains("Vintage Camera", $myProducts, "My Products fixture should render.");
    $nullProduct = product_markup($myProducts, "<h2>Vintage Camera</h2>");
    assert_contains("<span>Other</span>", $nullProduct, "My Products must label NULL as Other.");
    assert_not_contains("Uncategorized", $nullProduct, "My Products must not label NULL as Uncategorized.");

    echo "Legacy category consistency regression tests passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ($serverPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_string($databasePath) && file_exists($databasePath)) {
        unlink($databasePath);
    }
    $sessionFile = $sessionDirectory . "/sess_legacycategorytest";
    if (file_exists($sessionFile)) {
        unlink($sessionFile);
    }
    if (is_dir($sessionDirectory)) {
        rmdir($sessionDirectory);
    }
}
