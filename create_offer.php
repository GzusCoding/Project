<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";

require_authenticated_user();
$creatorId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($creatorId === false || $creatorId === null || $creatorId <= 0) {
    http_response_code(403);
    exit("Your account could not be verified.");
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
$readPositiveId = static function ($value): ?int {
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $value = (string) $value;
    if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return $id === false ? null : $id;
};
$priceToMinor = static function (mixed $price): int|false {
    if (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) {
        return false;
    }
    $formatted = number_format((float) $price, 2, ".", "");
    if (preg_match('/\A[0-9]+\.[0-9]{2}\z/', $formatted) !== 1) {
        return false;
    }
    $minorText = ltrim(str_replace(".", "", $formatted), "0");
    $minor = filter_var($minorText === "" ? "0" : $minorText, FILTER_VALIDATE_INT);

    return $minor === false ? false : $minor;
};

$targetId = $readPositiveId($_SERVER["REQUEST_METHOD"] === "POST"
    ? ($_POST["target_id"] ?? null)
    : ($_GET["target_id"] ?? null));
if ($targetId === null) {
    http_response_code(404);
    exit("The requested product is not available for offers.");
}

$offeredProductId = "";
$errors = [];
$target = null;
$offeredProducts = [];
$offersAvailable = true;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $offeredProductId = is_string($_POST["offered_product_id"] ?? null)
        ? $_POST["offered_product_id"]
        : "";
    if (!csrf_token_is_valid()) {
        http_response_code(400);
        $errors[] = "Your request could not be verified. Reload the page and try again.";
    }
}

try {
    $pdo->query("SELECT id FROM offers LIMIT 0");
} catch (PDOException $e) {
    error_log("Offer creation is unavailable because the offers schema could not be read (code: {$e->getCode()}).");
    http_response_code(503);
    $offersAvailable = false;
    $errors[] = "Offers are temporarily unavailable. Please try again later.";
}

if ($offersAvailable) {
    try {
        $lookupAttempt = 0;
        while (true) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, user_id, name, description, price, transaction_type, status
                     FROM products
                     WHERE id = :id
                       AND status = 'active'
                       AND NOT EXISTS (
                            SELECT 1 FROM product_reservations
                            WHERE product_reservations.product_id = products.id
                       )
                     LIMIT 1"
                );
                $stmt->execute(["id" => $targetId]);
                $target = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare(
                    "SELECT id, name, description, price, transaction_type
                     FROM products
                     WHERE user_id = :user_id
                       AND status = 'active'
                       AND transaction_type IN ('exchange', 'both')
                       AND NOT EXISTS (
                            SELECT 1 FROM product_reservations
                            WHERE product_reservations.product_id = products.id
                       )
                     ORDER BY created_at DESC, id DESC"
                );
                $stmt->execute(["user_id" => $creatorId]);
                $offeredProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            } catch (PDOException $e) {
                $nativeCode = (int) ($e->errorInfo[1] ?? 0);
                $isSqliteLock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
                    && (in_array($nativeCode, [5, 6], true) || str_contains(strtolower($e->getMessage()), "locked"));
                if (!$isSqliteLock || $lookupAttempt >= 5) {
                    throw $e;
                }
                usleep(20000 * ($lookupAttempt + 1));
                $lookupAttempt++;
            }
        }
        if (
            $target === false
            || (int) $target["user_id"] === $creatorId
            || $target["status"] !== "active"
            || !in_array($target["transaction_type"], ["exchange", "both"], true)
        ) {
            http_response_code(404);
            $target = null;
            $errors[] = "This product is not available for exchange offers.";
        }
    } catch (PDOException $e) {
        error_log("Offer product lookup failed (code: {$e->getCode()}).");
        http_response_code(500);
        $errors[] = "Products could not be loaded. Please try again later.";
    }
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && $offersAvailable
    && csrf_token_is_valid()
    && $target !== null
) {
    $offeredId = $readPositiveId($offeredProductId);
    if ($offeredId === null) {
        $errors[] = "Choose one of your eligible active products.";
    }

    if ($offeredId !== null && $offeredId === $targetId) {
        $errors[] = "You cannot offer the same product that is being listed.";
    }

    if ($errors === []) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $writeTransactionStarted = false;
        try {
            if ($driver === "sqlite") {
                $pdo->exec("BEGIN IMMEDIATE");
            } else {
                $pdo->beginTransaction();
            }
            $writeTransactionStarted = true;
            $stmt = $pdo->prepare(
                "SELECT id, user_id, transaction_type, status, price
                 FROM products
                 WHERE id = :id
                   AND NOT EXISTS (
                        SELECT 1 FROM product_reservations
                        WHERE product_reservations.product_id = products.id
                   )
                 LIMIT 1"
            );
            $stmt->execute(["id" => $targetId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->execute(["id" => $offeredId]);
            $offered = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $target === false
                || (int) $target["user_id"] === $creatorId
                || $target["status"] !== "active"
                || !in_array($target["transaction_type"], ["exchange", "both"], true)
            ) {
                throw new DomainException("The target product is no longer available.");
            }
            if (
                $offered === false
                || (int) $offered["user_id"] !== $creatorId
                || $offered["status"] !== "active"
                || !in_array($offered["transaction_type"], ["exchange", "both"], true)
            ) {
                throw new DomainException("The offered product is not eligible.");
            }
            if ((int) $offered["id"] === (int) $target["id"]) {
                throw new DomainException("The products must be different.");
            }

            $offeredPriceMinor = $priceToMinor($offered["price"]);
            $targetPriceMinor = $priceToMinor($target["price"]);
            if ($offeredPriceMinor === false || $targetPriceMinor === false) {
                throw new DomainException("The product values cannot be used to calculate the cash difference.");
            }
            $cashAmountMinor = abs($offeredPriceMinor - $targetPriceMinor);
            $recipientId = (int) $target["user_id"];
            $cashPayerId = $offeredPriceMinor <= $targetPriceMinor ? $creatorId : $recipientId;
            $cashCurrency = $cashAmountMinor > 0 ? "THB" : null;
            $currencyExponent = $cashAmountMinor > 0 ? 2 : null;

            $stmt = $pdo->prepare(
                "INSERT INTO offers (
                    creator_id, recipient_id, cash_payer_id, offered_product_id,
                    target_product_id, cash_amount_minor, cash_currency,
                    currency_exponent, offered_price_minor, target_price_minor, status
                 ) VALUES (
                    :creator_id, :recipient_id, :cash_payer_id, :offered_product_id,
                    :target_product_id, :cash_amount_minor, :cash_currency,
                    :currency_exponent, :offered_price_minor, :target_price_minor, 'pending'
                 )"
            );
            $stmt->bindValue(":creator_id", $creatorId, PDO::PARAM_INT);
            $stmt->bindValue(":recipient_id", $recipientId, PDO::PARAM_INT);
            $stmt->bindValue(":cash_payer_id", $cashPayerId, PDO::PARAM_INT);
            $stmt->bindValue(":offered_product_id", (int) $offered["id"], PDO::PARAM_INT);
            $stmt->bindValue(":target_product_id", (int) $target["id"], PDO::PARAM_INT);
            $stmt->bindValue(":cash_amount_minor", $cashAmountMinor, PDO::PARAM_INT);
            $stmt->bindValue(":cash_currency", $cashCurrency, $cashCurrency === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(":currency_exponent", $currencyExponent, $currencyExponent === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(":offered_price_minor", $offeredPriceMinor, PDO::PARAM_INT);
            $stmt->bindValue(":target_price_minor", $targetPriceMinor, PDO::PARAM_INT);
            $stmt->execute();
            $newOfferId = (int) $pdo->lastInsertId();
            if ($driver === "sqlite") {
                $pdo->exec("COMMIT");
            } else {
                $pdo->commit();
            }
            $writeTransactionStarted = false;

            $_SESSION["offer_success"] = "Your exchange offer has been sent and is pending.";
            header("Location: offer_detail.php?id=" . $newOfferId, true, 303);
            exit;
        } catch (DomainException $e) {
            if ($writeTransactionStarted) {
                if ($driver === "sqlite") {
                    $pdo->exec("ROLLBACK");
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            $target = null;
            http_response_code(422);
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            if ($writeTransactionStarted) {
                if ($driver === "sqlite") {
                    $pdo->exec("ROLLBACK");
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            error_log("Offer insert failed (code: {$e->getCode()}).");
            http_response_code(500);
            $errors[] = "Your offer could not be saved. Please try again.";
        }
    }
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && $errors !== []
    && http_response_code() === 200
) {
    http_response_code(422);
}

$selectedOfferedId = $readPositiveId($offeredProductId);
$selectedProduct = null;
foreach ($offeredProducts as $product) {
    if ((int) $product["id"] === $selectedOfferedId) {
        $selectedProduct = $product;
        break;
    }
}
$targetPriceMinor = $target === null ? false : $priceToMinor($target["price"]);
$selectedPriceMinor = $selectedProduct === null ? false : $priceToMinor($selectedProduct["price"]);
$previewDifference = $targetPriceMinor !== false && $selectedPriceMinor !== false
    ? abs($targetPriceMinor - $selectedPriceMinor)
    : null;
$previewPayer = $previewDifference === null || $previewDifference === 0
    ? ""
    : ($selectedPriceMinor < $targetPriceMinor ? "Offer creator" : "Offer recipient");
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape(t("Make an Offer")) ?> | Buy &amp; Exchange</title>
    <style>
        body { max-width: 760px; margin: 32px auto; padding: 16px; background: #fffaf5; color: #24211f; font: 16px/1.5 Arial, sans-serif; }
        main { padding: 24px; border: 1px solid #eee5de; border-radius: 12px; background: #fff; }
        label { display: block; margin-top: 16px; font-weight: bold; }
        select, input { width: 100%; padding: 10px; margin-top: 6px; border: 1px solid #cfc7c1; border-radius: 6px; font: inherit; }
        button, .button { display: inline-block; margin-top: 20px; padding: 10px 16px; border: 0; border-radius: 6px; background: #f27b35; color: #fff; text-decoration: none; font: inherit; cursor: pointer; }
        .products { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin: 20px 0; }
        .product { padding: 14px; border: 1px solid #eee5de; border-radius: 8px; }
        .errors { padding: 12px 16px; border: 1px solid #f3c7bd; border-radius: 8px; background: #fff5f2; color: #9f3126; }
        .muted { color: #716963; }
    </style>
    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <h1><?= $escape(t("Make an Offer")) ?></h1>
    <?php if ($errors !== []): ?>
        <ul class="errors" role="alert">
            <?php foreach (array_unique($errors) as $error): ?>
                <li><?= $escape(t($error)) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($target !== null && $offersAvailable): ?>
        <div class="products">
            <section class="product" id="requested-product"
                     data-price-minor="<?= $targetPriceMinor === false ? "" : $targetPriceMinor ?>">
                <h2><?= $escape(t("Your eligible products")) ?></h2>
                <?php foreach ($offeredProducts as $product): ?>
                    <article>
                        <p><strong><?= $escape($product["name"]) ?></strong></p>
                        <p class="muted"><?= $escape($product["description"] ?? "") ?></p>
                        <p><?= $escape(t("Estimated value:")) ?> <?= $escape($product["price"]) ?> THB</p>
                        <p><?= $escape(t("Transaction type:")) ?> <?= $escape(t($product["transaction_type"])) ?></p>
                    </article>
                <?php endforeach; ?>
            </section>
            <section class="product">
                <h2><?= $escape(t("Requested product")) ?></h2>
                <p><strong><?= $escape($target["name"]) ?></strong></p>
                <p class="muted"><?= $escape($target["description"] ?? "") ?></p>
                <p><?= $escape(t("Estimated value:")) ?> <?= $escape($target["price"]) ?> THB</p>
                <p><?= $escape(t("Transaction type:")) ?> <?= $escape(t($target["transaction_type"])) ?></p>
            </section>
        </div>

        <?php if ($offeredProducts === []): ?>
            <p><?= $escape(t("You need an active product that supports exchange before you can make an offer.")) ?></p>
        <?php else: ?>
            <form method="post" action="create_offer.php">
                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                <input type="hidden" name="target_id" value="<?= (int) $target["id"] ?>">
                <label for="offered_product_id"><?= $escape(t("Choose your product")) ?></label>
                <select id="offered_product_id" name="offered_product_id" required>
                    <option value=""><?= $escape(t("Select a product")) ?></option>
                    <?php foreach ($offeredProducts as $product): ?>
                        <?php $productPriceMinor = $priceToMinor($product["price"]); ?>
                        <option value="<?= (int) $product["id"] ?>"
                                data-price-minor="<?= $productPriceMinor === false ? "" : $productPriceMinor ?>"
                            <?= (int) $product["id"] === $selectedOfferedId ? " selected" : "" ?>>
                            <?= $escape($product["name"]) ?> (<?= $escape(t($product["transaction_type"])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <p class="cash-difference-preview">
                    <?= $escape(t("Cash difference")) ?>:
                    <strong id="cash-difference">
                        <?= $previewDifference === null
                            ? $escape(t("Select a product to calculate the difference."))
                            : ($previewDifference === 0
                                ? $escape(t("None"))
                                : $escape(number_format($previewDifference / 100, 2, ".", "") . " THB")) ?>
                    </strong>
                </p>
                <p id="cash-payer" class="muted" aria-live="polite">
                    <?php if ($previewPayer !== ""): ?>
                        <?= $escape(t($previewPayer)) ?> <?= $escape(t("pays the cash difference.")) ?>
                    <?php elseif ($previewDifference === 0): ?>
                        <?= $escape(t("No cash payment is required for equal-valued items.")) ?>
                    <?php endif; ?>
                </p>
                <p class="muted"><?= $escape(t("The owner of the lower-valued item pays the difference. Values are saved with the offer.")) ?></p>
                <button type="submit"><?= $escape(t("Send offer")) ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <p><a href="<?= $target !== null ? "product_detail.php?id=" . (int) $target["id"] : "index.php" ?>"><?= $escape(t("Back")) ?></a></p>
</main>
<script>
(() => {
    const select = document.getElementById("offered_product_id");
    const target = document.getElementById("requested-product");
    const amount = document.getElementById("cash-difference");
    const payer = document.getElementById("cash-payer");
    if (!select || !target || !amount || !payer) return;

    const labels = {
        choose: <?= json_encode(t("Select a product to calculate the difference."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        none: <?= json_encode(t("None"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        equal: <?= json_encode(t("No cash payment is required for equal-valued items."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        creator: <?= json_encode(t("Offer creator"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        recipient: <?= json_encode(t("Offer recipient"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        pays: <?= json_encode(t("pays the cash difference."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    const format = new Intl.NumberFormat(document.documentElement.lang === "th" ? "th-TH" : "en-US", {
        style: "currency",
        currency: "THB",
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    const update = () => {
        const selected = select.selectedOptions[0];
        const offered = Number(selected?.dataset.priceMinor);
        const requested = Number(target.dataset.priceMinor);
        if (!selected?.value || !Number.isSafeInteger(offered) || !Number.isSafeInteger(requested)) {
            amount.textContent = labels.choose;
            payer.textContent = "";
            return;
        }
        const difference = Math.abs(offered - requested);
        amount.textContent = difference === 0 ? labels.none : format.format(difference / 100);
        payer.textContent = difference === 0
            ? labels.equal
            : `${offered < requested ? labels.creator : labels.recipient} ${labels.pays}`;
    };

    select.addEventListener("change", update);
    update();
})();
</script>
</body>
</html>
