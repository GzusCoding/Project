<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "product-reservation-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;
$created = false;

function reservation_operations_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reservation_operations_session(string $path, string $id, int $userId, string $token): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId, "csrf_token" => $token, "user_name" => "Fixture"];
    session_write_close();
}

function reservation_operations_request(
    string $baseUrl,
    string $path,
    string $sessionId,
    ?array $form = null
): array {
    if ($form === null) {
        $path .= (str_contains($path, "?") ? "&" : "?") . "lang=en";
    }
    $headers = ["Connection: close", "Cookie: PHPSESSID=" . $sessionId];
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
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/\AHTTP\/\S+ ([0-9]{3})/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    return [$status, is_string($body) ? $body : ""];
}

function reservation_operations_remove_tree(string $root): void
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
        throw new RuntimeException("Could not create isolated product reservation fixtures.");
    }
    $created = true;
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password TEXT NOT NULL
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
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE product_reservations (
            product_id INTEGER PRIMARY KEY,
            transaction_id INTEGER NOT NULL,
            reserved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "INSERT INTO users (id, name, email, password)
         VALUES (1, 'Fixture', 'fixture@example.test', 'unused')"
    );
    $insertProduct = $pdo->prepare(
        "INSERT INTO products (
            id, user_id, name, description, price, category,
            transaction_type, item_condition, status
         ) VALUES (:id, 1, :name, 'Original description', 2, 'other', 'both', 'used', :status)"
    );
    foreach ([[10, "Reserved status", "reserved"], [20, "Reserved relation", "active"], [30, "Available", "active"]] as [$id, $name, $status]) {
        $insertProduct->execute(["id" => $id, "name" => $name, "status" => $status]);
    }
    $pdo->exec(
        "INSERT INTO product_reservations (product_id, transaction_id)
         VALUES (10, 1), (20, 1)"
    );

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
    reservation_operations_assert($ready, "The isolated PHP server did not start.");

    $sessionId = bin2hex(random_bytes(16));
    $csrfToken = bin2hex(random_bytes(32));
    reservation_operations_session($sessionPath, $sessionId, 1, $csrfToken);
    foreach ([10, 20] as $reservedId) {
        [$status] = reservation_operations_request(
            $baseUrl,
            "/edit_product.php?id={$reservedId}",
            $sessionId
        );
        reservation_operations_assert($status === 409, "Reserved product {$reservedId} could be edited.");
        [$status] = reservation_operations_request(
            $baseUrl,
            "/deactivate_product.php?id={$reservedId}",
            $sessionId
        );
        reservation_operations_assert($status === 200, "Reserved product confirmation page failed.");
        [$status, $body] = reservation_operations_request(
            $baseUrl,
            "/deactivate_product.php?id={$reservedId}",
            $sessionId,
            ["csrf_token" => $csrfToken, "confirm_deactivation" => "1"]
        );
        reservation_operations_assert(
            $status === 409 && str_contains($body, "reserved for an exchange"),
            "Reserved product {$reservedId} could be deactivated."
        );
    }
    reservation_operations_assert(
        $pdo->query("SELECT status FROM products WHERE id IN (10, 20) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
            === ["reserved", "active"],
        "A reserved-product request changed product status."
    );
    [$status, $listing] = reservation_operations_request($baseUrl, "/index.php", $sessionId);
    reservation_operations_assert(
        $status === 200
            && str_contains($listing, "Available")
            && !str_contains($listing, "Reserved status")
            && !str_contains($listing, "Reserved relation"),
        "Reserved products were displayed as available marketplace listings."
    );
    [$status] = reservation_operations_request($baseUrl, "/product_detail.php?id=20", $sessionId);
    reservation_operations_assert($status === 404, "A reserved product detail was displayed as available.");
    [$status, $ownedProducts] = reservation_operations_request($baseUrl, "/my_products.php", $sessionId);
    reservation_operations_assert(
        $status === 200
            && str_contains($ownedProducts, "Reserved for an exchange")
            && !str_contains($ownedProducts, "edit_product.php?id=10")
            && !str_contains($ownedProducts, "deactivate_product.php?id=10")
            && str_contains($ownedProducts, "edit_product.php?id=30"),
        "Reserved management actions were not hidden or available-product actions were lost."
    );

    [$status, $editPage] = reservation_operations_request(
        $baseUrl,
        "/edit_product.php?id=30",
        $sessionId
    );
    reservation_operations_assert($status === 200, "An available product could not be edited.");
    [$status] = reservation_operations_request(
        $baseUrl,
        "/edit_product.php?id=30",
        $sessionId,
        [
            "csrf_token" => $csrfToken,
            "name" => "Updated available",
            "description" => "Updated description",
            "price" => "3.00",
            "category" => "other",
            "transaction_type" => "both",
            "item_condition" => "used"
        ]
    );
    reservation_operations_assert(
        $status === 302
            && $pdo->query("SELECT name FROM products WHERE id = 30")->fetchColumn() === "Updated available",
        "Normal product editing behavior regressed."
    );
    [$status] = reservation_operations_request(
        $baseUrl,
        "/deactivate_product.php?id=30",
        $sessionId,
        ["csrf_token" => $csrfToken, "confirm_deactivation" => "1"]
    );
    reservation_operations_assert(
        $status === 302
            && $pdo->query("SELECT status FROM products WHERE id = 30")->fetchColumn() === "inactive",
        "Normal product deactivation behavior regressed."
    );
    fwrite(STDOUT, "Product reservation operation tests passed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    if (isset($serverLog) && is_file($serverLog)) {
        fwrite(STDERR, file_get_contents($serverLog) ?: "");
    }
    $exitCode = 1;
} finally {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo = null;
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($created) {
        reservation_operations_remove_tree($temporaryRoot);
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}
