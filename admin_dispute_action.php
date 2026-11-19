<?php

declare(strict_types=1);

require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/payment_support.php";

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
$roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$roleStmt->execute(["id" => $userId]);
if ($roleStmt->fetchColumn() !== "admin") {
    payment_json_response(["error" => "Administrator access is required."], 403);
}
if (!csrf_token_is_valid()) {
    payment_json_response(["error" => "Your request could not be verified. Reload the page and try again."], 400);
}
$transactionId = payment_positive_id($_POST["transaction_id"] ?? null);
$disputeId = payment_positive_id($_POST["dispute_id"] ?? null);
$resolution = $_POST["resolution"] ?? null;
$reason = payment_valid_reason($_POST["resolution_reason"] ?? null);
if (
    $transactionId === false
    || $disputeId === false
    || !is_string($resolution)
    || !in_array($resolution, ["in_progress", "cancelled"], true)
    || $reason === false
) {
    payment_json_response(["error" => "A valid dispute decision and reason are required."], 400);
}

session_write_close();
$transactionStarted = false;
try {
    payment_begin($pdo);
    $transactionStarted = true;
    $stmt = $pdo->prepare(
        "SELECT id, status
         FROM exchange_transactions
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute(["id" => $transactionId]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($transaction === false) {
        payment_rollback($pdo);
        $transactionStarted = false;
        payment_json_response(["error" => "Transaction not found."], 404);
    }
    if ($transaction["status"] !== "disputed") {
        throw new DomainException("Only a disputed transaction can be resolved.", 409);
    }
    $stmt = $pdo->prepare(
        "UPDATE transaction_disputes
         SET resolved_by = :resolved_by,
             resolution = :resolution,
             resolution_reason = :resolution_reason,
             resolved_at = CURRENT_TIMESTAMP
         WHERE id = :id AND transaction_id = :transaction_id AND resolved_at IS NULL"
    );
    $stmt->execute([
        "resolved_by" => $userId,
        "resolution" => $resolution,
        "resolution_reason" => $reason,
        "id" => $disputeId,
        "transaction_id" => $transactionId
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new DomainException("This dispute is no longer open.", 409);
    }
    $stmt = $pdo->prepare(
        "UPDATE exchange_transactions
         SET status = :status,
             cancelled_at = CASE WHEN :status = 'cancelled' THEN CURRENT_TIMESTAMP ELSE cancelled_at END,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = 'disputed'"
    );
    $stmt->execute([
        "status" => $resolution,
        "id" => $transactionId
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new DomainException("The transaction could not be updated.", 409);
    }
    $pdo->commit();
    $transactionStarted = false;
    payment_json_response(["success" => true, "message" => "The dispute decision and audit record were saved."]);
} catch (DomainException $e) {
    if ($transactionStarted) {
        payment_rollback($pdo);
    }
    payment_json_response(["error" => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400);
} catch (Throwable $e) {
    if ($transactionStarted) {
        payment_rollback($pdo);
    }
    error_log("Dispute resolution failed (code: {$e->getCode()}).");
    payment_json_response(["error" => "The dispute could not be resolved. Please try again later."], 503);
}
