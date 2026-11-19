<?php
require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/category_support.php";

require_authenticated_user();
$userId = (int) $_SESSION["user_id"];

// รับรหัสสินค้าจาก URL
$product_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

$product = null;
$loadFailed = false;
$sellerReviewSummary = null;

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
        if (
            $product !== false
            && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
            && $pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'reviews'"
            )->fetchColumn() !== false
        ) {
            $reviewStmt = $pdo->prepare(
                "SELECT AVG(rating) AS average_rating, COUNT(*) AS review_count
                 FROM reviews WHERE reviewee_id = :user_id"
            );
            $reviewStmt->execute(["user_id" => (int) $product["user_id"]]);
            $sellerReviewSummary = $reviewStmt->fetch(PDO::FETCH_ASSOC);
        }
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
$canBuy = $isProductFound
    && !$isOwner
    && $product["status"] === "active"
    && in_array($product["transaction_type"] ?? null, ["buy", "both"], true);
$hasImage = $isProductFound
    && is_string($product["image"] ?? null)
    && preg_match(
        '/\Auploads\/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)\z/i',
        $product["image"]
    ) === 1
    && is_file(__DIR__ . "/" . $product["image"]);
$categories = marketplace_category_labels($pdo);
$transactionTypes = [
    "buy" => "Buy",
    "exchange" => "Exchange",
    "both" => "Buy or exchange"
];
$transactionTypes = array_map("t", $transactionTypes);
$conditions = [
    "new" => "New",
    "like_new" => "Like new",
    "used" => "Used"
];
$conditions = array_map("t", $conditions);
$name = $_SESSION["user_name"] ?? "สมาชิก";
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars(t("Product details"), ENT_QUOTES, "UTF-8") ?> | <?= htmlspecialchars(t("Hybrid Marketplace"), ENT_QUOTES, "UTF-8") ?></title>

    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>

<body class="marketplace-page">
<?= language_switcher() ?>

    <div class="card">

        <h1><?= htmlspecialchars(t("Product details"), ENT_QUOTES, "UTF-8") ?></h1>

        <?php if ($product): ?>

            <?php if ($hasImage): ?>
                <img
                    src="<?= htmlspecialchars($product["image"], ENT_QUOTES, "UTF-8") ?>"
                    alt="<?= htmlspecialchars($product["name"], ENT_QUOTES, "UTF-8") ?>"
                    class="product-image"
                >
            <?php else: ?>
                <div class="no-image"><?= htmlspecialchars(t("No image"), ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <?php if ($product["status"] === "inactive"): ?>
                <p class="badge badge--inactive"><?= htmlspecialchars(t("Inactive listing — visible only to you"), ENT_QUOTES, "UTF-8") ?></p>
            <?php endif; ?>

            <h2>
                <?= htmlspecialchars($product["name"], ENT_QUOTES, "UTF-8") ?>
            </h2>

            <p class="description">
                <?= htmlspecialchars(
                    $product["description"] ?? t("ไม่มีรายละเอียดสินค้า"),
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </p>

            <p class="price">
                <?= number_format((float)$product["price"], 2) ?> <?= htmlspecialchars(t("บาท"), ENT_QUOTES, "UTF-8") ?>
            </p>

            <div class="badges" aria-label="<?= htmlspecialchars(t("Product details"), ENT_QUOTES, "UTF-8") ?>">
                <span class="badge"><?= htmlspecialchars($categories[$product["category"] ?? ""] ?? t("Other"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                <span class="badge"><?= htmlspecialchars($conditions[$product["item_condition"] ?? ""] ?? t("Condition not set"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                <span class="badge"><?= htmlspecialchars($transactionTypes[$product["transaction_type"] ?? ""] ?? t("Unknown type"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
            </div>

            <p class="seller">
                <?= htmlspecialchars(t("Seller"), ENT_QUOTES, "UTF-8") ?>:
                <?php if (!empty($product["user_id"])): ?>
                    <a href="user_profile.php?id=<?= (int) $product["user_id"] ?>">
                        <?= htmlspecialchars($product["seller_name"] ?? t("Not specified"), ENT_QUOTES, "UTF-8") ?>
                    </a>
                <?php else: ?>
                    <?= htmlspecialchars($product["seller_name"] ?? t("Not specified"), ENT_QUOTES, "UTF-8") ?>
                <?php endif; ?>
                <?php if ((int) ($sellerReviewSummary["review_count"] ?? 0) > 0): ?>
                    · <?= htmlspecialchars(t("Average rating"), ENT_QUOTES, "UTF-8") ?> <?= number_format((float)$sellerReviewSummary["average_rating"], 1) ?>/5
                    (<?= (int)$sellerReviewSummary["review_count"] ?> <?= htmlspecialchars(t("Review"), ENT_QUOTES, "UTF-8") ?>)
                <?php endif; ?>
            </p>

            <?php if ($isOwner): ?>
                <p><a class="button" href="edit_product.php?id=<?= (int) $product["id"] ?>"><?= htmlspecialchars(t("Manage product"), ENT_QUOTES, "UTF-8") ?></a></p>
            <?php else: ?>
                <?php if ($canBuy): ?>
                    <form method="post" action="create_order.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="product_id" value="<?= (int) $product["id"] ?>">
                        <label for="payment-method"><?= htmlspecialchars(t("Payment method:"), ENT_QUOTES, "UTF-8") ?></label>
                        <select id="payment-method" name="payment_method" required>
                            <option value="promptpay">PromptPay</option>
                            <option value="bank_transfer"><?= htmlspecialchars(t("Bank transfer"), ENT_QUOTES, "UTF-8") ?></option>
                            <option value="cash_on_handover"><?= htmlspecialchars(t("Cash on handover"), ENT_QUOTES, "UTF-8") ?></option>
                        </select>
                        <button class="button" type="submit"><?= htmlspecialchars(t("Buy item"), ENT_QUOTES, "UTF-8") ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($canMakeOffer): ?>
                    <p><a class="button" href="create_offer.php?target_id=<?= (int) $product["id"] ?>"><?= htmlspecialchars(t("Make an Offer"), ENT_QUOTES, "UTF-8") ?></a></p>
                <?php endif; ?>
                <?php if ($product["status"] === "active"): ?>
                    <form class="report-form" method="post" action="report_action.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="product_id" value="<?= (int) $product["id"] ?>">
                        <label for="report-type"><?= htmlspecialchars(t("Report"), ENT_QUOTES, "UTF-8") ?></label>
                        <select id="report-type" name="report_type" required>
                            <option value="listing"><?= htmlspecialchars(t("Listing report"), ENT_QUOTES, "UTF-8") ?></option>
                            <option value="behavior"><?= htmlspecialchars(t("User behavior report"), ENT_QUOTES, "UTF-8") ?></option>
                        </select>
                        <label for="report-reason"><?= htmlspecialchars(t("Details"), ENT_QUOTES, "UTF-8") ?></label>
                        <textarea id="report-reason" name="reason" minlength="1" maxlength="2000" required></textarea>
                        <button class="button" type="submit"><?= htmlspecialchars(t("ส่งรายงานให้ผู้ดูแล"), ENT_QUOTES, "UTF-8") ?></button>
                        <p id="report-state" role="status" aria-live="polite"></p>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

        <?php else: ?>

            <p>
                <?= $loadFailed
                    ? htmlspecialchars(t("สินค้าไม่สามารถโหลดได้ในขณะนี้ กรุณาลองใหม่อีกครั้งภายหลัง"), ENT_QUOTES, "UTF-8")
                    : htmlspecialchars(t("ไม่พบสินค้าที่ต้องการ หรือรหัสสินค้าไม่ถูกต้อง"), ENT_QUOTES, "UTF-8") ?>
            </p>

        <?php endif; ?>

        <a class="button" href="index.php">← <?= htmlspecialchars(t("Back to marketplace"), ENT_QUOTES, "UTF-8") ?></a>

    </div>

<script>
    document.querySelectorAll(".report-form").forEach(form => {
        form.addEventListener("submit", async event => {
            event.preventDefault();
            const state = document.getElementById("report-state");
            try {
                const response = await fetch(form.action, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "Accept": "application/json" },
                    body: new FormData(form)
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || <?= json_encode(t("ส่งรายงานไม่สำเร็จ"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                state.textContent = <?= json_encode(t("ส่งรายงานแล้ว หมายเลข "), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + result.report_id;
                form.reset();
            } catch (error) {
                state.textContent = error.message || <?= json_encode(t("ส่งรายงานไม่สำเร็จ"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            }
        });
    });
</script>
</body>
</html>