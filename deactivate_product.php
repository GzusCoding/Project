<?php
require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

require_authenticated_user();

$userId = (int) $_SESSION["user_id"];
$productId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if ($userId <= 0 || $productId === false || $productId === null || $productId <= 0) {
    http_response_code(404);
    exit("Product not found.");
}
if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: GET, POST");
    exit("Method not allowed.");
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, name, status,
                EXISTS (
                    SELECT 1 FROM product_reservations
                    WHERE product_reservations.product_id = products.id
                ) AS is_reserved
         FROM products
         WHERE id = :id AND user_id = :user_id
         LIMIT 1"
    );
    $stmt->execute(["id" => $productId, "user_id" => $userId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Owned product lookup failed (code: {$e->getCode()}).");
    http_response_code(500);
    exit("The product could not be loaded. Please try again later.");
}

if ($product === false) {
    http_response_code(404);
    exit("Product not found.");
}
$isReserved = $product["status"] === "reserved" || (int) $product["is_reserved"] === 1;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($isReserved) {
        http_response_code(409);
        $error = "This product is reserved for an exchange and cannot be deactivated.";
    } elseif (!csrf_token_is_valid()) {
        http_response_code(400);
        $error = "Your request could not be verified. Reload the page and try again.";
    } elseif (($_POST["confirm_deactivation"] ?? null) !== "1") {
        http_response_code(400);
        $error = "Confirm that you want to deactivate this product.";
    } else {
        try {
            $stmt = $pdo->prepare(
                "UPDATE products
                 SET status = 'inactive'
                WHERE id = :id
                  AND user_id = :user_id
                  AND status <> 'reserved'
                  AND NOT EXISTS (
                       SELECT 1 FROM product_reservations
                       WHERE product_reservations.product_id = products.id
                  )"
            );
            $stmt->execute(["id" => $productId, "user_id" => $userId]);
            if ($stmt->rowCount() !== 1) {
                http_response_code(409);
                $error = "This product was reserved and can no longer be deactivated.";
            } else {
                $_SESSION["product_management_success"] = "Your product has been deactivated.";
                header("Location: my_products.php");
                exit;
            }
        } catch (PDOException $e) {
            error_log("Product deactivation failed (code: {$e->getCode()}).");
            http_response_code(500);
            $error = "The product could not be deactivated. Please try again later.";
        }
    }
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fffaf5">
    <title>Deactivate product | Buy &amp; Exchange</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #24211f; background: #fffaf5; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        main { width: min(600px, calc(100% - 32px)); margin: 60px auto; padding: 26px; border: 1px solid #eee5de; border-radius: 18px; background: white; }
        .actions { display: flex; gap: 10px; margin-top: 24px; }
        .button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 0 15px; border: 1px solid #eee5de; border-radius: 10px; background: white; color: inherit; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .danger { border-color: #a63b32; color: white; background: #a63b32; }
        .error { padding: 12px; border-radius: 9px; color: #9f3126; background: #fff5f2; }
    </style>
</head>
<body>
<main>
    <h1>Deactivate product?</h1>
    <p>This will hide <strong><?= $escape($product["name"]) ?></strong> from public listings. The product and its image will not be deleted.</p>
    <?php if (isset($error)): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <?php if ($isReserved): ?>
        <p>This product is reserved for an exchange and cannot be deactivated.</p>
        <a class="button" href="my_products.php">Back to my products</a>
    <?php else: ?>
        <form method="POST" action="deactivate_product.php?id=<?= (int) $productId ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
            <div class="actions">
                <a class="button" href="my_products.php">Cancel</a>
                <button class="button danger" type="submit" name="confirm_deactivation" value="1">Confirm deactivation</button>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
