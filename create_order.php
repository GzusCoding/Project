<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/buy_order_support.php";
require_authenticated_user();

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method not allowed.");
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    http_response_code(503);
    exit("Buy orders are temporarily unavailable.");
}
if (!csrf_token_is_valid()) {
    http_response_code(400);
    exit("Your request could not be verified. Reload the page and try again.");
}

$buyerId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
$productId = buy_order_positive_id($_POST["product_id"] ?? null);
$paymentMethod = $_POST["payment_method"] ?? null;
$paymentMethods = ["promptpay", "bank_transfer", "cash_on_handover"];
if (
    $buyerId === false || $buyerId === null || $buyerId <= 0
    || $productId === false
    || !is_string($paymentMethod)
    || !in_array($paymentMethod, $paymentMethods, true)
) {
    http_response_code(400);
    exit("Invalid purchase details.");
}

$transactionStarted = false;
try {
    $pdo->exec("BEGIN IMMEDIATE");
    $transactionStarted = true;

    $stmt = $pdo->prepare(
        "SELECT id, user_id, name, price, status, transaction_type
         FROM products
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute(["id" => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($product === false) {
        throw new DomainException("Product not found.", 404);
    }
    if ((int) $product["user_id"] === $buyerId) {
        throw new DomainException("You cannot buy your own product.", 403);
    }
    if (
        $product["status"] !== "active"
        || !in_array($product["transaction_type"], ["buy", "both"], true)
    ) {
        throw new DomainException("This product is no longer available for purchase.", 409);
    }
    $reservation = $pdo->prepare(
        "SELECT 1 FROM product_reservations WHERE product_id = :product_id"
    );
    $reservation->execute(["product_id" => $productId]);
    if ($reservation->fetchColumn() !== false) {
        throw new DomainException("This product is already reserved.", 409);
    }

    $price = (float) $product["price"];
    if (!is_finite($price) || $price < 0 || $price > PHP_INT_MAX / 100) {
        throw new RuntimeException("The product price cannot be recorded.");
    }
    $priceMinor = (int) round($price * 100, 0, PHP_ROUND_HALF_UP);
    $stmt = $pdo->prepare(
        "INSERT INTO buy_orders (
            buyer_id, seller_id, product_id, product_name, price_minor, payment_method
         ) VALUES (
            :buyer_id, :seller_id, :product_id, :product_name, :price_minor, :payment_method
         )"
    );
    $stmt->execute([
        "buyer_id" => $buyerId,
        "seller_id" => (int) $product["user_id"],
        "product_id" => $productId,
        "product_name" => (string) $product["name"],
        "price_minor" => $priceMinor,
        "payment_method" => $paymentMethod
    ]);
    $orderId = (int) $pdo->lastInsertId();
    if ($orderId <= 0) {
        throw new RuntimeException("The buy order ID could not be created.");
    }

    $stmt = $pdo->prepare(
        "INSERT INTO buy_order_chat_rooms (buy_order_id, buyer_id, seller_id)
         VALUES (:buy_order_id, :buyer_id, :seller_id)"
    );
    $stmt->execute([
        "buy_order_id" => $orderId,
        "buyer_id" => $buyerId,
        "seller_id" => (int) $product["user_id"]
    ]);

    $stmt = $pdo->prepare(
        "UPDATE products
         SET status = 'reserved'
         WHERE id = :product_id
           AND user_id = :seller_id
           AND status = 'active'
           AND transaction_type IN ('buy', 'both')
           AND NOT EXISTS (
               SELECT 1 FROM product_reservations
               WHERE product_id = :reserved_product_id
           )"
    );
    $stmt->execute([
        "product_id" => $productId,
        "seller_id" => (int) $product["user_id"],
        "reserved_product_id" => $productId
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new DomainException("This product is no longer available for purchase.", 409);
    }

    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
    $stmt->execute(["id" => $buyerId]);
    $buyerName = $stmt->fetchColumn();
    if (!is_string($buyerName)) {
        throw new RuntimeException("The buyer could not be loaded for notification.");
    }
    notification_create(
        $pdo,
        (int) $product["user_id"],
        "buy_order_created",
        "buy_order",
        $orderId,
        "New purchase order",
        "{$buyerName} ordered {$product["name"]}. Confirm dispatch after preparing the item."
    );

    $pdo->exec("COMMIT");
    $transactionStarted = false;
    header("Location: buy_order.php?id=" . $orderId, true, 303);
    exit;
} catch (DomainException $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    http_response_code($e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400);
    exit($e->getMessage());
} catch (Throwable $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    error_log("Buy order creation failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("The purchase could not be recorded. Please try again later.");
}
