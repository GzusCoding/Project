<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/payment_support.php";

header("Cache-Control: no-store, private");
header("X-Content-Type-Options: nosniff");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
    header("Allow: GET");
    http_response_code(405);
    exit("Method not allowed.");
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
$evidenceId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(401);
    exit("Authentication required.");
}
if (!user_account_is_active($pdo, (int) $userId)) {
    http_response_code(403);
    exit("This account is suspended or unavailable.");
}
if ($evidenceId === false || $evidenceId === null || $evidenceId <= 0) {
    http_response_code(404);
    exit("Evidence not found.");
}

try {
    $stmt = $pdo->prepare(
        "SELECT buy_order_payment_evidence.evidence_storage_key
         FROM buy_order_payment_evidence
         INNER JOIN buy_orders ON buy_orders.id = buy_order_payment_evidence.order_id
         WHERE buy_order_payment_evidence.id = :id
           AND buy_order_payment_evidence.evidence_storage_key IS NOT NULL
           AND (buy_orders.buyer_id = :buyer_id OR buy_orders.seller_id = :seller_id)
         LIMIT 1"
    );
    $stmt->execute([
        "id" => $evidenceId,
        "buyer_id" => $userId,
        "seller_id" => $userId
    ]);
    $storageKey = $stmt->fetchColumn();
    if (!is_string($storageKey) || preg_match('/\A[a-f0-9]{64}\z/', $storageKey) !== 1) {
        http_response_code(404);
        exit("Evidence not found.");
    }

    $directory = payment_store_directory();
    $path = $directory . DIRECTORY_SEPARATOR . $storageKey . ".evidence";
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit("Evidence not found.");
    }
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        throw new RuntimeException("MIME inspection is unavailable.");
    }
    $mimeType = finfo_file($fileInfo, $path);
    finfo_close($fileInfo);
    if (!is_string($mimeType) || !in_array($mimeType, [
        "image/jpeg", "image/png", "application/pdf"
    ], true)) {
        throw new RuntimeException("The stored evidence has an unexpected MIME type.");
    }
    header("Content-Type: {$mimeType}");
    header('Content-Disposition: attachment; filename="buy-order-payment-evidence"');
    header("Content-Length: " . (string) filesize($path));
    header("Content-Security-Policy: sandbox");
    readfile($path);
    exit;
} catch (Throwable $e) {
    error_log("Buy order evidence retrieval failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("Evidence is temporarily unavailable.");
}
