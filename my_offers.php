<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";

require_authenticated_user();
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(403);
    exit("Authentication required.");
}

$sections = [
    "all" => "All Activity",
    "incoming" => "Incoming",
    "outgoing" => "Outgoing"
];
$sections = array_map("t", $sections);
$statuses = [
    "pending" => "Pending",
    "accepted" => "Accepted",
    "rejected" => "Rejected",
    "cancelled" => "Cancelled",
    "superseded" => "Superseded",
    "expired" => "Expired",
    "in_progress" => "In progress",
    "completed" => "Completed"
];
$statuses = array_map("t", $statuses);
$sectionInput = $_GET["section"] ?? "all";
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
$participantFilter = match ($section) {
    "incoming" => "offers.recipient_id = :user_id",
    "outgoing" => "offers.creator_id = :user_id",
    default => "(offers.creator_id = :creator_id OR offers.recipient_id = :recipient_id)"
};
$sql = "SELECT offers.id, offers.creator_id, offers.recipient_id, offers.cash_payer_id,
               offers.cash_amount_minor, offers.cash_currency, offers.currency_exponent,
               offers.status, offers.created_at,
               exchange_transactions.id AS exchange_transaction_id,
               exchange_transactions.status AS transaction_status,
               exchange_transactions.payment_status AS payment_status,
               offered.name AS offered_product_name, target.name AS target_product_name,
               creator.name AS creator_name, recipient.name AS recipient_name
        FROM offers
        JOIN products AS offered ON offered.id = offers.offered_product_id
        JOIN products AS target ON target.id = offers.target_product_id
        JOIN users AS creator ON creator.id = offers.creator_id
        JOIN users AS recipient ON recipient.id = offers.recipient_id
        LEFT JOIN exchange_transactions ON exchange_transactions.offer_id = offers.id
        WHERE {$participantFilter}";
if ($statusFilter !== "all") {
    $sql .= " AND offers.status = :status";
}
$sql .= " ORDER BY offers.created_at DESC, offers.id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $params = match ($section) {
        "incoming", "outgoing" => ["user_id" => $userId],
        default => ["creator_id" => $userId, "recipient_id" => $userId]
    };
    if ($statusFilter !== "all") {
        $params["status"] = $statusFilter;
    }
    $stmt->execute($params);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Offers list query failed (code: {$e->getCode()}).");
    $loadFailed = true;
}

$orders = [];
$ordersLoadFailed = false;
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    $ordersLoadFailed = true;
} elseif (
    $statusFilter === "all"
    || in_array($statusFilter, ["in_progress", "completed"], true)
) {
    $orderParticipantFilter = match ($section) {
        "incoming" => "buy_orders.seller_id = :user_id",
        "outgoing" => "buy_orders.buyer_id = :user_id",
        default => "(buy_orders.buyer_id = :buyer_id OR buy_orders.seller_id = :seller_id)"
    };
    $orderSql = "SELECT buy_orders.id, buy_orders.buyer_id, buy_orders.seller_id,
                        buy_orders.product_name, buy_orders.price_minor,
                        buy_orders.status, buy_orders.created_at,
                        buyer.name AS buyer_name, seller.name AS seller_name
                 FROM buy_orders
                 INNER JOIN users AS buyer ON buyer.id = buy_orders.buyer_id
                 INNER JOIN users AS seller ON seller.id = buy_orders.seller_id
                 WHERE {$orderParticipantFilter}";
    if ($statusFilter !== "all") {
        $orderSql .= " AND buy_orders.status = :status";
    }
    $orderSql .= " ORDER BY buy_orders.created_at DESC, buy_orders.id DESC";

    try {
        $stmt = $pdo->prepare($orderSql);
        $orderParams = match ($section) {
            "incoming", "outgoing" => ["user_id" => $userId],
            default => ["buyer_id" => $userId, "seller_id" => $userId]
        };
        if ($statusFilter !== "all") {
            $orderParams["status"] = $statusFilter;
        }
        $stmt->execute($orderParams);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Purchase and sale list query failed (code: {$e->getCode()}).");
        $ordersLoadFailed = true;
    }
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
$orderStatusLabels = [
    "in_progress" => "In progress",
    "completed" => "Completed"
];
$orderStatusLabels = array_map("t", $orderStatusLabels);
$filterQuery = http_build_query(["section" => $section, "status" => $statusFilter]);
?>
<!DOCTYPE html>
<html lang="<?= $escape(app_language()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fffaf5">
    <title><?= $escape(t("My offers & orders")) ?> | Buy &amp; Exchange</title>
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
        .offer, .buy-order {
            display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 14px;
            padding: 16px; border: 1px solid var(--line); border-radius: 14px; background: white;
        }
        .offer h2, .buy-order h2 { margin: 0 0 9px; font-size: 18px; overflow-wrap: anywhere; }
        .meta { flex-wrap: wrap; color: var(--muted); font-size: 13px; }
        .direction, .status { font-weight: 750; color: #55453c; }
        .notice { padding: 14px; border-radius: 10px; background: #fff5f2; color: #9f3126; }
        @media (max-width: 650px) {
            main { margin: 22px auto; }
            .offer, .buy-order { grid-template-columns: 1fr; }
            .toolbar { align-items: flex-start; flex-direction: column; }
            .tabs { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<?= language_switcher() ?>
<main>
    <header>
        <h1><?= $escape(t("My offers & orders")) ?></h1>
        <a class="button" href="index.php"><?= $escape(t("Back to marketplace")) ?></a>
    </header>
    <p class="subtitle"><?= $escape(t("Exchange offers and purchases or sales involving your account.")) ?></p>

    <nav class="tabs" aria-label="<?= $escape(t("Activity direction")) ?>">
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
        <label for="status"><?= $escape(t("Filter by status")) ?></label>
        <select id="status" name="status" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === "all" ? " selected" : "" ?>><?= $escape(t("All statuses")) ?></option>
            <?php foreach ($statuses as $statusValue => $statusLabel): ?>
                <option value="<?= $escape($statusValue) ?>"<?= $statusFilter === $statusValue ? " selected" : "" ?>>
                    <?= $escape($statusLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="button" type="submit"><?= $escape(t("Apply filter")) ?></button>
    </form>

    <?php if ($loadFailed): ?>
        <p class="notice" role="alert"><?= $escape(t("Your offers could not be loaded. Please try again later.")) ?></p>
    <?php endif; ?>
    <?php if ($ordersLoadFailed): ?>
        <p class="notice" role="alert"><?= $escape(t("Your purchases and sales could not be loaded. Please try again later.")) ?></p>
    <?php endif; ?>

    <?php if (!$loadFailed && !$ordersLoadFailed && $offers === [] && $orders === []): ?>
        <?php if ($section === "all" && $statusFilter === "all"): ?>
            <article class="offer">
                <div>
                    <h2><?= $escape(t("No offers or orders yet")) ?></h2>
                    <p><?= $escape(t("Items you buy and items sold to you will appear here, along with exchange offers.")) ?></p>
                </div>
                <a class="button" href="index.php#products"><?= $escape(t("Browse products")) ?></a>
            </article>
        <?php else: ?>
            <p>No <?= $escape(strtolower($sections[$section])) ?> activity matches this filter.</p>
            <p><a href="my_offers.php?section=all&amp;status=all"><?= $escape(t("Show all activity")) ?></a></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$loadFailed && $offers !== []): ?>
        <section class="offer-list" aria-label="<?= $escape(t("Exchange offers")) ?>">
            <h2><?= $escape(t("Exchange offers")) ?></h2>
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
                            <span class="direction">
                                <?= $escape(t((int) $offer["creator_id"] === $userId ? "Outgoing Offer" : "Incoming Offer")) ?>
                            </span>
                            <span class="status"><?= $escape(t("Offer status")) ?>: <?= $escape($statuses[$offer["status"]] ?? t("Unknown")) ?></span>
                            <?php if ($offer["exchange_transaction_id"] !== null): ?>
                                <span><?= $escape(t("Transaction status:")) ?> <?= $escape($transactionStatusLabels[$offer["transaction_status"]] ?? t("Unknown")) ?></span>
                                <span><?= $escape(t("Payment status:")) ?> <?= $escape($paymentStatusLabels[$offer["payment_status"]] ?? t("Unknown")) ?></span>
                            <?php elseif ($offer["status"] === "accepted"): ?>
                                <span><?= $escape(t("Transaction status:")) ?> <?= $escape(t("No transaction recorded (legacy)")) ?></span>
                                <span><?= $escape(t("Payment status:")) ?> <?= $escape(t("No payment record (legacy)")) ?></span>
                            <?php else: ?>
                                <span><?= $escape(t("Transaction status:")) ?> <?= $escape(t("Not started")) ?></span>
                                <span><?= $escape(t("Payment status:")) ?> <?= $escape(t("Not applicable (no transaction)")) ?></span>
                            <?php endif; ?>
                            <span><?= $escape(t("Cash difference")) ?>: <?= $escape(t($formatCashDifference($offer))) ?></span>
                            <?php if ((int) $offer["cash_amount_minor"] > 0): ?>
                                <span><?= $escape(t("Paid by")) ?>:
                                    <?= $escape((int) $offer["cash_payer_id"] === (int) $offer["creator_id"]
                                        ? $offer["creator_name"]
                                        : $offer["recipient_name"]) ?>
                                </span>
                            <?php endif; ?>
                            <time datetime="<?= $escape($offer["created_at"]) ?>"><?= $escape(t("Created")) ?> <?= $escape($offer["created_at"]) ?></time>
                        </div>
                    </div>
                    <div>
                        <a class="button" href="<?= $escape($offerUrl) ?>"><?= $escape(t("View offer")) ?></a>
                        <?php if ($offer["exchange_transaction_id"] !== null): ?>
                            <a class="button" href="exchange_transaction.php?id=<?= (int) $offer["exchange_transaction_id"] ?>"><?= $escape(t("View transaction / chat")) ?></a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (!$ordersLoadFailed && $orders !== []): ?>
        <section class="offer-list" aria-label="<?= $escape(t("Purchases and sales")) ?>">
            <h2><?= $escape(t("Purchases and sales")) ?></h2>
            <?php foreach ($orders as $order): ?>
                <?php $isBuyer = (int) $order["buyer_id"] === $userId; ?>
                <article class="buy-order">
                    <div>
                        <h2><?= $escape($order["product_name"]) ?></h2>
                        <div class="meta">
                            <span class="direction"><?= $escape(t($isBuyer ? "Purchase (you are the buyer)" : "Sale (you are the seller)")) ?></span>
                            <span class="status"><?= $escape(t("Order status")) ?>: <?= $escape($orderStatusLabels[$order["status"]] ?? t("Unknown")) ?></span>
                            <span><?= $escape(t("Buyer")) ?>: <?= $escape($order["buyer_name"]) ?></span>
                            <span><?= $escape(t("Seller")) ?>: <?= $escape($order["seller_name"]) ?></span>
                            <span><?= $escape(t("Price")) ?>: <?= number_format((int) $order["price_minor"] / 100, 2) ?> THB</span>
                            <time datetime="<?= $escape($order["created_at"]) ?>"><?= $escape(t("Created")) ?> <?= $escape($order["created_at"]) ?></time>
                        </div>
                    </div>
                    <a class="button" href="buy_order.php?id=<?= (int) $order["id"] ?>"><?= $escape(t("View order")) ?></a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
