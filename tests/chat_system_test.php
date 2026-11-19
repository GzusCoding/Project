<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . "chat-system-test-" . bin2hex(random_bytes(8));
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . "hybrid_marketplace.sqlite";
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . "sessions";
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . "server.log";
$server = null;
$createdTemporaryRoot = false;

function chat_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function chat_test_migration_run(string $script, string $databasePath, ?string $input): array
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
        throw new RuntimeException("Could not start the disposable chat migration.");
    }
    if ($input !== null) {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

function chat_test_session(string $path, string $id, int $userId, string $csrfToken): void
{
    session_save_path($path);
    session_id($id);
    session_start();
    $_SESSION = ["user_id" => $userId, "csrf_token" => $csrfToken];
    session_write_close();
}

function chat_test_request(
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

function chat_test_json(array $response): array
{
    return json_decode($response[1], true, 512, JSON_THROW_ON_ERROR);
}

function chat_test_send(
    string $baseUrl,
    string $sessionId,
    string $csrfToken,
    int $transactionId,
    string $body,
    array $extra = []
): array {
    return chat_test_request(
        $baseUrl,
        "/chat_messages.php",
        $sessionId,
        $extra + [
            "transaction_id" => (string) $transactionId,
            "message_body" => $body,
            "csrf_token" => $csrfToken
        ]
    );
}

function chat_test_remove_tree(string $root): void
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
        throw new RuntimeException("Could not create isolated chat test directories.");
    }
    $createdTemporaryRoot = true;
    $migrationPath = $temporaryRoot . DIRECTORY_SEPARATOR . "migrate_chat_messages.php";
    if (!copy(
        $projectRoot . DIRECTORY_SEPARATOR . "migrations" . DIRECTORY_SEPARATOR . "migrate_chat_messages.php",
        $migrationPath
    )) {
        throw new RuntimeException("Could not copy the chat migration into the disposable directory.");
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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "CREATE TABLE schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec("INSERT INTO schema_migrations (version) VALUES (3)");
    $pdo->exec(
        "CREATE TABLE exchange_transactions (
            id INTEGER PRIMARY KEY,
            creator_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            offer_id INTEGER NOT NULL DEFAULT 1,
            cash_amount_minor INTEGER NOT NULL DEFAULT 0,
            cash_currency TEXT NULL,
            currency_exponent INTEGER NULL,
            payment_status TEXT NOT NULL DEFAULT 'not_required',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "CREATE TABLE chat_rooms (
            id INTEGER PRIMARY KEY,
            exchange_transaction_id INTEGER NOT NULL UNIQUE,
            creator_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            FOREIGN KEY (exchange_transaction_id) REFERENCES exchange_transactions(id) ON DELETE RESTRICT,
            FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "INSERT INTO users (id, name, email, password) VALUES
            (1, '<img src=x onerror=alert(1)> Creator', 'creator@example.test', 'unused'),
            (2, 'Recipient', 'recipient@example.test', 'unused'),
            (3, 'Unrelated', 'unrelated@example.test', 'unused'),
            (4, 'Other participant', 'other@example.test', 'unused')"
    );
    $insertTransaction = $pdo->prepare(
        "INSERT INTO exchange_transactions (id, creator_id, recipient_id, status)
         VALUES (:id, :creator, :recipient, :status)"
    );
    $insertRoom = $pdo->prepare(
        "INSERT INTO chat_rooms (id, exchange_transaction_id, creator_id, recipient_id)
         VALUES (:id, :transaction, :creator, :recipient)"
    );
    foreach ([
        [1, 1, 2, "in_progress"],
        [2, 3, 4, "in_progress"],
        [3, 1, 2, "disputed"],
        [4, 1, 2, "in_progress"],
        [5, 1, 2, "in_progress"]
    ] as [$id, $creator, $recipient, $status]) {
        $insertTransaction->execute([
            "id" => $id,
            "creator" => $creator,
            "recipient" => $recipient,
            "status" => $status
        ]);
        $insertRoom->execute([
            "id" => $id,
            "transaction" => $id,
            "creator" => $creator,
            "recipient" => $recipient
        ]);
    }

    [$wrongConfirmation, , $wrongConfirmationError] = chat_test_migration_run(
        $migrationPath,
        $databasePath,
        "APPLY EXCHANGE TRANSACTION MIGRATION 3\n"
    );
    chat_test_assert(
        $wrongConfirmation !== 0 && str_contains($wrongConfirmationError, "confirmation did not match"),
        "Migration 4 accepted a different confirmation phrase."
    );
    [$wrongPathExit, , $wrongPathError] = chat_test_migration_run(
        $migrationPath,
        $databasePath . ".wrong",
        null
    );
    chat_test_assert(
        $wrongPathExit !== 0 && str_contains($wrongPathError, "not the expected project database"),
        "Migration 4 accepted an unexpected database path."
    );
    chat_test_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 4")->fetchColumn() === 0,
        "The rejected migration changed the disposable database."
    );
    [$migrationExit, $migrationOut, $migrationError] = chat_test_migration_run(
        $migrationPath,
        $databasePath,
        "APPLY CHAT MESSAGES MIGRATION 4\n"
    );
    chat_test_assert(
        $migrationExit === 0
            && str_contains($migrationOut, "Migration version 4 applied and verified.")
            && str_contains($migrationOut, "Backup retained at:")
            && $migrationError === "",
        "Disposable version 4 migration failed: {$migrationOut}{$migrationError}"
    );
    if (preg_match('/Backup retained at: (.+)\n/', $migrationOut, $backupMatch) !== 1) {
        throw new RuntimeException("The migration did not identify its verified backup.");
    }
    $backupPdo = new PDO("sqlite:" . trim($backupMatch[1]));
    chat_test_assert(
        $backupPdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && (int) $backupPdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 4")->fetchColumn() === 0
            && $backupPdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'chat_messages'")->fetchColumn() === false,
        "The pre-migration backup is missing, inconsistent, or contains the new schema."
    );
    [$rerunExit, $rerunOut, $rerunError] = chat_test_migration_run($migrationPath, $databasePath, null);
    chat_test_assert(
        $rerunExit === 0 && str_contains($rerunOut, "no database changes made") && $rerunError === "",
        "A verified version 4 rerun was not a no-op."
    );
    chat_test_assert(
        $pdo->query("PRAGMA integrity_check")->fetchColumn() === "ok"
            && $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) === [],
        "The migrated disposable database failed integrity or foreign-key checks."
    );
    $pdo->exec("ALTER TABLE chat_messages ADD COLUMN image_storage_key TEXT NULL");
    $pdo->exec("ALTER TABLE chat_messages ADD COLUMN image_mime_type TEXT NULL");
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("SAVEPOINT chat_constraint");
    try {
        $pdo->exec(
            "INSERT INTO chat_messages (room_id, sender_id, message_body) VALUES (1, 3, 'spoofed')"
        );
        throw new RuntimeException("The database accepted a sender who is not a room participant.");
    } catch (PDOException) {
        $pdo->exec("ROLLBACK TO chat_constraint");
        $pdo->exec("RELEASE chat_constraint");
    }
    $pdo->exec(
        "INSERT INTO chat_messages (room_id, sender_id, message_body)
         VALUES (1, 1, 'temporary foreign key check')"
    );
    $pdo->exec("SAVEPOINT chat_constraint");
    try {
        $pdo->exec("DELETE FROM chat_rooms WHERE id = 1");
        throw new RuntimeException("The database allowed deleting a referenced chat room.");
    } catch (PDOException) {
        $pdo->exec("ROLLBACK TO chat_constraint");
        $pdo->exec("RELEASE chat_constraint");
    }
    $pdo->exec("DELETE FROM chat_messages WHERE message_body = 'temporary foreign key check'");

    $tokens = [
        1 => "csrf_user_one_test_token",
        2 => "csrf_user_two_test_token",
        3 => "csrf_user_three_test_token"
    ];
    $sessions = [
        1 => "chat-test-user-one",
        2 => "chat-test-user-two",
        3 => "chat-test-user-three"
    ];
    foreach ($sessions as $user => $sessionId) {
        chat_test_session($sessionPath, $sessionId, $user, $tokens[$user]);
    }
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve a local test port: " . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) $address, strrpos((string) $address, ":") + 1);
    $log = fopen($serverLog, "a");
    if ($log === false) {
        throw new RuntimeException("Could not create the local PHP server log.");
    }
    $server = proc_open(
        [PHP_BINARY, "-d", "session.save_path=" . $sessionPath, "-S", "127.0.0.1:" . $port, "-t", $projectRoot],
        [0 => ["file", "/dev/null", "r"], 1 => $log, 2 => $log],
        $serverPipes,
        $projectRoot,
        [
            "DB_DRIVER" => "sqlite",
            "DB_PATH" => $databasePath,
            "PATH" => getenv("PATH") ?: "",
            "XDEBUG_MODE" => "off"
        ]
    );
    fclose($log);
    if (!is_resource($server)) {
        throw new RuntimeException("Could not start the local PHP test server.");
    }
    $baseUrl = "http://127.0.0.1:" . $port;
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        [$status] = chat_test_request($baseUrl, "/chat_messages.php?transaction_id=1", null);
        if ($status === 401) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    chat_test_assert($ready, "The local PHP server did not become ready.");

    [$chatListStatus, $chatListBody] = chat_test_request(
        $baseUrl,
        "/my_chats.php",
        $sessions[1]
    );
    [$otherChatListStatus, $otherChatListBody] = chat_test_request(
        $baseUrl,
        "/my_chats.php",
        $sessions[3]
    );
    [$guestChatListStatus, , $guestChatListHeaders] = chat_test_request(
        $baseUrl,
        "/my_chats.php",
        null
    );
    chat_test_assert(
        $chatListStatus === 200
            && str_contains($chatListBody, "รายการแลกเปลี่ยน #1")
            && str_contains($chatListBody, 'href="exchange_transaction.php?id=1"')
            && $otherChatListStatus === 200
            && str_contains($otherChatListBody, 'href="exchange_transaction.php?id=2"')
            && !str_contains($otherChatListBody, 'href="exchange_transaction.php?id=1"')
            && $guestChatListStatus === 302
            && in_array("Location: login.php", $guestChatListHeaders, true),
        "The chat inbox was unavailable, exposed another participant's room, or allowed a guest."
    );

    $userOneSession = $sessions[1];
    $userTwoSession = $sessions[2];
    $userThreeSession = $sessions[3];
    [$pageStatus, $page] = chat_test_request(
        $baseUrl,
        "/exchange_transaction.php?id=1&lang=en",
        $userOneSession
    );
    chat_test_assert(
        $pageStatus === 200
            && str_contains($page, "Transaction chat")
            && str_contains($page, "Recipient")
            && str_contains($page, 'name="image_file"')
            && str_contains($page, "image/webp")
            && str_contains($page, 'const actionUrl = form.getAttribute("action");')
            && !str_contains($page, '<img src=x onerror=alert(1)> Creator'),
        "The transaction page did not safely render the chat and participant details ({$pageStatus}): "
            . substr($page, 0, 500) . "\n" . (string) file_get_contents($serverLog)
    );
    [$unauthorizedPageStatus] = chat_test_request($baseUrl, "/exchange_transaction.php?id=1", $userThreeSession);
    chat_test_assert($unauthorizedPageStatus === 404, "An unrelated participant accessed the transaction page.");
    [$guestStatus, $guestBody] = chat_test_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=1",
        null
    );
    chat_test_assert(
        $guestStatus === 401 && chat_test_json([$guestStatus, $guestBody])["error"] === "Authentication required.",
        "An unauthenticated visitor retrieved chat messages."
    );

    [$initialStatus, $initialBody] = chat_test_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=1",
        $userOneSession
    );
    chat_test_assert(
        $initialStatus === 200 && chat_test_json([$initialStatus, $initialBody])["messages"] === [],
        "A participant could not retrieve an empty chat."
    );
    [$unrelatedStatus] = chat_test_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=1",
        $userThreeSession
    );
    [$wrongRoomStatus] = chat_test_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=2",
        $userOneSession
    );
    chat_test_assert(
        $unrelatedStatus === 404 && $wrongRoomStatus === 404,
        "A nonparticipant accessed a chat room."
    );
    [$missingCsrfStatus] = chat_test_request(
        $baseUrl,
        "/chat_messages.php",
        $userOneSession,
        ["transaction_id" => "1", "message_body" => "Missing CSRF"]
    );
    chat_test_assert($missingCsrfStatus === 400, "A message without valid CSRF was accepted.");
    [$emptyStatus] = chat_test_send($baseUrl, $userOneSession, $tokens[1], 1, " \t ");
    [$oversizedStatus] = chat_test_send($baseUrl, $userOneSession, $tokens[1], 1, str_repeat("x", 2001));
    chat_test_assert(
        $emptyStatus === 400 && $oversizedStatus === 400,
        "Empty or oversized messages were accepted."
    );

    $xssAndSql = "'; DROP TABLE chat_messages; -- <script>alert(1)</script>";
    [$firstStatus, $firstBody] = chat_test_send(
        $baseUrl,
        $userOneSession,
        $tokens[1],
        1,
        $xssAndSql,
        ["sender_id" => "2"]
    );
    $first = chat_test_json([$firstStatus, $firstBody]);
    chat_test_assert(
        $firstStatus === 201
            && (int) $first["message"]["sender_id"] === 1
            && $first["message"]["message_body"] === $xssAndSql
            && !str_contains($firstBody, "<script>")
            && str_contains($firstBody, "\\u003Cscript\\u003E"),
        "Message data, XSS encoding, SQL safety, or sender derivation failed."
    );
    [$secondStatus, $secondBody] = chat_test_send(
        $baseUrl,
        $userTwoSession,
        $tokens[2],
        1,
        "Reply from recipient"
    );
    $second = chat_test_json([$secondStatus, $secondBody]);
    chat_test_assert(
        $secondStatus === 201 && (int) $second["message"]["sender_id"] === 2,
        "The second transaction participant could not send as themselves."
    );
    [$updatedChatListStatus, $updatedChatListBody] = chat_test_request(
        $baseUrl,
        "/my_chats.php",
        $sessions[1]
    );
    chat_test_assert(
        $updatedChatListStatus === 200
            && str_contains($updatedChatListBody, "Reply from recipient"),
        "The chat inbox did not show the latest message."
    );
    [$retrievalStatus, $retrievalBody] = chat_test_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=1&after_id=0",
        $userTwoSession
    );
    $retrieved = chat_test_json([$retrievalStatus, $retrievalBody]);
    chat_test_assert(
        $retrievalStatus === 200
            && count($retrieved["messages"]) === 2
            && (int) $retrieved["messages"][0]["id"] < (int) $retrieved["messages"][1]["id"]
            && $retrieved["messages"][0]["message_body"] === $xssAndSql,
        "Participants did not receive all messages in stable order."
    );
    [$incrementalStatus, $incrementalBody] = chat_test_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=1&after_id=" . (int) $first["message"]["id"],
        $userOneSession
    );
    [$emptyIncrementalStatus, $emptyIncrementalBody] = chat_test_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=1&after_id=" . (int) $second["message"]["id"],
        $userOneSession
    );
    chat_test_assert(
        $incrementalStatus === 200
            && count(chat_test_json([$incrementalStatus, $incrementalBody])["messages"]) === 1
            && $emptyIncrementalStatus === 200
            && chat_test_json([$emptyIncrementalStatus, $emptyIncrementalBody])["messages"] === [],
        "Incremental retrieval did not respect the last-message cursor."
    );
    [$otherRoomStatus, $otherRoomBody] = chat_test_request(
        $baseUrl,
        "/chat_messages.php?transaction_id=2",
        $userThreeSession
    );
    chat_test_assert(
        $otherRoomStatus === 200
            && chat_test_json([$otherRoomStatus, $otherRoomBody])["messages"] === [],
        "Messages leaked between separate rooms."
    );

    [$disputedStatus] = chat_test_send(
        $baseUrl,
        $userOneSession,
        $tokens[1],
        3,
        "Disputed transaction remains writable"
    );
    chat_test_assert($disputedStatus === 201, "Sending was not allowed during a disputed transaction.");

    foreach ([1 => "completed", 5 => "cancelled"] as $transactionId => $status) {
        if ($transactionId === 5) {
            $seed = $pdo->prepare(
                "INSERT INTO chat_messages (room_id, sender_id, message_body)
                 VALUES (5, 1, 'History before cancellation')"
            );
            $seed->execute();
        }
        $pdo->prepare("UPDATE exchange_transactions SET status = :status WHERE id = :id")
            ->execute(["status" => $status, "id" => $transactionId]);
        [$readonlyStatus] = chat_test_send(
            $baseUrl,
            $userOneSession,
            $tokens[1],
            $transactionId,
            "Must not be sent"
        );
        [$historyStatus, $historyBody] = chat_test_request(
            $baseUrl,
            "/chat_messages.php?transaction_id=" . $transactionId,
            $userTwoSession
        );
        $history = chat_test_json([$historyStatus, $historyBody]);
        chat_test_assert(
            $readonlyStatus === 409
                && $historyStatus === 200
                && count($history["messages"]) >= 1
                && !$history["can_send"],
            "Completed or cancelled chat was not preserved as read-only."
        );
    }

    $pdo->prepare("UPDATE exchange_transactions SET status = 'in_progress' WHERE id = 1")->execute();
    for ($count = 0; $count < 7; $count++) {
        [$sendStatus] = chat_test_send(
            $baseUrl,
            $userOneSession,
            $tokens[1],
            1,
            "Rate test " . $count
        );
        chat_test_assert($sendStatus === 201, "A message below the sender rate limit was rejected.");
    }
    [$rateLimitedStatus] = chat_test_send(
        $baseUrl,
        $userOneSession,
        $tokens[1],
        1,
        "Rate limit test"
    );
    chat_test_assert($rateLimitedStatus === 429, "The per-sender message rate limit was not enforced.");

    echo "Chat system tests passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($createdTemporaryRoot) {
        chat_test_remove_tree($temporaryRoot);
    }
}
