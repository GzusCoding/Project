<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";

require_authenticated_user();
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
$transactionIdInput = $_GET["id"] ?? null;
$transactionId = is_string($transactionIdInput)
    && preg_match('/\A[1-9][0-9]*\z/', $transactionIdInput) === 1
        ? filter_var($transactionIdInput, FILTER_VALIDATE_INT)
        : false;
if ($userId === false || $userId === null || $userId <= 0 || $transactionId === false || $transactionId === null) {
    http_response_code(404);
    exit("Exchange transaction not found.");
}

$transactionColumns = [];
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
    $transactionColumns = array_column(
        $pdo->query('PRAGMA table_info("exchange_transactions")')->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
}
$paymentMethodSelect = in_array("payment_method", $transactionColumns, true)
    ? "exchange_transactions.payment_method"
    : "NULL AS payment_method";
$cashPayerSelect = in_array("cash_payer_id", $transactionColumns, true)
    ? "exchange_transactions.cash_payer_id"
    : "exchange_transactions.creator_id AS cash_payer_id";

try {
    $stmt = $pdo->prepare(
        "SELECT exchange_transactions.id, exchange_transactions.offer_id,
                exchange_transactions.creator_id, exchange_transactions.recipient_id,
        {$cashPayerSelect},
                exchange_transactions.cash_amount_minor, exchange_transactions.cash_currency,
                exchange_transactions.currency_exponent, {$paymentMethodSelect},
                exchange_transactions.payment_status, exchange_transactions.status,
                exchange_transactions.created_at,
                chat_rooms.id AS chat_room_id,
                creator.name AS creator_name, recipient.name AS recipient_name
         FROM exchange_transactions
         LEFT JOIN chat_rooms
           ON chat_rooms.exchange_transaction_id = exchange_transactions.id
         INNER JOIN users AS creator ON creator.id = exchange_transactions.creator_id
         INNER JOIN users AS recipient ON recipient.id = exchange_transactions.recipient_id
         WHERE exchange_transactions.id = :id
           AND (
                exchange_transactions.creator_id = :creator_user_id
                OR exchange_transactions.recipient_id = :recipient_user_id
           )
         LIMIT 1"
    );
    $stmt->execute([
        "id" => $transactionId,
        "creator_user_id" => $userId,
        "recipient_user_id" => $userId
    ]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Exchange transaction lookup failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Exchange transaction details are temporarily unavailable.");
}

if ($transaction === false) {
    http_response_code(404);
    exit("Exchange transaction not found.");
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
$transactionStatuses = [
    "in_progress" => "In progress",
    "disputed" => "Disputed",
    "completed" => "Completed",
    "cancelled" => "Cancelled"
];
$transactionStatuses = array_map("t", $transactionStatuses);
$paymentStatuses = [
    "not_required" => "Not required",
    "awaiting_method" => "Awaiting payment method",
    "awaiting_payment" => "Awaiting payment",
    "submitted" => "Submitted for review",
    "verified" => "Verified",
    "rejected" => "Rejected"
];
$paymentStatuses = array_map("t", $paymentStatuses);
$paymentMethods = [
    "promptpay" => "PromptPay",
    "bank_transfer" => "Bank transfer",
    "cash_on_handover" => "Cash on handover"
];
$paymentMethods = array_map("t", $paymentMethods);
try {
    $schemaObjects = $pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name IN ('payment_evidence', 'transaction_disputes')"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($schemaObjects) === 1) {
        throw new RuntimeException("Payment migration schema is incomplete.");
    }
    $paymentSchemaAvailable = count($schemaObjects) === 2;
    $paymentEvidence = [];
    $disputes = [];
    if ($paymentSchemaAvailable) {
        $evidenceStmt = $pdo->prepare(
        "SELECT payment_evidence.id, payment_evidence.payment_method,
                payment_evidence.amount_minor, payment_evidence.currency,
                payment_evidence.currency_exponent, payment_evidence.status,
                payment_evidence.submitted_at, payment_evidence.verified_at,
                payment_evidence.review_note, submitter.name AS submitter_name,
                verifier.name AS verifier_name
         FROM payment_evidence
         INNER JOIN users AS submitter ON submitter.id = payment_evidence.submitted_by
         LEFT JOIN users AS verifier ON verifier.id = payment_evidence.verified_by
         WHERE payment_evidence.transaction_id = :transaction_id
         ORDER BY payment_evidence.id ASC"
        );
        $evidenceStmt->execute(["transaction_id" => (int) $transaction["id"]]);
        $paymentEvidence = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);
        $disputeStmt = $pdo->prepare(
        "SELECT transaction_disputes.id, transaction_disputes.reported_by,
                transaction_disputes.reason, transaction_disputes.reported_at,
                transaction_disputes.resolved_by, transaction_disputes.resolution,
                transaction_disputes.resolution_reason, transaction_disputes.resolved_at,
                reporter.name AS reporter_name, resolver.name AS resolver_name
         FROM transaction_disputes
         INNER JOIN users AS reporter ON reporter.id = transaction_disputes.reported_by
         LEFT JOIN users AS resolver ON resolver.id = transaction_disputes.resolved_by
         WHERE transaction_disputes.transaction_id = :transaction_id
         ORDER BY transaction_disputes.id ASC"
        );
        $disputeStmt->execute(["transaction_id" => (int) $transaction["id"]]);
        $disputes = $disputeStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $handoverSchemaAvailable = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
        && $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'handover_confirmations'"
        )->fetchColumn() !== false;
    $handoverConfirmations = [];
    if ($handoverSchemaAvailable) {
        $handoverStmt = $pdo->prepare(
            "SELECT handover_confirmations.confirmation_type,
                    handover_confirmations.confirmer_id,
                    handover_confirmations.confirmed_at,
                    confirmer.name AS confirmer_name
             FROM handover_confirmations
             INNER JOIN users AS confirmer ON confirmer.id = handover_confirmations.confirmer_id
             WHERE handover_confirmations.transaction_id = :transaction_id
             ORDER BY handover_confirmations.confirmation_type"
        );
        $handoverStmt->execute(["transaction_id" => (int) $transaction["id"]]);
        $handoverConfirmations = $handoverStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $exchangeReviews = [];
    $myExchangeReview = null;
    $reviewSchemaAvailable = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
        && $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'reviews'"
        )->fetchColumn() !== false;
    if ($reviewSchemaAvailable) {
        $reviewStmt = $pdo->prepare(
            "SELECT reviews.reviewer_id, reviews.reviewee_id, reviews.rating,
                    reviews.comment, reviews.created_at, reviewer.name AS reviewer_name
             FROM reviews
             INNER JOIN users AS reviewer ON reviewer.id = reviews.reviewer_id
             WHERE reviews.exchange_transaction_id = :transaction_id
             ORDER BY reviews.created_at, reviews.id"
        );
        $reviewStmt->execute(["transaction_id" => (int) $transaction["id"]]);
        $exchangeReviews = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($exchangeReviews as $exchangeReview) {
            if ((int) $exchangeReview["reviewer_id"] === $userId) {
                $myExchangeReview = $exchangeReview;
                break;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Exchange transaction payment history lookup failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Payment evidence is temporarily unavailable.");
}

$formatAmount = static function (int $minor, int $exponent): string {
    if ($exponent === 0) {
        return (string) $minor;
    }
    $base = 10 ** $exponent;
    return intdiv($minor, $base) . "." . str_pad((string) ($minor % $base), $exponent, "0", STR_PAD_LEFT);
};
$isCreator = (int) $transaction["creator_id"] === $userId;
$isRecipient = (int) $transaction["recipient_id"] === $userId;
$isPayer = (int) $transaction["cash_payer_id"] === $userId;
$isPaymentReviewer = !$isPayer;
$handoverConfirmationLabels = [
    "creator_handed_over_offered_item" => [
        "label" => "Creator handed over the offered item",
        "participant" => "creator"
    ],
    "recipient_received_offered_item" => [
        "label" => "Recipient received the offered item",
        "participant" => "recipient"
    ],
    "recipient_handed_over_requested_item" => [
        "label" => "Recipient handed over the requested item",
        "participant" => "recipient"
    ],
    "creator_received_requested_item" => [
        "label" => "Creator received the requested item",
        "participant" => "creator"
    ]
];
$handoverConfirmationsByType = [];
foreach ($handoverConfirmations as $handoverConfirmation) {
    $handoverConfirmationsByType[$handoverConfirmation["confirmation_type"]] = $handoverConfirmation;
}
$isPositivePayment = (int) $transaction["cash_amount_minor"] > 0;
$paymentStatusLabel = $paymentStatuses[$transaction["payment_status"]] ?? "Unknown";
if ($transaction["status"] === "disputed") {
    $paymentStatusLabel = "Disputed - payment is not considered complete";
} elseif ($transaction["status"] === "cancelled") {
    $paymentStatusLabel = "Cancelled - payment is not considered complete";
} elseif (
    $transaction["payment_method"] === "cash_on_handover"
    && $transaction["payment_status"] === "submitted"
) {
    $paymentStatusLabel = "Awaiting confirmation from the other participant";
} elseif (
    $transaction["payment_method"] === "cash_on_handover"
    && $transaction["payment_status"] === "verified"
) {
    $paymentStatusLabel = "Receipt confirmed by the other participant";
}
$currencyFields = [
    "amount_minor" => (string) $transaction["cash_amount_minor"],
    "currency" => (string) $transaction["cash_currency"],
    "currency_exponent" => (string) $transaction["currency_exponent"]
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape(t("Exchange transaction")) ?> | Buy &amp; Exchange</title>
    <style>
    * { box-sizing: border-box; }
    body { max-width: 760px; margin: 32px auto; padding: 16px; background: #fffaf5; color: #24211f; font: 16px/1.5 Arial, sans-serif; }
    main { padding: 24px; border: 1px solid #eee5de; border-radius: 12px; background: #fff; }
    .notice { padding: 12px; border-radius: 8px; background: #fff5f2; color: #7b4e2f; }
    .payment-panel, .dispute-panel, .handover-panel, .review-panel { margin-top: 24px; padding: 16px; border: 1px solid #e8dfd8; border-radius: 10px; }
    .transaction-action-form { display: grid; gap: 8px; margin: 12px 0; }
    .transaction-action-form textarea, .transaction-action-form select, .transaction-action-form input { max-width: 100%; padding: 8px; font: inherit; }
    .transaction-action-form button { justify-self: start; padding: 9px 14px; border: 0; border-radius: 7px; background: #315d3b; color: white; font: inherit; cursor: pointer; }
    .action-state { min-height: 1.5em; color: #7b4e2f; }
    .evidence-entry, .dispute-entry { margin: 12px 0; padding: 12px; border: 1px solid #eee5de; border-radius: 8px; }
    .evidence-entry p, .dispute-entry p { margin: 4px 0; }
    @media (max-width: 520px) {
        body { margin: 0 auto; padding: 10px; }
        main { padding: 16px; }
    }
    </style>
    <link rel="stylesheet" href="assets/css/marketplace.css">
    <link rel="stylesheet" href="assets/css/chat.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <h1><?= $escape(t("Exchange transaction")) ?></h1>
    <p><?= $escape(t("Transaction status:")) ?> <?= $escape($transactionStatuses[$transaction["status"]] ?? t("Unknown")) ?></p>
    <p><?= $escape(t("Payment status:")) ?> <?= $escape(t($paymentStatusLabel)) ?></p>
    <p><?= $escape(t("Cash difference")) ?>:
        <?= (int) $transaction["cash_amount_minor"] === 0
            ? $escape(t("No cash payment required"))
            : $escape($formatAmount((int) $transaction["cash_amount_minor"], (int) $transaction["currency_exponent"]))
                . " " . $escape($transaction["cash_currency"]) . " · " . $escape(t("Paid by the offer creator")) ?>
    </p>
    <p><?= $escape(t("Accepted")) ?> <?= $escape($transaction["created_at"]) ?></p>
    <p><?= $escape(t("Participants:")) ?> <?= $escape($transaction["creator_name"]) ?> <?= $escape(t("and")) ?> <?= $escape($transaction["recipient_name"]) ?></p>
    <p class="notice"><?= $escape(t("Offer acceptance is agreement only. Product ownership has not changed. Any future ownership transfer requires completion of the applicable payment and handover requirements.")) ?></p>
    <section class="handover-panel" aria-labelledby="handover-title">
        <h2 id="handover-title"><?= $escape(t("Product handover confirmations")) ?></h2>
        <p><?= $escape(t("These confirmations record product handover only. They are separate from payment verification and do not complete the transaction or transfer ownership.")) ?></p>
        <?php if (!$handoverSchemaAvailable): ?>
            <p class="notice"><?= $escape(t("Handover confirmations are unavailable until the v6 migration is installed.")) ?></p>
        <?php else: ?>
            <?php foreach ($handoverConfirmationLabels as $type => $details): ?>
                <?php $record = $handoverConfirmationsByType[$type] ?? null; ?>
                <article class="evidence-entry">
                    <p><strong><?= $escape(t($details["label"])) ?>:</strong>
                        <?php if ($record !== null): ?>
                            <?= $escape(t("Confirmed by")) ?> <?= $escape($record["confirmer_name"]) ?>
                            <?= $escape(t("at")) ?> <?= $escape($record["confirmed_at"]) ?>
                        <?php else: ?>
                            <?= $escape(t("Awaiting confirmation")) ?>
                        <?php endif; ?>
                    </p>
                    <?php if (
                        $record === null
                        && $transaction["status"] === "in_progress"
                        && (($details["participant"] === "creator" && $isCreator)
                            || ($details["participant"] === "recipient" && $isRecipient))
                    ): ?>
                        <form class="transaction-action-form" method="post" action="handover_action.php">
                            <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                            <input type="hidden" name="transaction_id" value="<?= (int) $transaction["id"] ?>">
                            <input type="hidden" name="confirmation_type" value="<?= $escape($type) ?>">
                            <button type="submit"><?= $escape(t("Confirm handover")) ?></button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if ($transaction["status"] === "disputed"): ?>
                <p class="notice"><?= $escape(t("Handover confirmations are unavailable while this transaction is disputed.")) ?></p>
            <?php elseif (in_array($transaction["status"], ["completed", "cancelled"], true)): ?>
                <p class="notice"><?= $escape(t("This transaction is terminal; no new handover confirmations can be added.")) ?></p>
            <?php endif; ?>
            <p class="action-state" aria-live="polite"></p>
        <?php endif; ?>
    </section>
    <section class="payment-panel" aria-labelledby="payment-title">
        <h2 id="payment-title"><?= $escape(t("Payment evidence")) ?></h2>
        <?php if ($isPositivePayment): ?>
            <p><?= $escape(t("Amount:")) ?> <?= $escape($formatAmount((int) $transaction["cash_amount_minor"], (int) $transaction["currency_exponent"])) ?> <?= $escape($transaction["cash_currency"]) ?></p>
            <p><?= $escape(t("Cash payer")) ?>:
                <?= $escape((int) $transaction["cash_payer_id"] === (int) $transaction["creator_id"]
                    ? $transaction["creator_name"]
                    : $transaction["recipient_name"]) ?>
            </p>
            <p><?= $escape(t("Payment method:")) ?> <?= $escape($paymentMethods[$transaction["payment_method"]] ?? t("Not selected")) ?></p>
            <p><?= $escape(t("Payment status:")) ?> <?= $escape(t($paymentStatusLabel)) ?></p>
        <?php else: ?>
            <p><?= $escape(t("No payment method or evidence is required for a zero cash difference.")) ?></p>
        <?php endif; ?>

        <?php if ($transaction["status"] === "disputed"): ?>
            <p class="notice"><?= $escape(t("Payment actions are blocked while this transaction is disputed.")) ?></p>
        <?php elseif ($paymentSchemaAvailable && $transaction["status"] === "in_progress" && $isPayer && $isPositivePayment && $transaction["payment_status"] === "awaiting_method"): ?>
            <form class="transaction-action-form" method="post" action="payment_action.php">
                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                <input type="hidden" name="transaction_id" value="<?= (int) $transaction["id"] ?>">
                <input type="hidden" name="action" value="select_method">
                <label for="payment-method"><?= $escape(t("Choose a payment method")) ?></label>
                <select id="payment-method" name="payment_method" required>
                    <option value=""><?= $escape(t("Select one")) ?></option>
                    <option value="promptpay">PromptPay</option>
                    <option value="bank_transfer">Bank transfer</option>
                    <option value="cash_on_handover">Cash on handover</option>
                </select>
                <button type="submit"><?= $escape(t("Save payment method")) ?></button>
            </form>
        <?php elseif (
            $transaction["status"] === "in_progress"
            && $paymentSchemaAvailable
            && $isPayer
            && $isPositivePayment
            && $transaction["payment_method"] === "cash_on_handover"
            && in_array($transaction["payment_status"], ["awaiting_payment", "rejected"], true)
        ): ?>
            <p><?= $escape(t("Marking cash paid submits your statement only; the other participant must separately confirm receipt.")) ?></p>
            <form class="transaction-action-form" method="post" action="payment_action.php">
                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                <input type="hidden" name="transaction_id" value="<?= (int) $transaction["id"] ?>">
                <input type="hidden" name="action" value="cash_paid">
                <?php foreach ($currencyFields as $name => $value): ?>
                    <input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>">
                <?php endforeach; ?>
                <button type="submit"><?= $escape(t("I paid cash")) ?></button>
            </form>
        <?php elseif (
            $transaction["status"] === "in_progress"
            && $paymentSchemaAvailable
            && $isPayer
            && $isPositivePayment
            && in_array($transaction["payment_method"], ["promptpay", "bank_transfer"], true)
            && in_array($transaction["payment_status"], ["awaiting_payment", "rejected"], true)
        ): ?>
            <form class="transaction-action-form" method="post" action="payment_action.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                <input type="hidden" name="transaction_id" value="<?= (int) $transaction["id"] ?>">
                <input type="hidden" name="action" value="submit_evidence">
                <?php foreach ($currencyFields as $name => $value): ?>
                    <input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>">
                <?php endforeach; ?>
                <label for="evidence-file"><?= $escape(t("Payment slip (JPEG, PNG, or PDF; maximum 5 MB)")) ?></label>
                <input id="evidence-file" type="file" name="evidence_file" accept="image/jpeg,image/png,application/pdf" required>
                <button type="submit"><?= $escape(t($transaction["payment_status"] === "rejected" ? "Resubmit payment slip" : "Submit payment slip")) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($paymentEvidence === []): ?>
            <p>No payment evidence has been submitted.</p>
        <?php else: ?>
            <h3>Submission history</h3>
            <?php foreach ($paymentEvidence as $evidence): ?>
                <?php
                $evidenceStatusLabel = t(ucfirst($evidence["status"]));
                if ($transaction["status"] === "disputed") {
                    $evidenceStatusLabel = $evidence["status"] === "verified"
                        ? t("Recorded as verified before dispute; payment is not considered complete")
                        : t("Payment is blocked by the dispute; prior record:") . " " . t(ucfirst($evidence["status"]));
                } elseif ($transaction["status"] === "cancelled") {
                    $evidenceStatusLabel = t("Transaction cancelled; prior record:") . " " . t(ucfirst($evidence["status"]));
                } elseif ($evidence["payment_method"] === "cash_on_handover") {
                    $evidenceStatusLabel = match ($evidence["status"]) {
                        "submitted" => t("Awaiting confirmation from the other participant"),
                        "verified" => t("Receipt confirmed by the other participant"),
                        "rejected" => t("Receipt denied by the other participant"),
                        default => t(ucfirst($evidence["status"]))
                    };
                }
                ?>
                <article class="evidence-entry">
                    <p><strong><?= $escape($paymentMethods[$evidence["payment_method"]] ?? $evidence["payment_method"]) ?></strong> ·
                        <?= $escape($formatAmount((int) $evidence["amount_minor"], (int) $evidence["currency_exponent"])) ?>
                        <?= $escape($evidence["currency"]) ?> · <?= $escape($evidenceStatusLabel) ?></p>
                    <p><?= $escape(t("Submitted by")) ?> <?= $escape($evidence["submitter_name"]) ?> <?= $escape(t("at")) ?> <?= $escape($evidence["submitted_at"]) ?></p>
                    <?php if ($evidence["payment_method"] !== "cash_on_handover"): ?>
                        <p><a href="payment_evidence.php?id=<?= (int) $evidence["id"] ?>"><?= $escape(t("Download payment evidence")) ?></a></p>
                    <?php endif; ?>
                    <?php if ($evidence["verified_at"] !== null): ?>
                        <p><?= $evidence["payment_method"] === "cash_on_handover"
                                ? ($evidence["status"] === "verified" ? t("Receipt confirmed by") : t("Receipt denied by"))
                                : t("Reviewed by") ?> <?= $escape($evidence["verifier_name"]) ?> <?= $escape(t("at")) ?> <?= $escape($evidence["verified_at"]) ?></p>
                    <?php endif; ?>
                    <?php if ($evidence["review_note"] !== null): ?>
                        <p><?= $escape(t("Rejection reason")) ?>: <?= $escape($evidence["review_note"]) ?></p>
                    <?php endif; ?>
                    <?php if (
                        $isPaymentReviewer
                        && $paymentSchemaAvailable
                        && $transaction["status"] === "in_progress"
                        && $transaction["payment_status"] === "submitted"
                        && $evidence["status"] === "submitted"
                    ): ?>
                        <form class="transaction-action-form" method="post" action="payment_action.php">
                            <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                            <input type="hidden" name="transaction_id" value="<?= (int) $transaction["id"] ?>">
                            <input type="hidden" name="evidence_id" value="<?= (int) $evidence["id"] ?>">
                            <button type="submit" name="action" value="verify">
                                <?= $escape(t($evidence["payment_method"] === "cash_on_handover" ? "Confirm cash receipt" : "Verify payment")) ?>
                            </button>
                            <label for="review-note-<?= (int) $evidence["id"] ?>"><?= $escape(t("Reason if rejecting")) ?></label>
                            <textarea id="review-note-<?= (int) $evidence["id"] ?>" name="review_note" maxlength="2000" rows="2"></textarea>
                            <button type="submit" name="action" value="reject">
                                <?= $escape(t($evidence["payment_method"] === "cash_on_handover" ? "Deny cash receipt" : "Reject payment")) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        <p class="action-state" aria-live="polite"></p>
    </section>

    <section class="dispute-panel" aria-labelledby="dispute-title">
        <h2 id="dispute-title"><?= $escape(t("Dispute history")) ?></h2>
        <?php if ($paymentSchemaAvailable && $transaction["status"] === "in_progress"): ?>
            <form class="transaction-action-form" method="post" action="dispute_action.php">
                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                <input type="hidden" name="transaction_id" value="<?= (int) $transaction["id"] ?>">
                <label for="dispute-reason"><?= $escape(t("Report a dispute")) ?></label>
                <textarea id="dispute-reason" name="reason" maxlength="2000" rows="3" required></textarea>
                <button type="submit"><?= $escape(t("Report a dispute")) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($disputes === []): ?>
            <p><?= $escape(t("No disputes have been reported.")) ?></p>
        <?php else: ?>
            <?php foreach ($disputes as $dispute): ?>
                <article class="dispute-entry">
                    <p><?= $escape(t("Reported by")) ?> <?= $escape($dispute["reporter_name"]) ?> <?= $escape(t("at")) ?> <?= $escape($dispute["reported_at"]) ?></p>
                    <p><?= $escape(t("Reason:")) ?> <?= $escape($dispute["reason"]) ?></p>
                    <?php if ($dispute["resolved_at"] !== null): ?>
                        <p><?= $escape(t("Decision:")) ?> <?= $escape(t($dispute["resolution"])) ?> <?= $escape(t("by")) ?> <?= $escape($dispute["resolver_name"]) ?>
                            <?= $escape(t("at")) ?> <?= $escape($dispute["resolved_at"]) ?></p>
                        <p><?= $escape(t("Resolution reason:")) ?> <?= $escape($dispute["resolution_reason"]) ?></p>
                    <?php else: ?>
                        <p><?= $escape(t("Awaiting administrator resolution.")) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        <p class="action-state" aria-live="polite"></p>
    </section>

    <?php if ($reviewSchemaAvailable && $transaction["status"] === "completed"): ?>
        <section class="review-panel" aria-labelledby="exchange-review-title">
            <h2 id="exchange-review-title"><?= $escape(t("Ratings and reviews")) ?></h2>
            <?php foreach ($exchangeReviews as $exchangeReview): ?>
                <article class="evidence-entry">
                    <p><strong><?= $escape($exchangeReview["reviewer_name"]) ?></strong> · <?= (int) $exchangeReview["rating"] ?>/5</p>
                    <p><?= nl2br($escape($exchangeReview["comment"])) ?></p>
                </article>
            <?php endforeach; ?>
            <?php if ($myExchangeReview === null): ?>
                <form class="exchange-review-form" method="post" action="review_action.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="exchange_transaction_id" value="<?= (int) $transaction["id"] ?>">
                    <label for="exchange-review-rating"><?= $escape(t("Your rating")) ?></label>
                    <select id="exchange-review-rating" name="rating" required>
                        <option value=""><?= $escape(t("Select a rating")) ?></option>
                        <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                            <option value="<?= $rating ?>"><?= $rating ?>/5</option>
                        <?php endfor; ?>
                    </select>
                    <label for="exchange-review-comment"><?= $escape(t("Your review")) ?></label>
                    <textarea id="exchange-review-comment" name="comment" minlength="1" maxlength="2000" required></textarea>
                    <button type="submit"><?= $escape(t("Submit review")) ?></button>
                </form>
            <?php endif; ?>
            <p id="exchange-review-state" class="action-state" role="status" aria-live="polite"></p>
        </section>
    <?php endif; ?>

    <script>
        document.querySelectorAll(".transaction-action-form").forEach(form => {
            form.addEventListener("submit", async event => {
                event.preventDefault();
                const state = form.closest(".payment-panel, .dispute-panel, .handover-panel").querySelector(".action-state");
                const submitter = event.submitter;
                if (submitter && submitter.name && submitter.value) {
                    form.querySelectorAll('input[name="action"]').forEach(input => input.remove());
                }
                const data = new FormData(form);
                if (submitter && submitter.name && submitter.value) data.set(submitter.name, submitter.value);
                try {
                    const actionUrl = form.getAttribute("action");
                    if (!actionUrl) throw new Error(<?= json_encode(t("The transaction action is unavailable."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    const response = await fetch(actionUrl, {
                        method: "POST",
                        credentials: "same-origin",
                        headers: { "Accept": "application/json" },
                        body: data
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || <?= json_encode(t("The request could not be completed."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    window.location.reload();
                } catch (error) {
                    state.textContent = error.message || <?= json_encode(t("The request could not be completed."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                }
            });
            document.querySelectorAll(".exchange-review-form").forEach(form => {
                form.addEventListener("submit", async event => {
                    event.preventDefault();
                    const state = document.getElementById("exchange-review-state");
                    try {
                        const response = await fetch(form.action, {
                            method: "POST",
                            credentials: "same-origin",
                            headers: { "Accept": "application/json" },
                            body: new FormData(form)
                        });
                        const result = await response.json();
                        if (!response.ok) throw new Error(result.error || <?= json_encode(t("The review could not be saved."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                        window.location.reload();
                    } catch (error) {
                        state.textContent = error.message || <?= json_encode(t("The review could not be saved."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    }
                });
            });
        });
    </script>
    <?php if ($transaction["chat_room_id"] !== null): ?>
        <?php $canSend = in_array($transaction["status"], ["in_progress", "disputed"], true); ?>
        <section
            class="chat"
            aria-labelledby="chat-title"
            data-context-type="exchange"
            data-context-id="<?= (int) $transaction["id"] ?>"
            data-current-user-id="<?= (int) $userId ?>"
            data-csrf-token="<?= $escape(csrf_token()) ?>"
        >
            <header>
                <h2 id="chat-title"><?= $escape(t("Transaction chat")) ?></h2>
                <p><?= $escape(t("Room")) ?> #<?= (int) $transaction["chat_room_id"] ?> · <?= $escape(t($canSend ? "Messages are visible only to these transaction participants." : "This chat is read-only. Message history is preserved.")) ?></p>
            </header>
            <div class="messages" id="chat-messages" role="log" aria-live="polite" aria-relevant="additions text"></div>
            <div class="chat-state" id="chat-state" role="status"><?= $escape(t("Loading messages…")) ?></div>
            <button type="button" class="chat-retry" id="chat-retry" hidden><?= $escape(t("Retry loading messages")) ?></button>
            <form class="chat-form" id="chat-form">
                <label for="chat-message" class="visually-hidden"><?= $escape(t("Message")) ?></label>
                <textarea id="chat-message" name="message_body" maxlength="2000" rows="2" <?= $canSend ? "" : "disabled" ?>></textarea>
                <label for="chat-image"><?= $escape(t("Attach image (JPEG, PNG, or WebP · up to 5 MB)")) ?></label>
                <input id="chat-image" name="image_file" type="file"
                    accept="image/jpeg,image/png,image/webp" <?= $canSend ? "" : "disabled" ?>>
                <button type="submit" <?= $canSend ? "" : "disabled" ?>><?= $escape(t("Send")) ?></button>
            </form>
        </section>
        <script src="assets/js/chat.js" defer></script>
    <?php endif; ?>
    <p><a href="offer_detail.php?id=<?= (int) $transaction["offer_id"] ?>"><?= $escape(t("Back to offer")) ?></a></p>
</main>
</body>
</html>
