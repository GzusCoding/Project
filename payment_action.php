<?php

declare(strict_types=1);

require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/payment_support.php";

header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store, private");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Allow: POST");
    payment_json_response(["error" => "Method not allowed."], 405);
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    payment_json_response(["error" => "Payment evidence is currently available only with SQLite."], 503);
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    payment_json_response(["error" => "Authentication required."], 401);
}
if (!csrf_token_is_valid()) {
    payment_json_response(["error" => "Your request could not be verified. Reload the page and try again."], 400);
}
$transactionId = payment_positive_id($_POST["transaction_id"] ?? null);
$action = $_POST["action"] ?? null;
if ($transactionId === false || !is_string($action)) {
    payment_json_response(["error" => "Invalid payment action."], 400);
}

session_write_close();
$transactionStarted = false;
$newStoragePath = null;
try {
    payment_begin($pdo);
    $transactionStarted = true;
    $transaction = payment_transaction($pdo, $transactionId, $userId, true);
    if ($transaction === false) {
        payment_rollback($pdo);
        $transactionStarted = false;
        payment_json_response(["error" => "Transaction not found."], 404);
    }

    if ($action === "select_method") {
        if ((int) $transaction["creator_id"] !== $userId) {
            throw new DomainException("Only the transaction creator can select the payment method.", 403);
        }
        if (
            $transaction["status"] !== "in_progress"
            || (int) $transaction["cash_amount_minor"] <= 0
            || $transaction["payment_status"] !== "awaiting_method"
            || $transaction["payment_method"] !== null
        ) {
            throw new DomainException("The payment method can no longer be selected.", 409);
        }
        $method = $_POST["payment_method"] ?? null;
        if (!is_string($method) || !in_array($method, [
            "promptpay", "bank_transfer", "cash_on_handover"
        ], true)) {
            throw new DomainException("Choose one of the approved payment methods.", 400);
        }
        $stmt = $pdo->prepare(
            "UPDATE exchange_transactions
             SET payment_method = :payment_method,
                 payment_status = 'awaiting_payment',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND status = 'in_progress'
               AND payment_status = 'awaiting_method'
               AND payment_method IS NULL"
        );
        $stmt->execute(["payment_method" => $method, "id" => $transactionId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("The payment method could not be selected.", 409);
        }
    } elseif ($action === "submit_evidence" || $action === "cash_paid") {
        if ((int) $transaction["creator_id"] !== $userId) {
            throw new DomainException("Only the transaction creator can submit payment.", 403);
        }
        if ($transaction["status"] !== "in_progress") {
            throw new DomainException("Payment is unavailable while this transaction is not in progress.", 409);
        }
        if ((int) $transaction["cash_amount_minor"] <= 0 || $transaction["payment_method"] === null) {
            throw new DomainException("This transaction does not have a payable amount or selected method.", 409);
        }
        if (!payment_terms_match($transaction)) {
            throw new DomainException("The amount, currency, or exponent does not match the transaction.", 400);
        }
        if (!in_array($transaction["payment_status"], ["awaiting_payment", "rejected"], true)) {
            throw new DomainException("Payment cannot be submitted in the current state.", 409);
        }
        if (
            $transaction["payment_status"] === "rejected"
            && (($latest = payment_latest_evidence($pdo, $transactionId)) === false
                || $latest["status"] !== "rejected")
        ) {
            throw new DomainException("A new submission is allowed only after the previous submission was rejected.", 409);
        }

        $evidenceKey = null;
        if ($action === "submit_evidence") {
            if (!in_array($transaction["payment_method"], ["promptpay", "bank_transfer"], true)) {
                throw new DomainException("A file is not accepted for this payment method.", 400);
            }
            $upload = $_FILES["evidence_file"] ?? null;
            if (!is_array($upload) || !isset($upload["error"], $upload["tmp_name"])) {
                throw new DomainException("Select a payment slip to submit.", 400);
            }
            if ((int) $upload["error"] !== UPLOAD_ERR_OK) {
                throw new DomainException(
                    (int) $upload["error"] === UPLOAD_ERR_INI_SIZE
                        || (int) $upload["error"] === UPLOAD_ERR_FORM_SIZE
                        ? "The payment slip exceeds the upload size limit."
                        : "The payment slip could not be uploaded.",
                    400
                );
            }
            if (
                !is_string($upload["tmp_name"])
                || !is_uploaded_file($upload["tmp_name"])
                || !function_exists("finfo_open")
            ) {
                throw new DomainException("The uploaded payment slip could not be inspected.", 400);
            }
            $size = filesize($upload["tmp_name"]);
            if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
                throw new DomainException("Payment slips must be between 1 byte and 5 MB.", 400);
            }
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($fileInfo === false) {
                throw new RuntimeException("MIME inspection is unavailable.");
            }
            $mimeType = finfo_file($fileInfo, $upload["tmp_name"]);
            finfo_close($fileInfo);
            if (!is_string($mimeType) || !in_array($mimeType, [
                "image/jpeg", "image/png", "application/pdf"
            ], true)) {
                throw new DomainException("Payment slips must be a JPEG, PNG, or PDF file.", 400);
            }
            $directory = payment_store_directory();
            $evidenceKey = bin2hex(random_bytes(32));
            $newStoragePath = $directory . DIRECTORY_SEPARATOR . $evidenceKey . ".evidence";
            if (!move_uploaded_file($upload["tmp_name"], $newStoragePath)) {
                throw new RuntimeException("The uploaded payment slip could not be stored.");
            }
            if (!chmod($newStoragePath, 0600)) {
                throw new RuntimeException("The stored payment slip could not be secured.");
            }
        } else {
            if ($transaction["payment_method"] !== "cash_on_handover") {
                throw new DomainException("Cash payment can be marked only for cash on handover.", 400);
            }
            if (isset($_FILES["evidence_file"])) {
                throw new DomainException("Cash payment does not accept a file upload.", 400);
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO payment_evidence (
                transaction_id, submitted_by, payment_method, amount_minor, currency,
                currency_exponent, evidence_storage_key, status
             ) VALUES (
                :transaction_id, :submitted_by, :payment_method, :amount_minor, :currency,
                :currency_exponent, :evidence_storage_key, 'submitted'
             )"
        );
        $stmt->execute([
            "transaction_id" => $transactionId,
            "submitted_by" => $userId,
            "payment_method" => $transaction["payment_method"],
            "amount_minor" => (int) $transaction["cash_amount_minor"],
            "currency" => $transaction["cash_currency"],
            "currency_exponent" => (int) $transaction["currency_exponent"],
            "evidence_storage_key" => $evidenceKey
        ]);
        $stmt = $pdo->prepare(
            "UPDATE exchange_transactions
             SET payment_status = 'submitted', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'in_progress'
               AND payment_status IN ('awaiting_payment', 'rejected')"
        );
        $stmt->execute(["id" => $transactionId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("Payment could not be submitted in the current state.", 409);
        }
    } elseif ($action === "verify" || $action === "reject") {
        if ((int) $transaction["recipient_id"] !== $userId) {
            throw new DomainException("Only the transaction recipient can review payment.", 403);
        }
        if ($transaction["status"] !== "in_progress" || $transaction["payment_status"] !== "submitted") {
            throw new DomainException("Payment review is unavailable in the current state.", 409);
        }
        $evidenceId = payment_positive_id($_POST["evidence_id"] ?? null);
        if ($evidenceId === false) {
            throw new DomainException("Invalid payment evidence.", 400);
        }
        $stmt = $pdo->prepare(
            "SELECT id, payment_method, status
             FROM payment_evidence
             WHERE id = :id AND transaction_id = :transaction_id"
        );
        $stmt->execute(["id" => $evidenceId, "transaction_id" => $transactionId]);
        $evidence = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($evidence === false || $evidence["status"] !== "submitted") {
            throw new DomainException("This payment evidence is no longer awaiting review.", 409);
        }
        $reviewNote = null;
        if ($action === "reject") {
            $reviewNote = payment_valid_reason($_POST["review_note"] ?? null);
            if ($reviewNote === false) {
                throw new DomainException("A reason is required to reject payment.", 400);
            }
        }
        $nextPaymentStatus = $action === "verify" ? "verified" : "rejected";
        $stmt = $pdo->prepare(
            "UPDATE payment_evidence
             SET status = :status, verified_by = :verified_by,
                 verified_at = CURRENT_TIMESTAMP, review_note = :review_note
             WHERE id = :id AND transaction_id = :transaction_id AND status = 'submitted'"
        );
        $stmt->execute([
            "status" => $nextPaymentStatus,
            "verified_by" => $userId,
            "review_note" => $reviewNote,
            "id" => $evidenceId,
            "transaction_id" => $transactionId
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("This payment evidence is no longer awaiting review.", 409);
        }
        $stmt = $pdo->prepare(
            "UPDATE exchange_transactions
             SET payment_status = :payment_status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'in_progress' AND payment_status = 'submitted'"
        );
        $stmt->execute([
            "payment_status" => $nextPaymentStatus,
            "id" => $transactionId
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("Payment review could not be saved.", 409);
        }
    } else {
        throw new DomainException("Invalid payment action.", 400);
    }

    $pdo->commit();
    $transactionStarted = false;
    payment_json_response(["success" => true, "message" => "Payment update saved."]);
} catch (DomainException $e) {
    if ($transactionStarted) {
        payment_rollback($pdo);
    }
    if ($newStoragePath !== null && is_file($newStoragePath)) {
        unlink($newStoragePath);
    }
    payment_json_response(["error" => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400);
} catch (Throwable $e) {
    if ($transactionStarted) {
        payment_rollback($pdo);
    }
    if ($newStoragePath !== null && is_file($newStoragePath)) {
        unlink($newStoragePath);
    }
    error_log("Payment action failed (code: {$e->getCode()}).");
    payment_json_response(["error" => "Payment could not be updated. Please try again later."], 503);
}
