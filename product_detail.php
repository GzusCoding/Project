```php
<?php
require_once __DIR__ . "/security.php";
require_once "db.php";

require_authenticated_user();
$userId = (int) $_SESSION["user_id"];

// รับรหัสสินค้าจาก URL
$product_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

$product = null;
$loadFailed = false;

if ($product_id !== false && $product_id !== null && $product_id > 0) {
    $sql = "
        SELECT products.*, users.name AS seller_name
        FROM products
        LEFT JOIN users ON products.user_id = users.id
        WHERE products.id = :id
          AND NOT EXISTS (
              SELECT 1 FROM product_reservations
              WHERE product_reservations.product_id = products.id
          )
          AND (
              products.status = 'active'
              OR (products.user_id = :user_id AND products.status = 'inactive')
          )
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["id" => $product_id, "user_id" => $userId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Public product detail query failed (code: {$e->getCode()}).");
        $loadFailed = true;
        http_response_code(500);
    }
}

$isProductFound = is_array($product);
if (!$isProductFound && !$loadFailed) {
    http_response_code(404);
}
$isOwner = $isProductFound && (int) $product["user_id"] === $userId;
$canMakeOffer = $isProductFound
    && !$isOwner
    && $product["status"] === "active"
    && in_array($product["transaction_type"] ?? null, ["exchange", "both"], true);
$hasImage = $isProductFound
    && is_string($product["image"] ?? null)
    && preg_match(
        '/\Auploads\/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)\z/i',
        $product["image"]
    ) === 1
    && is_file(__DIR__ . "/" . $product["image"]);
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
$conditions = [
    "new" => "New",
    "like_new" => "Like new",
    "used" => "Used"
];
$name = $_SESSION["user_name"] ?? "สมาชิก";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>รายละเอียดสินค้า | Hybrid Marketplace</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f7fb;
            color: #222;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px #ddd;
        }

        .product-image {
            display: block;
            width: 100%;
            max-height: 450px;
            object-fit: contain;
            background: #f1f5f9;
            border-radius: 10px;
            margin: 20px 0;
        }

        .no-image {
            min-height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #64748b;
            border-radius: 10px;
            margin: 20px 0;
        }

        .price {
            color: #2563eb;
            font-size: 26px;
            font-weight: bold;
        }

        .description {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            line-height: 1.7;
        }

        .seller {
            color: #64748b;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 16px 0;
        }

        .badge {
            padding: 6px 10px;
            border-radius: 999px;
            color: #765039;
            background: #fff1e5;
            font-size: 12px;
            font-weight: bold;
        }

        .badge--inactive {
            color: #8a4b45;
            background: #fff0ed;
        }

        .button {
            display: inline-block;
            padding: 10px 16px;
            background: #2563eb;
            color: white;
            border-radius: 6px;
            text-decoration: none;
        }

        .button:hover {
            background: #1d4ed8;
        }

        @media (max-width: 600px) {
            body {
                margin: 10px auto;
                padding: 12px;
            }

            .card {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

    <div class="card">

        <h1>รายละเอียดสินค้า</h1>

        <?php if ($product): ?>

            <?php if ($hasImage): ?>
                <img
                    src="<?= htmlspecialchars($product["image"], ENT_QUOTES, "UTF-8") ?>"
                    alt="<?= htmlspecialchars($product["name"], ENT_QUOTES, "UTF-8") ?>"
                    class="product-image"
                >
            <?php else: ?>
                <div class="no-image">ไม่มีรูปสินค้า</div>
            <?php endif; ?>

            <?php if ($product["status"] === "inactive"): ?>
                <p class="badge badge--inactive">Inactive listing — visible only to you</p>
            <?php endif; ?>

            <h2>
                <?= htmlspecialchars($product["name"], ENT_QUOTES, "UTF-8") ?>
            </h2>

            <p class="description">
                <?= htmlspecialchars(
                    $product["description"] ?? "ไม่มีรายละเอียดสินค้า",
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </p>

            <p class="price">
                <?= number_format((float)$product["price"], 2) ?> บาท
            </p>

            <div class="badges" aria-label="Product details">
                <span class="badge"><?= htmlspecialchars($categories[$product["category"] ?? ""] ?? "Other", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                <span class="badge"><?= htmlspecialchars($conditions[$product["item_condition"] ?? ""] ?? "Condition not set", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                <span class="badge"><?= htmlspecialchars($transactionTypes[$product["transaction_type"] ?? ""] ?? "Unknown type", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
            </div>

            <p class="seller">
                ผู้ขาย:
                <?= htmlspecialchars(
                    $product["seller_name"] ?? "ไม่ระบุ",
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </p>

            <?php if ($isOwner): ?>
                <p><a class="button" href="edit_product.php?id=<?= (int) $product["id"] ?>">Manage product</a></p>
            <?php elseif ($canMakeOffer): ?>
                <p><a class="button" href="create_offer.php?target_id=<?= (int) $product["id"] ?>">Make an Offer</a></p>
            <?php endif; ?>

        <?php else: ?>

            <p>
                <?= $loadFailed
                    ? "สินค้าไม่สามารถโหลดได้ในขณะนี้ กรุณาลองใหม่อีกครั้งภายหลัง"
                    : "ไม่พบสินค้าที่ต้องการ หรือรหัสสินค้าไม่ถูกต้อง" ?>
            </p>

        <?php endif; ?>

        <a class="button" href="index.php">← กลับไปหน้ารายการสินค้า</a>

    </div>

</body>
</html>