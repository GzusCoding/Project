<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/buy_order_support.php";

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Allow: POST");
    buy_order_json_response(["error" => "Method not allowed."], 405);
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    buy_order_json_response(["error" => "Buy orders are temporarily unavailable."], 503);
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    buy_order_json_response(["error" => "Authentication required."], 401);
}
if (!csrf_token_is_valid()) {
    buy_order_json_response(
        ["error" => "Your request could not be verified. Reload the page and try again."],
        400
    );
}
$orderId = buy_order_positive_id($_POST["order_id"] ?? null);
$action = $_POST["action"] ?? null;
if (
    $orderId === false
    || !is_string($action)
    || !in_array($action, [
        "dispatch", "receive", "submit_payment", "cash_paid", "verify_payment", "reject_payment"
    ], true)
) {
    buy_order_json_response(["error" => "Invalid order action."], 400);
}

session_write_close();
$transactionStarted = false;
$newStoragePath = null;
try {
    buy_order_begin($pdo);
    $transactionStarted = true;

    $stmt = $pdo->prepare(
        "SELECT id, buyer_id, seller_id, product_id, payment_method, status
         FROM buy_orders
         WHERE id = :id
           AND (buyer_id = :buyer_id OR seller_id = :seller_id)
         LIMIT 1"
    );
    $stmt->execute([
        "id" => $orderId,
        "buyer_id" => $userId,
        "seller_id" => $userId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($order === false) {
        throw new DomainException("Buy order not found.", 404);
    }
    if ($order["status"] !== "in_progress") {
        throw new DomainException("This order is already complete.", 409);
    }

    $latestPaymentStmt = $pdo->prepare(
        "SELECT id, status FROM buy_order_payment_evidence
         WHERE order_id = :order_id ORDER BY id DESC LIMIT 1"
    );
    $latestPaymentStmt->execute(["order_id" => $orderId]);
    $latestPayment = $latestPaymentStmt->fetch(PDO::FETCH_ASSOC);

    if ($action === "submit_payment" || $action === "cash_paid") {
        if ((int) $order["buyer_id"] !== $userId) {
            throw new DomainException("Only the buyer can submit payment.", 403);
        }
        if ($latestPayment !== false && $latestPayment["status"] !== "rejected") {
            throw new DomainException("Payment is already awaiting review or verified.", 409);
        }
        $storageKey = null;
        if ($action === "cash_paid") {
            if ($order["payment_method"] !== "cash_on_handover" || isset($_FILES["evidence_file"])) {
                throw new DomainException("Cash payment can be reported only for cash on handover.", 400);
            }
        } else {
            if (!in_array($order["payment_method"], ["promptpay", "bank_transfer"], true)) {
                throw new DomainException("Choose the cash confirmation action for cash on handover.", 400);
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
            $storageKey = bin2hex(random_bytes(32));
            $newStoragePath = $directory . DIRECTORY_SEPARATOR . $storageKey . ".evidence";
            if (!move_uploaded_file($upload["tmp_name"], $newStoragePath)) {
                throw new RuntimeException("The uploaded payment slip could not be stored.");
            }
            if (!chmod($newStoragePath, 0600)) {
                throw new RuntimeException("The stored payment slip could not be secured.");
            }
        }
        $stmt = $pdo->prepare(
            "INSERT INTO buy_order_payment_evidence (
                order_id, submitted_by, payment_method, evidence_storage_key
             ) VALUES (
                :order_id, :submitted_by, :payment_method, :evidence_storage_key
             )"
        );
        $stmt->execute([
            "order_id" => $orderId,
            "submitted_by" => $userId,
            "payment_method" => $order["payment_method"],
            "evidence_storage_key" => $storageKey
        ]);
        notification_create(
            $pdo,
            (int) $order["seller_id"],
            "buy_payment_submitted",
            "buy_order",
            $orderId,
            "Payment submitted for review",
            $action === "cash_paid"
                ? "The buyer says they paid cash. Confirm receipt of payment."
                : "The buyer submitted a payment slip. Review it before dispatch."
        );
        $pdo->exec("COMMIT");
        $transactionStarted = false;
        buy_order_json_response(["success" => true, "payment_status" => "submitted"]);
    }

    if ($action === "verify_payment" || $action === "reject_payment") {
        if ((int) $order["seller_id"] !== $userId) {
            throw new DomainException("Only the seller can review payment.", 403);
        }
        if ($latestPayment === false || $latestPayment["status"] !== "submitted") {
            throw new DomainException("There is no payment awaiting review.", 409);
        }
        $reviewNote = null;
        if ($action === "reject_payment") {
            $reviewNote = payment_valid_reason($_POST["review_note"] ?? null);
            if ($reviewNote === false) {
                throw new DomainException("Enter a reason for rejecting the payment.", 400);
            }
        }
        $paymentStatus = $action === "verify_payment" ? "verified" : "rejected";
        $stmt = $pdo->prepare(
            "UPDATE buy_order_payment_evidence
             SET status = :status, verified_by = :verified_by,
                 verified_at = CURRENT_TIMESTAMP, review_note = :review_note
             WHERE id = :id AND order_id = :order_id AND status = 'submitted'"
        );
        $stmt->execute([
            "status" => $paymentStatus,
            "verified_by" => $userId,
            "review_note" => $reviewNote,
            "id" => (int) $latestPayment["id"],
            "order_id" => $orderId
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("This payment was already reviewed.", 409);
        }
        notification_create(
            $pdo,
            (int) $order["buyer_id"],
            $paymentStatus === "verified" ? "buy_payment_verified" : "buy_payment_rejected",
            "buy_order",
            $orderId,
            $paymentStatus === "verified" ? "Payment confirmed" : "Payment needs attention",
            $paymentStatus === "verified"
                ? "The seller confirmed your payment. The order can proceed to dispatch."
                : "The seller rejected the payment submission. Review the reason on the order page."
        );
        $pdo->exec("COMMIT");
        $transactionStarted = false;
        buy_order_json_response(["success" => true, "payment_status" => $paymentStatus]);
    }

    $confirmationType = $action === "dispatch" ? "seller_dispatched" : "buyer_received";
    $participantId = $action === "dispatch" ? (int) $order["seller_id"] : (int) $order["buyer_id"];
    if ($participantId !== $userId) {
        throw new DomainException(
            $action === "dispatch" ? "Only the seller can confirm dispatch." : "Only the buyer can confirm receipt.",
            403
        );
    }
    if ($action === "dispatch" && ($latestPayment === false || $latestPayment["status"] !== "verified")) {
        throw new DomainException("The seller must confirm payment before dispatch.", 409);
    }
    if ($action === "receive") {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM buy_order_confirmations
             WHERE order_id = :order_id
               AND confirmation_type = 'seller_dispatched'"
        );
        $stmt->execute(["order_id" => $orderId]);
        if ($stmt->fetchColumn() === false) {
            throw new DomainException("The seller must confirm dispatch first.", 409);
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO buy_order_confirmations (
            order_id, confirmation_type, confirmer_id
         ) VALUES (:order_id, :confirmation_type, :confirmer_id)"
    );
    try {
        $stmt->execute([
            "order_id" => $orderId,
            "confirmation_type" => $confirmationType,
            "confirmer_id" => $userId
        ]);
    } catch (PDOException $e) {
        if (str_contains(strtolower($e->getMessage()), "unique")) {
            throw new DomainException("This confirmation was already recorded.", 409);
        }
        throw $e;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM buy_order_confirmations WHERE order_id = :order_id"
    );
    $stmt->execute(["order_id" => $orderId]);
    $completed = (int) $stmt->fetchColumn() === 2;
    if ($completed) {
        $stmt = $pdo->prepare(
            "UPDATE products
             SET status = 'sold'
             WHERE id = :product_id
               AND user_id = :seller_id
               AND status = 'reserved'
               AND NOT EXISTS (
                   SELECT 1 FROM product_reservations
                   WHERE product_id = :reserved_product_id
               )"
        );
        $stmt->execute([
            "product_id" => (int) $order["product_id"],
            "seller_id" => (int) $order["seller_id"],
            "reserved_product_id" => (int) $order["product_id"]
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("The purchased product could not be marked as sold.");
        }
        $stmt = $pdo->prepare(
            "UPDATE buy_orders
             SET status = 'completed', completed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'in_progress'"
        );
        $stmt->execute(["id" => $orderId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("The buy order could not be completed.");
        }
        foreach ([
            [(int) $order["buyer_id"], (int) $order["seller_id"]],
            [(int) $order["seller_id"], (int) $order["buyer_id"]]
        ] as [$recipientId, $revieweeId]) {
            $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
            $stmt->execute(["id" => $revieweeId]);
            $revieweeName = $stmt->fetchColumn();
            if (!is_string($revieweeName)) {
                throw new RuntimeException("An order participant could not be loaded for notification.");
            }
            notification_create(
                $pdo,
                $recipientId,
                "review_requested",
                "buy_order",
                $orderId,
                "Rate your completed purchase",
                "Your order is complete. Leave a rating and review for {$revieweeName}."
            );
        }
    }

    $pdo->exec("COMMIT");
    $transactionStarted = false;
    buy_order_json_response([
        "success" => true,
        "status" => $completed ? "completed" : "in_progress"
    ]);
} catch (DomainException $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    if ($newStoragePath !== null && is_file($newStoragePath) && !unlink($newStoragePath)) {
        error_log("A rejected payment evidence upload could not be removed.");
    }
    buy_order_json_response(
        ["error" => $e->getMessage()],
        $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400
    );
} catch (Throwable $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    if ($newStoragePath !== null && is_file($newStoragePath) && !unlink($newStoragePath)) {
        error_log("A failed payment evidence upload could not be removed.");
    }
    error_log("Buy order action failed (code: {$e->getCode()}).");
    buy_order_json_response(["error" => "The order could not be updated. Please try again later."], 503);
}
