<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/security.php";

$userId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(404);
    exit("Profile not found.");
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    http_response_code(503);
    exit("Profiles are temporarily unavailable.");
}

try {
    $userColumns = array_column(
        $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
    $statusFilter = in_array("account_status", $userColumns, true)
        ? " AND account_status = 'active'"
        : "";
    $stmt = $pdo->prepare(
        "SELECT id, name FROM users WHERE id = :id{$statusFilter} LIMIT 1"
    );
    $stmt->execute(["id" => $userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($profile === false) {
        http_response_code(404);
        exit("Profile not found.");
    }

    $reviewsAvailable = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'reviews'"
    )->fetchColumn() !== false;
    $reviews = [];
    $ratingSummary = ["average_rating" => null, "review_count" => 0];
    if ($reviewsAvailable) {
        $stmt = $pdo->prepare(
            "SELECT AVG(rating) AS average_rating, COUNT(*) AS review_count
             FROM reviews WHERE reviewee_id = :reviewee_id"
        );
        $stmt->execute(["reviewee_id" => $userId]);
        $ratingSummary = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare(
            "SELECT reviews.rating, reviews.comment, reviews.created_at,
                    reviewer.name AS reviewer_name
             FROM reviews
             INNER JOIN users AS reviewer ON reviewer.id = reviews.reviewer_id
             WHERE reviews.reviewee_id = :reviewee_id
             ORDER BY reviews.created_at DESC, reviews.id DESC
             LIMIT 100"
        );
        $stmt->execute(["reviewee_id" => $userId]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Public profile query failed (code: {$e->getCode()}).");
    http_response_code(503);
    exit("The profile is temporarily unavailable.");
}

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
    <title><?= $escape(t("Seller profile")) ?> | <?= $escape(t("Hybrid Marketplace")) ?></title>
    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
<main>
    <h1><?= $escape(t("Seller profile")) ?>: <?= $escape($profile["name"]) ?></h1>
    <?php if ((int) ($ratingSummary["review_count"] ?? 0) > 0): ?>
        <p><?= $escape(t("Average rating")) ?> <?= number_format((float) $ratingSummary["average_rating"], 1) ?>/5
            (<?= (int) $ratingSummary["review_count"] ?> <?= $escape(t("Review")) ?>)</p>
    <?php else: ?>
        <p><?= $escape(t("No reviews yet.")) ?></p>
    <?php endif; ?>
    <section aria-labelledby="profile-reviews-title">
        <h2 id="profile-reviews-title"><?= $escape(t("Reviews from buyers and exchange partners")) ?></h2>
        <?php foreach ($reviews as $review): ?>
            <article>
                <p><strong><?= $escape($review["reviewer_name"]) ?></strong>
                    · <?= (int) $review["rating"] ?>/5</p>
                <p><?= nl2br($escape($review["comment"])) ?></p>
                <p><?= $escape($review["created_at"]) ?></p>
            </article>
        <?php endforeach; ?>
    </section>
    <p><a href="index.php"><?= $escape(t("Back to home")) ?></a></p>
</main>
</body>
</html>
