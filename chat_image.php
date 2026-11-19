<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/chat_media_support.php";

header("Cache-Control: no-store, private");
header("X-Content-Type-Options: nosniff");
header("Cross-Origin-Resource-Policy: same-origin");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'none'; sandbox");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
    header("Allow: GET");
    http_response_code(405);
    exit("Method not allowed.");
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(401);
    exit("Authentication required.");
}
if (!user_account_is_active($pdo, (int) $userId)) {
    http_response_code(403);
    exit("This account is suspended or unavailable.");
}

$hasTransactionId = array_key_exists("transaction_id", $_GET);
$hasOrderId = array_key_exists("order_id", $_GET);
$messageId = filter_var($_GET["message_id"] ?? null, FILTER_VALIDATE_INT);
if (
    $hasTransactionId === $hasOrderId
    || $messageId === false
    || $messageId === null
    || $messageId <= 0
) {
    http_response_code(404);
    exit("Chat image not found.");
}
$contextIdValue = $_GET[$hasOrderId ? "order_id" : "transaction_id"];
if (
    !is_string($contextIdValue)
    || preg_match('/\A[1-9][0-9]*\z/', $contextIdValue) !== 1
    || filter_var($contextIdValue, FILTER_VALIDATE_INT) === false
) {
    http_response_code(404);
    exit("Chat image not found.");
}
$contextId = (int) $contextIdValue;
$messageTable = $hasOrderId ? "buy_order_chat_messages" : "chat_messages";
if (!chat_media_schema_available($pdo, $messageTable)) {
    http_response_code(503);
    exit("Chat image sharing is not enabled yet.");
}

try {
    if ($hasOrderId) {
        $stmt = $pdo->prepare(
            "SELECT messages.image_storage_key, messages.image_mime_type
             FROM buy_order_chat_messages AS messages
             INNER JOIN buy_order_chat_rooms AS rooms
               ON rooms.id = messages.room_id
             INNER JOIN buy_orders
               ON buy_orders.id = rooms.buy_order_id
              AND buy_orders.buyer_id = rooms.buyer_id
              AND buy_orders.seller_id = rooms.seller_id
             WHERE messages.id = :message_id
               AND buy_orders.id = :order_id
               AND (buy_orders.buyer_id = :buyer_id OR buy_orders.seller_id = :seller_id)
             LIMIT 1"
        );
        $stmt->execute([
            "message_id" => $messageId,
            "order_id" => $contextId,
            "buyer_id" => $userId,
            "seller_id" => $userId
        ]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT messages.image_storage_key, messages.image_mime_type
             FROM chat_messages AS messages
             INNER JOIN chat_rooms AS rooms ON rooms.id = messages.room_id
             INNER JOIN exchange_transactions
               ON exchange_transactions.id = rooms.exchange_transaction_id
              AND exchange_transactions.creator_id = rooms.creator_id
              AND exchange_transactions.recipient_id = rooms.recipient_id
             WHERE messages.id = :message_id
               AND exchange_transactions.id = :transaction_id
               AND (exchange_transactions.creator_id = :creator_id
                    OR exchange_transactions.recipient_id = :recipient_id)
             LIMIT 1"
        );
        $stmt->execute([
            "message_id" => $messageId,
            "transaction_id" => $contextId,
            "creator_id" => $userId,
            "recipient_id" => $userId
        ]);
    }
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    if (
        $image === false
        || !is_string($image["image_storage_key"])
        || preg_match('/\A[a-f0-9]{64}\z/', $image["image_storage_key"]) !== 1
        || !is_string($image["image_mime_type"])
        || !in_array($image["image_mime_type"], ["image/jpeg", "image/png", "image/webp"], true)
    ) {
        http_response_code(404);
        exit("Chat image not found.");
    }
    $path = chat_media_storage_directory()
        . DIRECTORY_SEPARATOR . $image["image_storage_key"] . ".image";
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit("Chat image not found.");
    }
    $fileSize = filesize($path);
    if ($fileSize === false || $fileSize < 1 || $fileSize > 5 * 1024 * 1024) {
        throw new RuntimeException("The stored chat image has an unexpected size.");
    }
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        throw new RuntimeException("Image MIME inspection is unavailable.");
    }
    $mimeType = finfo_file($fileInfo, $path);
    finfo_close($fileInfo);
    $imageInfo = @getimagesize($path);
    if (
        !is_string($mimeType)
        || $mimeType !== $image["image_mime_type"]
        || !is_array($imageInfo)
        || ($imageInfo["mime"] ?? null) !== $mimeType
    ) {
        throw new RuntimeException("The stored chat image has an unexpected format.");
    }
    header("Content-Type: {$mimeType}");
    header('Content-Disposition: inline; filename="chat-image"');
    header("Content-Length: " . (string) $fileSize);
    if (readfile($path) === false) {
        throw new RuntimeException("The chat image could not be delivered.");
    }
    exit;
} catch (PDOException $e) {
    error_log("Chat image lookup failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Chat image is temporarily unavailable.");
} catch (Throwable $e) {
    error_log("Chat image delivery failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Chat image is temporarily unavailable.");
}
