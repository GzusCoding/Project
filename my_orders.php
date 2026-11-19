<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/buy_order_support.php";
require_authenticated_user();

$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(403);
    exit("Authentication required.");
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    http_response_code(503);
    exit("Buy orders are temporarily unavailable.");
}

try {
    $stmt = $pdo->prepare(
        "SELECT buy_orders.id, buy_orders.product_name, buy_orders.price_minor,
                buy_orders.payment_method, buy_orders.status, buy_orders.created_at,
                buy_orders.buyer_id, buyer.name AS buyer_name, seller.name AS seller_name
         FROM buy_orders
         INNER JOIN users AS buyer ON buyer.id = buy_orders.buyer_id
         INNER JOIN users AS seller ON seller.id = buy_orders.seller_id
         WHERE buy_orders.buyer_id = :buyer_id OR buy_orders.seller_id = :seller_id
         ORDER BY buy_orders.created_at DESC, buy_orders.id DESC"
    );
    $stmt->execute(["buyer_id" => $userId, "seller_id" => $userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Buy order list failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Your buy orders are temporarily unavailable.");
}
$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(t("My orders"), ENT_QUOTES, "UTF-8") ?> | <?= htmlspecialchars(t("Hybrid Marketplace"), ENT_QUOTES, "UTF-8") ?></title>
    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <h1><?= htmlspecialchars(t("My orders"), ENT_QUOTES, "UTF-8") ?></h1>
    <p><a href="index.php"><?= htmlspecialchars(t("Back to marketplace"), ENT_QUOTES, "UTF-8") ?></a></p>
    <?php if ($orders === []): ?>
        <p><?= htmlspecialchars(t("ยังไม่มีรายการซื้อขาย"), ENT_QUOTES, "UTF-8") ?></p>
    <?php else: ?>
        <?php foreach ($orders as $order): ?>
            <article>
                <h2><?= $escape($order["product_name"]) ?></h2>
                <p><?= htmlspecialchars(t("รายการ #"), ENT_QUOTES, "UTF-8") ?><?= (int) $order["id"] ?> · <?= htmlspecialchars(t((int) $order["buyer_id"] === $userId ? "Buyer" : "Seller"), ENT_QUOTES, "UTF-8") ?></p>
                <p><?= htmlspecialchars(t("Price"), ENT_QUOTES, "UTF-8") ?> <?= number_format((int) $order["price_minor"] / 100, 2) ?> <?= htmlspecialchars(t("บาท"), ENT_QUOTES, "UTF-8") ?></p>
                <p><?= htmlspecialchars(t("Buyer"), ENT_QUOTES, "UTF-8") ?> <?= $escape($order["buyer_name"]) ?> · <?= htmlspecialchars(t("Seller"), ENT_QUOTES, "UTF-8") ?> <?= $escape($order["seller_name"]) ?></p>
                <p><?= htmlspecialchars(t("Status"), ENT_QUOTES, "UTF-8") ?> <?= htmlspecialchars(t($order["status"] === "completed" ? "สำเร็จ" : "กำลังดำเนินการ"), ENT_QUOTES, "UTF-8") ?></p>
                <a href="buy_order.php?id=<?= (int) $order["id"] ?>"><?= htmlspecialchars(t("ดูรายละเอียดรายการ"), ENT_QUOTES, "UTF-8") ?></a>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
