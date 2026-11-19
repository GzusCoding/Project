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
    buy_order_json_response(["error" => "Reviews are temporarily unavailable."], 503);
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    buy_order_json_response(["error" => "Authentication required."], 401);
}
if (!user_account_is_active($pdo, (int) $userId)) {
    buy_order_json_response(["error" => "This account is suspended or unavailable."], 403);
}
if (!csrf_token_is_valid()) {
    buy_order_json_response(
        ["error" => "Your request could not be verified. Reload the page and try again."],
        400
    );
}

$orderId = buy_order_positive_id($_POST["order_id"] ?? null);
$exchangeId = buy_order_positive_id($_POST["exchange_transaction_id"] ?? null);
$ratingInput = $_POST["rating"] ?? null;
$comment = $_POST["comment"] ?? null;
if (
    ($orderId === false) === ($exchangeId === false)
    || !is_string($ratingInput)
    || preg_match('/\A[1-5]\z/', $ratingInput) !== 1
    || !is_string($comment)
    || preg_match('//u', $comment) !== 1
) {
    buy_order_json_response(["error" => "Enter a valid rating and review."], 400);
}
$characterCount = preg_match_all('/./us', $comment);
if (
    $characterCount === false
    || $characterCount < 1
    || $characterCount > 2000
    || strlen($comment) > 8000
    || preg_match('/^\s*$/u', $comment) === 1
) {
    buy_order_json_response(
        ["error" => "Reviews must contain 1 to 2,000 characters."],
        400
    );
}

$transactionStarted = false;
try {
    buy_order_begin($pdo);
    $transactionStarted = true;
    if ($orderId !== false) {
        $stmt = $pdo->prepare(
            "SELECT buyer_id, seller_id
             FROM buy_orders
             WHERE id = :id AND status = 'completed'
               AND (buyer_id = :buyer_id OR seller_id = :seller_id)
             LIMIT 1"
        );
        $stmt->execute([
            "id" => $orderId,
            "buyer_id" => $userId,
            "seller_id" => $userId
        ]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        $transactionColumn = "order_id";
        $transactionValue = $orderId;
    } else {
        $stmt = $pdo->prepare(
            "SELECT creator_id, recipient_id
             FROM exchange_transactions
             WHERE id = :id AND status = 'completed'
               AND (creator_id = :creator_id OR recipient_id = :recipient_id)
             LIMIT 1"
        );
        $stmt->execute([
            "id" => $exchangeId,
            "creator_id" => $userId,
            "recipient_id" => $userId
        ]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        $transactionColumn = "exchange_transaction_id";
        $transactionValue = $exchangeId;
    }
    if ($transaction === false) {
        throw new DomainException("Only a participant in a completed transaction can review.", 403);
    }
    $firstParticipant = $orderId !== false ? "buyer_id" : "creator_id";
    $secondParticipant = $orderId !== false ? "seller_id" : "recipient_id";
    $revieweeId = (int) $transaction[$firstParticipant] === $userId
        ? (int) $transaction[$secondParticipant]
        : (int) $transaction[$firstParticipant];

    $stmt = $pdo->prepare(
        "INSERT INTO reviews (
            {$transactionColumn}, reviewer_id, reviewee_id, rating, comment
         ) VALUES (
            :transaction_id, :reviewer_id, :reviewee_id, :rating, :comment
         )"
    );
    $stmt->execute([
        "transaction_id" => $transactionValue,
        "reviewer_id" => $userId,
        "reviewee_id" => $revieweeId,
        "rating" => (int) $ratingInput,
        "comment" => trim($comment)
    ]);
    $reviewId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
    $stmt->execute(["id" => $userId]);
    $reviewerName = $stmt->fetchColumn();
    if (!is_string($reviewerName)) {
        throw new RuntimeException("The reviewer could not be loaded for notification.");
    }
    notification_create(
        $pdo,
        $revieweeId,
        "review_received",
        $orderId !== false ? "buy_order" : "exchange_transaction",
        $transactionValue,
        "You received a new review",
        "{$reviewerName} gave you a {$ratingInput}/5 rating."
    );
    $pdo->exec("COMMIT");
    $transactionStarted = false;
    buy_order_json_response(["success" => true, "review_id" => $reviewId], 201);
} catch (DomainException $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    buy_order_json_response(
        ["error" => $e->getMessage()],
        $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400
    );
} catch (PDOException $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    if (str_contains(strtolower($e->getMessage()), "unique")) {
        buy_order_json_response(["error" => "You already reviewed this transaction."], 409);
    }
    error_log("Review submission failed (code: {$e->getCode()}).");
    buy_order_json_response(["error" => "The review could not be saved."], 503);
} catch (Throwable $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    error_log("Review submission failed (code: {$e->getCode()}).");
    buy_order_json_response(["error" => "The review could not be saved."], 503);
}
