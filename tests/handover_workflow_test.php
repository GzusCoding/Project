<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "handover-workflow-test-" . bin2hex(random_bytes(8));
$appRoot = $temporaryRoot . DIRECTORY_SEPARATOR . "app";
$databasePath = $appRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$created = false;

function handover_workflow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function handover_workflow_migration(string $script, string $databasePath, string $confirmation): array
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
        throw new RuntimeException("Could not start an isolated migration.");
    }
    fwrite($pipes[0], $confirmation . "\n");
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

function handover_workflow_session(string $path, string $id, int $userId, string $csrf): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId, "csrf_token" => $csrf];
    session_write_close();
}

function handover_workflow_start_cli(
    string $appRoot,
    string $sessionPath,
    string $databasePath,
    string $target,
    string $method,
    string $sessionId,
    array $payload
): array {
    $runner = <<<'PHP'
register_shutdown_function(static function (): void {
    fwrite(STDERR, "HTTP_STATUS=" . http_response_code());
});
session_id($argv[1]);
$_SERVER["REQUEST_METHOD"] = $argv[2];
$_POST = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
$_GET = ["id" => $argv[4]];
if (isset($_POST["lang"]) && is_string($_POST["lang"])) {
    $_GET["lang"] = $_POST["lang"];
}
http_response_code(200);
require $argv[5];
PHP;
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            "-d",
            "session.save_path=" . $sessionPath,
            "-r",
            $runner,
            $sessionId,
            $method,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $target === "exchange_transaction.php" ? (string) ($payload["transaction_id"] ?? "1") : "1",
            $appRoot . DIRECTORY_SEPARATOR . $target
        ],
        [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
        $appRoot,
        [
            "DB_DRIVER" => "sqlite",
            "DB_PATH" => $databasePath,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start a CLI request.");
    }

    return [$process, $pipes];
}

function handover_workflow_finish_cli(array $request): array
{
    [$process, $pipes] = $request;
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if (preg_match('/HTTP_STATUS=([0-9]{3})/', $errors, $matches) !== 1) {
        throw new RuntimeException("CLI request did not report an HTTP status: {$errors}");
    }

    return [(int) $matches[1], is_string($body) ? $body : "", $errors, $exit];
}

function handover_workflow_request(
    string $appRoot,
    string $sessionPath,
    string $databasePath,
    string $target,
    string $method,
    string $sessionId,
    array $payload
): array {
    return handover_workflow_finish_cli(
        handover_workflow_start_cli(
            $appRoot,
            $sessionPath,
            $databasePath,
            $target,
            $method,
            $sessionId,
            $payload
        )
    );
}

function handover_workflow_json(array $response): array
{
    return json_decode($response[1], true, 512, JSON_THROW_ON_ERROR);
}

function handover_workflow_form(int $transactionId, string $csrf, string $type): array
{
    return [
        "transaction_id" => (string) $transactionId,
        "confirmation_type" => $type,
        "csrf_token" => $csrf
    ];
}

function handover_workflow_cleanup(string $root): void
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
    if (!mkdir($temporaryRoot, 0700) || !mkdir($appRoot, 0700) || !mkdir($sessionPath, 0700)) {
        throw new RuntimeException("Could not create isolated handover test directories.");
    }
    $created = true;
    if (!mkdir($appRoot . DIRECTORY_SEPARATOR . "includes", 0700)) {
        throw new RuntimeException("Could not create isolated shared-code directory.");
    }
    foreach ([
        "migrate_offers.php",
        "migrate_exchange_transactions.php",
        "migrate_chat_messages.php",
        "migrate_payment_evidence.php",
        "migrate_handover_confirmations.php",
        "db.php",
        "security.php",
        "i18n.php",
        "handover_support.php",
        "notification_support.php",
        "exchange_completion_support.php",
        "handover_action.php",
        "exchange_transaction.php"
    ] as $file) {
        $isMigration = str_starts_with($file, "migrate_");
        $isSharedCode = in_array($file, [
            "db.php", "security.php", "i18n.php", "handover_support.php",
            "notification_support.php", "exchange_completion_support.php"
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
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            category TEXT,
            transaction_type TEXT NOT NULL DEFAULT 'both',
            item_condition TEXT,
            status TEXT NOT NULL DEFAULT 'reserved',
            FOREIGN KEY (user_id) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec("INSERT INTO schema_migrations (version) VALUES (1)");
    $pdo->exec(
        "INSERT INTO users (id, name, email, password, role) VALUES
            (1, 'Creator', 'creator@example.test', 'unused', 'user'),
            (2, 'Recipient', 'recipient@example.test', 'unused', 'user'),
            (3, 'Admin', 'admin@example.test', 'unused', 'admin'),
            (4, 'Unrelated', 'unrelated@example.test', 'unused', 'user')"
    );
    $pdo->exec(
        "INSERT INTO products (id, user_id, name, price, transaction_type, status) VALUES
            (10, 1, 'Offered item', 1, 'exchange', 'reserved'),
            (20, 2, 'Requested item', 1, 'both', 'reserved')"
    );
    foreach ([
        "migrate_offers.php" => "APPLY OFFERS MIGRATION 2",
        "migrate_exchange_transactions.php" => "APPLY EXCHANGE TRANSACTION MIGRATION 3"
    ] as $script => $confirmation) {
        [$exit, $stdout, $stderr] = handover_workflow_migration(
            $appRoot . DIRECTORY_SEPARATOR . $script,
            $databasePath,
            $confirmation
        );
        handover_workflow_assert($exit === 0, "{$script} fixture setup failed: {$stdout}{$stderr}");
    }

    $offers = $pdo->prepare(
        "INSERT INTO offers (
            creator_id, recipient_id, cash_payer_id, offered_product_id, target_product_id,
            cash_amount_minor, cash_currency, currency_exponent, status
         ) VALUES (1, 2, 1, 10, 20, 0, NULL, NULL, 'accepted')"
    );
    $transactions = [];
    $transactionInsert = $pdo->prepare(
        "INSERT INTO exchange_transactions (
            offer_id, creator_id, recipient_id, offered_product_id, target_product_id,
            cash_payer_id, cash_amount_minor, payment_status, status
         ) VALUES (:offer_id, 1, 2, 10, 20, 1, 0, 'not_required', 'in_progress')"
    );
    for ($i = 1; $i <= 4; $i++) {
        $offers->execute();
        $transactionInsert->execute(["offer_id" => (int) $pdo->lastInsertId()]);
        $transactions[$i] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare(
        "INSERT INTO product_reservations (product_id, transaction_id) VALUES (10, :transaction_id)"
    )->execute(["transaction_id" => $transactions[2]]);
    $pdo->prepare(
        "INSERT INTO chat_rooms (exchange_transaction_id, creator_id, recipient_id)
         VALUES (:transaction_id, 1, 2)"
    )->execute(["transaction_id" => $transactions[2]]);
    [$exit, $stdout, $stderr] = handover_workflow_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_chat_messages.php",
        $databasePath,
        "APPLY CHAT MESSAGES MIGRATION 4"
    );
    handover_workflow_assert($exit === 0, "Version 4 fixture setup failed: {$stdout}{$stderr}");
    $pdo->prepare(
        "INSERT INTO chat_messages (room_id, sender_id, message_body)
         SELECT id, 1, 'Dispute chat remains available'
         FROM chat_rooms WHERE exchange_transaction_id = :transaction_id"
    )->execute(["transaction_id" => $transactions[2]]);
    [$exit, $stdout, $stderr] = handover_workflow_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        "APPLY PAYMENT EVIDENCE MIGRATION 5"
    );
    handover_workflow_assert($exit === 0, "Version 5 fixture setup failed: {$stdout}{$stderr}");

    $pdo->prepare(
        "UPDATE exchange_transactions SET status = 'completed' WHERE id = :id"
    )->execute(["id" => $transactions[4]]);
    [$exit, $stdout, $stderr] = handover_workflow_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_handover_confirmations.php",
        $databasePath,
        "APPLY HANDOVER CONFIRMATIONS MIGRATION 6"
    );
    handover_workflow_assert($exit === 0, "Version 6 fixture setup failed: {$stdout}{$stderr}");

    $csrf = [
        1 => "handover_creator_csrf",
        2 => "handover_recipient_csrf",
        4 => "handover_unrelated_csrf"
    ];
    $sessions = [
        1 => "handover-creator-session",
        2 => "handover-recipient-session",
        4 => "handover-unrelated-session"
    ];
    foreach ($sessions as $userId => $sessionId) {
        handover_workflow_session($sessionPath, $sessionId, $userId, $csrf[$userId]);
    }

    $transactionId = $transactions[1];
    $creatorType = "creator_handed_over_offered_item";
    $creatorForm = handover_workflow_form($transactionId, $csrf[1], $creatorType);
    $guest = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        "handover-guest-session",
        $creatorForm
    );
    handover_workflow_assert(
        $guest[0] === 401,
        "An unauthenticated confirmation was accepted ({$guest[0]}): {$guest[1]} {$guest[2]}"
    );
    $missingCsrf = $creatorForm;
    unset($missingCsrf["csrf_token"]);
    $csrfResponse = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[1],
        $missingCsrf
    );
    handover_workflow_assert($csrfResponse[0] === 400, "A request without CSRF was accepted.");
    $methodResponse = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "GET",
        $sessions[1],
        $creatorForm
    );
    handover_workflow_assert($methodResponse[0] === 405, "A non-POST handover action was accepted.");
    $invalidId = $creatorForm;
    $invalidId["transaction_id"] = "0";
    handover_workflow_assert(
        handover_workflow_request(
            $appRoot,
            $sessionPath,
            $databasePath,
            "handover_action.php",
            "POST",
            $sessions[1],
            $invalidId
        )[0] === 400,
        "A malformed transaction ID was accepted."
    );

    $wrongParticipant = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[2],
        handover_workflow_form($transactionId, $csrf[2], $creatorType)
    );
    handover_workflow_assert($wrongParticipant[0] === 403, "The other participant confirmed the creator's action.");
    $outsider = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[4],
        handover_workflow_form($transactionId, $csrf[4], $creatorType)
    );
    handover_workflow_assert($outsider[0] === 404, "A nonparticipant accessed transaction confirmation state.");

    $first = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[1],
        $creatorForm
    );
    $firstJson = handover_workflow_json($first);
    handover_workflow_assert(
        $first[0] === 200
            && $firstJson["success"] === true
            && $firstJson["confirmation"]["confirmer_id"] === 1
            && count($firstJson["confirmations"]) === 1,
        "The creator's handover confirmation was not recorded correctly."
    );
    $originalTimestamp = $firstJson["confirmation"]["confirmed_at"];
    $replay = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[1],
        $creatorForm
    );
    $replayJson = handover_workflow_json($replay);
    handover_workflow_assert(
        $replay[0] === 200
            && $replayJson["confirmation"]["confirmer_id"] === 1
            && $replayJson["confirmation"]["confirmed_at"] === $originalTimestamp
            && (int) $pdo->query(
                "SELECT COUNT(*) FROM handover_confirmations
                 WHERE transaction_id = {$transactionId} AND confirmation_type = '{$creatorType}'"
            )->fetchColumn() === 1,
        "A duplicate request changed or duplicated the original confirmation."
    );
    $unauthorizedReplay = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[2],
        handover_workflow_form($transactionId, $csrf[2], $creatorType)
    );
    handover_workflow_assert(
        $unauthorizedReplay[0] === 403
            && !str_contains($unauthorizedReplay[1], $originalTimestamp),
        "An unauthorized participant received an existing confirmation."
    );

    $remaining = [
        [$sessions[2], 2, "recipient_received_offered_item"],
        [$sessions[2], 2, "recipient_handed_over_requested_item"],
        [$sessions[1], 1, "creator_received_requested_item"]
    ];
    foreach ($remaining as [$sessionId, $userId, $type]) {
        $response = handover_workflow_request(
            $appRoot,
            $sessionPath,
            $databasePath,
            "handover_action.php",
            "POST",
            $sessionId,
            handover_workflow_form($transactionId, $csrf[$userId], $type)
        );
        handover_workflow_assert($response[0] === 200, "Confirmation {$type} failed: {$response[1]}");
    }
    handover_workflow_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM handover_confirmations WHERE transaction_id = {$transactionId}"
        )->fetchColumn() === 4,
        "All four genuine confirmations were not recorded."
    );

    $concurrentTransactionId = $transactions[2];
    $concurrentType = "recipient_received_offered_item";
    $concurrentForm = handover_workflow_form($concurrentTransactionId, $csrf[2], $concurrentType);
    $requestA = handover_workflow_start_cli(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[2],
        $concurrentForm
    );
    $requestB = handover_workflow_start_cli(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[2],
        $concurrentForm
    );
    $concurrentA = handover_workflow_finish_cli($requestA);
    $concurrentB = handover_workflow_finish_cli($requestB);
    $concurrentJsonA = handover_workflow_json($concurrentA);
    $concurrentJsonB = handover_workflow_json($concurrentB);
    handover_workflow_assert(
        $concurrentA[0] === 200
            && $concurrentB[0] === 200
            && $concurrentJsonA["confirmation"]["confirmed_at"] === $concurrentJsonB["confirmation"]["confirmed_at"]
            && (int) $pdo->query(
                "SELECT COUNT(*) FROM handover_confirmations
                 WHERE transaction_id = {$concurrentTransactionId}
                   AND confirmation_type = '{$concurrentType}'"
            )->fetchColumn() === 1,
        "Concurrent duplicate requests created multiple confirmations or diverged."
    );

    $creatorView = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "exchange_transaction.php",
        "GET",
        $sessions[1],
        ["transaction_id" => (string) $concurrentTransactionId, "lang" => "en"]
    );
    handover_workflow_assert(
        $creatorView[0] === 200
            && str_contains($creatorView[1], 'name="confirmation_type" value="creator_handed_over_offered_item"')
            && !str_contains($creatorView[1], 'name="confirmation_type" value="recipient_handed_over_requested_item"'),
        "The transaction page did not restrict visible confirmation actions to the viewer."
    );

    $pdo->prepare(
        "INSERT INTO transaction_disputes (transaction_id, reported_by, reason)
         VALUES (:transaction_id, 1, 'Workflow test dispute')"
    )->execute(["transaction_id" => $concurrentTransactionId]);
    $pdo->prepare(
        "UPDATE exchange_transactions SET status = 'disputed' WHERE id = :id"
    )->execute(["id" => $concurrentTransactionId]);
    $replayDuringDispute = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[2],
        $concurrentForm
    );
    $newDuringDispute = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "handover_action.php",
        "POST",
        $sessions[1],
        handover_workflow_form(
            $concurrentTransactionId,
            $csrf[1],
            "creator_handed_over_offered_item"
        )
    );
    handover_workflow_assert(
        $replayDuringDispute[0] === 200 && $newDuringDispute[0] === 409,
        "Disputed confirmation replay or new confirmation behavior was incorrect."
    );
    $disputedView = handover_workflow_request(
        $appRoot,
        $sessionPath,
        $databasePath,
        "exchange_transaction.php",
        "GET",
        $sessions[2],
        ["transaction_id" => (string) $concurrentTransactionId, "lang" => "en"]
    );
    handover_workflow_assert(
        $disputedView[0] === 200
            && str_contains($disputedView[1], "Handover confirmations are unavailable while this transaction is disputed.")
            && str_contains($disputedView[1], "Messages are visible only to these transaction participants.")
            && preg_match('/<textarea id="chat-message"[^>]*disabled/', $disputedView[1]) !== 1,
        "Dispute handling blocked chat or left handover confirmation actions enabled."
    );

    $cancelledId = $transactions[3];
    $pdo->prepare(
        "UPDATE exchange_transactions SET status = 'cancelled' WHERE id = :id"
    )->execute(["id" => $cancelledId]);
    foreach ([
        [$cancelledId, "Cancelled transaction accepted a new confirmation."],
        [$transactions[4], "Completed transaction accepted a new confirmation."]
    ] as [$terminalId, $message]) {
        $response = handover_workflow_request(
            $appRoot,
            $sessionPath,
            $databasePath,
            "handover_action.php",
            "POST",
            $sessions[1],
            handover_workflow_form($terminalId, $csrf[1], $creatorType)
        );
        handover_workflow_assert($response[0] === 409, $message);
    }

    $productState = $pdo->query(
        "SELECT id, user_id, status FROM products ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $reservationState = $pdo->query(
        "SELECT product_id, transaction_id, reserved_at FROM product_reservations ORDER BY product_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $paymentState = $pdo->query(
        "SELECT id, payment_status, payment_method, status FROM exchange_transactions ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    handover_workflow_assert(
        $productState === [
            ["id" => 10, "user_id" => 1, "status" => "reserved"],
            ["id" => 20, "user_id" => 2, "status" => "reserved"]
        ]
            && count($reservationState) === 1
            && $paymentState[0]["payment_status"] === "not_required"
            && $paymentState[0]["status"] === "in_progress"
            && $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "Handover confirmation changed payment, product, reservation, or transaction data."
    );
    fwrite(STDOUT, "Handover confirmation disposable SQLite workflow tests passed.\n");
} finally {
    if ($created) {
        handover_workflow_cleanup($temporaryRoot);
    }
}
