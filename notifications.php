<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/notification_support.php";
require_authenticated_user();

$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(401);
    exit("Authentication required.");
}
if (!notification_schema_available($pdo)) {
    http_response_code(503);
    exit("Notifications are not enabled until the marketplace database is migrated.");
}

$stmt = $pdo->prepare(
    "SELECT id, notification_type, reference_type, reference_id,
            title, message, created_at, read_at
     FROM notifications
     WHERE recipient_id = :recipient_id
     ORDER BY (read_at IS NULL) DESC, id DESC
     LIMIT 100"
);
$stmt->execute(["recipient_id" => $userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
?>
<!DOCTYPE html>
<html lang="<?= $escape(app_language()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape(t("Notifications")) ?> | <?= $escape(t("Hybrid Marketplace")) ?></title>
    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <h1><?= $escape(t("Notifications")) ?></h1>
    <?php if ($notifications === []): ?>
        <p><?= $escape(t("No notifications yet.")) ?></p>
    <?php else: ?>
        <?php foreach ($notifications as $notification): ?>
            <article aria-label="<?= $escape(t($notification["read_at"] === null ? "Unread" : "Read")) ?>">
                <h2><?= $escape(t($notification["title"])) ?></h2>
                <p><?= $escape(t($notification["message"])) ?></p>
                <p><?= $escape($notification["created_at"]) ?>
                    · <?= $escape(t($notification["read_at"] === null ? "Unread" : "Read")) ?></p>
                <a href="<?= $escape(notification_target($notification)) ?>"><?= $escape(t("Open related item")) ?></a>
                <?php if ($notification["read_at"] === null): ?>
                    <form method="post" action="notification_action.php">
                        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                        <input type="hidden" name="notification_id" value="<?= (int) $notification["id"] ?>">
                        <button type="submit"><?= $escape(t("Mark as read")) ?></button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
    <p><a href="index.php"><?= $escape(t("Back to home")) ?></a></p>
</main>
</body>
</html>
