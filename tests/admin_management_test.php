<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "admin-management-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;

function admin_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function admin_test_session(string $path, string $id, int $userId): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId];
    session_write_close();
}

function admin_test_request(
    string $baseUrl,
    string $path,
    string $sessionId,
    ?array $form = null
): array {
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

function admin_test_csrf_token(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException("The admin page did not render a CSRF token.");
    }

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, "UTF-8");
}

function admin_test_remove_tree(string $root): void
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
        throw new RuntimeException("Could not create isolated admin test directories.");
    }
    $pdo = new PDO("sqlite:" . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec(
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            role TEXT NOT NULL,
            account_status TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "CREATE TABLE categories (
            category_code TEXT PRIMARY KEY,
            category_name TEXT NOT NULL UNIQUE,
            description TEXT NULL,
            category_name_th TEXT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            price REAL NOT NULL DEFAULT 10,
            category TEXT NULL,
            status TEXT NOT NULL DEFAULT 'active'
        )"
    );
    $pdo->exec("CREATE TABLE buy_orders (id INTEGER PRIMARY KEY)");
    $pdo->exec(
        "CREATE TABLE exchange_transactions (
            id INTEGER PRIMARY KEY,
            status TEXT NOT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE admin_user_status_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            admin_id INTEGER NOT NULL,
            old_status TEXT NOT NULL,
            new_status TEXT NOT NULL,
            reason TEXT NOT NULL,
            changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (admin_id) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "INSERT INTO users (id, name, email, role, account_status) VALUES
            (1, 'Admin', 'admin@example.test', 'admin', 'active'),
            (2, 'Member', 'member@example.test', 'user', 'active')"
    );
    $pdo->exec(
        "INSERT INTO categories (category_code, category_name, category_name_th)
         VALUES ('other', 'Other', 'อื่น ๆ')"
    );
    $pdo->exec(
        "INSERT INTO products (id, user_id, name, category, status)
         VALUES (1, 2, 'Used item', 'other', 'active')"
    );
    $pdo->exec("INSERT INTO buy_orders (id) VALUES (1)");
    $pdo->exec("INSERT INTO exchange_transactions (id, status) VALUES (1, 'completed')");
    $pdo = null;

    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve an admin test port: " . $error);
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
            "PATH" => getenv("PATH") ?: ""
        ]
    );
    if (!is_resource($server)) {
        throw new RuntimeException("Could not start the admin test server.");
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
        throw new RuntimeException("The admin test server did not start.");
    }

    $adminSession = "adminmanagementtest";
    admin_test_session($sessionPath, $adminSession, 1);
    [$status, $html] = admin_test_request(
        $baseUrl,
        "/admin.php?lang=en",
        $adminSession
    );
    admin_test_assert(
        $status === 200
            && str_contains($html, "Administration overview")
            && str_contains($html, "Category management")
            && str_contains($html, "member@example.test")
            && str_contains($html, "Completed exchanges"),
        "The admin dashboard did not render its management sections and statistics."
    );
    $csrfToken = admin_test_csrf_token($html);

    [$status] = admin_test_request(
        $baseUrl,
        "/admin_management_action.php",
        $adminSession,
        [
            "csrf_token" => $csrfToken,
            "admin_action" => "save_category",
            "category_code" => "collectibles",
            "category_name" => "Collectibles",
            "category_name_th" => "ของสะสม",
            "description" => "Collectible items"
        ]
    );
    admin_test_assert($status === 303, "Creating a category did not redirect after saving.");
    admin_test_session($sessionPath, "membermanagementtest", 2);
    [$status, $englishForm] = admin_test_request(
        $baseUrl,
        "/add_product.php?lang=en",
        "membermanagementtest"
    );
    admin_test_assert(
        $status === 200 && str_contains($englishForm, "Collectibles"),
        "A new category was not available in the English product form."
    );
    [$status, $thaiForm] = admin_test_request(
        $baseUrl,
        "/add_product.php?lang=th",
        "membermanagementtest"
    );
    admin_test_assert(
        $status === 200 && str_contains($thaiForm, "ของสะสม"),
        "A new category was not localized in the Thai product form."
    );

    [$status] = admin_test_request(
        $baseUrl,
        "/admin_management_action.php",
        $adminSession,
        [
            "csrf_token" => $csrfToken,
            "admin_action" => "set_user_status",
            "user_id" => "2",
            "account_status" => "suspended",
            "reason" => "Policy violation"
        ]
    );
    admin_test_assert($status === 303, "Suspending a member did not redirect after saving.");
    $pdo = new PDO("sqlite:" . $databasePath);
    admin_test_assert(
        $pdo->query("SELECT account_status FROM users WHERE id = 2")->fetchColumn() === "suspended"
            && (int) $pdo->query("SELECT COUNT(*) FROM admin_user_status_history")->fetchColumn() === 1,
        "Member status changes must be recorded with history."
    );
    $pdo = null;

    [$status] = admin_test_request(
        $baseUrl,
        "/admin_management_action.php",
        $adminSession,
        [
            "csrf_token" => $csrfToken,
            "admin_action" => "delete_category",
            "category_code" => "other"
        ]
    );
    admin_test_assert($status === 303, "Deleting a referenced category should return a safe redirect.");
    $pdo = new PDO("sqlite:" . $databasePath);
    admin_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM categories WHERE category_code = 'other'"
        )->fetchColumn() === 1,
        "A category used by a product must not be deleted."
    );
    $pdo = null;

    [$status] = admin_test_request(
        $baseUrl,
        "/admin_management_action.php",
        $adminSession,
        [
            "csrf_token" => $csrfToken,
            "admin_action" => "delete_category",
            "category_code" => "collectibles"
        ]
    );
    admin_test_assert($status === 303, "Deleting an unused category did not redirect after saving.");
    $pdo = new PDO("sqlite:" . $databasePath);
    admin_test_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM categories WHERE category_code = 'collectibles'"
        )->fetchColumn() === 0,
        "An unused category could not be deleted."
    );
    $pdo = null;

    echo "Admin management integration tests passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_dir($temporaryRoot)) {
        admin_test_remove_tree($temporaryRoot);
    }
}
