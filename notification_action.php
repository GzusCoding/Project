<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/notification_support.php";

header("Cache-Control: no-store, private");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Allow: POST");
    http_response_code(405);
    exit("Method not allowed.");
}
$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(401);
    exit("Authentication required.");
}
if (!user_account_is_active($pdo, (int) $userId)) {
    http_response_code(403);
    exit("This account is suspended or unavailable.");
}
if (!csrf_token_is_valid()) {
    http_response_code(400);
    exit("Your request could not be verified. Reload the page and try again.");
}
$notificationId = filter_var($_POST["notification_id"] ?? null, FILTER_VALIDATE_INT);
if ($notificationId === false || $notificationId === null || $notificationId <= 0) {
    http_response_code(400);
    exit("Invalid notification.");
}
if (!notification_schema_available($pdo)) {
    http_response_code(503);
    exit("Notifications are not enabled until the marketplace database is migrated.");
}

try {
    $stmt = $pdo->prepare(
        "UPDATE notifications SET read_at = CURRENT_TIMESTAMP
         WHERE id = :id AND recipient_id = :recipient_id AND read_at IS NULL"
    );
    $stmt->execute(["id" => $notificationId, "recipient_id" => $userId]);
    header("Location: notifications.php", true, 303);
} catch (PDOException $e) {
    error_log("Notification update failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("The notification could not be updated.");
}
