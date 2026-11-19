<?php

declare(strict_types=1);

require_once __DIR__ . "/notification_support.php";

function exchange_complete_if_ready(PDO $pdo, int $transactionId): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
        return false;
    }
    $migrationTable = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'"
    );
    if ($migrationTable->fetchColumn() === false) {
        return false;
    }
    $migrationStmt = $pdo->prepare(
        "SELECT 1 FROM schema_migrations WHERE version = 7"
    );
    $migrationStmt->execute();
    if ($migrationStmt->fetchColumn() === false) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT id, creator_id, recipient_id, offered_product_id, target_product_id,
                cash_amount_minor, payment_status, status
         FROM exchange_transactions
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute(["id" => $transactionId]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($transaction === false || $transaction["status"] !== "in_progress") {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM handover_confirmations
         WHERE transaction_id = :transaction_id"
    );
    $stmt->execute(["transaction_id" => $transactionId]);
    if ((int) $stmt->fetchColumn() !== 4) {
        return false;
    }

    $paymentReady = (int) $transaction["cash_amount_minor"] === 0
        ? $transaction["payment_status"] === "not_required"
        : $transaction["payment_status"] === "verified";
    if (!$paymentReady) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM transaction_disputes
         WHERE transaction_id = :transaction_id AND resolved_at IS NULL
         LIMIT 1"
    );
    $stmt->execute(["transaction_id" => $transactionId]);
    if ($stmt->fetchColumn() !== false) {
        return false;
    }

    $offeredProductId = (int) $transaction["offered_product_id"];
    $targetProductId = (int) $transaction["target_product_id"];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM product_reservations
         WHERE transaction_id = :transaction_id
           AND product_id IN (:offered_product_id, :target_product_id)"
    );
    $stmt->execute([
        "transaction_id" => $transactionId,
        "offered_product_id" => $offeredProductId,
        "target_product_id" => $targetProductId
    ]);
    if ((int) $stmt->fetchColumn() !== 2) {
        throw new RuntimeException("The exchange product reservations are inconsistent.");
    }

    $stmt = $pdo->prepare(
        "UPDATE products
         SET user_id = CASE
                WHEN id = :offered_id THEN :new_offered_owner
                ELSE :new_target_owner
             END,
             status = 'inactive'
         WHERE (
                id = :offered_match
                AND user_id = :creator_id
                AND status = 'reserved'
             )
            OR (
                id = :target_match
                AND user_id = :recipient_id
                AND status = 'reserved'
             )"
    );
    $stmt->execute([
        "offered_id" => $offeredProductId,
        "new_offered_owner" => (int) $transaction["recipient_id"],
        "new_target_owner" => (int) $transaction["creator_id"],
        "offered_match" => $offeredProductId,
        "creator_id" => (int) $transaction["creator_id"],
        "target_match" => $targetProductId,
        "recipient_id" => (int) $transaction["recipient_id"]
    ]);
    if ($stmt->rowCount() !== 2) {
        throw new RuntimeException("Both exchange products could not be transferred.");
    }

    $stmt = $pdo->prepare(
        "DELETE FROM product_reservations
         WHERE transaction_id = :transaction_id
           AND product_id IN (:offered_product_id, :target_product_id)"
    );
    $stmt->execute([
        "transaction_id" => $transactionId,
        "offered_product_id" => $offeredProductId,
        "target_product_id" => $targetProductId
    ]);
    if ($stmt->rowCount() !== 2) {
        throw new RuntimeException("The exchange reservations could not be released.");
    }

    $stmt = $pdo->prepare(
        "UPDATE exchange_transactions
         SET status = 'completed',
             completed_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = 'in_progress'"
    );
    $stmt->execute(["id" => $transactionId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException("The exchange transaction could not be completed.");
    }

    $participants = [
        [(int) $transaction["creator_id"], (int) $transaction["recipient_id"]],
        [(int) $transaction["recipient_id"], (int) $transaction["creator_id"]]
    ];
    foreach ($participants as [$recipientId, $revieweeId]) {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
        $stmt->execute(["id" => $revieweeId]);
        $revieweeName = $stmt->fetchColumn();
        if (!is_string($revieweeName)) {
            throw new RuntimeException("An exchange participant could not be loaded for notification.");
        }
        notification_create(
            $pdo,
            $recipientId,
            "review_requested",
            "exchange_transaction",
            $transactionId,
            "Rate your completed exchange",
            "Your exchange is complete. Leave a rating and review for {$revieweeName}."
        );
    }

    return true;
}
