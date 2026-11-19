<?php

declare(strict_types=1);

require_once __DIR__ . "/notification_support.php";
require_once __DIR__ . "/payment_support.php";

function buy_order_json_response(array $payload, int $status = 200): never
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

function buy_order_positive_id(mixed $value): int|false
{
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        return false;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return $id === false || $id <= 0 ? false : $id;
}

function buy_order_begin(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
        throw new RuntimeException("Buy orders are currently available only with SQLite.");
    }
    $pdo->exec("BEGIN IMMEDIATE");
}

function buy_order_rollback(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
        $pdo->exec("ROLLBACK");
    } elseif ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
