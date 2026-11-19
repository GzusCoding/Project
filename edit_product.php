<?php
require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/category_support.php";

require_authenticated_user();

$userId = (int) $_SESSION["user_id"];
$productId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if ($userId <= 0 || $productId === false || $productId === null || $productId <= 0) {
    http_response_code(404);
    exit("Product not found.");
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, name, description, price, image, category, transaction_type, item_condition, status,
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
if ($product["status"] === "reserved" || (int) $product["is_reserved"] === 1) {
    http_response_code(409);
    exit("This product is reserved for an exchange and cannot be edited.");
}

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
$values = [
    "name" => (string) $product["name"],
    "description" => (string) ($product["description"] ?? ""),
    "price" => (string) $product["price"],
    "category" => (string) ($product["category"] ?? ""),
    "transaction_type" => (string) $product["transaction_type"],
    "item_condition" => (string) ($product["item_condition"] ?? "")
];
$errors = [];
$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($values as $field => $default) {
        $values[$field] = is_string($_POST[$field] ?? null)
            ? trim($_POST[$field])
            : "";
    }

    if (!csrf_token_is_valid()) {
        http_response_code(400);
        $errors[] = "Your request could not be verified. Reload the page and try again.";
    } else {
        $nameLength = mb_strlen($values["name"], "UTF-8");
        $descriptionLength = mb_strlen($values["description"], "UTF-8");
        if ($values["name"] === "" || $nameLength > 200) {
            $errors[] = "Enter a product name no longer than 200 characters.";
        }
        if ($values["description"] === "" || $descriptionLength > 5000) {
            $errors[] = "Enter a description no longer than 5,000 characters.";
        }
        if (
            preg_match('/\A\d+(?:\.\d{1,2})?\z/', $values["price"]) !== 1
            || !is_finite((float) $values["price"])
            || (float) $values["price"] <= 0
        ) {
            $errors[] = "Enter a positive price with no more than two decimal places.";
        }
        if (!array_key_exists($values["category"], $categories)) {
            $errors[] = "Choose a valid category.";
        }
        if (!array_key_exists($values["transaction_type"], $transactionTypes)) {
            $errors[] = "Choose a valid transaction type.";
        }
        if (!array_key_exists($values["item_condition"], $conditions)) {
            $errors[] = "Choose a valid item condition.";
        }

        $upload = $_FILES["product_image"] ?? null;
        $replaceImage = false;
        $allowedTypes = [
            "image/jpeg" => "jpg",
            "image/png" => "png",
            "image/webp" => "webp"
        ];
        $mime = null;
        if ($upload !== null) {
            if (!is_array($upload) || !is_int($upload["error"] ?? null)) {
                $errors[] = "The uploaded image could not be validated.";
            } elseif ($upload["error"] !== UPLOAD_ERR_NO_FILE) {
                $replaceImage = true;
                if ($upload["error"] !== UPLOAD_ERR_OK) {
                    if (
                        $upload["error"] === UPLOAD_ERR_INI_SIZE
                        || $upload["error"] === UPLOAD_ERR_FORM_SIZE
                    ) {
                        $errors[] = "The image must be no larger than 5 MB.";
                    } else {
                        $errors[] = "The image upload failed. Please try again.";
                    }
                } elseif (
                    !is_int($upload["size"] ?? null)
                    || $upload["size"] <= 0
                    || $upload["size"] > 5 * 1024 * 1024
                ) {
                    $errors[] = "The image must be no larger than 5 MB.";
                } elseif (
                    !is_string($upload["tmp_name"] ?? null)
                    || !is_uploaded_file($upload["tmp_name"])
                ) {
                    $errors[] = "The uploaded image could not be validated.";
                } else {
                    $actualSize = filesize($upload["tmp_name"]);
                    if ($actualSize === false || $actualSize <= 0 || $actualSize > 5 * 1024 * 1024) {
                        $errors[] = "The image must be no larger than 5 MB.";
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($upload["tmp_name"]);
                        if (!is_string($mime) || !isset($allowedTypes[$mime])) {
                            $errors[] = "Only JPEG, PNG, and WebP images are supported.";
                        }
                    }
                }
            }
        }

        if ($errors === []) {
            $newImagePath = $product["image"];
            $newImageDestination = null;
            if ($replaceImage) {
                $uploadDir = __DIR__ . "/uploads/";
                if (
                    !is_dir($uploadDir)
                    && !mkdir($uploadDir, 0755, true)
                    && !is_dir($uploadDir)
                ) {
                    $errors[] = "The image could not be saved. Please try again.";
                } elseif (!is_writable($uploadDir)) {
                    $errors[] = "The image could not be saved. Please try again.";
                } else {
                    for ($attempt = 0; $attempt < 5; $attempt++) {
                        $fileName = bin2hex(random_bytes(16)) . "." . $allowedTypes[$mime];
                        $candidate = $uploadDir . $fileName;
                        $reservation = @fopen($candidate, "x");
                        if ($reservation !== false) {
                            fclose($reservation);
                            $newImageDestination = $candidate;
                            $newImagePath = "uploads/" . $fileName;
                            break;
                        }
                    }
                    if ($newImageDestination === null) {
                        $errors[] = "A unique image filename could not be generated. Please try again.";
                    } elseif (!move_uploaded_file($upload["tmp_name"], $newImageDestination)) {
                        if (!unlink($newImageDestination)) {
                            error_log("Failed to remove a reserved image after upload failed.");
                        }
                        $newImageDestination = null;
                        $errors[] = "The image could not be saved. Please try again.";
                    }
                }
            }

            if ($errors === []) {
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE products
                         SET name = :name,
                             description = :description,
                             price = :price,
                             image = :image,
                             category = :category,
                             transaction_type = :transaction_type,
                             item_condition = :item_condition
                         WHERE id = :id
                           AND user_id = :user_id
                           AND status <> 'reserved'
                           AND NOT EXISTS (
                                SELECT 1 FROM product_reservations
                                WHERE product_reservations.product_id = products.id
                           )"
                    );
                    $stmt->execute([
                        "name" => $values["name"],
                        "description" => $values["description"],
                        "price" => $values["price"],
                        "image" => $newImagePath,
                        "category" => $values["category"],
                        "transaction_type" => $values["transaction_type"],
                        "item_condition" => $values["item_condition"],
                        "id" => $productId,
                        "user_id" => $userId
                    ]);
                    if ($stmt->rowCount() !== 1) {
                        throw new DomainException("This product was reserved and can no longer be edited.");
                    }
                } catch (DomainException $e) {
                    if (
                        $newImageDestination !== null
                        && is_file($newImageDestination)
                        && !unlink($newImageDestination)
                    ) {
                        error_log("Failed to remove new product image after reserved product update.");
                    }
                    http_response_code(409);
                    $errors[] = $e->getMessage();
                } catch (PDOException $e) {
                    error_log("Product update failed (code: {$e->getCode()}).");
                    if (
                        $newImageDestination !== null
                        && is_file($newImageDestination)
                        && !unlink($newImageDestination)
                    ) {
                        error_log("Failed to remove new product image after database update failure.");
                    }
                    $errors[] = "The product could not be saved. Please try again.";
                }

                if ($errors === []) {
                    if (
                        $newImagePath !== $product["image"]
                        && $product["image"] !== null
                        && $product["image"] !== ""
                    ) {
                        try {
                            $referenceStmt = $pdo->prepare(
                                "SELECT COUNT(*) FROM products
                                 WHERE image = :image AND id <> :id"
                            );
                            $referenceStmt->execute([
                                "image" => $product["image"],
                                "id" => $productId
                            ]);
                            $referenceCount = (int) $referenceStmt->fetchColumn();
                            if (
                                $referenceCount === 0
                                && preg_match(
                                    '#\Auploads/[a-f0-9]{32}\.(?:jpg|png|webp)\z#D',
                                    (string) $product["image"]
                                ) === 1
                            ) {
                                $oldImage = __DIR__ . "/" . (string) $product["image"];
                                $uploadsRealPath = realpath(__DIR__ . "/uploads");
                                if (
                                    $uploadsRealPath !== false
                                    && realpath(dirname($oldImage)) === $uploadsRealPath
                                    && is_file($oldImage)
                                    && !unlink($oldImage)
                                ) {
                                    error_log("Failed to remove replaced product image.");
                                }
                            }
                        } catch (PDOException $e) {
                            error_log("Old product image reference check failed (code: {$e->getCode()}).");
                        }
                    }
                    $_SESSION["product_management_success"] = "Your product has been updated.";
                    header("Location: my_products.php");
                    exit;
                }
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: GET, POST");
    exit("Method not allowed.");
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fffaf5">
    <title><?= $escape(t("Edit product")) ?> | Buy &amp; Exchange</title>
    <style>
        :root {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #24211f; background: #fffaf5; --orange: #f27b35; --line: #eee5de; --muted: #827a75;
        }
        * { box-sizing: border-box; }
        body { margin: 0; }
        main { width: min(850px, calc(100% - 32px)); margin: 34px auto; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 24px; }
        h1 { margin: 0; font-size: clamp(28px, 5vw, 40px); }
        .card { padding: clamp(18px, 4vw, 30px); border: 1px solid var(--line); border-radius: 18px; background: white; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 17px; }
        .wide { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 7px; font-size: 13px; font-weight: 750; }
        input, textarea, select {
            width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid #e8dfd8;
            border-radius: 10px; font: inherit; font-size: 14px; background: white;
        }
        textarea { min-height: 120px; resize: vertical; }
        .current-image { display: block; max-width: 100%; max-height: 220px; margin: 10px 0; object-fit: contain; }
        .help, .muted { color: var(--muted); font-size: 12px; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
        .button {
            display: inline-flex; min-height: 42px; align-items: center; justify-content: center;
            padding: 0 15px; border: 1px solid var(--line); border-radius: 10px;
            background: white; color: inherit; font: inherit; font-size: 13px; font-weight: 700;
            text-decoration: none; cursor: pointer;
        }
        .button--primary { border-color: var(--orange); color: white; background: var(--orange); }
        .errors { padding: 12px 14px 12px 34px; border: 1px solid #f3c7bd; border-radius: 10px; color: #9f3126; background: #fff5f2; }
        @media (max-width: 600px) { .fields { grid-template-columns: 1fr; } .wide { grid-column: auto; } }
    </style>
</head>
<body>
<?= language_switcher() ?>
<main>
    <header>
        <h1><?= $escape(t("Edit product")) ?></h1>
        <a class="button" href="my_products.php"><?= $escape(t("Back to my products")) ?></a>
    </header>
    <?php if ($errors !== []): ?>
        <ul class="errors" role="alert">
            <?php foreach ($errors as $error): ?><li><?= $escape(t($error)) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="POST" action="edit_product.php?id=<?= (int) $productId ?>" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
        <div class="fields">
            <div class="wide">
                <label for="name"><?= $escape(t("Product name")) ?></label>
                <input id="name" name="name" maxlength="200" required value="<?= $escape($values["name"]) ?>">
            </div>
            <div class="wide">
                <label for="description"><?= $escape(t("Description")) ?></label>
                <textarea id="description" name="description" maxlength="5000" required><?= $escape($values["description"]) ?></textarea>
            </div>
            <div>
                <label for="price"><?= $escape(t("Price")) ?></label>
                <input id="price" name="price" type="number" min="0.01" step="0.01" required value="<?= $escape($values["price"]) ?>">
            </div>
            <div>
                <label for="category"><?= $escape(t("Category")) ?></label>
                <select id="category" name="category" required>
                    <option value=""><?= $escape(t("Choose a category")) ?></option>
                    <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= $escape($value) ?>"<?= $values["category"] === $value ? " selected" : "" ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="transaction_type"><?= $escape(t("Transaction type")) ?></label>
                <select id="transaction_type" name="transaction_type" required>
                    <?php foreach ($transactionTypes as $value => $label): ?>
                        <option value="<?= $escape($value) ?>"<?= $values["transaction_type"] === $value ? " selected" : "" ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="item_condition"><?= $escape(t("Item condition")) ?></label>
                <select id="item_condition" name="item_condition" required>
                    <option value=""><?= $escape(t("Choose condition")) ?></option>
                    <?php foreach ($conditions as $value => $label): ?>
                        <option value="<?= $escape($value) ?>"<?= $values["item_condition"] === $value ? " selected" : "" ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wide">
                <label for="product_image"><?= $escape(t("Replace image (optional)")) ?></label>
                <input id="product_image" name="product_image" type="file" accept="image/jpeg,image/png,image/webp">
                <p class="help"><?= $escape(t("JPEG, PNG, or WebP; maximum 5 MB. Leave empty to keep the current image.")) ?></p>
                <?php if ((string) $product["image"] !== ""): ?>
                    <p class="muted"><?= $escape(t("Current image")) ?></p>
                    <img class="current-image" src="<?= $escape($product["image"]) ?>" alt="<?= $escape($product["name"]) ?>">
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <a class="button" href="my_products.php"><?= $escape(t("Cancel")) ?></a>
            <button class="button button--primary" type="submit"><?= $escape(t("Save changes")) ?></button>
        </div>
    </form>
</main>
</body>
</html>
