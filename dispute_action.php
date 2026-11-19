<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/payment_support.php";

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Allow: POST");
    payment_json_response(["error" => "Method not allowed."], 405);
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    payment_json_response(["error" => "Dispute support is currently available only with SQLite."], 503);
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    payment_json_response(["error" => "Authentication required."], 401);
}
if (!user_account_is_active($pdo, (int) $userId)) {
    payment_json_response(["error" => "This account is suspended or unavailable."], 403);
}
if (!csrf_token_is_valid()) {
    payment_json_response(["error" => "Your request could not be verified. Reload the page and try again."], 400);
}
$transactionId = payment_positive_id($_POST["transaction_id"] ?? null);
$reason = payment_valid_reason($_POST["reason"] ?? null);
if ($transactionId === false || $reason === false) {
    payment_json_response(["error" => "A valid dispute reason is required."], 400);
}

session_write_close();
$transactionStarted = false;
try {
    payment_begin($pdo);
    $transactionStarted = true;
    $transaction = payment_transaction($pdo, $transactionId, $userId, true);
    if ($transaction === false) {
        payment_rollback($pdo);
        $transactionStarted = false;
        payment_json_response(["error" => "Transaction not found."], 404);
    }
    if ($transaction["status"] !== "in_progress") {
        throw new DomainException("A dispute can be reported only while the transaction is in progress.", 409);
    }
    $stmt = $pdo->prepare(
        "INSERT INTO transaction_disputes (transaction_id, reported_by, reason)
         VALUES (:transaction_id, :reported_by, :reason)"
    );
    $stmt->execute([
        "transaction_id" => $transactionId,
        "reported_by" => $userId,
        "reason" => $reason
    ]);
    $stmt = $pdo->prepare(
        "UPDATE exchange_transactions
         SET status = 'disputed', updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = 'in_progress'"
    );
    $stmt->execute(["id" => $transactionId]);
    if ($stmt->rowCount() !== 1) {
        throw new DomainException("The transaction could not be disputed.", 409);
    }
    payment_commit($pdo);
    $transactionStarted = false;
    payment_json_response(["success" => true, "message" => "The dispute was recorded. Payment remains blocked."]);
} catch (DomainException $e) {
    if ($transactionStarted) {
        payment_rollback($pdo);
    }
    payment_json_response(["error" => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400);
} catch (Throwable $e) {
    if ($transactionStarted) {
        payment_rollback($pdo);
    }
    error_log("Dispute report failed (code: {$e->getCode()}).");
    payment_json_response(["error" => "The dispute could not be recorded. Please try again later."], 503);
}
