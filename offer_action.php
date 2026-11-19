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

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method not allowed.");
}

if (!csrf_token_is_valid()) {
    http_response_code(400);
    exit("Your request could not be verified. Reload the page and try again.");
}

$offerIdInput = $_POST["offer_id"] ?? null;
$offerId = is_string($offerIdInput) && preg_match('/\A[1-9][0-9]*\z/', $offerIdInput) === 1
    ? filter_var($offerIdInput, FILTER_VALIDATE_INT)
    : false;
$action = $_POST["action"] ?? null;
if ($offerId === false || $offerId === null || !is_string($action)) {
    http_response_code(400);
    exit("Invalid offer action.");
}

$transitions = [
    "cancel" => [
        "status" => "cancelled",
        "participant" => "creator_id",
        "success" => "Your offer has been cancelled."
    ],
    "reject" => [
        "status" => "rejected",
        "participant" => "recipient_id",
        "success" => "The offer has been rejected."
    ]
];
if ($action === "accept") {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $transactionStarted = false;
    try {
        if ($driver === "sqlite") {
            $pdo->exec("BEGIN IMMEDIATE");
        } else {
            $pdo->beginTransaction();
        }
        $transactionStarted = true;

        $offerSql = "SELECT id, creator_id, recipient_id, cash_payer_id,
                            offered_product_id, target_product_id, cash_amount_minor,
                            cash_currency, currency_exponent, status
                     FROM offers
                     WHERE id = :id
                     LIMIT 1";
        if ($driver === "mysql") {
            $offerSql .= " FOR UPDATE";
        }
        $stmt = $pdo->prepare($offerSql);
        $stmt->execute(["id" => $offerId]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($offer === false) {
            throw new DomainException("Offer not found.");
        }
        if (
            (int) $offer["creator_id"] !== $userId
            && (int) $offer["recipient_id"] !== $userId
        ) {
            throw new DomainException("Offer not found.");
        }
        if ((int) $offer["recipient_id"] !== $userId) {
            throw new DomainException("Only the offer recipient can accept it.");
        }
        if ($offer["status"] !== "pending") {
            throw new DomainException("This offer is no longer pending.");
        }

        $offeredProductId = (int) $offer["offered_product_id"];
        $targetProductId = (int) $offer["target_product_id"];
        $creatorId = (int) $offer["creator_id"];
        $recipientId = (int) $offer["recipient_id"];
        if (
            $offeredProductId <= 0
            || $targetProductId <= 0
            || $offeredProductId === $targetProductId
            || $creatorId <= 0
            || $recipientId <= 0
            || $creatorId === $recipientId
            || !in_array((int) $offer["cash_payer_id"], [$creatorId, $recipientId], true)
            || (int) $offer["cash_amount_minor"] < 0
        ) {
            throw new DomainException("The offer no longer meets the exchange rules.");
        }

        $productSql = "SELECT id, user_id, status, transaction_type
                       FROM products
                       WHERE id IN (:offered_id, :target_id)
                       ORDER BY id";
        if ($driver === "mysql") {
            $productSql .= " FOR UPDATE";
        }
        $stmt = $pdo->prepare($productSql);
        $stmt->execute([
            "offered_id" => $offeredProductId,
            "target_id" => $targetProductId
        ]);
        $products = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $products[(int) $product["id"]] = $product;
        }

        $offeredProduct = $products[$offeredProductId] ?? false;
        $targetProduct = $products[$targetProductId] ?? false;
        if (
            $offeredProduct === false
            || $targetProduct === false
            || (int) $offeredProduct["user_id"] !== $creatorId
            || (int) $targetProduct["user_id"] !== $recipientId
            || $offeredProduct["status"] !== "active"
            || $targetProduct["status"] !== "active"
            || !in_array($offeredProduct["transaction_type"], ["exchange", "both"], true)
            || !in_array($targetProduct["transaction_type"], ["exchange", "both"], true)
        ) {
            throw new DomainException("The products no longer meet the exchange rules.");
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM product_reservations
             WHERE product_id IN (:offered_id, :target_id)"
        );
        $stmt->execute([
            "offered_id" => $offeredProductId,
            "target_id" => $targetProductId
        ]);
        if ((int) $stmt->fetchColumn() !== 0) {
            throw new DomainException("One or both products are already reserved.");
        }

        $paymentStatus = (int) $offer["cash_amount_minor"] === 0
            ? "not_required"
            : "awaiting_method";
        $stmt = $pdo->prepare(
            "INSERT INTO exchange_transactions (
                offer_id, creator_id, recipient_id, offered_product_id, target_product_id,
                cash_payer_id, cash_amount_minor, cash_currency, currency_exponent,
                payment_status, status
             ) VALUES (
                :offer_id, :creator_id, :recipient_id, :offered_product_id, :target_product_id,
                :cash_payer_id, :cash_amount_minor, :cash_currency, :currency_exponent,
                :payment_status, 'in_progress'
             )"
        );
        $stmt->execute([
            "offer_id" => $offerId,
            "creator_id" => $creatorId,
            "recipient_id" => $recipientId,
            "offered_product_id" => $offeredProductId,
            "target_product_id" => $targetProductId,
            "cash_payer_id" => (int) $offer["cash_payer_id"],
            "cash_amount_minor" => (int) $offer["cash_amount_minor"],
            "cash_currency" => $offer["cash_currency"],
            "currency_exponent" => $offer["currency_exponent"],
            "payment_status" => $paymentStatus
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        if ($transactionId <= 0) {
            throw new RuntimeException("The exchange transaction ID could not be created.");
        }

        $stmt = $pdo->prepare(
            "UPDATE products
             SET status = 'reserved'
             WHERE id = :id
               AND user_id = :current_owner
               AND status = 'active'
               AND transaction_type IN ('exchange', 'both')"
        );
        $stmt->execute([
            "id" => $offeredProductId,
            "current_owner" => $creatorId
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("The offered product could not be reserved.");
        }
        $stmt->execute([
            "id" => $targetProductId,
            "current_owner" => $recipientId
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("The requested product could not be reserved.");
        }

        $stmt = $pdo->prepare(
            "INSERT INTO product_reservations (product_id, transaction_id)
             VALUES (:product_id, :transaction_id)"
        );
        foreach ([$offeredProductId, $targetProductId] as $reservedProductId) {
            $stmt->execute([
                "product_id" => $reservedProductId,
                "transaction_id" => $transactionId
            ]);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO chat_rooms (exchange_transaction_id, creator_id, recipient_id)
             VALUES (:transaction_id, :creator_id, :recipient_id)"
        );
        $stmt->execute([
            "transaction_id" => $transactionId,
            "creator_id" => $creatorId,
            "recipient_id" => $recipientId
        ]);

        $stmt = $pdo->prepare(
            "UPDATE offers
             SET status = 'accepted',
                 updated_at = CURRENT_TIMESTAMP,
                 responded_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND recipient_id = :recipient_id
               AND status = 'pending'"
        );
        $stmt->execute(["id" => $offerId, "recipient_id" => $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("This offer is no longer pending.");
        }

        $stmt = $pdo->prepare(
            "UPDATE offers
             SET status = 'superseded',
                 updated_at = CURRENT_TIMESTAMP,
                 responded_at = CURRENT_TIMESTAMP
             WHERE id <> :accepted_id
               AND status = 'pending'
               AND (
                    offered_product_id IN (:offered_product, :target_product)
                    OR target_product_id IN (:offered_target, :target_target)
               )"
        );
        $stmt->execute([
            "accepted_id" => $offerId,
            "offered_product" => $offeredProductId,
            "target_product" => $targetProductId,
            "offered_target" => $offeredProductId,
            "target_target" => $targetProductId
        ]);

        if ($driver === "sqlite") {
            $pdo->exec("COMMIT");
        } else {
            $pdo->commit();
        }
        $transactionStarted = false;
        $_SESSION["offer_success"] = "Offer accepted. The exchange is agreed and both products are reserved; ownership has not changed.";
        $section = "incoming";
    } catch (DomainException $e) {
        if ($transactionStarted) {
            if ($driver === "sqlite") {
                $pdo->exec("ROLLBACK");
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        if ($e->getMessage() === "Offer not found.") {
            http_response_code(404);
            exit("Offer not found.");
        }
        $_SESSION["offer_error"] = $e->getMessage();
        $section = "incoming";
    } catch (Throwable $e) {
        if ($transactionStarted) {
            if ($driver === "sqlite") {
                $pdo->exec("ROLLBACK");
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        error_log("Offer acceptance failed (code: {$e->getCode()}).");
        http_response_code(503);
        exit("The offer could not be accepted. Please try again later.");
    }
} elseif (!isset($transitions[$action])) {
    http_response_code(400);
    exit("Invalid offer action.");
} else {
    $transition = $transitions[$action];
    try {
        $stmt = $pdo->prepare(
            "UPDATE offers
             SET status = :new_status,
                 updated_at = CURRENT_TIMESTAMP,
                 responded_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND status = 'pending'
               AND {$transition["participant"]} = :user_id"
        );
        $stmt->execute([
            "new_status" => $transition["status"],
            "id" => $offerId,
            "user_id" => $userId
        ]);

        if ($stmt->rowCount() === 1) {
            $_SESSION["offer_success"] = $transition["success"];
            $section = $action === "cancel" ? "outgoing" : "incoming";
        } else {
            $stmt = $pdo->prepare(
                "SELECT creator_id, recipient_id
                 FROM offers
                 WHERE id = :id
                   AND (creator_id = :user_id OR recipient_id = :user_id)
                 LIMIT 1"
            );
            $stmt->execute(["id" => $offerId, "user_id" => $userId]);
            $participants = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($participants === false) {
                http_response_code(404);
                exit("Offer not found.");
            }

            $_SESSION["offer_error"] = "This offer could not be changed. It may no longer be pending, or you may not be allowed to perform that action.";
            $section = (int) $participants["creator_id"] === $userId ? "outgoing" : "incoming";
        }
    } catch (PDOException $e) {
        error_log("Offer action failed (code: {$e->getCode()}).");
        http_response_code(503);
        exit("The offer could not be updated. Please try again later.");
    }
}

header("Location: offer_detail.php?" . http_build_query([
    "id" => $offerId,
    "section" => $section,
    "status" => "all"
]));
http_response_code(303);
exit;
