<?php

declare(strict_types=1);

require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

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
    $stmt = $pdo->prepare(
        "SELECT offers.id, offers.creator_id, offers.recipient_id, offers.cash_payer_id,
                offers.offered_product_id, offers.target_product_id, offers.cash_amount_minor,
                offers.cash_currency, offers.currency_exponent, offers.status, offers.created_at,
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
$transactionStatusLabels = [
    "in_progress" => "In progress",
    "disputed" => "Disputed",
    "completed" => "Completed",
    "cancelled" => "Cancelled"
];
$paymentStatusLabels = [
    "not_required" => "Not required",
    "awaiting_method" => "Awaiting payment method",
    "awaiting_payment" => "Awaiting payment",
    "submitted" => "Submitted for review",
    "verified" => "Verified",
    "rejected" => "Rejected"
];
$returnSection = is_string($_GET["section"] ?? null)
    && in_array($_GET["section"], ["incoming", "outgoing"], true)
    ? $_GET["section"]
    : "incoming";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offer details | Buy &amp; Exchange</title>
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
</head>
<body>
<main>
    <h1>Offer details</h1>
    <?php if ($success !== ""): ?>
        <p class="success" role="status"><?= $escape($success) ?></p>
    <?php endif; ?>
    <?php if ($error !== ""): ?>
        <p class="error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>
    <p>Status: <?= $escape($statusLabels[$offer["status"]] ?? "Unknown") ?></p>
    <?php if ($offer["exchange_transaction_id"] !== null): ?>
        <p>Transaction status: <?= $escape($transactionStatusLabels[$offer["transaction_status"]] ?? "Unknown") ?></p>
        <p>Payment status: <?= $escape($paymentStatusLabels[$offer["payment_status"]] ?? "Unknown") ?></p>
        <p>Accepting an offer records agreement only. Product ownership has not changed; both products are reserved while the exchange is in progress.</p>
        <p><a href="exchange_transaction.php?id=<?= (int) $offer["exchange_transaction_id"] ?>">View exchange transaction and chat room</a></p>
    <?php elseif ($offer["status"] === "accepted"): ?>
        <p>Transaction status: No transaction recorded (legacy accepted offer).</p>
        <p>Payment status: No payment record (legacy history).</p>
    <?php else: ?>
        <p>Transaction status: Not started.</p>
        <p>Payment status: Not applicable (no transaction).</p>
    <?php endif; ?>
    <div class="products">
        <section class="product">
            <h2>Offered product</h2>
            <p><strong><?= $escape($offer["offered_product_name"]) ?></strong></p>
            <p><?= $escape($offer["offered_product_description"] ?? "") ?></p>
            <p>Current owner: <?= (int) $offer["offered_product_owner_id"] === (int) $offer["creator_id"] ? "Offer creator" : "Offer recipient" ?></p>
            <p>Availability: <?= $escape(ucfirst((string) $offer["offered_product_status"])) ?></p>
        </section>
        <section class="product">
            <h2>Requested product</h2>
            <p><strong><?= $escape($offer["target_product_name"]) ?></strong></p>
            <p><?= $escape($offer["target_product_description"] ?? "") ?></p>
            <p>Current owner: <?= (int) $offer["target_product_owner_id"] === (int) $offer["creator_id"] ? "Offer creator" : "Offer recipient" ?></p>
            <p>Availability: <?= $escape(ucfirst((string) $offer["target_product_status"])) ?></p>
        </section>
    </div>
    <p>Cash difference:
        <?php if ((int) $offer["cash_amount_minor"] === 0): ?>
            None
        <?php else: ?>
            <?= (int) $offer["cash_amount_minor"] ?> <?= $escape($offer["cash_currency"]) ?> minor units
            (<?= (int) $offer["currency_exponent"] ?> decimal places), paid by the offer creator
        <?php endif; ?>
    </p>
    <?php if ($offer["status"] === "pending"): ?>
        <div class="actions">
            <?php if ((int) $offer["recipient_id"] === $userId): ?>
                <form method="post" action="offer_action.php"
                      onsubmit="return confirm('Accept this offer as agreement? Ownership will not change yet.');">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="offer_id" value="<?= (int) $offer["id"] ?>">
                    <input type="hidden" name="action" value="accept">
                    <button class="button" type="submit">Accept offer</button>
                </form>
            <?php endif; ?>
            <?php if ((int) $offer["creator_id"] === $userId): ?>
                <form method="post" action="offer_action.php"
                      onsubmit="return confirm('Cancel this pending offer?');">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="offer_id" value="<?= (int) $offer["id"] ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button class="button danger" type="submit">Cancel offer</button>
                </form>
            <?php endif; ?>
            <?php if ((int) $offer["recipient_id"] === $userId): ?>
                <form method="post" action="offer_action.php"
                      onsubmit="return confirm('Reject this pending offer?');">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="offer_id" value="<?= (int) $offer["id"] ?>">
                    <input type="hidden" name="action" value="reject">
                    <button class="button danger" type="submit">Reject offer</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <p><a href="<?= $escape($offersUrl) ?>">Back to my offers</a> · <a href="index.php">Back to marketplace</a></p>
</main>
</body>
</html>
