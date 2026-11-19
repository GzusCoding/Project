<?php

declare(strict_types=1);

function payment_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store, private");
    header("X-Content-Type-Options: nosniff");
    echo json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    exit;
}

function payment_positive_id(mixed $value): int|false
{
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        return false;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return $id === false || $id <= 0 ? false : $id;
}

function payment_transaction(PDO $pdo, int $transactionId, int $userId, bool $lock = false): array|false
{
    $sql = "SELECT id, creator_id, recipient_id, cash_amount_minor, cash_currency,
                   currency_exponent, payment_method, payment_status, status
            FROM exchange_transactions
            WHERE id = :id
              AND (creator_id = :creator_id OR recipient_id = :recipient_id)
            LIMIT 1";
    if ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql") {
        $sql .= " FOR UPDATE";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        "id" => $transactionId,
        "creator_id" => $userId,
        "recipient_id" => $userId
    ]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    return $transaction === false ? false : $transaction;
}

function payment_begin(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
        $pdo->exec("BEGIN IMMEDIATE");
    } else {
        $pdo->beginTransaction();
    }
}

function payment_rollback(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
        $pdo->exec("ROLLBACK");
    } elseif ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function payment_terms_match(array $transaction): bool
{
    $amount = $_POST["amount_minor"] ?? null;
    $currency = $_POST["currency"] ?? null;
    $exponent = $_POST["currency_exponent"] ?? null;

    return is_string($amount)
        && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $amount) === 1
        && hash_equals((string) $transaction["cash_amount_minor"], $amount)
        && is_string($currency)
        && $currency === (string) $transaction["cash_currency"]
        && is_string($exponent)
        && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $exponent) === 1
        && hash_equals((string) $transaction["currency_exponent"], $exponent);
}

function payment_latest_evidence(PDO $pdo, int $transactionId): array|false
{
    $stmt = $pdo->prepare(
        "SELECT id, status
         FROM payment_evidence
         WHERE transaction_id = :transaction_id
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute(["transaction_id" => $transactionId]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    return $latest === false ? false : $latest;
}

function payment_store_directory(): string
{
    $configured = getenv("PAYMENT_EVIDENCE_DIR");
    $directory = is_string($configured) && $configured !== ""
        ? $configured
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . "private-payment-evidence";
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("The private evidence directory could not be created.");
    }
    $resolved = realpath($directory);
    if ($resolved === false || !is_dir($resolved) || !chmod($resolved, 0700)) {
        throw new RuntimeException("The private evidence directory could not be secured.");
    }
    $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? "";
    $publicRoot = is_string($documentRoot) && $documentRoot !== "" ? realpath($documentRoot) : false;
    if (
        $publicRoot !== false
        && ($resolved === $publicRoot || str_starts_with(
            $resolved . DIRECTORY_SEPARATOR,
            rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        ))
    ) {
        throw new RuntimeException("The evidence directory must be outside the public web root.");
    }

    return $resolved;
}

function payment_valid_reason(mixed $value): string|false
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        return false;
    }
    $length = preg_match_all('/./us', $value);
    if ($length === false || $length < 1 || $length > 2000 || preg_match('/^\s*$/u', $value) === 1) {
        return false;
    }

    return trim($value);
}
