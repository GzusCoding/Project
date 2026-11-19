<?php

declare(strict_types=1);

function marketplace_category_labels(PDO $pdo): array
{
    $defaultCategories = [
        "electronics" => "Electronics",
        "fashion" => "Fashion",
        "home_living" => "Home & Living",
        "books" => "Books",
        "sports_outdoors" => "Sports & Outdoors",
        "games_collectibles" => "Games & Collectibles",
        "other" => "Other"
    ];

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
        return array_map("t", $defaultCategories);
    }

    $tableExists = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'categories'"
    )->fetchColumn() !== false;
    if (!$tableExists) {
        return array_map("t", $defaultCategories);
    }

    $columns = array_column(
        $pdo->query("PRAGMA table_info(categories)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
    $hasThaiName = in_array("category_name_th", $columns, true);
    $stmt = $pdo->query(
        "SELECT category_code, category_name"
        . ($hasThaiName ? ", category_name_th" : "")
        . " FROM categories ORDER BY category_name, category_code"
    );
    $labels = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $category) {
        $englishName = (string) $category["category_name"];
        $thaiName = $hasThaiName && is_string($category["category_name_th"] ?? null)
            ? trim($category["category_name_th"])
            : "";
        $labels[(string) $category["category_code"]] = app_language() === "th"
            ? ($thaiName !== "" ? $thaiName : t($englishName))
            : $englishName;
    }

    return $labels;
}
