<?php

declare(strict_types=1);

require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, private");
header("X-Content-Type-Options: nosniff");

function chat_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    try {
        echo json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    } catch (JsonException $e) {
        error_log("Chat response encoding failed.");
        http_response_code(500);
        echo '{"error":"The chat response could not be generated."}';
    }
    exit;
}

function chat_positive_id(mixed $value, bool $allowZero = false): int|false
{
    if (!is_string($value)) {
        return false;
    }
    $pattern = $allowZero ? '/\A(?:0|[1-9][0-9]*)\z/' : '/\A[1-9][0-9]*\z/';
    if (preg_match($pattern, $value) !== 1) {
        return false;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return $id === false || $id < ($allowZero ? 0 : 1) ? false : $id;
}

function chat_participant_context(PDO $pdo, int $transactionId, int $userId): array|false
{
    $stmt = $pdo->prepare(
        "SELECT chat_rooms.id AS room_id, exchange_transactions.status,
                creator.name AS creator_name, recipient.name AS recipient_name
         FROM exchange_transactions
         INNER JOIN chat_rooms
           ON chat_rooms.exchange_transaction_id = exchange_transactions.id
          AND chat_rooms.creator_id = exchange_transactions.creator_id
          AND chat_rooms.recipient_id = exchange_transactions.recipient_id
         INNER JOIN users AS creator ON creator.id = exchange_transactions.creator_id
         INNER JOIN users AS recipient ON recipient.id = exchange_transactions.recipient_id
         WHERE exchange_transactions.id = :transaction_id
           AND (exchange_transactions.creator_id = :user_id
                OR exchange_transactions.recipient_id = :user_id)
           AND (chat_rooms.creator_id = :room_user_id
                OR chat_rooms.recipient_id = :room_user_id)
         LIMIT 1"
    );
    $stmt->execute([
        "transaction_id" => $transactionId,
        "user_id" => $userId,
        "room_user_id" => $userId
    ]);
    $context = $stmt->fetch(PDO::FETCH_ASSOC);

    return $context === false ? false : $context;
}

function chat_rollback(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
        $pdo->exec("ROLLBACK");
    } elseif ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$requestMethod = $_SERVER["REQUEST_METHOD"] ?? "";
if (!in_array($requestMethod, ["GET", "POST"], true)) {
    header("Allow: GET, POST");
    chat_json_response(["error" => "Method not allowed."], 405);
}

$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    chat_json_response(["error" => "Authentication required."], 401);
}

$transactionId = chat_positive_id(
    $requestMethod === "GET" ? ($_GET["transaction_id"] ?? null) : ($_POST["transaction_id"] ?? null)
);
if ($transactionId === false) {
    chat_json_response(["error" => "Invalid transaction."], 400);
}

if ($requestMethod === "POST") {
    if (!csrf_token_is_valid()) {
        chat_json_response(["error" => "Your request could not be verified. Reload the page and try again."], 400);
    }
    $body = $_POST["message_body"] ?? null;
    if (!is_string($body) || preg_match('//u', $body) !== 1) {
        chat_json_response(["error" => "Enter a valid message."], 400);
    }
    $characterCount = preg_match_all('/./us', $body);
    if (
        $characterCount === false
        || $characterCount > 2000
        || preg_match('/^\s*$/u', $body) === 1
        || strlen($body) > 8000
    ) {
        chat_json_response(["error" => "Messages must contain 1 to 2000 characters."], 400);
    }
}

session_write_close();

try {
    if ($requestMethod === "GET") {
        $afterId = chat_positive_id($_GET["after_id"] ?? "0", true);
        if ($afterId === false) {
            chat_json_response(["error" => "Invalid message cursor."], 400);
        }
        $context = chat_participant_context($pdo, $transactionId, $userId);
        if ($context === false) {
            chat_json_response(["error" => "Chat not found."], 404);
        }
        $stmt = $pdo->prepare(
            "SELECT chat_messages.id, chat_messages.sender_id, users.name AS sender_name,
                    chat_messages.message_body, chat_messages.created_at
             FROM chat_messages
             INNER JOIN users ON users.id = chat_messages.sender_id
             WHERE chat_messages.room_id = :room_id
               AND chat_messages.id > :after_id
             ORDER BY chat_messages.id ASC
             LIMIT 100"
        );
        $stmt->execute([
            "room_id" => (int) $context["room_id"],
            "after_id" => $afterId
        ]);
        chat_json_response([
            "transaction_id" => $transactionId,
            "room_id" => (int) $context["room_id"],
            "status" => $context["status"],
            "can_send" => in_array($context["status"], ["in_progress", "disputed"], true),
            "participants" => [
                "creator" => $context["creator_name"],
                "recipient" => $context["recipient_name"]
            ],
            "messages" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
        $pdo->exec("BEGIN IMMEDIATE");
    } else {
        $pdo->beginTransaction();
    }
    $transactionStarted = true;
    $context = chat_participant_context($pdo, $transactionId, $userId);
    if ($context === false) {
        chat_rollback($pdo);
        $transactionStarted = false;
        chat_json_response(["error" => "Chat not found."], 404);
    }
    if (!in_array($context["status"], ["in_progress", "disputed"], true)) {
        chat_rollback($pdo);
        $transactionStarted = false;
        chat_json_response(["error" => "This chat is read-only."], 409);
    }

    $rateLimit = $pdo->prepare(
        "SELECT COUNT(*)
         FROM chat_messages
         WHERE sender_id = :sender_id
           AND datetime(created_at) >= datetime('now', '-60 seconds')"
    );
    $rateLimit->execute(["sender_id" => $userId]);
    if ((int) $rateLimit->fetchColumn() >= 10) {
        chat_rollback($pdo);
        $transactionStarted = false;
        chat_json_response(["error" => "Please wait before sending another message."], 429);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO chat_messages (room_id, sender_id, message_body)
         VALUES (:room_id, :sender_id, :message_body)"
    );
    $stmt->execute([
        "room_id" => (int) $context["room_id"],
        "sender_id" => $userId,
        "message_body" => $body
    ]);
    $messageId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        "SELECT chat_messages.id, chat_messages.sender_id, users.name AS sender_name,
                chat_messages.message_body, chat_messages.created_at
         FROM chat_messages
         INNER JOIN users ON users.id = chat_messages.sender_id
         WHERE chat_messages.room_id = :room_id
           AND chat_messages.id = :message_id"
    );
    $stmt->execute([
        "room_id" => (int) $context["room_id"],
        "message_id" => $messageId
    ]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($message === false) {
        throw new RuntimeException("The saved message could not be retrieved.");
    }
    $pdo->commit();
    $transactionStarted = false;
    chat_json_response([
        "transaction_id" => $transactionId,
        "room_id" => (int) $context["room_id"],
        "message" => $message
    ], 201);
} catch (PDOException $e) {
    if (isset($transactionStarted) && $transactionStarted) {
        chat_rollback($pdo);
    }
    error_log("Chat request failed (code: {$e->getCode()}).");
    chat_json_response(["error" => "Chat is temporarily unavailable."], 503);
} catch (Throwable $e) {
    if (isset($transactionStarted) && $transactionStarted) {
        chat_rollback($pdo);
    }
    error_log("Chat request failed (code: {$e->getCode()}).");
    chat_json_response(["error" => "Chat is temporarily unavailable."], 503);
}
