<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "payment-workflow-test-" . bin2hex(random_bytes(8));
$appRoot = $temporaryRoot . DIRECTORY_SEPARATOR . "app";
$databasePath = $appRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$evidencePath = $temporaryRoot . DIRECTORY_SEPARATOR . "private-evidence";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;
$created = false;

function payment_workflow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function payment_workflow_migration(string $script, string $databasePath, string $confirmation): array
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

function payment_workflow_session(string $path, string $id, int $userId, string $csrf): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId, "csrf_token" => $csrf];
    session_write_close();
}

function payment_workflow_request(
    string $baseUrl,
    string $path,
    ?string $sessionId,
    ?array $form = null,
    ?array $file = null
): array {
    $headers = ["Connection: close"];
    if ($sessionId !== null) {
        $headers[] = "Cookie: PHPSESSID=" . $sessionId;
    }
    $method = $form === null ? "GET" : "POST";
    $content = "";
    if ($form !== null && $file === null) {
        $headers[] = "Content-Type: application/x-www-form-urlencoded";
        $content = http_build_query($form);
    } elseif ($form !== null) {
        $boundary = "----payment-test-" . bin2hex(random_bytes(12));
        $headers[] = "Content-Type: multipart/form-data; boundary={$boundary}";
        foreach ($form as $name => $value) {
            $content .= "--{$boundary}\r\n";
            $content .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $content .= (string) $value . "\r\n";
        }
        $content .= "--{$boundary}\r\n";
        $content .= 'Content-Disposition: form-data; name="evidence_file"; filename="user-controlled.txt"' . "\r\n";
        $content .= "Content-Type: " . $file["mime"] . "\r\n\r\n";
        $content .= $file["contents"] . "\r\n";
        $content .= "--{$boundary}--\r\n";
    }
    $options = [
        "http" => [
            "method" => $method,
            "header" => $headers,
            "content" => $content,
            "ignore_errors" => true,
            "timeout" => 20,
            "follow_location" => 0
        ]
    ];
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

function payment_workflow_json(array $response): array
{
    return json_decode($response[1], true, 512, JSON_THROW_ON_ERROR);
}

function payment_workflow_fields(int $transactionId, string $csrf, string $action, array $extra = []): array
{
    return $extra + [
        "transaction_id" => (string) $transactionId,
        "action" => $action,
        "csrf_token" => $csrf
    ];
}

function payment_workflow_terms(int $amount, ?string $currency, ?int $exponent): array
{
    return [
        "amount_minor" => (string) $amount,
        "currency" => $currency ?? "",
        "currency_exponent" => $exponent === null ? "" : (string) $exponent
    ];
}

function payment_workflow_cleanup(string $root): void
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
        throw new RuntimeException("Could not create isolated payment workflow directories.");
    }
    $created = true;
    if (!mkdir($appRoot . DIRECTORY_SEPARATOR . "includes", 0700)) {
        throw new RuntimeException("Could not create isolated shared-code directory.");
    }
    $files = [
        "migrate_offers.php",
        "migrate_exchange_transactions.php",
        "migrate_chat_messages.php",
        "migrate_payment_evidence.php",
        "migrate_exchange_cash_difference.php",
        "db.php",
        "security.php",
        "i18n.php",
        "payment_support.php",
        "notification_support.php",
        "exchange_completion_support.php",
        "payment_action.php",
        "payment_evidence.php",
        "dispute_action.php",
        "admin_dispute_action.php",
        "exchange_transaction.php",
        "chat_messages.php",
        "chat_media_support.php",
        "admin.php"
    ];
    foreach ($files as $file) {
        $isMigration = str_starts_with($file, "migrate_");
        $isSharedCode = in_array($file, [
            "db.php", "security.php", "i18n.php", "payment_support.php",
            "notification_support.php", "exchange_completion_support.php",
            "chat_media_support.php"
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
        [$exit, $stdout, $stderr] = payment_workflow_migration(
            $appRoot . DIRECTORY_SEPARATOR . $script,
            $databasePath,
            $confirmation
        );
        payment_workflow_assert($exit === 0, "{$script} fixture setup failed: {$stdout}{$stderr}");
    }

    $offerInsert = $pdo->prepare(
        "INSERT INTO offers (
            creator_id, recipient_id, cash_payer_id, offered_product_id, target_product_id,
            cash_amount_minor, cash_currency, currency_exponent, status
         ) VALUES (1, 2, :payer, 10, 20, :amount, :currency, :exponent, 'accepted')"
    );
    $transactionInsert = $pdo->prepare(
        "INSERT INTO exchange_transactions (
            offer_id, creator_id, recipient_id, offered_product_id, target_product_id,
            cash_payer_id, cash_amount_minor, cash_currency, currency_exponent,
            payment_status, status
         ) VALUES (:offer_id, 1, 2, 10, 20, :payer, :amount, :currency, :exponent,
                   :payment_status, 'in_progress')"
    );
    $transactions = [];
    foreach ([
        [500, "USD", 2, "awaiting_method", 1],
        [1250, "USD", 2, "awaiting_method", 1],
        [900, "USD", 2, "awaiting_method", 1],
        [0, null, null, "not_required", 1],
        [700, "USD", 2, "awaiting_method", 1]
    ] as $index => [$amount, $currency, $exponent, $paymentStatus, $payerId]) {
        $offerInsert->execute([
            "payer" => $payerId,
            "amount" => $amount,
            "currency" => $currency,
            "exponent" => $exponent
        ]);
        $offerId = (int) $pdo->lastInsertId();
        $transactionInsert->execute([
            "offer_id" => $offerId,
            "payer" => $payerId,
            "amount" => $amount,
            "currency" => $currency,
            "exponent" => $exponent,
            "payment_status" => $paymentStatus
        ]);
        $transactions[$index + 1] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare(
        "INSERT INTO product_reservations (product_id, transaction_id) VALUES (10, :transaction_id)"
    )->execute(["transaction_id" => $transactions[5]]);
    $pdo->prepare(
        "INSERT INTO chat_rooms (exchange_transaction_id, creator_id, recipient_id)
         VALUES (:transaction_id, 1, 2)"
    )->execute(["transaction_id" => $transactions[5]]);
    [$exit, $stdout, $stderr] = payment_workflow_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_chat_messages.php",
        $databasePath,
        "APPLY CHAT MESSAGES MIGRATION 4"
    );
    payment_workflow_assert($exit === 0, "Version 4 fixture setup failed: {$stdout}{$stderr}");
    $pdo->prepare(
        "INSERT INTO chat_messages (room_id, sender_id, message_body)
         SELECT id, 1, 'Pre-existing dispute chat'
         FROM chat_rooms WHERE exchange_transaction_id = :transaction_id"
    )->execute(["transaction_id" => $transactions[5]]);
    [$exit, $stdout, $stderr] = payment_workflow_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_payment_evidence.php",
        $databasePath,
        "APPLY PAYMENT EVIDENCE MIGRATION 5"
    );
    payment_workflow_assert($exit === 0, "Version 5 fixture setup failed: {$stdout}{$stderr}");
    $pdo->exec(
        "CREATE TABLE handover_confirmations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_id INTEGER NOT NULL,
            confirmation_type TEXT NOT NULL,
            confirmer_id INTEGER NOT NULL,
            confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "INSERT INTO schema_migrations (version) VALUES (6), (7), (8), (9), (10), (11)"
    );
    $pdo->exec(
        "CREATE TRIGGER exchange_completion_guard
         BEFORE UPDATE OF status ON exchange_transactions
         FOR EACH ROW
         WHEN OLD.status = 'in_progress' AND NEW.status = 'completed'
         BEGIN
             SELECT RAISE(ABORT, 'exchange completion requirements are not met');
         END"
    );
    [$exit, $stdout, $stderr] = payment_workflow_migration(
        $appRoot . DIRECTORY_SEPARATOR . "migrate_exchange_cash_difference.php",
        $databasePath,
        "APPLY EXCHANGE CASH DIFFERENCE MIGRATION 12"
    );
    payment_workflow_assert($exit === 0, "Version 12 fixture setup failed: {$stdout}{$stderr}");
    $offerInsert->execute([
        "payer" => 2,
        "amount" => 800,
        "currency" => "THB",
        "exponent" => 2
    ]);
    $recipientPayerOfferId = (int) $pdo->lastInsertId();
    $transactionInsert->execute([
        "offer_id" => $recipientPayerOfferId,
        "payer" => 2,
        "amount" => 800,
        "currency" => "THB",
        "exponent" => 2,
        "payment_status" => "awaiting_method"
    ]);
    $transactions[6] = (int) $pdo->lastInsertId();

    $csrfTokens = [
        1 => "workflow_creator_csrf",
        2 => "workflow_recipient_csrf",
        3 => "workflow_admin_csrf",
        4 => "workflow_other_csrf"
    ];
    $sessions = [
        1 => "payment-workflow-creator",
        2 => "payment-workflow-recipient",
        3 => "payment-workflow-admin",
        4 => "payment-workflow-other"
    ];
    foreach ($sessions as $userId => $sessionId) {
        payment_workflow_session($sessionPath, $sessionId, $userId, $csrfTokens[$userId]);
    }

    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve a local test port: {$error}");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) $address, strrpos((string) $address, ":") + 1);
    $log = fopen($serverLog, "a");
    if ($log === false) {
        throw new RuntimeException("Could not create the local application log.");
    }
    $server = proc_open(
        [PHP_BINARY, "-d", "session.save_path=" . $sessionPath, "-S", "127.0.0.1:" . $port, "-t", $appRoot],
        [0 => ["file", "/dev/null", "r"], 1 => $log, 2 => $log],
        $pipes,
        $appRoot,
        [
            "DB_DRIVER" => "sqlite",
            "DB_PATH" => $databasePath,
            "PAYMENT_EVIDENCE_DIR" => $evidencePath,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    fclose($log);
    if (!is_resource($server)) {
        throw new RuntimeException("Could not start the local payment test server.");
    }
    $baseUrl = "http://127.0.0.1:" . $port;
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        [$status] = payment_workflow_request($baseUrl, "/payment_action.php", null);
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    payment_workflow_assert($ready, "The local PHP server did not become ready.");

    $creatorSession = $sessions[1];
    $recipientSession = $sessions[2];
    $adminSession = $sessions[3];
    $otherSession = $sessions[4];
    $creatorCsrf = $csrfTokens[1];
    $recipientCsrf = $csrfTokens[2];
    $adminCsrf = $csrfTokens[3];
    $otherCsrf = $csrfTokens[4];

    [$guestStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        null,
        payment_workflow_fields($transactions[1], $creatorCsrf, "select_method", ["payment_method" => "promptpay"])
    );
    payment_workflow_assert($guestStatus === 401, "An unauthenticated user selected a payment method.");
    [$noCsrfStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        ["transaction_id" => (string) $transactions[1], "action" => "select_method", "payment_method" => "promptpay"]
    );
    payment_workflow_assert($noCsrfStatus === 400, "A payment action without CSRF was accepted.");
    [$recipientSelectStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[1], $recipientCsrf, "select_method", ["payment_method" => "promptpay"])
    );
    [$invalidMethodStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[2], $creatorCsrf, "select_method", ["payment_method" => "gateway"])
    );
    payment_workflow_assert(
        $recipientSelectStatus === 403 && $invalidMethodStatus === 400,
        "Payment method authorization or allowlist validation failed."
    );
    [$payerMethodStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[6], $recipientCsrf, "select_method", ["payment_method" => "promptpay"])
    );
    [$nonPayerMethodStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[6], $creatorCsrf, "select_method", ["payment_method" => "bank_transfer"])
    );
    payment_workflow_assert(
        $payerMethodStatus === 200 && $nonPayerMethodStatus === 403,
        "A recipient designated as payer could not select the method, or the other party was allowed to do so: "
            . "{$payerMethodStatus}/{$nonPayerMethodStatus}; "
            . (is_file($serverLog) ? file_get_contents($serverLog) : "")
    );
    [$payerPageStatus, $payerPage] = payment_workflow_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . $transactions[6] . "&lang=en",
        $recipientSession
    );
    [$nonPayerPageStatus, $nonPayerPage] = payment_workflow_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . $transactions[6] . "&lang=en",
        $creatorSession
    );
    payment_workflow_assert(
        $payerPageStatus === 200
            && $nonPayerPageStatus === 200
            && str_contains($payerPage, "Payment slip")
            && !str_contains($nonPayerPage, "Payment slip"),
        "The exchange page did not expose payment actions to the recipient payer only."
    );
    foreach ([
        [1, "promptpay"],
        [2, "bank_transfer"],
        [3, "cash_on_handover"],
        [5, "bank_transfer"]
    ] as [$transactionNumber, $method]) {
        [$status, $body] = payment_workflow_request(
            $baseUrl,
            "/payment_action.php",
            $creatorSession,
            payment_workflow_fields(
                $transactions[$transactionNumber],
                $creatorCsrf,
                "select_method",
                ["payment_method" => $method]
            )
        );
        payment_workflow_assert($status === 200, "Could not select {$method}: {$body}");
    }
    [$changeMethodStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[1], $creatorCsrf, "select_method", ["payment_method" => "bank_transfer"])
    );
    [$zeroMethodStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[4], $creatorCsrf, "select_method", ["payment_method" => "cash_on_handover"])
    );
    payment_workflow_assert(
        $changeMethodStatus === 409 && $zeroMethodStatus === 409,
        "A selected method changed later or a zero-cash transaction acquired a method."
    );

    $png = base64_decode(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l1sAAAAASUVORK5CYII=",
        true
    );
    if (!is_string($png)) {
        throw new RuntimeException("Could not generate the isolated PNG upload fixture.");
    }
    $validPng = ["mime" => "image/png", "contents" => $png];
    $validPdf = ["mime" => "application/pdf", "contents" => "%PDF-1.4\npayment slip\n%%EOF\n"];
    $terms1 = payment_workflow_terms(500, "USD", 2);
    foreach ([
        array_merge($terms1, ["amount_minor" => "501"]),
        array_merge($terms1, ["currency" => "EUR"]),
        array_merge($terms1, ["currency_exponent" => "3"])
    ] as $wrongTerms) {
        [$status] = payment_workflow_request(
            $baseUrl,
            "/payment_action.php",
            $creatorSession,
            payment_workflow_fields($transactions[1], $creatorCsrf, "submit_evidence", $wrongTerms),
            $validPng
        );
        payment_workflow_assert($status === 400, "Mismatched payment terms were accepted.");
    }
    [$noUploadStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[1], $creatorCsrf, "submit_evidence", $terms1)
    );
    [$wrongMimeStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[1], $creatorCsrf, "submit_evidence", $terms1),
        ["mime" => "text/plain", "contents" => "not a payment slip"]
    );
    [$oversizedStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[1], $creatorCsrf, "submit_evidence", $terms1),
        ["mime" => "image/png", "contents" => $png . str_repeat("x", 5 * 1024 * 1024)]
    );
    [$recipientUploadStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[1], $recipientCsrf, "submit_evidence", $terms1),
        $validPng
    );
    payment_workflow_assert(
        $noUploadStatus === 400
            && $wrongMimeStatus === 400
            && $oversizedStatus === 400
            && $recipientUploadStatus === 403,
        "Upload presence, MIME, size, or creator-only authorization was not enforced."
    );
    $terms6 = payment_workflow_terms(800, "THB", 2);
    [$wrongPayerSubmitStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[6], $creatorCsrf, "submit_evidence", $terms6),
        $validPng
    );
    [$recipientPayerSubmitStatus, $recipientPayerSubmitBody] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[6], $recipientCsrf, "submit_evidence", $terms6),
        $validPng
    );
    $recipientPayerEvidence = $pdo->query(
        "SELECT id, submitted_by FROM payment_evidence WHERE transaction_id = {$transactions[6]}"
    )->fetch(PDO::FETCH_ASSOC);
    payment_workflow_assert($recipientPayerEvidence !== false, "The recipient payer did not create an evidence record.");
    [$reviewerPageStatus, $reviewerPage] = payment_workflow_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . $transactions[6] . "&lang=en",
        $creatorSession
    );
    [$payerReviewPageStatus, $payerReviewPage] = payment_workflow_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . $transactions[6] . "&lang=en",
        $recipientSession
    );
    payment_workflow_assert(
        $reviewerPageStatus === 200
            && $payerReviewPageStatus === 200
            && str_contains($reviewerPage, "Verify payment")
            && !str_contains($payerReviewPage, "Verify payment"),
        "The exchange page did not expose evidence review to the participant other than the payer."
    );
    [$wrongPayerReviewStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[6], $recipientCsrf, "verify", [
            "evidence_id" => (string) $recipientPayerEvidence["id"]
        ])
    );
    [$otherParticipantReviewStatus, $otherParticipantReviewBody] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[6], $creatorCsrf, "verify", [
            "evidence_id" => (string) $recipientPayerEvidence["id"]
        ])
    );
    payment_workflow_assert(
        $wrongPayerSubmitStatus === 403
            && $recipientPayerSubmitStatus === 200
            && (int) $recipientPayerEvidence["submitted_by"] === 2
            && $wrongPayerReviewStatus === 403
            && $otherParticipantReviewStatus === 200
            && $pdo->query("SELECT payment_status FROM exchange_transactions WHERE id = {$transactions[6]}")->fetchColumn() === "verified",
        "Recipient-paid THB evidence did not enforce payer submission and other-party review: "
            . "{$recipientPayerSubmitStatus}/{$otherParticipantReviewStatus}; {$recipientPayerSubmitBody}{$otherParticipantReviewBody}"
    );
    [$submitStatus, $submitBody] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[1], $creatorCsrf, "submit_evidence", $terms1),
        $validPng
    );
    payment_workflow_assert($submitStatus === 200, "A valid PromptPay slip failed: {$submitBody}");
    $firstEvidence = $pdo->query(
        "SELECT * FROM payment_evidence WHERE transaction_id = {$transactions[1]}"
    )->fetch(PDO::FETCH_ASSOC);
    payment_workflow_assert(
        $firstEvidence !== false
            && $firstEvidence["payment_method"] === "promptpay"
            && (int) $firstEvidence["amount_minor"] === 500
            && $firstEvidence["currency"] === "USD"
            && (int) $firstEvidence["currency_exponent"] === 2
            && $firstEvidence["status"] === "submitted"
            && preg_match('/\A[a-f0-9]{64}\z/', $firstEvidence["evidence_storage_key"]) === 1,
        "The evidence record did not capture the exact submitted transaction terms."
    );
    $storedEvidence = $evidencePath . DIRECTORY_SEPARATOR . $firstEvidence["evidence_storage_key"] . ".evidence";
    payment_workflow_assert(
        is_file($storedEvidence)
            && realpath($evidencePath) !== false
            && !str_starts_with((string) realpath($evidencePath), $appRoot . DIRECTORY_SEPARATOR),
        "Evidence was not stored under an opaque key outside the web root."
    );
    [$creatorVerifyStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[1], $creatorCsrf, "verify", ["evidence_id" => (string) $firstEvidence["id"]])
    );
    [$unrelatedDownloadStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_evidence.php?id=" . (int) $firstEvidence["id"],
        $otherSession
    );
    [$authorizedDownloadStatus, $authorizedBytes, $authorizedHeaders] = payment_workflow_request(
        $baseUrl,
        "/payment_evidence.php?id=" . (int) $firstEvidence["id"],
        $recipientSession
    );
    payment_workflow_assert(
        $creatorVerifyStatus === 403
            && $unrelatedDownloadStatus === 404
            && $authorizedDownloadStatus === 200
            && $authorizedBytes === $png
            && in_array("Content-Type: image/png", $authorizedHeaders, true),
        "Creator verification or participant-only evidence access failed: "
            . "{$creatorVerifyStatus}/{$unrelatedDownloadStatus}/{$authorizedDownloadStatus}; "
            . strlen($authorizedBytes) . "/" . strlen($png) . " bytes; "
            . bin2hex(substr($authorizedBytes, 0, 8)) . "/" . bin2hex(substr($png, 0, 8))
            . " end " . bin2hex(substr($authorizedBytes, -8)) . "/" . bin2hex(substr($png, -8))
            . "; " . implode(" | ", $authorizedHeaders)
    );

    [$missingRejectReasonStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[1], $recipientCsrf, "reject", [
            "evidence_id" => (string) $firstEvidence["id"]
        ])
    );
    payment_workflow_assert($missingRejectReasonStatus === 400, "Evidence was rejected without a reason.");
    [$rejectStatus, $rejectBody] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[1], $recipientCsrf, "reject", [
            "evidence_id" => (string) $firstEvidence["id"],
            "review_note" => "The receipt amount is not legible."
        ])
    );
    payment_workflow_assert($rejectStatus === 200, "Recipient rejection failed: {$rejectBody}");
    [$resubmitStatus, $resubmitBody] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[1], $creatorCsrf, "submit_evidence", $terms1),
        $validPdf
    );
    payment_workflow_assert($resubmitStatus === 200, "Resubmission after rejection failed: {$resubmitBody}");
    $evidenceHistory = $pdo->query(
        "SELECT id, status, review_note, evidence_storage_key
         FROM payment_evidence WHERE transaction_id = {$transactions[1]} ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    payment_workflow_assert(
        count($evidenceHistory) === 2
            && $evidenceHistory[0]["status"] === "rejected"
            && $evidenceHistory[0]["review_note"] === "The receipt amount is not legible."
            && $evidenceHistory[1]["status"] === "submitted"
            && is_file($evidencePath . DIRECTORY_SEPARATOR . $evidenceHistory[0]["evidence_storage_key"] . ".evidence"),
        "Resubmission replaced or failed to preserve rejected evidence."
    );
    [$verifyStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[1], $recipientCsrf, "verify", [
            "evidence_id" => (string) $evidenceHistory[1]["id"]
        ])
    );
    payment_workflow_assert(
        $verifyStatus === 200
            && $pdo->query("SELECT payment_status FROM exchange_transactions WHERE id = {$transactions[1]}")->fetchColumn() === "verified",
        "Recipient verification did not finalize the payment status."
    );

    $terms2 = payment_workflow_terms(1250, "USD", 2);
    [$bankSubmitStatus, $bankSubmitBody] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[2], $creatorCsrf, "submit_evidence", $terms2),
        $validPdf
    );
    payment_workflow_assert($bankSubmitStatus === 200, "A valid bank-transfer PDF failed: {$bankSubmitBody}");
    $bankEvidence = $pdo->query(
        "SELECT id, payment_method FROM payment_evidence WHERE transaction_id = {$transactions[2]}"
    )->fetch(PDO::FETCH_ASSOC);
    payment_workflow_assert(
        $bankEvidence["payment_method"] === "bank_transfer",
        "Bank transfer did not create its own evidence record."
    );
    [$bankVerifyStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[2], $recipientCsrf, "verify", [
            "evidence_id" => (string) $bankEvidence["id"]
        ])
    );
    payment_workflow_assert($bankVerifyStatus === 200, "Bank-transfer evidence could not be verified.");

    $terms3 = payment_workflow_terms(900, "USD", 2);
    [$cashFileStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[3], $creatorCsrf, "cash_paid", $terms3),
        $validPng
    );
    payment_workflow_assert($cashFileStatus === 400, "A cash submission accepted an uploaded file.");
    [$cashSubmitStatus, $cashSubmitBody] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[3], $creatorCsrf, "cash_paid", $terms3)
    );
    payment_workflow_assert($cashSubmitStatus === 200, "Cash paid submission failed: {$cashSubmitBody}");
    $cashFirst = $pdo->query(
        "SELECT * FROM payment_evidence WHERE transaction_id = {$transactions[3]} ORDER BY id"
    )->fetch(PDO::FETCH_ASSOC);
    payment_workflow_assert(
        $cashFirst["evidence_storage_key"] === null
            && $cashFirst["status"] === "submitted"
            && (int) $cashFirst["submitted_by"] === 1
            && $pdo->query("SELECT payment_status FROM exchange_transactions WHERE id = {$transactions[3]}")->fetchColumn() === "submitted",
        "Cash submission created a file key or skipped the submitter record."
    );
    [$cashPendingPageStatus, $cashPendingPage] = payment_workflow_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . $transactions[3] . "&lang=en",
        $recipientSession
    );
    payment_workflow_assert(
        $cashPendingPageStatus === 200
            && str_contains($cashPendingPage, "Awaiting confirmation from the other participant")
            && str_contains($cashPendingPage, "Submitted by Creator at")
            && !str_contains($cashPendingPage, "Payment status: Verified"),
        "Unconfirmed cash was presented as paid or omitted its submitter history: "
            . $cashPendingPageStatus . " " . $cashPendingPage . "; "
            . (is_file($serverLog) ? file_get_contents($serverLog) : "")
    );
    [$cashCreatorVerifyStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[3], $creatorCsrf, "verify", ["evidence_id" => (string) $cashFirst["id"]])
    );
    [$cashDenyWithoutReason] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[3], $recipientCsrf, "reject", ["evidence_id" => (string) $cashFirst["id"]])
    );
    [$cashDenyStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[3], $recipientCsrf, "reject", [
            "evidence_id" => (string) $cashFirst["id"],
            "review_note" => "Cash was not received."
        ])
    );
    payment_workflow_assert(
        $cashCreatorVerifyStatus === 403
            && $cashDenyWithoutReason === 400
            && $cashDenyStatus === 200,
        "Cash receipt denial or creator self-verification was incorrectly allowed."
    );
    [$cashResubmitStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[3], $creatorCsrf, "cash_paid", $terms3)
    );
    payment_workflow_assert($cashResubmitStatus === 200, "Cash could not be resubmitted after denial.");
    $cashHistory = $pdo->query(
        "SELECT * FROM payment_evidence WHERE transaction_id = {$transactions[3]} ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    [$cashConfirmStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[3], $recipientCsrf, "verify", [
            "evidence_id" => (string) $cashHistory[1]["id"]
        ])
    );
    $cashHistory = $pdo->query(
        "SELECT * FROM payment_evidence WHERE transaction_id = {$transactions[3]} ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    payment_workflow_assert(
        $cashConfirmStatus === 200
            && count($cashHistory) === 2
            && $cashHistory[0]["status"] === "rejected"
            && $cashHistory[0]["evidence_storage_key"] === null
            && $cashHistory[1]["status"] === "verified"
            && (int) $cashHistory[1]["submitted_by"] === 1
            && (int) $cashHistory[1]["verified_by"] === 2
            && $cashHistory[1]["submitted_at"] !== null
            && $cashHistory[1]["verified_at"] !== null
            && $pdo->query("SELECT payment_status FROM exchange_transactions WHERE id = {$transactions[3]}")->fetchColumn() === "verified",
        "Cash receipt confirmation did not record both parties and timestamps."
    );
    [$cashPageStatus, $cashPage] = payment_workflow_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . $transactions[3] . "&lang=en",
        $recipientSession
    );
    payment_workflow_assert(
        $cashPageStatus === 200
            && str_contains($cashPage, "Cash on handover")
            && str_contains($cashPage, "Receipt confirmed by Recipient at")
            && str_contains($cashPage, "Submitted by Creator at")
            && str_contains($cashPage, "Receipt confirmed by the other participant"),
        "The chat page did not display cash confirmation history."
    );
    [$cashDisputeStatus] = payment_workflow_request(
        $baseUrl,
        "/dispute_action.php",
        $recipientSession,
        [
            "transaction_id" => (string) $transactions[3],
            "reason" => "Cash receipt is now disputed.",
            "csrf_token" => $recipientCsrf
        ]
    );
    [$cashDisputedPageStatus, $cashDisputedPage] = payment_workflow_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . $transactions[3] . "&lang=en",
        $recipientSession
    );
    payment_workflow_assert(
        $cashDisputeStatus === 200
            && $cashDisputedPageStatus === 200
            && str_contains($cashDisputedPage, "Disputed - payment is not considered complete")
            && str_contains($cashDisputedPage, "Recorded as verified before dispute; payment is not considered complete")
            && !str_contains($cashDisputedPage, "Payment status: Verified"),
        "A disputed transaction was displayed as successfully paid."
    );

    [$zeroSubmitStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[4], $creatorCsrf, "cash_paid", payment_workflow_terms(0, null, null))
    );
    payment_workflow_assert(
        $zeroSubmitStatus === 409
            && $pdo->query("SELECT COUNT(*) FROM payment_evidence WHERE transaction_id = {$transactions[4]}")->fetchColumn() == 0,
        "A zero-cash transaction created a payment record."
    );

    $terms5 = payment_workflow_terms(700, "USD", 2);
    [$disputePaymentSubmit] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[5], $creatorCsrf, "submit_evidence", $terms5),
        $validPng
    );
    payment_workflow_assert($disputePaymentSubmit === 200, "Could not seed disputed payment evidence.");
    [$missingDisputeReason] = payment_workflow_request(
        $baseUrl,
        "/dispute_action.php",
        $creatorSession,
        ["transaction_id" => (string) $transactions[5], "csrf_token" => $creatorCsrf]
    );
    [$unrelatedDispute] = payment_workflow_request(
        $baseUrl,
        "/dispute_action.php",
        $otherSession,
        ["transaction_id" => (string) $transactions[5], "reason" => "Not a participant", "csrf_token" => $otherCsrf]
    );
    payment_workflow_assert(
        $missingDisputeReason === 400 && $unrelatedDispute === 404,
        "Dispute reason or participant validation failed."
    );
    [$reportStatus, $reportBody] = payment_workflow_request(
        $baseUrl,
        "/dispute_action.php",
        $recipientSession,
        ["transaction_id" => (string) $transactions[5], "reason" => "The handover terms are contested.", "csrf_token" => $recipientCsrf]
    );
    payment_workflow_assert($reportStatus === 200, "Participant dispute report failed: {$reportBody}");
    $disputeEvidence = $pdo->query(
        "SELECT id FROM payment_evidence WHERE transaction_id = {$transactions[5]}"
    )->fetchColumn();
    [$disputedVerifyStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $recipientSession,
        payment_workflow_fields($transactions[5], $recipientCsrf, "verify", ["evidence_id" => (string) $disputeEvidence])
    );
    [$disputedSubmitStatus] = payment_workflow_request(
        $baseUrl,
        "/payment_action.php",
        $creatorSession,
        payment_workflow_fields($transactions[5], $creatorCsrf, "submit_evidence", $terms5),
        $validPdf
    );
    payment_workflow_assert(
        $disputedVerifyStatus === 409
            && $disputedSubmitStatus === 409
            && $pdo->query("SELECT status FROM exchange_transactions WHERE id = {$transactions[5]}")->fetchColumn() === "disputed",
        "A disputed transaction allowed payment submission or verification."
    );
    [$chatPostStatus] = payment_workflow_request(
        $baseUrl,
        "/chat_messages.php",
        $creatorSession,
        [
            "transaction_id" => (string) $transactions[5],
            "message_body" => "Chat remains open during the dispute.",
            "csrf_token" => $creatorCsrf
        ]
    );
    [$chatGetStatus, $chatBody] = payment_workflow_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=" . $transactions[5],
        $recipientSession
    );
    payment_workflow_assert(
        $chatPostStatus === 201
            && $chatGetStatus === 200
            && str_contains($chatBody, "Pre-existing dispute chat")
            && str_contains($chatBody, "Chat remains open during the dispute."),
        "Disputing the transaction damaged its participant chat."
    );

    [$nonAdminResolution] = payment_workflow_request(
        $baseUrl,
        "/admin_dispute_action.php",
        $creatorSession,
        [
            "transaction_id" => (string) $transactions[5],
            "dispute_id" => "1",
            "resolution" => "in_progress",
            "resolution_reason" => "Unauthorized attempt",
            "csrf_token" => $creatorCsrf
        ]
    );
    [$adminPageStatus, $adminPage] = payment_workflow_request($baseUrl, "/admin.php", $adminSession);
    payment_workflow_assert(
        $nonAdminResolution === 403 && $adminPageStatus === 200 && str_contains($adminPage, "The handover terms are contested."),
        "Admin-only dispute access or the review queue failed."
    );
    $firstDispute = $pdo->query(
        "SELECT id FROM transaction_disputes WHERE transaction_id = {$transactions[5]} AND resolved_at IS NULL"
    )->fetchColumn();
    [$missingAdminCsrf] = payment_workflow_request(
        $baseUrl,
        "/admin_dispute_action.php",
        $adminSession,
        [
            "transaction_id" => (string) $transactions[5],
            "dispute_id" => (string) $firstDispute,
            "resolution" => "in_progress",
            "resolution_reason" => "Reviewed"
        ]
    );
    payment_workflow_assert($missingAdminCsrf === 400, "Admin dispute resolution did not require CSRF.");
    [$resumeStatus] = payment_workflow_request(
        $baseUrl,
        "/admin_dispute_action.php",
        $adminSession,
        [
            "transaction_id" => (string) $transactions[5],
            "dispute_id" => (string) $firstDispute,
            "resolution" => "in_progress",
            "resolution_reason" => "Participants should resume discussion.",
            "csrf_token" => $adminCsrf
        ]
    );
    payment_workflow_assert($resumeStatus === 200, "Admin could not resolve a dispute to in_progress.");
    [$secondReport] = payment_workflow_request(
        $baseUrl,
        "/dispute_action.php",
        $creatorSession,
        [
            "transaction_id" => (string) $transactions[5],
            "reason" => "The unresolved exchange can no longer proceed.",
            "csrf_token" => $creatorCsrf
        ]
    );
    $secondDispute = $pdo->query(
        "SELECT id FROM transaction_disputes WHERE transaction_id = {$transactions[5]} ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    [$cancelStatus] = payment_workflow_request(
        $baseUrl,
        "/admin_dispute_action.php",
        $adminSession,
        [
            "transaction_id" => (string) $transactions[5],
            "dispute_id" => (string) $secondDispute,
            "resolution" => "cancelled",
            "resolution_reason" => "Cancel under the dispute decision.",
            "csrf_token" => $adminCsrf
        ]
    );
    $audit = $pdo->query(
        "SELECT reported_by, resolved_by, resolution, resolution_reason, resolved_at
         FROM transaction_disputes WHERE transaction_id = {$transactions[5]} ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $transactionAfterCancel = $pdo->query(
        "SELECT status, cancelled_at, payment_status FROM exchange_transactions WHERE id = {$transactions[5]}"
    )->fetch(PDO::FETCH_ASSOC);
    payment_workflow_assert(
        $secondReport === 200
            && $cancelStatus === 200
            && count($audit) === 2
            && (int) $audit[0]["reported_by"] === 2
            && (int) $audit[0]["resolved_by"] === 3
            && $audit[0]["resolution"] === "in_progress"
            && $audit[0]["resolved_at"] !== null
            && (int) $audit[1]["reported_by"] === 1
            && (int) $audit[1]["resolved_by"] === 3
            && $audit[1]["resolution"] === "cancelled"
            && $audit[1]["resolution_reason"] === "Cancel under the dispute decision."
            && $transactionAfterCancel["status"] === "cancelled"
            && $transactionAfterCancel["cancelled_at"] !== null
            && $transactionAfterCancel["payment_status"] === "submitted",
        "Admin dispute resolution did not preserve the complete audit decision or payment state: "
            . json_encode([
                "second_report" => $secondReport,
                "cancel_status" => $cancelStatus,
                "audit" => $audit,
                "transaction" => $transactionAfterCancel
            ], JSON_THROW_ON_ERROR)
    );
    $reservation = $pdo->query(
        "SELECT product_reservations.transaction_id, products.user_id, products.status
         FROM product_reservations INNER JOIN products ON products.id = product_reservations.product_id
         WHERE product_reservations.transaction_id = {$transactions[5]}"
    )->fetch(PDO::FETCH_ASSOC);
    payment_workflow_assert(
        $reservation !== false
            && (int) $reservation["transaction_id"] === $transactions[5]
            && (int) $reservation["user_id"] === 1
            && $reservation["status"] === "reserved",
        "Dispute resolution released a reservation or transferred ownership."
    );
    [$cancelledReport] = payment_workflow_request(
        $baseUrl,
        "/dispute_action.php",
        $recipientSession,
        [
            "transaction_id" => (string) $transactions[5],
            "reason" => "Cannot report after cancellation.",
            "csrf_token" => $recipientCsrf
        ]
    );
    payment_workflow_assert($cancelledReport === 409, "A cancelled transaction accepted a new dispute.");

    [$pageStatus, $page] = payment_workflow_request(
        $baseUrl,
        "/exchange_transaction.php?id=" . $transactions[1] . "&lang=en",
        $creatorSession
    );
    payment_workflow_assert(
        $pageStatus === 200
            && str_contains($page, "Payment evidence")
            && str_contains($page, "PromptPay")
            && str_contains($page, "Verified")
            && str_contains($page, "Submission history"),
        "The transaction chat page did not render payment details/history."
    );
    payment_workflow_assert(
        $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "The full payment and dispute workflow left an integrity or foreign-key violation."
    );
    fwrite(STDOUT, "Payment evidence, cash confirmation, dispute, and Admin workflow tests passed.\n");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo = null;
    }
    if ($created) {
        payment_workflow_cleanup($temporaryRoot);
    }
}
