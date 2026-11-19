<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/chat_media_support.php";

require_authenticated_user();
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(403);
    exit("Authentication required.");
}

try {
    $exchangeImageAvailable = chat_media_schema_available($pdo, "chat_messages");
    $exchangeLastMessage = $exchangeImageAvailable
        ? "CASE
               WHEN chat_messages.image_storage_key IS NOT NULL
                    AND chat_messages.message_body = '[image]'
               THEN 'รูปภาพ'
               ELSE chat_messages.message_body
           END"
        : "chat_messages.message_body";
    $exchangeStmt = $pdo->prepare(
        "SELECT chat_rooms.id AS room_id, exchange_transactions.id AS transaction_id,
                NULL AS order_id, 'exchange' AS context_type,
                exchange_transactions.status, exchange_transactions.created_at,
                CASE
                    WHEN chat_rooms.creator_id = :display_creator_id THEN recipient.name
                    ELSE creator.name
                END AS participant_name,
                (
                    SELECT {$exchangeLastMessage}
                    FROM chat_messages
                    WHERE chat_messages.room_id = chat_rooms.id
                    ORDER BY chat_messages.id DESC
                    LIMIT 1
                ) AS last_message,
                (
                    SELECT chat_messages.created_at
                    FROM chat_messages
                    WHERE chat_messages.room_id = chat_rooms.id
                    ORDER BY chat_messages.id DESC
                    LIMIT 1
                ) AS last_message_at
         FROM chat_rooms
         INNER JOIN exchange_transactions
           ON exchange_transactions.id = chat_rooms.exchange_transaction_id
          AND exchange_transactions.creator_id = chat_rooms.creator_id
          AND exchange_transactions.recipient_id = chat_rooms.recipient_id
         INNER JOIN users AS creator ON creator.id = chat_rooms.creator_id
         INNER JOIN users AS recipient ON recipient.id = chat_rooms.recipient_id
         WHERE chat_rooms.creator_id = :creator_id
            OR chat_rooms.recipient_id = :recipient_id
         ORDER BY COALESCE(last_message_at, exchange_transactions.created_at) DESC,
                  chat_rooms.id DESC"
    );
    $exchangeStmt->execute([
        "display_creator_id" => $userId,
        "creator_id" => $userId,
        "recipient_id" => $userId
    ]);
    $exchangeChats = $exchangeStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderChatAvailable = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
        && $pdo->query(
            "SELECT 1 FROM sqlite_master
             WHERE type = 'table' AND name = 'buy_order_chat_rooms'"
        )->fetchColumn() !== false;
    $orderChats = [];
    if ($orderChatAvailable) {
        $orderImageAvailable = chat_media_schema_available($pdo, "buy_order_chat_messages");
        $orderLastMessage = $orderImageAvailable
            ? "CASE
                   WHEN buy_order_chat_messages.image_storage_key IS NOT NULL
                        AND buy_order_chat_messages.message_body = '[image]'
                   THEN 'รูปภาพ'
                   ELSE buy_order_chat_messages.message_body
               END"
            : "buy_order_chat_messages.message_body";
        $orderStmt = $pdo->prepare(
        "SELECT buy_order_chat_rooms.id AS room_id, NULL AS transaction_id,
                buy_orders.id AS order_id, 'buy_order' AS context_type,
                buy_orders.status, buy_orders.created_at,
                CASE
                    WHEN buy_order_chat_rooms.buyer_id = :display_buyer_id THEN seller.name
                    ELSE buyer.name
                END AS participant_name,
                (
                    SELECT {$orderLastMessage}
                    FROM buy_order_chat_messages
                    WHERE buy_order_chat_messages.room_id = buy_order_chat_rooms.id
                    ORDER BY buy_order_chat_messages.id DESC
                    LIMIT 1
                ) AS last_message,
                (
                    SELECT buy_order_chat_messages.created_at
                    FROM buy_order_chat_messages
                    WHERE buy_order_chat_messages.room_id = buy_order_chat_rooms.id
                    ORDER BY buy_order_chat_messages.id DESC
                    LIMIT 1
                ) AS last_message_at
         FROM buy_order_chat_rooms
         INNER JOIN buy_orders
           ON buy_orders.id = buy_order_chat_rooms.buy_order_id
          AND buy_orders.buyer_id = buy_order_chat_rooms.buyer_id
          AND buy_orders.seller_id = buy_order_chat_rooms.seller_id
         INNER JOIN users AS buyer ON buyer.id = buy_orders.buyer_id
         INNER JOIN users AS seller ON seller.id = buy_orders.seller_id
         WHERE buy_order_chat_rooms.buyer_id = :buyer_id
            OR buy_order_chat_rooms.seller_id = :seller_id
         ORDER BY COALESCE(last_message_at, buy_orders.created_at) DESC,
                  buy_order_chat_rooms.id DESC"
        );
        $orderStmt->execute([
            "display_buyer_id" => $userId,
            "buyer_id" => $userId,
            "seller_id" => $userId
        ]);
        $orderChats = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $chats = array_merge($exchangeChats, $orderChats);
    usort($chats, static function (array $left, array $right): int {
        $leftAt = (string) ($left["last_message_at"] ?? $left["created_at"]);
        $rightAt = (string) ($right["last_message_at"] ?? $right["created_at"]);

        return strcmp($rightAt, $leftAt)
            ?: ((int) $right["room_id"] <=> (int) $left["room_id"]);
    });
} catch (PDOException $e) {
    error_log("Chat list query failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Your chats are temporarily unavailable.");
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
$statusLabels = [
    "in_progress" => "กำลังดำเนินการ",
    "disputed" => "อยู่ระหว่างตรวจสอบข้อพิพาท",
    "completed" => "สำเร็จ · อ่านประวัติได้",
    "cancelled" => "ยกเลิก · อ่านประวัติได้"
];
$statusLabels = array_map("t", $statusLabels);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(t("My chats"), ENT_QUOTES, "UTF-8") ?> | Buy &amp; Exchange</title>
    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <header>
        <h1><?= htmlspecialchars(t("My chats"), ENT_QUOTES, "UTF-8") ?></h1>
        <a class="button" href="index.php"><?= htmlspecialchars(t("Back to home"), ENT_QUOTES, "UTF-8") ?></a>
    </header>
    <p><?= htmlspecialchars(t("ห้องสนทนาจะเปิดเมื่อข้อเสนอแลกเปลี่ยนได้รับการตอบรับ หรือมีรายการซื้อสินค้า"), ENT_QUOTES, "UTF-8") ?></p>
    <?php if (!$orderChatAvailable): ?>
        <p class="notice" role="alert"><?= htmlspecialchars(t("แชทรายการซื้อขายยังไม่พร้อมใช้งาน กรุณาอัปเดตฐานข้อมูลก่อนใช้งาน"), ENT_QUOTES, "UTF-8") ?></p>
    <?php endif; ?>

    <?php if ($chats === []): ?>
        <article>
            <h2><?= htmlspecialchars(t("There are no chat rooms yet."), ENT_QUOTES, "UTF-8") ?></h2>
            <p><?= htmlspecialchars(t("เมื่อมีรายการซื้อ หรือข้อเสนอแลกเปลี่ยนได้รับการตอบรับ ระบบจะสร้างห้องสนทนาให้ผู้ซื้อและผู้ขายโดยอัตโนมัติ"), ENT_QUOTES, "UTF-8") ?></p>
            <a class="button" href="my_offers.php"><?= htmlspecialchars(t("Browse offers and orders"), ENT_QUOTES, "UTF-8") ?></a>
        </article>
    <?php else: ?>
        <?php foreach ($chats as $chat): ?>
            <?php
            $canSend = $chat["context_type"] === "buy_order"
                ? $chat["status"] === "in_progress"
                : in_array($chat["status"], ["in_progress", "disputed"], true);
            $lastMessage = $chat["last_message"];
            $roomTitle = $chat["context_type"] === "buy_order"
                ? t("Purchase") . " #" . (int) $chat["order_id"]
                : t("Exchange transaction") . " #" . (int) $chat["transaction_id"];
            $chatUrl = $chat["context_type"] === "buy_order"
                ? "buy_order.php?id=" . (int) $chat["order_id"]
                : "exchange_transaction.php?id=" . (int) $chat["transaction_id"];
            ?>
            <article>
                <h2><?= $escape($roomTitle) ?></h2>
                <p><?= htmlspecialchars(t("คู่สนทนา:"), ENT_QUOTES, "UTF-8") ?> <?= $escape($chat["participant_name"]) ?></p>
                <p>
                    <span class="badge<?= $canSend ? "" : " badge--inactive" ?>">
                        <?= $escape($statusLabels[$chat["status"]] ?? t("ไม่ทราบสถานะ")) ?>
                    </span>
                </p>
                <?php if ($lastMessage === null): ?>
                    <p><?= htmlspecialchars(t("ยังไม่มีข้อความ · เริ่มพูดคุยหรือนัดหมายได้เลย"), ENT_QUOTES, "UTF-8") ?></p>
                <?php else: ?>
                    <p><?= nl2br($escape($lastMessage)) ?></p>
                    <p><small><?= htmlspecialchars(t("ข้อความล่าสุด"), ENT_QUOTES, "UTF-8") ?> <?= $escape($chat["last_message_at"]) ?></small></p>
                <?php endif; ?>
                <a class="button" href="<?= $escape($chatUrl) ?>">
                    <?= htmlspecialchars(t($canSend ? "เปิดห้องแชท" : "ดูประวัติแชท"), ENT_QUOTES, "UTF-8") ?>
                </a>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
