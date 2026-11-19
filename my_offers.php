<?php

declare(strict_types=1);

require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

require_authenticated_user();
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(403);
    exit("Authentication required.");
}

$sections = [
    "incoming" => "Incoming Offers",
    "outgoing" => "Outgoing Offers"
];
$statuses = [
    "pending" => "Pending",
    "accepted" => "Accepted",
    "rejected" => "Rejected",
    "cancelled" => "Cancelled",
    "superseded" => "Superseded",
    "expired" => "Expired"
];
$sectionInput = $_GET["section"] ?? "incoming";
$statusInput = $_GET["status"] ?? "all";
if (
    !is_string($sectionInput)
    || !isset($sections[$sectionInput])
    || !is_string($statusInput)
    || ($statusInput !== "all" && !isset($statuses[$statusInput]))
) {
    http_response_code(400);
    exit("Invalid offer filter.");
}

$section = $sectionInput;
$statusFilter = $statusInput;
$offers = [];
$loadFailed = false;
$participantColumn = $section === "incoming" ? "recipient_id" : "creator_id";
$sql = "SELECT offers.id, offers.creator_id, offers.recipient_id,
               offers.cash_amount_minor, offers.cash_currency, offers.currency_exponent,
               offers.status, offers.created_at,
               exchange_transactions.id AS exchange_transaction_id,
               exchange_transactions.status AS transaction_status,
               exchange_transactions.payment_status AS payment_status,
               offered.name AS offered_product_name, target.name AS target_product_name
        FROM offers
        JOIN products AS offered ON offered.id = offers.offered_product_id
        JOIN products AS target ON target.id = offers.target_product_id
        LEFT JOIN exchange_transactions ON exchange_transactions.offer_id = offers.id
        WHERE offers.{$participantColumn} = :user_id";
if ($statusFilter !== "all") {
    $sql .= " AND offers.status = :status";
}
$sql .= " ORDER BY offers.created_at DESC, offers.id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $params = ["user_id" => $userId];
    if ($statusFilter !== "all") {
        $params["status"] = $statusFilter;
    }
    $stmt->execute($params);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Offers list query failed (code: {$e->getCode()}).");
    $loadFailed = true;
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
$formatCashDifference = static function (array $offer): string {
    $amount = (string) $offer["cash_amount_minor"];
    if ($amount === "0") {
        return "None";
    }

    $exponent = (int) $offer["currency_exponent"];
    if ($exponent > 0) {
        $paddedAmount = str_pad($amount, $exponent + 1, "0", STR_PAD_LEFT);
        $wholeAmount = substr($paddedAmount, 0, -$exponent);
        $fractionalAmount = substr($paddedAmount, -$exponent);
        $amount = $wholeAmount . "." . $fractionalAmount;
    }

    return $amount . " " . (string) $offer["cash_currency"];
};
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
$filterQuery = http_build_query(["section" => $section, "status" => $statusFilter]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fffaf5">
    <title>My offers | Buy &amp; Exchange</title>
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
        header, .toolbar, .tabs, .meta { display: flex; align-items: center; gap: 12px; }
        header, .toolbar { justify-content: space-between; }
        h1 { margin: 0 0 8px; font-size: clamp(28px, 5vw, 40px); }
        .subtitle { margin: 0 0 25px; color: var(--muted); }
        a { color: inherit; }
        .button, .tab {
            display: inline-flex; min-height: 40px; align-items: center; justify-content: center;
            padding: 0 14px; border: 1px solid var(--line); border-radius: 10px;
            background: white; font-size: 13px; font-weight: 700; text-decoration: none;
        }
        .tabs { margin-bottom: 20px; }
        .tab[aria-current="page"] { border-color: var(--orange); color: #bd4d14; }
        .toolbar { margin-bottom: 14px; }
        .toolbar label { font-weight: 700; }
        select { min-height: 40px; padding: 0 10px; border: 1px solid var(--line); border-radius: 9px; background: white; }
        .offer-list { display: grid; gap: 12px; }
        .offer {
            display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 14px;
            padding: 16px; border: 1px solid var(--line); border-radius: 14px; background: white;
        }
        .offer h2 { margin: 0 0 9px; font-size: 18px; overflow-wrap: anywhere; }
        .meta { flex-wrap: wrap; color: var(--muted); font-size: 13px; }
        .direction, .status { font-weight: 750; color: #55453c; }
        .notice { padding: 14px; border-radius: 10px; background: #fff5f2; color: #9f3126; }
        @media (max-width: 650px) {
            main { margin: 22px auto; }
            .offer { grid-template-columns: 1fr; }
            .toolbar { align-items: flex-start; flex-direction: column; }
            .tabs { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <h1>My offers</h1>
        <a class="button" href="index.php">Back to marketplace</a>
    </header>
    <p class="subtitle">Review offers involving your products.</p>

    <nav class="tabs" aria-label="Offer direction">
        <?php foreach ($sections as $sectionKey => $sectionLabel): ?>
            <a class="tab"
               href="my_offers.php?<?= $escape(http_build_query(["section" => $sectionKey, "status" => $statusFilter])) ?>"
               <?= $section === $sectionKey ? 'aria-current="page"' : "" ?>>
                <?= $escape($sectionLabel) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="toolbar" method="get" action="my_offers.php">
        <input type="hidden" name="section" value="<?= $escape($section) ?>">
        <label for="status">Filter by status</label>
        <select id="status" name="status" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === "all" ? " selected" : "" ?>>All statuses</option>
            <?php foreach ($statuses as $statusValue => $statusLabel): ?>
                <option value="<?= $escape($statusValue) ?>"<?= $statusFilter === $statusValue ? " selected" : "" ?>>
                    <?= $escape($statusLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="button" type="submit">Apply filter</button>
    </form>

    <?php if ($loadFailed): ?>
        <p class="notice" role="alert">Your offers could not be loaded. Please try again later.</p>
    <?php elseif ($offers === []): ?>
        <p>No <?= $escape(strtolower($sections[$section])) ?> match this filter.</p>
    <?php else: ?>
        <section class="offer-list" aria-label="<?= $escape($sections[$section]) ?>">
            <?php foreach ($offers as $offer): ?>
                <?php
                    $offerUrl = "offer_detail.php?" . http_build_query([
                        "id" => (int) $offer["id"],
                        "section" => $section,
                        "status" => $statusFilter
                    ]);
                ?>
                <article class="offer">
                    <div>
                        <h2><?= $escape($offer["offered_product_name"]) ?> &rarr; <?= $escape($offer["target_product_name"]) ?></h2>
                        <div class="meta">
                            <span class="direction"><?= $escape($sections[$section]) ?></span>
                            <span class="status">Offer status: <?= $escape($statuses[$offer["status"]] ?? "Unknown") ?></span>
                            <?php if ($offer["exchange_transaction_id"] !== null): ?>
                                <span>Transaction status: <?= $escape($transactionStatusLabels[$offer["transaction_status"]] ?? "Unknown") ?></span>
                                <span>Payment status: <?= $escape($paymentStatusLabels[$offer["payment_status"]] ?? "Unknown") ?></span>
                            <?php elseif ($offer["status"] === "accepted"): ?>
                                <span>Transaction status: No transaction recorded (legacy)</span>
                                <span>Payment status: No payment record (legacy)</span>
                            <?php else: ?>
                                <span>Transaction status: Not started</span>
                                <span>Payment status: Not applicable (no transaction)</span>
                            <?php endif; ?>
                            <span>Cash difference: <?= $escape($formatCashDifference($offer)) ?></span>
                            <time datetime="<?= $escape($offer["created_at"]) ?>">Created <?= $escape($offer["created_at"]) ?></time>
                        </div>
                    </div>
                    <div>
                        <a class="button" href="<?= $escape($offerUrl) ?>">View offer</a>
                        <?php if ($offer["exchange_transaction_id"] !== null): ?>
                            <a class="button" href="exchange_transaction.php?id=<?= (int) $offer["exchange_transaction_id"] ?>">View transaction / chat</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
