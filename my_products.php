<?php
require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

require_authenticated_user();

$userId = (int) $_SESSION["user_id"];
$products = [];
$loadFailed = false;
$success = is_string($_SESSION["product_management_success"] ?? null)
    ? $_SESSION["product_management_success"]
    : "";
unset($_SESSION["product_management_success"]);

try {
    $stmt = $pdo->prepare(
        "SELECT id, name, price, image, category, transaction_type, status,
                EXISTS (
                    SELECT 1 FROM product_reservations
                    WHERE product_reservations.product_id = products.id
                ) AS is_reserved
         FROM products
         WHERE user_id = :user_id
         ORDER BY created_at DESC"
    );
    $stmt->execute(["user_id" => $userId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Owned products query failed (code: {$e->getCode()}).");
    $loadFailed = true;
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
$categories = [
    "electronics" => "Electronics",
    "fashion" => "Fashion",
    "home_living" => "Home & Living",
    "books" => "Books",
    "sports_outdoors" => "Sports & Outdoors",
    "games_collectibles" => "Games & Collectibles",
    "other" => "Other"
];
$transactionTypes = [
    "buy" => "Buy",
    "exchange" => "Exchange",
    "both" => "Buy or exchange"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fffaf5">
    <title>My products | Buy &amp; Exchange</title>
    <style>
        :root {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #24211f;
            background: #fffaf5;
            --orange: #f27b35;
            --line: #eee5de;
            --muted: #827a75;
        }
        * { box-sizing: border-box; }
        body { margin: 0; }
        main { width: min(1050px, calc(100% - 32px)); margin: 40px auto; }
        header, .actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        header { flex-wrap: wrap; }
        .header-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        h1 { margin: 0 0 8px; font-size: clamp(28px, 5vw, 40px); }
        .subtitle { margin: 0 0 25px; color: var(--muted); }
        a { color: inherit; }
        .button {
            display: inline-flex; min-height: 40px; align-items: center; justify-content: center;
            padding: 0 14px; border: 1px solid var(--line); border-radius: 10px;
            background: white; font-size: 13px; font-weight: 700; text-decoration: none;
        }
        .button--primary { border-color: var(--orange); color: white; background: var(--orange); }
        .product-list { display: grid; gap: 14px; }
        .product {
            display: grid; grid-template-columns: 130px minmax(0, 1fr) auto; align-items: center;
            gap: 18px; padding: 14px; border: 1px solid var(--line); border-radius: 16px; background: white;
        }
        .product img, .placeholder { width: 130px; height: 110px; border-radius: 11px; object-fit: cover; background: #f7f2ed; }
        .placeholder { display: grid; place-items: center; color: var(--muted); font-size: 12px; }
        .product h2 { margin: 0 0 7px; font-size: 18px; overflow-wrap: anywhere; }
        .meta { display: flex; flex-wrap: wrap; gap: 7px 14px; color: var(--muted); font-size: 13px; }
        .price { color: #d95f20; font-size: 17px; font-weight: 800; }
        .status { font-weight: 750; }
        .status--inactive { color: #8a4b45; }
        .actions { justify-content: flex-end; flex-wrap: wrap; }
        .notice { padding: 14px; border-radius: 10px; background: #fff5f2; color: #9f3126; }
        .success { padding: 14px; border-radius: 10px; background: #f0fbf3; color: #23643b; }
        @media (max-width: 650px) {
            main { margin: 22px auto; }
            .product { grid-template-columns: 82px minmax(0, 1fr); gap: 12px; }
            .product img, .placeholder { width: 82px; height: 82px; }
            .actions { grid-column: 1 / -1; justify-content: flex-start; }
            .product h2 { font-size: 16px; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <h1>My products</h1>
        <div class="header-actions">
            <a class="button button--primary" href="add_product.php">+ Add Product</a>
            <a class="button" href="index.php">Back to marketplace</a>
        </div>
    </header>
    <p class="subtitle">Manage the items you have listed.</p>

    <?php if ($success !== ""): ?>
        <p class="success" role="status"><?= $escape($success) ?></p>
    <?php endif; ?>

    <?php if ($loadFailed): ?>
        <p class="notice" role="alert">Your products could not be loaded. Please try again later.</p>
    <?php elseif ($products === []): ?>
        <p>You have not listed any products yet.</p>
    <?php else: ?>
        <section class="product-list" aria-label="Your products">
            <?php foreach ($products as $product): ?>
                <article class="product">
                    <?php if (is_string($product["image"]) && $product["image"] !== ""): ?>
                        <img src="<?= $escape($product["image"]) ?>" alt="<?= $escape($product["name"]) ?>">
                    <?php else: ?>
                        <div class="placeholder">No image</div>
                    <?php endif; ?>
                    <div>
                        <h2><?= $escape($product["name"]) ?></h2>
                        <div class="meta">
                            <span class="price"><?= number_format((float) $product["price"], 2) ?></span>
                            <span><?= $escape($categories[$product["category"] ?? ""] ?? "Other") ?></span>
                            <span><?= $escape($transactionTypes[$product["transaction_type"] ?? ""] ?? "Unknown type") ?></span>
                            <span class="status<?= $product["status"] === "inactive" ? " status--inactive" : "" ?>">
                                <?= $escape(ucfirst((string) $product["status"])) ?>
                            </span>
                        </div>
                    </div>
                    <div class="actions">
                        <?php if ($product["status"] === "reserved" || (int) $product["is_reserved"] === 1): ?>
                            <span>Reserved for an exchange</span>
                        <?php else: ?>
                            <a class="button button--primary" href="edit_product.php?id=<?= (int) $product["id"] ?>">Edit</a>
                            <?php if ($product["status"] !== "inactive"): ?>
                                <a class="button" href="deactivate_product.php?id=<?= (int) $product["id"] ?>">Deactivate</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
