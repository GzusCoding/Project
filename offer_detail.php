<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";

require_authenticated_user();
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
$offerIdInput = $_GET["id"] ?? null;
$offerId = is_string($offerIdInput) && preg_match('/\A[1-9][0-9]*\z/', $offerIdInput) === 1
    ? filter_var($offerIdInput, FILTER_VALIDATE_INT)
    : false;
if ($userId === false || $userId === null || $userId <= 0 || $offerId === false || $offerId === null) {
    http_response_code(404);
    exit("Offer not found.");
}

try {
    $offerColumns = [];
    foreach ($pdo->query("PRAGMA table_info(offers)") as $column) {
        $offerColumns[] = $column["name"];
    }
    $offeredPriceSelect = in_array("offered_price_minor", $offerColumns, true)
        ? "offers.offered_price_minor"
        : "NULL AS offered_price_minor";
    $targetPriceSelect = in_array("target_price_minor", $offerColumns, true)
        ? "offers.target_price_minor"
        : "NULL AS target_price_minor";
    $stmt = $pdo->prepare(
        "SELECT offers.id, offers.creator_id, offers.recipient_id, offers.cash_payer_id,
                offers.offered_product_id, offers.target_product_id, offers.cash_amount_minor,
        offers.cash_currency, offers.currency_exponent,
        {$offeredPriceSelect}, {$targetPriceSelect},
        offers.status, offers.created_at,
                offered.name AS offered_product_name, offered.description AS offered_product_description,
                offered.user_id AS offered_product_owner_id, offered.status AS offered_product_status,
                target.name AS target_product_name, target.description AS target_product_description,
                target.user_id AS target_product_owner_id, target.status AS target_product_status,
                exchange_transactions.id AS exchange_transaction_id,
                exchange_transactions.status AS transaction_status,
                exchange_transactions.payment_status AS payment_status
         FROM offers
         JOIN products AS offered ON offered.id = offers.offered_product_id
         JOIN products AS target ON target.id = offers.target_product_id
         LEFT JOIN exchange_transactions ON exchange_transactions.offer_id = offers.id
         WHERE offers.id = :id
           AND (offers.creator_id = :user_id OR offers.recipient_id = :user_id)
         LIMIT 1"
    );
    $stmt->execute(["id" => $offerId, "user_id" => $userId]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Offer detail query failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Offer details are temporarily unavailable.");
}

if ($offer === false) {
    http_response_code(404);
    exit("Offer not found.");
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
$statusLabels = [
    "pending" => "Pending",
    "accepted" => "Accepted",
    "rejected" => "Rejected",
    "cancelled" => "Cancelled",
    "superseded" => "Superseded",
    "expired" => "Expired"
];
$statusLabels = array_map("t", $statusLabels);
$transactionStatusLabels = [
    "in_progress" => "In progress",
    "disputed" => "Disputed",
    "completed" => "Completed",
    "cancelled" => "Cancelled"
];
$transactionStatusLabels = array_map("t", $transactionStatusLabels);
$paymentStatusLabels = [
    "not_required" => "Not required",
    "awaiting_method" => "Awaiting payment method",
    "awaiting_payment" => "Awaiting payment",
    "submitted" => "Submitted for review",
    "verified" => "Verified",
    "rejected" => "Rejected"
];
$paymentStatusLabels = array_map("t", $paymentStatusLabels);
$returnSection = is_string($_GET["section"] ?? null)
    && in_array($_GET["section"], ["all", "incoming", "outgoing"], true)
    ? $_GET["section"]
    : "all";
$returnStatus = is_string($_GET["status"] ?? null)
    && (in_array($_GET["status"], array_keys($statusLabels), true) || $_GET["status"] === "all")
    ? $_GET["status"]
    : "all";
$offersUrl = "my_offers.php?" . http_build_query([
    "section" => $returnSection,
    "status" => $returnStatus
]);
$success = is_string($_SESSION["offer_success"] ?? null) ? $_SESSION["offer_success"] : "";
$error = is_string($_SESSION["offer_error"] ?? null) ? $_SESSION["offer_error"] : "";
unset($_SESSION["offer_success"]);
unset($_SESSION["offer_error"]);
$cashDifference = "None";
if ((int) $offer["cash_amount_minor"] > 0) {
    $cashAmount = (string) $offer["cash_amount_minor"];
    $exponent = (int) $offer["currency_exponent"];
    if ($exponent > 0) {
        $cashAmount = str_pad($cashAmount, $exponent + 1, "0", STR_PAD_LEFT);
        $cashAmount = substr($cashAmount, 0, -$exponent) . "." . substr($cashAmount, -$exponent);
    }
    $cashDifference = $cashAmount . " " . (string) $offer["cash_currency"];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape(t("Offer details")) ?> | Buy &amp; Exchange</title>
    <style>
        body { max-width: 760px; margin: 32px auto; padding: 16px; background: #fffaf5; color: #24211f; font: 16px/1.5 Arial, sans-serif; }
        main { padding: 24px; border: 1px solid #eee5de; border-radius: 12px; background: #fff; }
        .products { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .product { padding: 14px; border: 1px solid #eee5de; border-radius: 8px; overflow-wrap: anywhere; }
        .success { padding: 12px; border-radius: 8px; background: #f0fbf3; color: #23643b; }
        .error { padding: 12px; border-radius: 8px; background: #fff5f2; color: #9f3126; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        .button { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; padding: 0 14px; border: 1px solid #eee5de; border-radius: 9px; background: white; color: inherit; font: inherit; font-size: 14px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .danger { border-color: #a63b32; color: white; background: #a63b32; }
    </style>
    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <h1><?= $escape(t("Offer details")) ?></h1>
    <?php if ($success !== ""): ?>
        <p class="success" role="status"><?= $escape(t($success)) ?></p>
    <?php endif; ?>
    <?php if ($error !== ""): ?>
        <p class="error" role="alert"><?= $escape(t($error)) ?></p>
    <?php endif; ?>
    <p><?= $escape(t("Status")) ?>: <?= $escape($statusLabels[$offer["status"]] ?? t("Unknown")) ?></p>
    <?php if ($offer["exchange_transaction_id"] !== null): ?>
        <p><?= $escape(t("Transaction status:")) ?> <?= $escape($transactionStatusLabels[$offer["transaction_status"]] ?? t("Unknown")) ?></p>
        <p><?= $escape(t("Payment status:")) ?> <?= $escape($paymentStatusLabels[$offer["payment_status"]] ?? t("Unknown")) ?></p>
        <p><?= $escape(t("Accepting an offer records agreement only. Product ownership has not changed; both products are reserved while the exchange is in progress.")) ?></p>
        <p><a href="exchange_transaction.php?id=<?= (int) $offer["exchange_transaction_id"] ?>"><?= $escape(t("View exchange transaction and chat room")) ?></a></p>
    <?php elseif ($offer["status"] === "accepted"): ?>
        <p><?= $escape(t("Transaction status:")) ?> <?= $escape(t("No transaction recorded (legacy accepted offer)")) ?></p>
        <p><?= $escape(t("Payment status:")) ?> <?= $escape(t("No payment record (legacy history)")) ?></p>
    <?php else: ?>
        <p><?= $escape(t("Transaction status:")) ?> <?= $escape(t("Not started")) ?>.</p>
        <p><?= $escape(t("Payment status:")) ?> <?= $escape(t("Not applicable (no transaction)")) ?>.</p>
    <?php endif; ?>
    <div class="products">
        <section class="product">
            <h2><?= $escape(t("Offered product")) ?></h2>
            <p><strong><?= $escape($offer["offered_product_name"]) ?></strong></p>
            <p><?= $escape($offer["offered_product_description"] ?? "") ?></p>
            <p><?= $escape(t("Current owner")) ?>: <?= $escape(t((int) $offer["offered_product_owner_id"] === (int) $offer["creator_id"] ? "Offer creator" : "Offer recipient")) ?></p>
            <p><?= $escape(t("Availability")) ?>: <?= $escape(t(ucfirst((string) $offer["offered_product_status"]))) ?></p>
        </section>
        <section class="product">
            <h2><?= $escape(t("Requested product")) ?></h2>
            <p><strong><?= $escape($offer["target_product_name"]) ?></strong></p>
            <p><?= $escape($offer["target_product_description"] ?? "") ?></p>
            <p><?= $escape(t("Current owner")) ?>: <?= $escape(t((int) $offer["target_product_owner_id"] === (int) $offer["creator_id"] ? "Offer creator" : "Offer recipient")) ?></p>
            <p><?= $escape(t("Availability")) ?>: <?= $escape(t(ucfirst((string) $offer["target_product_status"]))) ?></p>
        </section>
    </div>
    <p><?= $escape(t("Cash difference")) ?>:
        <?= $cashDifference === "None" ? $escape(t("None")) : $escape($cashDifference) ?>
        <?php if ((int) $offer["cash_amount_minor"] > 0): ?>
            — <?= $escape(t("Paid by")) ?> <?= $escape(t((int) $offer["cash_payer_id"] === (int) $offer["creator_id"] ? "Offer creator" : "Offer recipient")) ?>
        <?php endif; ?>
    </p>
    <?php if ($offer["offered_price_minor"] !== null && $offer["target_price_minor"] !== null): ?>
        <p><?= $escape(t("Estimated values when the offer was made")) ?>:
            <?= $escape(t("Offered product")) ?> <?= number_format((int) $offer["offered_price_minor"] / 100, 2) ?> THB,
            <?= $escape(t("Requested product")) ?> <?= number_format((int) $offer["target_price_minor"] / 100, 2) ?> THB
        </p>
    <?php endif; ?>
    <?php if ($offer["status"] === "pending"): ?>
        <div class="actions">
            <?php if ((int) $offer["recipient_id"] === $userId): ?>
                <form method="post" action="offer_action.php"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode(t("Accept this offer as agreement? Ownership will not change yet."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>);">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="offer_id" value="<?= (int) $offer["id"] ?>">
                    <input type="hidden" name="action" value="accept">
                    <button class="button" type="submit"><?= $escape(t("Accept offer")) ?></button>
                </form>
            <?php endif; ?>
            <?php if ((int) $offer["creator_id"] === $userId): ?>
                <form method="post" action="offer_action.php"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode(t("Cancel this pending offer?"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>);">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="offer_id" value="<?= (int) $offer["id"] ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button class="button danger" type="submit"><?= $escape(t("Cancel offer")) ?></button>
                </form>
            <?php endif; ?>
            <?php if ((int) $offer["recipient_id"] === $userId): ?>
                <form method="post" action="offer_action.php"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode(t("Reject this pending offer?"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>);">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="offer_id" value="<?= (int) $offer["id"] ?>">
                    <input type="hidden" name="action" value="reject">
                    <button class="button danger" type="submit"><?= $escape(t("Reject offer")) ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <p><a href="<?= $escape($offersUrl) ?>"><?= $escape(t("Back to my offers")) ?></a> · <a href="index.php"><?= $escape(t("Back to marketplace")) ?></a></p>
</main>
</body>
</html>
