<?php

declare(strict_types=1);

require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/handover_support.php";

header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store, private");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Allow: POST");
    handover_json_response(["error" => "Method not allowed."], 405);
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    handover_json_response(["error" => "Handover confirmation is currently available only with SQLite."], 503);
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    handover_json_response(["error" => "Authentication required."], 401);
}
if (!csrf_token_is_valid()) {
    handover_json_response(["error" => "Your request could not be verified. Reload the page and try again."], 400);
}
$transactionId = handover_positive_id($_POST["transaction_id"] ?? null);
$confirmationType = $_POST["confirmation_type"] ?? null;
$types = handover_confirmation_types();
if ($transactionId === false || !is_string($confirmationType) || !isset($types[$confirmationType])) {
    handover_json_response(["error" => "Invalid handover confirmation."], 400);
}

session_write_close();
$transactionStarted = false;
try {
    handover_begin($pdo);
    $transactionStarted = true;
    $stmt = $pdo->prepare(
        "SELECT id, creator_id, recipient_id, status
         FROM exchange_transactions
         WHERE id = :id
           AND (creator_id = :creator_id OR recipient_id = :recipient_id)
         LIMIT 1"
    );
    $stmt->execute([
        "id" => $transactionId,
        "creator_id" => $userId,
        "recipient_id" => $userId
    ]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($transaction === false) {
        throw new DomainException("Transaction not found.", 404);
    }

    $expectedParticipant = $types[$confirmationType] === "creator"
        ? (int) $transaction["creator_id"]
        : (int) $transaction["recipient_id"];
    if ($expectedParticipant !== $userId) {
        throw new DomainException("You are not authorized to confirm this handover.", 403);
    }

    $stmt = $pdo->prepare(
        "SELECT confirmation_type, confirmer_id, confirmed_at
         FROM handover_confirmations
         WHERE transaction_id = :transaction_id
           AND confirmation_type = :confirmation_type"
    );
    $stmt->execute([
        "transaction_id" => $transactionId,
        "confirmation_type" => $confirmationType
    ]);
    $confirmation = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($confirmation === false) {
        if ($transaction["status"] !== "in_progress") {
            throw new DomainException("New handover confirmations are unavailable for this transaction.", 409);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO handover_confirmations (
                transaction_id, confirmation_type, confirmer_id
             ) VALUES (
                :transaction_id, :confirmation_type, :confirmer_id
             )
             ON CONFLICT (transaction_id, confirmation_type) DO NOTHING"
        );
        $stmt->execute([
            "transaction_id" => $transactionId,
            "confirmation_type" => $confirmationType,
            "confirmer_id" => $userId
        ]);
        $stmt = $pdo->prepare(
            "SELECT confirmation_type, confirmer_id, confirmed_at
             FROM handover_confirmations
             WHERE transaction_id = :transaction_id
               AND confirmation_type = :confirmation_type"
        );
        $stmt->execute([
            "transaction_id" => $transactionId,
            "confirmation_type" => $confirmationType
        ]);
        $confirmation = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($confirmation === false) {
            throw new RuntimeException("The handover confirmation was not recorded.");
        }
    }

    $state = handover_confirmation_state($pdo, $transactionId);
    $pdo->commit();
    $transactionStarted = false;
    handover_json_response([
        "success" => true,
        "confirmation" => $confirmation,
        "confirmations" => $state
    ]);
} catch (DomainException $e) {
    if ($transactionStarted) {
        handover_rollback($pdo);
    }
    handover_json_response(
        ["error" => $e->getMessage()],
        $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400
    );
} catch (Throwable $e) {
    if ($transactionStarted) {
        handover_rollback($pdo);
    }
    error_log("Handover confirmation failed (code: {$e->getCode()}).");
    handover_json_response(["error" => "The handover confirmation could not be saved. Please try again later."], 503);
}
