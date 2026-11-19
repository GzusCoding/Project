<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/chat_media_support.php";

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

function chat_exchange_participant_context(PDO $pdo, int $transactionId, int $userId): array|false
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

function chat_buy_order_participant_context(PDO $pdo, int $orderId, int $userId): array|false
{
    $stmt = $pdo->prepare(
        "SELECT buy_order_chat_rooms.id AS room_id, buy_orders.status,
                buyer.name AS buyer_name, seller.name AS seller_name
         FROM buy_orders
         INNER JOIN buy_order_chat_rooms
           ON buy_order_chat_rooms.buy_order_id = buy_orders.id
          AND buy_order_chat_rooms.buyer_id = buy_orders.buyer_id
          AND buy_order_chat_rooms.seller_id = buy_orders.seller_id
         INNER JOIN users AS buyer ON buyer.id = buy_orders.buyer_id
         INNER JOIN users AS seller ON seller.id = buy_orders.seller_id
         WHERE buy_orders.id = :order_id
           AND (buy_orders.buyer_id = :buyer_id OR buy_orders.seller_id = :seller_id)
         LIMIT 1"
    );
    $stmt->execute([
        "order_id" => $orderId,
        "buyer_id" => $userId,
        "seller_id" => $userId
    ]);
    $context = $stmt->fetch(PDO::FETCH_ASSOC);

    return $context === false ? false : $context;
}

function chat_context(PDO $pdo, string $contextType, int $contextId, int $userId): array|false
{
    return $contextType === "order"
        ? chat_buy_order_participant_context($pdo, $contextId, $userId)
        : chat_exchange_participant_context($pdo, $contextId, $userId);
}

function chat_rollback(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
        $pdo->exec("ROLLBACK");
    } elseif ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function chat_add_image_url(array $message, string $contextType, int $contextId): array
{
    $hasImage = is_string($message["image_storage_key"] ?? null);
    $message["image_url"] = $hasImage
        ? "chat_image.php?"
            . ($contextType === "order" ? "order_id=" : "transaction_id=")
            . $contextId . "&message_id=" . (int) $message["id"]
        : null;
    unset($message["image_storage_key"], $message["image_mime_type"]);

    return $message;
}

function chat_remove_stored_image(?string $path): void
{
    if ($path !== null && is_file($path) && !unlink($path)) {
        error_log("Failed to remove a chat image after its message could not be saved.");
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
if (!user_account_is_active($pdo, (int) $userId)) {
    chat_json_response(["error" => "This account is suspended or unavailable."], 403);
}

$requestData = $requestMethod === "GET" ? $_GET : $_POST;
$hasTransactionId = array_key_exists("transaction_id", $requestData);
$hasOrderId = array_key_exists("order_id", $requestData);
if ($hasTransactionId === $hasOrderId) {
    chat_json_response(["error" => "Choose one chat context."], 400);
}
$contextType = $hasOrderId ? "order" : "exchange";
$contextId = chat_positive_id($requestData[$hasOrderId ? "order_id" : "transaction_id"]);
if ($contextId === false) {
    chat_json_response(["error" => "Invalid chat context."], 400);
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
        || strlen($body) > 8000
    ) {
        chat_json_response(["error" => "Messages must contain 1 to 2000 characters."], 400);
    }
}

session_write_close();

$storedImagePath = null;
try {
    if ($requestMethod === "GET") {
        $afterId = chat_positive_id($_GET["after_id"] ?? "0", true);
        if ($afterId === false) {
            chat_json_response(["error" => "Invalid message cursor."], 400);
        }
        $context = chat_context($pdo, $contextType, $contextId, $userId);
        if ($context === false) {
            chat_json_response(["error" => "Chat not found."], 404);
        }
        $messageTable = $contextType === "order" ? "buy_order_chat_messages" : "chat_messages";
        $imageColumnsAvailable = chat_media_schema_available($pdo, $messageTable);
        $imageColumns = $imageColumnsAvailable
            ? "{$messageTable}.image_storage_key, {$messageTable}.image_mime_type"
            : "NULL AS image_storage_key, NULL AS image_mime_type";
        $stmt = $pdo->prepare(
            "SELECT {$messageTable}.id, {$messageTable}.sender_id, users.name AS sender_name,
                    {$messageTable}.message_body, {$messageTable}.created_at,
                    {$imageColumns}
             FROM {$messageTable}
             INNER JOIN users ON users.id = {$messageTable}.sender_id
             WHERE {$messageTable}.room_id = :room_id
               AND {$messageTable}.id > :after_id
             ORDER BY {$messageTable}.id ASC
             LIMIT 100"
        );
        $stmt->execute([
            "room_id" => (int) $context["room_id"],
            "after_id" => $afterId
        ]);
        $messages = array_map(
            static fn (array $message): array => chat_add_image_url($message, $contextType, $contextId),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
        $response = [
            "context_type" => $contextType,
            "room_id" => (int) $context["room_id"],
            "status" => $context["status"],
            "can_send" => $contextType === "order"
                ? $context["status"] === "in_progress"
                : in_array($context["status"], ["in_progress", "disputed"], true),
            "messages" => $messages
        ];
        $response[$contextType === "order" ? "order_id" : "transaction_id"] = $contextId;
        $response["participants"] = $contextType === "order"
            ? ["buyer" => $context["buyer_name"], "seller" => $context["seller_name"]]
            : ["creator" => $context["creator_name"], "recipient" => $context["recipient_name"]];
        chat_json_response($response);
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
        $pdo->exec("BEGIN IMMEDIATE");
    } else {
        $pdo->beginTransaction();
    }
    $transactionStarted = true;
    $context = chat_context($pdo, $contextType, $contextId, $userId);
    if ($context === false) {
        chat_rollback($pdo);
        $transactionStarted = false;
        chat_json_response(["error" => "Chat not found."], 404);
    }
    $canSend = $contextType === "order"
        ? $context["status"] === "in_progress"
        : in_array($context["status"], ["in_progress", "disputed"], true);
    if (!$canSend) {
        chat_rollback($pdo);
        $transactionStarted = false;
        chat_json_response(["error" => "This chat is read-only."], 409);
    }

    $imageUpload = chat_media_validate_upload($_FILES["image_file"] ?? null);
    $messageTable = $contextType === "order" ? "buy_order_chat_messages" : "chat_messages";
    $imageColumnsAvailable = chat_media_schema_available($pdo, $messageTable);
    if ($imageUpload !== null && !$imageColumnsAvailable) {
        throw new DomainException("Chat image sharing is not enabled until database migration 10 is applied.", 503);
    }
    if (preg_match('/^\s*$/u', $body) === 1 && $imageUpload === null) {
        throw new DomainException("Enter a message or choose an image.", 400);
    }
    $messageBody = preg_match('/^\s*$/u', $body) === 1 ? "[image]" : $body;

    $rateLimit = $pdo->prepare(
        "SELECT COUNT(*)
         FROM {$messageTable}
         WHERE sender_id = :sender_id
           AND datetime(created_at) >= datetime('now', '-60 seconds')"
    );
    $rateLimit->execute(["sender_id" => $userId]);
    if ((int) $rateLimit->fetchColumn() >= 10) {
        chat_rollback($pdo);
        $transactionStarted = false;
        chat_json_response(["error" => "Please wait before sending another message."], 429);
    }

    $imageStorageKey = null;
    $imageMimeType = null;
    if ($imageUpload !== null) {
        $storedImage = chat_media_store_upload($imageUpload);
        $storedImagePath = $storedImage["path"];
        $imageStorageKey = $storedImage["storage_key"];
        $imageMimeType = $imageUpload["mime_type"];
    }
    $imageInsertColumns = $imageColumnsAvailable ? ", image_storage_key, image_mime_type" : "";
    $imageInsertValues = $imageColumnsAvailable ? ", :image_storage_key, :image_mime_type" : "";
    $insertValues = [
        "room_id" => (int) $context["room_id"],
        "sender_id" => $userId,
        "message_body" => $messageBody
    ];
    if ($imageColumnsAvailable) {
        $insertValues["image_storage_key"] = $imageStorageKey;
        $insertValues["image_mime_type"] = $imageMimeType;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO {$messageTable} (
            room_id, sender_id, message_body{$imageInsertColumns}
         ) VALUES (
            :room_id, :sender_id, :message_body{$imageInsertValues}
         )"
    );
    $stmt->execute($insertValues);
    $messageId = (int) $pdo->lastInsertId();
    $imageSelectColumns = $imageColumnsAvailable
        ? "{$messageTable}.image_storage_key, {$messageTable}.image_mime_type"
        : "NULL AS image_storage_key, NULL AS image_mime_type";
    $stmt = $pdo->prepare(
        "SELECT {$messageTable}.id, {$messageTable}.sender_id, users.name AS sender_name,
                {$messageTable}.message_body, {$messageTable}.created_at,
                {$imageSelectColumns}
         FROM {$messageTable}
         INNER JOIN users ON users.id = {$messageTable}.sender_id
         WHERE {$messageTable}.room_id = :room_id
           AND {$messageTable}.id = :message_id"
    );
    $stmt->execute([
        "room_id" => (int) $context["room_id"],
        "message_id" => $messageId
    ]);
    $storedMessage = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($storedMessage === false) {
        throw new RuntimeException("The saved message could not be retrieved.");
    }
    $message = chat_add_image_url($storedMessage, $contextType, $contextId);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
        $pdo->exec("COMMIT");
    } else {
        $pdo->commit();
    }
    $transactionStarted = false;
    $storedImagePath = null;
    $response = [
        "context_type" => $contextType,
        "room_id" => (int) $context["room_id"],
        "message" => $message
    ];
    $response[$contextType === "order" ? "order_id" : "transaction_id"] = $contextId;
    chat_json_response($response, 201);
} catch (DomainException $e) {
    if (isset($transactionStarted) && $transactionStarted) {
        chat_rollback($pdo);
    }
    chat_remove_stored_image($storedImagePath);
    $status = $e->getCode();
    chat_json_response(
        ["error" => $e->getMessage()],
        $status >= 400 && $status <= 599 ? $status : 400
    );
} catch (PDOException $e) {
    if (isset($transactionStarted) && $transactionStarted) {
        chat_rollback($pdo);
    }
    chat_remove_stored_image($storedImagePath);
    error_log("Chat request failed (code: {$e->getCode()}).");
    chat_json_response(["error" => "Chat is temporarily unavailable."], 503);
} catch (Throwable $e) {
    if (isset($transactionStarted) && $transactionStarted) {
        chat_rollback($pdo);
    }
    chat_remove_stored_image($storedImagePath);
    error_log("Chat request failed (code: {$e->getCode()}).");
    chat_json_response(["error" => "Chat is temporarily unavailable."], 503);
}
