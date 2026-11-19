<?php

declare(strict_types=1);

function handover_json_response(array $payload, int $status = 200): never
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

function handover_positive_id(mixed $value): int|false
{
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        return false;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return $id === false || $id <= 0 ? false : $id;
}

function handover_confirmation_types(): array
{
    return [
        "creator_handed_over_offered_item" => "creator",
        "recipient_received_offered_item" => "recipient",
        "recipient_handed_over_requested_item" => "recipient",
        "creator_received_requested_item" => "creator"
    ];
}

function handover_confirmation_state(PDO $pdo, int $transactionId): array
{
    $stmt = $pdo->prepare(
        "SELECT handover_confirmations.confirmation_type,
                handover_confirmations.confirmer_id,
                handover_confirmations.confirmed_at,
                users.name AS confirmer_name
         FROM handover_confirmations
         INNER JOIN users ON users.id = handover_confirmations.confirmer_id
         WHERE handover_confirmations.transaction_id = :transaction_id
         ORDER BY handover_confirmations.confirmation_type"
    );
    $stmt->execute(["transaction_id" => $transactionId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function handover_begin(PDO $pdo): void
{
    $pdo->exec("BEGIN IMMEDIATE");
}

function handover_rollback(PDO $pdo): void
{
    $pdo->exec("ROLLBACK");
}
