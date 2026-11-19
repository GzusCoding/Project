<?php

declare(strict_types=1);

function notification_schema_available(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
        return false;
    }

    $stmt = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'notifications'"
    );

    return $stmt->fetchColumn() !== false;
}

function notification_create(
    PDO $pdo,
    int $recipientId,
    string $type,
    string $referenceType,
    int $referenceId,
    string $title,
    string $message
): void {
    if (!notification_schema_available($pdo)) {
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (
            recipient_id, notification_type, reference_type, reference_id, title, message
         ) VALUES (
            :recipient_id, :notification_type, :reference_type, :reference_id, :title, :message
         )
         ON CONFLICT (recipient_id, notification_type, reference_type, reference_id) DO NOTHING"
    );
    $stmt->execute([
        "recipient_id" => $recipientId,
        "notification_type" => $type,
        "reference_type" => $referenceType,
        "reference_id" => $referenceId,
        "title" => $title,
        "message" => $message
    ]);
}

function notification_unread_count(PDO $pdo, int $recipientId): int
{
    if (!notification_schema_available($pdo)) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE recipient_id = :recipient_id AND read_at IS NULL"
    );
    $stmt->execute(["recipient_id" => $recipientId]);

    return (int) $stmt->fetchColumn();
}

function notification_target(array $notification): string
{
    $referenceId = (int) $notification["reference_id"];

    return match ($notification["reference_type"]) {
        "buy_order" => "buy_order.php?id=" . $referenceId,
        "exchange_transaction" => "exchange_transaction.php?id=" . $referenceId,
        "content_report" => "my_reports.php?id=" . $referenceId,
        default => "notifications.php"
    };
}
