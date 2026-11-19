<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/buy_order_support.php";
require_authenticated_user();

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Allow: POST");
    buy_order_json_response(["error" => "Method not allowed."], 405);
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    buy_order_json_response(["error" => "Reports are temporarily unavailable."], 503);
}
$reporterId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
$productId = buy_order_positive_id($_POST["product_id"] ?? null);
$reportType = $_POST["report_type"] ?? null;
$reason = $_POST["reason"] ?? null;
if (
    $reporterId === false || $reporterId === null || $reporterId <= 0
    || $productId === false
    || !is_string($reportType)
    || !in_array($reportType, ["listing", "behavior"], true)
    || !is_string($reason)
    || preg_match('//u', $reason) !== 1
) {
    buy_order_json_response(["error" => "Enter valid report details."], 400);
}
$reasonLength = preg_match_all('/./us', $reason);
if (
    $reasonLength === false
    || $reasonLength < 1
    || $reasonLength > 2000
    || strlen($reason) > 8000
    || preg_match('/^\s*$/u', $reason) === 1
) {
    buy_order_json_response(["error" => "The report must contain 1 to 2,000 characters."], 400);
}

$transactionStarted = false;
try {
    buy_order_begin($pdo);
    $transactionStarted = true;
    $stmt = $pdo->prepare(
        "SELECT user_id, status FROM products WHERE id = :id LIMIT 1"
    );
    $stmt->execute(["id" => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($product === false || !in_array($product["status"], ["active", "reserved"], true)) {
        throw new DomainException("This listing can no longer be reported.", 404);
    }
    $reportedUserId = (int) $product["user_id"];
    if ($reportedUserId === (int) $reporterId) {
        throw new DomainException("You cannot report your own listing or account.", 403);
    }
    $stmt = $pdo->prepare(
        "INSERT INTO content_reports (
            reporter_id, reported_user_id, product_id, report_type, reason
         ) VALUES (
            :reporter_id, :reported_user_id, :product_id, :report_type, :reason
         )"
    );
    $stmt->execute([
        "reporter_id" => (int) $reporterId,
        "reported_user_id" => $reportedUserId,
        "product_id" => $reportType === "listing" ? $productId : null,
        "report_type" => $reportType,
        "reason" => trim($reason)
    ]);
    $reportId = (int) $pdo->lastInsertId();
    $pdo->exec("COMMIT");
    $transactionStarted = false;
    buy_order_json_response(["success" => true, "report_id" => $reportId], 201);
} catch (DomainException $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    buy_order_json_response(
        ["error" => $e->getMessage()],
        $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400
    );
} catch (Throwable $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    error_log("Content report submission failed (code: {$e->getCode()}).");
    buy_order_json_response(["error" => "The report could not be saved."], 503);
}
