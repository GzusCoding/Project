<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/buy_order_support.php";
require_authenticated_user();

$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
$orderId = buy_order_positive_id($_GET["id"] ?? null);
if ($userId === false || $userId === null || $userId <= 0 || $orderId === false) {
    http_response_code(404);
    exit("Buy order not found.");
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    http_response_code(503);
    exit("Buy orders are temporarily unavailable.");
}

try {
    $stmt = $pdo->prepare(
        "SELECT buy_orders.*, buyer.name AS buyer_name, seller.name AS seller_name,
                buy_order_chat_rooms.id AS chat_room_id
         FROM buy_orders
         INNER JOIN users AS buyer ON buyer.id = buy_orders.buyer_id
         INNER JOIN users AS seller ON seller.id = buy_orders.seller_id
         LEFT JOIN buy_order_chat_rooms
           ON buy_order_chat_rooms.buy_order_id = buy_orders.id
          AND buy_order_chat_rooms.buyer_id = buy_orders.buyer_id
          AND buy_order_chat_rooms.seller_id = buy_orders.seller_id
         WHERE buy_orders.id = :id
           AND (buy_orders.buyer_id = :buyer_id OR buy_orders.seller_id = :seller_id)
         LIMIT 1"
    );
    $stmt->execute([
        "id" => $orderId,
        "buyer_id" => $userId,
        "seller_id" => $userId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($order === false) {
        http_response_code(404);
        exit("Buy order not found.");
    }
    $stmt = $pdo->prepare(
        "SELECT confirmation_type, confirmer_id, confirmed_at
         FROM buy_order_confirmations
         WHERE order_id = :order_id"
    );
    $stmt->execute(["order_id" => $orderId]);
    $confirmations = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $confirmation) {
        $confirmations[$confirmation["confirmation_type"]] = $confirmation;
    }
    $stmt = $pdo->prepare(
        "SELECT buy_order_payment_evidence.*, verifier.name AS verifier_name
         FROM buy_order_payment_evidence
         LEFT JOIN users AS verifier ON verifier.id = buy_order_payment_evidence.verified_by
         WHERE buy_order_payment_evidence.order_id = :order_id
         ORDER BY buy_order_payment_evidence.id DESC
         LIMIT 1"
    );
    $stmt->execute(["order_id" => $orderId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare(
        "SELECT rating, comment, created_at
         FROM reviews
         WHERE order_id = :order_id AND reviewer_id = :reviewer_id"
    );
    $stmt->execute(["order_id" => $orderId, "reviewer_id" => $userId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Buy order lookup failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Buy order details are temporarily unavailable.");
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
$paymentMethods = [
    "promptpay" => "PromptPay",
    "bank_transfer" => "Bank transfer",
    "cash_on_handover" => "Cash on handover"
];
$paymentMethods = array_map("t", $paymentMethods);
$isSeller = (int) $order["seller_id"] === $userId;
$isBuyer = (int) $order["buyer_id"] === $userId;
$confirmationLabels = [
    "seller_dispatched" => "Seller confirmed dispatch",
    "buyer_received" => "Buyer confirmed receipt"
];
$confirmationLabels = array_map("t", $confirmationLabels);
$paymentStatuses = [
    "submitted" => "รอผู้ขายตรวจสอบ",
    "verified" => "ผู้ขายยืนยันแล้ว",
    "rejected" => "ผู้ขายปฏิเสธ"
];
$paymentStatuses = array_map("t", $paymentStatuses);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape(t("Buy order")) ?> | <?= $escape(t("Hybrid Marketplace")) ?></title>
    <link rel="stylesheet" href="assets/css/marketplace.css">
    <link rel="stylesheet" href="assets/css/chat.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <h1><?= $escape(t("รายการซื้อ")) ?> #<?= (int) $order["id"] ?></h1>
    <p><?= $escape(t("Product")) ?>: <?= $escape($order["product_name"]) ?></p>
    <p><?= $escape(t("Price")) ?>: <?= number_format((int) $order["price_minor"] / 100, 2) ?> <?= $escape(t("บาท")) ?></p>
    <p><?= $escape(t("Payment method:")) ?> <?= $escape($paymentMethods[$order["payment_method"]] ?? t("Not specified")) ?></p>
    <p><?= $escape(t("Buyer")) ?>: <?= $escape($order["buyer_name"]) ?> · <?= $escape(t("Seller")) ?>: <?= $escape($order["seller_name"]) ?></p>
    <p><?= $escape(t("Status")) ?>: <?= $escape(t($order["status"] === "completed" ? "สำเร็จ" : "กำลังดำเนินการ")) ?></p>
    <p><?= $escape(t("ระบบบันทึกวิธีชำระเงินเท่านั้น ไม่ได้เรียกเก็บหรือยืนยันการชำระเงินจริง")) ?></p>

    <?php if ($order["chat_room_id"] === null): ?>
        <p class="notice" role="alert"><?= $escape(t("ไม่พบห้องสนทนาของรายการนี้ กรุณาติดต่อผู้ดูแลระบบ")) ?></p>
    <?php else: ?>
        <section
            class="chat"
            aria-labelledby="chat-title"
            data-context-type="order"
            data-context-id="<?= (int) $order["id"] ?>"
            data-current-user-id="<?= (int) $userId ?>"
            data-csrf-token="<?= $escape(csrf_token()) ?>"
        >
            <header>
                <h2 id="chat-title"><?= $escape(t("แชทรายการซื้อขาย")) ?></h2>
                <p>ห้อง #<?= (int) $order["chat_room_id"] ?> · <?= $order["status"] === "in_progress"
                    ? $escape(t("ผู้ซื้อและผู้ขายในรายการนี้เท่านั้นที่เห็นข้อความได้"))
                    : $escape(t("รายการเสร็จสิ้นแล้ว แชทนี้อ่านประวัติได้อย่างเดียว")) ?></p>
            </header>
            <div class="messages" id="chat-messages" role="log" aria-live="polite" aria-relevant="additions text"></div>
            <div class="chat-state" id="chat-state" role="status"><?= $escape(t("กำลังโหลดข้อความ…")) ?></div>
            <button type="button" class="chat-retry" id="chat-retry" hidden><?= $escape(t("ลองโหลดข้อความอีกครั้ง")) ?></button>
            <form class="chat-form" id="chat-form">
                <label for="chat-message" class="visually-hidden"><?= $escape(t("ข้อความ")) ?></label>
                <textarea id="chat-message" name="message_body" maxlength="2000" rows="2"
                    <?= $order["status"] === "in_progress" ? "" : "disabled" ?>></textarea>
                <label for="chat-image"><?= $escape(t("แนบรูปภาพ (JPEG, PNG หรือ WebP · ไม่เกิน 5 MB)")) ?></label>
                <input id="chat-image" name="image_file" type="file"
                    accept="image/jpeg,image/png,image/webp"
                    <?= $order["status"] === "in_progress" ? "" : "disabled" ?>>
                <button type="submit" <?= $order["status"] === "in_progress" ? "" : "disabled" ?>><?= $escape(t("ส่งข้อความ")) ?></button>
            </form>
        </section>
        <script src="assets/js/chat.js" defer></script>
    <?php endif; ?>

    <section aria-labelledby="buy-payment-title">
        <h2 id="buy-payment-title"><?= $escape(t("การชำระเงิน")) ?></h2>
        <?php if ($payment === false): ?>
            <p><?= $escape(t("ยังไม่มีการส่งหลักฐานหรือแจ้งชำระเงิน")) ?></p>
        <?php else: ?>
            <p><?= $escape(t("Status")) ?>: <?= $escape($paymentStatuses[$payment["status"]] ?? t("Unknown status")) ?></p>
            <p><?= $escape(t("Submitted")) ?> <?= $escape($payment["submitted_at"]) ?></p>
            <?php if ($payment["evidence_storage_key"] !== null): ?>
                <p><a href="buy_order_evidence.php?id=<?= (int) $payment["id"] ?>"><?= $escape(t("ดาวน์โหลดหลักฐานการชำระเงิน")) ?></a></p>
            <?php endif; ?>
            <?php if ($payment["verified_at"] !== null): ?>
                <p><?= $escape(t($payment["status"] === "verified" ? "ตรวจสอบโดย" : "พิจารณาโดย")) ?>
                    <?= $escape($payment["verifier_name"]) ?> · <?= $escape($payment["verified_at"]) ?></p>
            <?php endif; ?>
            <?php if ($payment["review_note"] !== null): ?>
                <p><?= $escape(t("Rejection reason")) ?>: <?= $escape($payment["review_note"]) ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($order["status"] === "in_progress" && $isBuyer
            && ($payment === false || $payment["status"] === "rejected")): ?>
            <?php if ($order["payment_method"] === "cash_on_handover"): ?>
                <form class="order-action" method="post" action="order_action.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>">
                    <input type="hidden" name="action" value="cash_paid">
                    <button type="submit"><?= $escape(t("แจ้งว่าชำระเงินสดแล้ว")) ?></button>
                </form>
            <?php else: ?>
                <form class="order-action" method="post" action="order_action.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>">
                    <input type="hidden" name="action" value="submit_payment">
                    <label for="buy-payment-slip"><?= $escape(t("อัปโหลดสลิป (JPEG, PNG หรือ PDF ไม่เกิน 5 MB)")) ?></label>
                    <input id="buy-payment-slip" type="file" name="evidence_file"
                        accept="image/jpeg,image/png,application/pdf" required>
                    <button type="submit"><?= $escape(t("ส่งหลักฐานให้ผู้ขายตรวจสอบ")) ?></button>
                </form>
            <?php endif; ?>
        <?php elseif ($order["status"] === "in_progress" && $isSeller
            && $payment !== false && $payment["status"] === "submitted"): ?>
            <form class="order-action" method="post" action="order_action.php">
                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>">
                <button type="submit" name="action" value="verify_payment"><?= $escape(t("ยืนยันหลักฐาน/รับชำระเงิน")) ?></button>
                <label for="buy-payment-review-note"><?= $escape(t("เหตุผลหากปฏิเสธหลักฐาน")) ?></label>
                <textarea id="buy-payment-review-note" name="review_note" maxlength="2000" rows="2"></textarea>
                <button type="submit" name="action" value="reject_payment"><?= $escape(t("ปฏิเสธหลักฐาน")) ?></button>
            </form>
        <?php endif; ?>
    </section>

    <?php foreach ($confirmationLabels as $type => $label): ?>
        <?php $confirmation = $confirmations[$type] ?? null; ?>
        <section>
            <h2><?= $escape($label) ?></h2>
            <?php if ($confirmation !== null): ?>
                <p><?= $escape(t("Confirmed")) ?> <?= $escape($confirmation["confirmed_at"]) ?></p>
            <?php elseif ($order["status"] === "completed"): ?>
                <p><?= $escape(t("ไม่พบข้อมูลการยืนยัน")) ?></p>
            <?php elseif ($type === "seller_dispatched" && $isSeller
                && $payment !== false && $payment["status"] === "verified"): ?>
                <form class="order-action" method="post" action="order_action.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>">
                    <input type="hidden" name="action" value="dispatch">
                    <button type="submit"><?= $escape(t("ยืนยันส่งมอบสินค้าแล้ว")) ?></button>
                </form>
            <?php elseif ($type === "buyer_received" && $isBuyer && isset($confirmations["seller_dispatched"])): ?>
                <form class="order-action" method="post" action="order_action.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>">
                    <input type="hidden" name="action" value="receive">
                    <button type="submit"><?= $escape(t("ยืนยันได้รับสินค้าแล้ว")) ?></button>
                </form>
            <?php elseif ($type === "seller_dispatched" && $isSeller): ?>
                <p><?= $escape(t("รอผู้ขายตรวจสอบการชำระเงินก่อนยืนยันส่งสินค้า")) ?></p>
            <?php else: ?>
                <p><?= $escape(t("Waiting for confirmation from the other party.")) ?></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <?php if ($order["status"] === "completed"): ?>
        <section>
            <h2><?= $escape(t("Ratings and reviews")) ?></h2>
            <?php if ($review !== false): ?>
                <p><?= $escape(t("Your rating:")) ?> <?= (int) $review["rating"] ?>/5</p>
                <p><?= nl2br($escape($review["comment"])) ?></p>
                <p><?= $escape(t("Saved at")) ?> <?= $escape($review["created_at"]) ?></p>
            <?php else: ?>
                <form class="review-form" method="post" action="review_action.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>">
                    <label for="review-rating"><?= $escape(t("Rating")) ?></label>
                    <select id="review-rating" name="rating" required>
                        <option value=""><?= $escape(t("Select a rating")) ?></option>
                        <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                            <option value="<?= $rating ?>"><?= $rating ?>/5</option>
                        <?php endfor; ?>
                    </select>
                    <label for="review-comment"><?= $escape(t("Your review")) ?></label>
                    <textarea id="review-comment" name="comment" minlength="1" maxlength="2000" required></textarea>
                    <button type="submit"><?= $escape(t("Submit review")) ?></button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <p id="order-state" role="status" aria-live="polite"></p>
    <p><a href="my_orders.php"><?= $escape(t("My orders")) ?></a> · <a href="index.php"><?= $escape(t("Back to marketplace")) ?></a></p>
</main>
<script>
    document.querySelectorAll(".order-action").forEach(form => {
        form.addEventListener("submit", async event => {
            event.preventDefault();
            const state = document.getElementById("order-state");
            try {
                const formData = new FormData(form);
                const submitter = event.submitter;
                if (
                    submitter instanceof HTMLButtonElement
                    && submitter.name !== ""
                    && submitter.value !== ""
                ) {
                    formData.set(submitter.name, submitter.value);
                }
                const response = await fetch(form.getAttribute("action"), {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "Accept": "application/json" },
                    body: formData
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || <?= json_encode(t("บันทึกการยืนยันไม่สำเร็จ"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                window.location.reload();
            } catch (error) {
                state.textContent = error.message || <?= json_encode(t("บันทึกการยืนยันไม่สำเร็จ"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            }
        });
    });
    document.querySelectorAll(".review-form").forEach(form => {
        form.addEventListener("submit", async event => {
            event.preventDefault();
            const state = document.getElementById("order-state");
            try {
                const response = await fetch(form.getAttribute("action"), {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "Accept": "application/json" },
                    body: new FormData(form)
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || <?= json_encode(t("บันทึกรีวิวไม่สำเร็จ"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                window.location.reload();
            } catch (error) {
                state.textContent = error.message || <?= json_encode(t("บันทึกรีวิวไม่สำเร็จ"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            }
        });
    });
</script>
</body>
</html>
