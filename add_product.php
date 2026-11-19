<?php
require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

require_authenticated_user();

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
$values = [
    "name" => "",
    "description" => "",
    "price" => "",
    "category" => "",
    "transaction_type" => "buy",
    "item_condition" => ""
];
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["transaction_type"] = "";
    foreach ($values as $field => $default) {
        if (is_string($_POST[$field] ?? null)) {
            $values[$field] = trim($_POST[$field]);
        }
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

        $price = $values["price"];
        if (
            preg_match('/\A\d+(?:\.\d{1,2})?\z/', $price) !== 1
            || !is_finite((float) $price)
            || (float) $price <= 0
        ) {
            $errors[] = "Enter a positive estimated value with no more than two decimal places.";
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
        if (!is_array($upload) || !is_int($upload["error"] ?? null)) {
            $errors[] = "Choose a product image to upload.";
        } elseif ($upload["error"] !== UPLOAD_ERR_OK) {
            if ($upload["error"] === UPLOAD_ERR_NO_FILE) {
                $errors[] = "Choose a product image to upload.";
            } elseif (
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
                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "image/webp" => "webp"
                ];
                if (!is_string($mime) || !isset($allowedTypes[$mime])) {
                    $errors[] = "Only JPEG, PNG, and WebP images are supported.";
                }
            }
        }

        if ($errors === []) {
            $uploadDir = __DIR__ . "/uploads/";
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $errors[] = "The image could not be saved. Please try again.";
            } elseif (!is_writable($uploadDir)) {
                $errors[] = "The image could not be saved. Please try again.";
            } else {
                $fileName = "";
                $destination = "";
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $fileName = bin2hex(random_bytes(16)) . "." . $allowedTypes[$mime];
                    $destination = $uploadDir . $fileName;
                    $reservation = @fopen($destination, "x");
                    if ($reservation !== false) {
                        fclose($reservation);
                        break;
                    }
                    $destination = "";
                }

                if ($destination === "") {
                    $errors[] = "A unique image filename could not be generated. Please try again.";
                } elseif (!move_uploaded_file($upload["tmp_name"], $destination)) {
                    if (!unlink($destination)) {
                        error_log("Failed to remove a reserved product image after upload failed.");
                    }
                    $errors[] = "The image could not be saved. Please try again.";
                } else {
                    $imagePath = "uploads/" . $fileName;
                    try {
                        $stmt = $pdo->prepare(
                            "INSERT INTO products
                                (user_id, name, description, price, image, category, transaction_type, item_condition, status)
                             VALUES
                                (:user_id, :name, :description, :price, :image, :category, :transaction_type, :item_condition, 'active')"
                        );
                        $stmt->execute([
                            "user_id" => $_SESSION["user_id"],
                            "name" => $values["name"],
                            "description" => $values["description"],
                            "price" => $price,
                            "image" => $imagePath,
                            "category" => $values["category"],
                            "transaction_type" => $values["transaction_type"],
                            "item_condition" => $values["item_condition"]
                        ]);

                        $_SESSION["product_success"] = "Your product has been added and is now active.";
                        header("Location: index.php#products");
                        exit;
                    } catch (PDOException $e) {
                        error_log("Product insert failed (code: {$e->getCode()}).");
                        if (!unlink($destination)) {
                            error_log("Failed to remove product image after a database insert failure.");
                        }
                        $errors[] = "The product could not be saved. Please try again.";
                    }
                }
            }
        }
    }
}

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
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
    <title>Add a product | Buy &amp; Exchange</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #24211f;
            background: #fffaf5;
            --orange: #f27b35;
            --orange-dark: #d95f20;
            --muted: #827a75;
            --line: #eee5de;
        }

        * { box-sizing: border-box; }

        body {
            min-width: 320px;
            margin: 0;
            background:
                radial-gradient(circle at 90% 5%, rgba(255, 222, 197, .58), transparent 25rem),
                #fffaf5;
        }

        a { color: inherit; }

        .page-shell {
            width: min(1080px, calc(100% - 40px));
            margin: 0 auto;
            padding: 28px 0 64px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 30px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
        }

        .brand-mark {
            display: grid;
            width: 43px;
            height: 43px;
            place-items: center;
            border-radius: 14px;
            color: white;
            background: linear-gradient(145deg, #ffa65f, var(--orange));
            box-shadow: 0 7px 16px rgba(242, 123, 53, .24);
            font-size: 19px;
            font-weight: 850;
        }

        .brand-copy { display: grid; gap: 2px; }
        .brand-copy strong { font-size: 14px; letter-spacing: .025em; }
        .brand-copy span { color: var(--muted); font-size: 11px; }

        .cancel-link, .submit-button {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            padding: 0 17px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: white;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .submit-button {
            border: 0;
            color: white;
            background: var(--orange);
            box-shadow: 0 8px 18px rgba(242, 123, 53, .2);
        }

        .submit-button:hover { background: var(--orange-dark); }
        .cancel-link:hover { background: #fff4e9; }

        .intro { margin-bottom: 22px; }
        .eyebrow {
            margin: 0 0 9px;
            color: #b95725;
            font-size: 11px;
            font-weight: 850;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-size: clamp(30px, 5vw, 43px);
            letter-spacing: -.045em;
        }

        .intro p:last-child {
            max-width: 620px;
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .form-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            align-items: start;
            gap: 20px;
        }

        .form-card, .image-card {
            border: 1px solid rgba(238, 229, 222, .9);
            border-radius: 22px;
            background: rgba(255, 255, 255, .94);
            box-shadow: 0 16px 42px rgba(91, 61, 43, .06);
        }

        .form-card { padding: clamp(20px, 4vw, 34px); }
        .image-card { padding: 20px; }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 19px 16px;
        }

        .field { min-width: 0; }
        .field--wide { grid-column: 1 / -1; }

        label, .field-label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: 750;
        }

        .required { color: var(--orange-dark); }

        input, textarea, select {
            width: 100%;
            min-height: 46px;
            padding: 11px 12px;
            border: 1px solid #e8dfd8;
            border-radius: 11px;
            outline: 0;
            color: #302a26;
            background: white;
            font: inherit;
            font-size: 14px;
        }

        textarea {
            min-height: 126px;
            resize: vertical;
            line-height: 1.6;
        }

        input:focus, textarea:focus, select:focus {
            border-color: #f2ae85;
            box-shadow: 0 0 0 3px rgba(242, 123, 53, .1);
        }

        input[type="file"] {
            min-height: 0;
            padding: 10px;
            font-size: 12px;
        }

        .help {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.5;
        }

        .price-wrap { position: relative; }
        .price-wrap input { padding-right: 55px; }
        .currency {
            position: absolute;
            top: 50%;
            right: 12px;
            color: var(--muted);
            font-size: 12px;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .image-preview {
            display: grid;
            min-height: 220px;
            margin-top: 15px;
            place-items: center;
            overflow: hidden;
            border: 1px dashed #e6d9ce;
            border-radius: 15px;
            color: #a39890;
            background: #fffaf6;
            text-align: center;
        }

        .image-preview img {
            display: none;
            width: 100%;
            max-height: 320px;
            object-fit: contain;
        }

        .preview-placeholder { padding: 20px; font-size: 13px; }
        .preview-placeholder span { display: block; margin-bottom: 8px; font-size: 30px; }

        .image-note {
            margin: 13px 0 0;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
        }

        .errors {
            margin: 0 0 20px;
            padding: 13px 16px 13px 36px;
            border: 1px solid #f3c7bd;
            border-radius: 12px;
            color: #9f3126;
            background: #fff5f2;
            font-size: 13px;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #f1eae4;
        }

        @media (max-width: 760px) {
            .form-layout { grid-template-columns: 1fr; }
            .image-card { order: -1; }
            .image-preview { min-height: 190px; }
        }

        @media (max-width: 520px) {
            .page-shell { width: min(100% - 24px, 600px); padding-top: 16px; }
            .topbar { margin-bottom: 25px; }
            .brand-copy span { display: none; }
            .field-grid { grid-template-columns: 1fr; }
            .field--wide { grid-column: auto; }
            .actions { display: grid; grid-template-columns: 1fr 1fr; }
            .actions a, .actions button { width: 100%; }
        }
    </style>
</head>
<body>
    <main class="page-shell">
        <header class="topbar">
            <a class="brand" href="index.php" aria-label="Buy and Exchange home">
                <span class="brand-mark" aria-hidden="true">B</span>
                <span class="brand-copy">
                    <strong>BUY &amp; EXCHANGE</strong>
                    <span>Marketplace for things worth sharing</span>
                </span>
            </a>
            <a class="cancel-link" href="index.php">Cancel</a>
        </header>

        <section class="intro">
            <p class="eyebrow">Share something good</p>
            <h1>Add a product</h1>
            <p>Tell the community about your item. The estimated value helps buyers and potential exchange partners understand what it is worth.</p>
        </section>

        <?php if ($errors !== []): ?>
            <ul class="errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
            <div class="form-layout">
                <section class="form-card" aria-label="Product details">
                    <div class="field-grid">
                        <div class="field field--wide">
                            <label for="name">Product name <span class="required">*</span></label>
                            <input type="text" id="name" name="name" maxlength="200" required
                                   value="<?= $escape($values["name"]) ?>" autocomplete="off">
                        </div>

                        <div class="field field--wide">
                            <label for="description">Description <span class="required">*</span></label>
                            <textarea id="description" name="description" maxlength="5000" rows="5" required><?= $escape($values["description"]) ?></textarea>
                            <p class="help">Describe the item, its features, and anything a new owner should know.</p>
                        </div>

                        <div class="field">
                            <label for="price">Price / estimated value <span class="required">*</span></label>
                            <div class="price-wrap">
                                <input type="number" id="price" name="price" min="0.01" step="0.01"
                                       inputmode="decimal" required value="<?= $escape($values["price"]) ?>">
                                <span class="currency">THB</span>
                            </div>
                            <p class="help">Enter a positive estimated value, including for exchange-only items.</p>
                        </div>

                        <div class="field">
                            <label for="category">Category <span class="required">*</span></label>
                            <select id="category" name="category" required>
                                <option value="">Choose a category</option>
                                <?php foreach ($categories as $value => $label): ?>
                                    <option value="<?= $escape($value) ?>"<?= $values["category"] === $value ? " selected" : "" ?>>
                                        <?= $escape($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="transaction_type">Transaction type <span class="required">*</span></label>
                            <select id="transaction_type" name="transaction_type" required>
                                <?php foreach ($transactionTypes as $value => $label): ?>
                                    <option value="<?= $escape($value) ?>"<?= $values["transaction_type"] === $value ? " selected" : "" ?>>
                                        <?= $escape($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="item_condition">Item condition <span class="required">*</span></label>
                            <select id="item_condition" name="item_condition" required>
                                <option value="">Choose a condition</option>
                                <?php foreach ($conditions as $value => $label): ?>
                                    <option value="<?= $escape($value) ?>"<?= $values["item_condition"] === $value ? " selected" : "" ?>>
                                        <?= $escape($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="actions">
                        <a class="cancel-link" href="index.php">Cancel</a>
                        <button class="submit-button" type="submit">Add product</button>
                    </div>
                </section>

                <aside class="image-card">
                    <label for="product_image">Product image <span class="required">*</span></label>
                    <input type="file" id="product_image" name="product_image"
                           accept="image/jpeg,image/png,image/webp" required>
                    <p class="help">JPEG, PNG, or WebP. Maximum file size: 5 MB.</p>
                    <div class="image-preview" id="image-preview">
                        <img id="preview-image" alt="Preview of selected product image">
                        <div class="preview-placeholder" id="preview-placeholder">
                            <span aria-hidden="true">◇</span>
                            Your image preview will appear here
                        </div>
                    </div>
                    <p class="image-note">Use a clear photo so people can get a good look at your item.</p>
                </aside>
            </div>
        </form>
    </main>
    <script>
        const imageInput = document.getElementById("product_image");
        const previewImage = document.getElementById("preview-image");
        const previewPlaceholder = document.getElementById("preview-placeholder");
        let previewUrl = null;

        imageInput.addEventListener("change", () => {
            if (previewUrl !== null) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = null;
            }

            const file = imageInput.files && imageInput.files[0];
            if (!file) {
                previewImage.removeAttribute("src");
                previewImage.style.display = "none";
                previewPlaceholder.style.display = "block";
                return;
            }

            previewUrl = URL.createObjectURL(file);
            previewImage.src = previewUrl;
            previewImage.style.display = "block";
            previewPlaceholder.style.display = "none";
        });
    </script>
</body>
</html>
