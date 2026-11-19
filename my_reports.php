<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_authenticated_user();

$userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(401);
    exit("Authentication required.");
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    http_response_code(503);
    exit("Reports are temporarily unavailable.");
}
$stmt = $pdo->query(
    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'content_reports'"
);
if ($stmt->fetchColumn() === false) {
    http_response_code(503);
    exit("Reports are not enabled until the marketplace database is migrated.");
}
$requestedId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$query = "SELECT content_reports.id, content_reports.report_type, content_reports.reason,
                 content_reports.status, content_reports.admin_action,
                 content_reports.admin_reason, content_reports.created_at,
                 content_reports.reviewed_at, products.name AS product_name
          FROM content_reports
          LEFT JOIN products ON products.id = content_reports.product_id
          WHERE content_reports.reporter_id = :reporter_id";
$params = ["reporter_id" => $userId];
if ($requestedId !== null && $requestedId !== false) {
    $query .= " AND content_reports.id = :id";
    $params["id"] = $requestedId;
}
$query .= " ORDER BY content_reports.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title><?= $escape(t("My reports")) ?> | <?= $escape(t("Hybrid Marketplace")) ?></title>
    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <h1><?= $escape(t("My reports")) ?></h1>
    <?php if ($reports === []): ?>
        <p><?= $escape(t("No reports found.")) ?></p>
    <?php else: ?>
        <?php foreach ($reports as $report): ?>
            <article>
                <h2><?= $escape(t("Report #")) ?><?= (int) $report["id"] ?></h2>
                <?php if ($report["product_name"] !== null): ?>
                    <p><?= $escape(t("Product")) ?>: <?= $escape($report["product_name"]) ?></p>
                <?php endif; ?>
                <p><?= $escape(t("Report type:")) ?> <?= $escape(t($report["report_type"])) ?></p>
                <p><?= $escape(t("Submitted details:")) ?> <?= $escape($report["reason"]) ?></p>
                <p><?= $escape(t("Status")) ?>: <?= $escape(t(ucfirst((string) $report["status"]))) ?></p>
                <?php if ($report["reviewed_at"] !== null): ?>
                    <p><?= $escape(t("Result:")) ?> <?= $escape(t($report["admin_action"])) ?></p>
                    <p><?= $escape(t("Admin reason:")) ?> <?= $escape($report["admin_reason"]) ?></p>
                    <p><?= $escape(t("Reviewed at:")) ?> <?= $escape($report["reviewed_at"]) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
    <p><a href="notifications.php"><?= $escape(t("Notifications")) ?></a> · <a href="index.php"><?= $escape(t("Back to home")) ?></a></p>
</main>
</body>
</html>
