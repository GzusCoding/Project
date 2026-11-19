<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/payment_support.php";

header("Cache-Control: no-store, private");
header("X-Content-Type-Options: nosniff");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
    header("Allow: GET");
    payment_json_response(["error" => "Method not allowed."], 405);
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
$evidenceId = payment_positive_id($_GET["id"] ?? null);
if ($userId === false || $userId === null || $userId <= 0) {
    payment_json_response(["error" => "Authentication required."], 401);
}
if (!user_account_is_active($pdo, (int) $userId)) {
    payment_json_response(["error" => "This account is suspended or unavailable."], 403);
}
if ($evidenceId === false) {
    payment_json_response(["error" => "Evidence not found."], 404);
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    payment_json_response(["error" => "Payment evidence is currently available only with SQLite."], 503);
}

session_write_close();
try {
    $stmt = $pdo->prepare(
        "SELECT payment_evidence.evidence_storage_key
         FROM payment_evidence
         INNER JOIN exchange_transactions
           ON exchange_transactions.id = payment_evidence.transaction_id
         WHERE payment_evidence.id = :id
           AND payment_evidence.evidence_storage_key IS NOT NULL
           AND (
               exchange_transactions.creator_id = :creator_id
               OR exchange_transactions.recipient_id = :recipient_id
           )
         LIMIT 1"
    );
    $stmt->execute([
        "id" => $evidenceId,
        "creator_id" => $userId,
        "recipient_id" => $userId
    ]);
    $storageKey = $stmt->fetchColumn();
    if (!is_string($storageKey) || preg_match('/\A[a-f0-9]{64}\z/', $storageKey) !== 1) {
        payment_json_response(["error" => "Evidence not found."], 404);
    }

    $configured = getenv("PAYMENT_EVIDENCE_DIR");
    $directory = is_string($configured) && $configured !== ""
        ? $configured
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . "private-payment-evidence";
    $resolvedDirectory = realpath($directory);
    $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? "";
    $publicRoot = is_string($documentRoot) && $documentRoot !== "" ? realpath($documentRoot) : false;
    if (
        $resolvedDirectory === false
        || !is_dir($resolvedDirectory)
        || ($publicRoot !== false && (
            $resolvedDirectory === $publicRoot
            || str_starts_with(
                $resolvedDirectory . DIRECTORY_SEPARATOR,
                rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            )
        ))
    ) {
        throw new RuntimeException("The private evidence directory is unavailable or publicly accessible.");
    }
    $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $storageKey . ".evidence";
    if (!is_file($path) || !is_readable($path)) {
        payment_json_response(["error" => "Evidence file not found."], 404);
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
    header('Content-Disposition: attachment; filename="payment-evidence"');
    header("Content-Length: " . (string) filesize($path));
    header("Content-Security-Policy: sandbox");
    if (readfile($path) === false) {
        throw new RuntimeException("The evidence file could not be delivered.");
    }
    exit;
} catch (Throwable $e) {
    error_log("Payment evidence retrieval failed (code: {$e->getCode()}).");
    payment_json_response(["error" => "Evidence is temporarily unavailable."], 503);
}
