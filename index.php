<?php
require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/notification_support.php";
require_once __DIR__ . "/includes/category_support.php";

$categories = marketplace_category_labels($pdo);
$transactionTypes = [
    "buy" => "Buy",
    "exchange" => "Exchange",
    "both" => "Buy or exchange"
];
$transactionTypes = array_map("t", $transactionTypes);
$conditions = [
    "new" => "New",
    "like_new" => "Like new",
    "used" => "Used"
];
$conditions = array_map("t", $conditions);
$searchInput = $_GET["search"] ?? "";
$search = is_string($searchInput) ? trim($searchInput) : "";
$categoryInput = $_GET["category"] ?? "";
$category = is_string($categoryInput) && array_key_exists($categoryInput, $categories)
    ? $categoryInput
    : "";
$transactionInput = $_GET["transaction"] ?? "all";
$transaction = is_string($transactionInput)
    && in_array($transactionInput, ["all", "buy", "exchange", "both"], true)
        ? $transactionInput
        : "all";
$invalidFilters = (
    $categoryInput !== "" && (!is_string($categoryInput) || !array_key_exists($categoryInput, $categories))
) || (
    $transactionInput !== "all"
    && (!is_string($transactionInput) || !in_array($transactionInput, ["buy", "exchange", "both"], true))
);
$isAuthenticated = isset($_SESSION["user_id"]);
$notificationCount = 0;
$notificationsAvailable = false;
if ($isAuthenticated) {
    $authenticatedUserId = filter_var($_SESSION["user_id"], FILTER_VALIDATE_INT);
    if (
        $authenticatedUserId === false
        || $authenticatedUserId === null
        || $authenticatedUserId <= 0
        || !user_account_is_active($pdo, $authenticatedUserId)
    ) {
        $_SESSION = [];
        session_destroy();
        $isAuthenticated = false;
    } else {
        $notificationsAvailable = notification_schema_available($pdo);
        if ($notificationsAvailable) {
            $notificationCount = notification_unread_count($pdo, $authenticatedUserId);
        }
    }
}
$name = is_string($_SESSION["user_name"] ?? null)
    ? $_SESSION["user_name"]
    : t("Member");
$productSuccess = is_string($_SESSION["product_success"] ?? null)
    ? $_SESSION["product_success"]
    : "";
unset($_SESSION["product_success"]);

$sql = "
    SELECT products.id, products.name, products.description, products.price,
           products.image, products.category, products.transaction_type,
           products.item_condition, users.name AS seller_name
    FROM products
    LEFT JOIN users ON products.user_id = users.id
    WHERE products.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM product_reservations
          WHERE product_reservations.product_id = products.id
      )
";

if ($search !== "") {
    $sql .= " AND (products.name LIKE :name_search OR products.description LIKE :description_search)";
}
if ($category !== "") {
    $sql .= $category === "other"
        ? " AND (products.category = :category OR products.category IS NULL)"
        : " AND products.category = :category";
}
if ($transaction === "buy") {
    $sql .= " AND products.transaction_type IN ('buy', 'both')";
} elseif ($transaction === "exchange") {
    $sql .= " AND products.transaction_type IN ('exchange', 'both')";
} elseif ($transaction === "both") {
    $sql .= " AND products.transaction_type = 'both'";
}

$sql .= " ORDER BY products.created_at DESC";

$products = [];
$listingFailed = false;
try {
    $stmt = $pdo->prepare($sql);
    $parameters = [];
    if ($search !== "") {
        $parameters["name_search"] = "%" . $search . "%";
        $parameters["description_search"] = "%" . $search . "%";
    }
    if ($category !== "") {
        $parameters["category"] = $category;
    }
    $stmt->execute($parameters);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Public product listing query failed (code: {$e->getCode()}).");
    $listingFailed = true;
}
$hasActiveFilters = $search !== "" || $category !== "" || $transaction !== "all";
$categoryFilterUrl = static function (string $value) use ($search, $transaction): string {
    return "index.php?" . http_build_query([
        "category" => $value,
        "transaction" => $transaction,
        "search" => $search
    ]) . "#products";
};
$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fff8f1">
    <title>Buy &amp; Exchange | <?= htmlspecialchars(t("Hybrid Marketplace"), ENT_QUOTES, "UTF-8") ?></title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #24211f;
            background: #fffaf5;
            font-synthesis: none;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
            --orange: #f27b35;
            --orange-dark: #d95f20;
            --ink: #24211f;
            --muted: #827a75;
            --line: #eee5de;
            --cream: #fff5eb;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            min-width: 320px;
            margin: 0;
            background: #fffaf5;
        }

        a {
            color: inherit;
        }

        button,
        input {
            font: inherit;
        }

        .site-header {
            position: sticky;
            z-index: 5;
            top: 0;
            border-bottom: 1px solid rgba(238, 229, 222, .8);
            background: rgba(255, 250, 245, .94);
            backdrop-filter: blur(16px);
        }

        .header-main,
        .nav-inner,
        main,
        .footer-inner {
            width: min(1180px, calc(100% - 48px));
            margin: 0 auto;
        }

        .header-main {
            display: flex;
            align-items: center;
            gap: 24px;
            min-height: 82px;
        }

        .brand {
            display: inline-flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .brand-mark {
            display: grid;
            width: 42px;
            height: 42px;
            place-items: center;
            border-radius: 14px;
            color: white;
            background: linear-gradient(145deg, #ffa65f, var(--orange));
            box-shadow: 0 7px 16px rgba(242, 123, 53, .24);
            font-size: 19px;
            font-weight: 850;
        }

        .brand-copy {
            display: grid;
            gap: 1px;
        }

        .brand-copy strong {
            font-size: 14px;
            letter-spacing: .025em;
        }

        .brand-copy span {
            color: var(--muted);
            font-size: 11px;
        }

        .search-form {
            display: flex;
            flex: 1;
            max-width: 510px;
            min-width: 160px;
            margin: 0 auto;
        }

        .search-form input {
            width: 100%;
            min-width: 0;
            height: 44px;
            padding: 0 15px;
            border: 1px solid var(--line);
            border-right: 0;
            border-radius: 13px 0 0 13px;
            outline: 0;
            background: white;
        }

        .search-form input:focus {
            border-color: #f2ae85;
            box-shadow: 0 0 0 3px rgba(242, 123, 53, .1);
        }

        .search-form button,
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 17px;
            border: 0;
            border-radius: 12px;
            color: white;
            background: var(--orange);
            font-size: 13px;
            font-weight: 750;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            transition: background .18s ease, transform .18s ease;
        }

        .search-form button {
            border-radius: 0 13px 13px 0;
        }

        .button:hover,
        .search-form button:hover {
            background: var(--orange-dark);
            transform: translateY(-1px);
        }

        .button--light {
            border: 1px solid var(--line);
            color: var(--ink);
            background: white;
        }

        .button--light:hover {
            background: #fff4e9;
        }

        .button--dark {
            color: white;
            background: #302a26;
        }

        .button--dark:hover {
            background: #494039;
        }

        .account-actions {
            display: flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 9px;
        }

        .account-name {
            max-width: 145px;
            overflow: hidden;
            font-size: 13px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .logout-form {
            margin: 0;
        }

        .logout-form button {
            min-height: 40px;
            padding: 0 13px;
            font-size: 12px;
        }

        .site-nav {
            border-top: 1px solid rgba(238, 229, 222, .75);
        }

        .nav-inner {
            display: flex;
            align-items: center;
            gap: 29px;
            min-height: 45px;
        }

        .nav-inner a {
            color: #655c56;
            font-size: 12px;
            font-weight: 650;
            text-decoration: none;
        }

        .nav-inner a:hover {
            color: var(--orange-dark);
        }

        .language-switcher {
            margin-left: auto;
            white-space: nowrap;
        }

        main {
            padding-bottom: 70px;
        }

        .hero {
            position: relative;
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            align-items: center;
            min-height: 410px;
            margin-top: 28px;
            padding: 54px 7%;
            overflow: hidden;
            border-radius: 30px;
            background:
                radial-gradient(circle at 82% 25%, rgba(255, 255, 255, .76), transparent 25%),
                linear-gradient(118deg, #ffead8 0%, #fff3e7 53%, #ffe1c9 100%);
        }

        .hero::after {
            position: absolute;
            right: 24%;
            bottom: -180px;
            width: 390px;
            height: 390px;
            border: 1px solid rgba(242, 123, 53, .17);
            border-radius: 50%;
            box-shadow: 0 0 0 35px rgba(255, 255, 255, .15), 0 0 0 75px rgba(255, 255, 255, .12);
            content: "";
            pointer-events: none;
        }

        .hero-copy {
            position: relative;
            z-index: 1;
            max-width: 570px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
            color: #b95725;
            font-size: 11px;
            font-weight: 850;
            letter-spacing: .15em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            width: 21px;
            height: 2px;
            background: var(--orange);
            content: "";
        }

        .hero h1 {
            max-width: 560px;
            margin: 0;
            font-size: clamp(38px, 5.2vw, 66px);
            line-height: 1.06;
            letter-spacing: -.055em;
        }

        .hero h1 span {
            color: var(--orange-dark);
        }

        .hero-copy p {
            max-width: 475px;
            margin: 19px 0 25px;
            color: #6f625a;
            font-size: 15px;
            line-height: 1.8;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .hero-art {
            position: relative;
            z-index: 1;
            display: grid;
            min-height: 250px;
            place-items: center;
        }

        .art-circle {
            position: absolute;
            width: min(280px, 80%);
            aspect-ratio: 1;
            border-radius: 50%;
            background: rgba(255, 255, 255, .48);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .75);
        }

        .swap-card {
            position: relative;
            display: grid;
            width: min(265px, 85%);
            min-height: 190px;
            align-content: center;
            justify-items: center;
            gap: 10px;
            border: 1px solid rgba(255, 255, 255, .85);
            border-radius: 28px;
            background: rgba(255, 255, 255, .79);
            box-shadow: 0 22px 52px rgba(128, 79, 45, .15);
            transform: rotate(4deg);
        }

        .swap-icons {
            display: flex;
            align-items: center;
            gap: 13px;
            color: var(--orange-dark);
            font-size: 42px;
        }

        .swap-icons span:nth-child(2) {
            font-size: 21px;
        }

        .swap-card strong {
            font-size: 15px;
        }

        .swap-card small {
            color: var(--muted);
            font-size: 11px;
        }

        .section {
            margin-top: 68px;
        }

        .section-heading {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 23px;
        }

        .section-heading h2 {
            margin: 0;
            font-size: clamp(23px, 3vw, 31px);
            letter-spacing: -.04em;
        }

        .section-heading p {
            max-width: 560px;
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .category-card {
            display: flex;
            align-items: center;
            gap: 18px;
            min-height: 126px;
            padding: 22px 25px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: white;
            text-decoration: none;
            transition: border-color .18s ease, transform .18s ease;
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .category-card:hover {
            border-color: #f2b18b;
            transform: translateY(-2px);
        }

        .category-card--active {
            border-color: var(--orange);
            box-shadow: 0 0 0 2px rgba(242, 123, 53, .12);
        }

        .category-icon {
            display: grid;
            flex: 0 0 auto;
            width: 64px;
            height: 64px;
            place-items: center;
            border-radius: 19px;
            background: var(--cream);
            font-size: 27px;
        }

        .category-card strong {
            display: block;
            margin-bottom: 5px;
            font-size: 15px;
        }

        .category-card > span:nth-child(2) {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .category-arrow {
            margin-left: auto;
            color: var(--orange);
            font-size: 22px;
        }

        .trade-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .trade-card {
            display: flex;
            gap: 17px;
            padding: 23px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: white;
        }

        .trade-icon {
            display: grid;
            flex: 0 0 auto;
            width: 48px;
            height: 48px;
            place-items: center;
            border-radius: 15px;
            color: #bc5725;
            background: var(--cream);
            font-size: 22px;
        }

        .trade-card h3 {
            margin: 1px 0 7px;
            font-size: 16px;
        }

        .trade-card p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .listing-toolbar {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
        }

        .flash-success {
            margin: 24px 0 0;
            padding: 14px 17px;
            border: 1px solid #b9e2c6;
            border-radius: 13px;
            color: #23643b;
            background: #f0fbf3;
            font-size: 13px;
            font-weight: 650;
        }

        .listing-toolbar .section-heading {
            display: block;
            margin: 0;
        }

        .listing-count {
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .filter-form {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) auto auto;
            align-items: end;
            gap: 12px;
            margin: 0 0 14px;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: white;
        }

        .filter-field {
            display: grid;
            gap: 6px;
            color: #655c56;
            font-size: 12px;
            font-weight: 700;
        }

        .filter-field select {
            width: 100%;
            min-width: 0;
            height: 42px;
            padding: 0 11px;
            border: 1px solid var(--line);
            border-radius: 10px;
            color: var(--ink);
            background: white;
            font: inherit;
        }

        .filter-field select:focus {
            border-color: #f2ae85;
            outline: 3px solid rgba(242, 123, 53, .12);
        }

        .filter-form .button {
            min-height: 42px;
        }

        .filter-reset {
            display: inline-flex;
            align-items: center;
            min-height: 42px;
            color: var(--orange-dark);
            font-size: 12px;
            font-weight: 750;
            text-decoration: none;
            white-space: nowrap;
        }

        .active-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin: 0 0 17px;
            color: var(--muted);
            font-size: 12px;
        }

        .filter-badge,
        .product-badge {
            display: inline-flex;
            align-items: center;
            min-height: 25px;
            padding: 4px 9px;
            border-radius: 999px;
            color: #765039;
            background: #fff1e5;
            font-size: 10px;
            font-weight: 750;
        }

        .filter-notice {
            margin: 0 0 14px;
            color: #9f3126;
            font-size: 13px;
        }

        .product-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 0 0 12px;
        }

        .product-badge--condition {
            color: #32644c;
            background: #eaf7ee;
        }

        .product-badge--transaction {
            color: #514783;
            background: #f0edff;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }

        .product-card {
            display: flex;
            min-width: 0;
            flex-direction: column;
            padding: 11px;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 19px;
            background: white;
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .product-card:hover {
            border-color: #f1c0a1;
            box-shadow: 0 13px 32px rgba(91, 61, 43, .08);
            transform: translateY(-3px);
        }

        .product-image,
        .no-image {
            display: grid;
            width: 100%;
            height: 205px;
            place-items: center;
            border-radius: 13px;
            background: #f7f2ed;
            object-fit: cover;
        }

        .no-image {
            color: #a1978f;
            font-size: 12px;
        }

        .no-image span {
            display: grid;
            justify-items: center;
            gap: 6px;
        }

        .no-image b {
            font-size: 27px;
            font-weight: 400;
        }

        .product-info {
            display: flex;
            flex: 1;
            flex-direction: column;
            padding: 14px 4px 4px;
        }

        .product-info h3 {
            display: -webkit-box;
            min-height: 44px;
            margin: 0;
            overflow: hidden;
            font-size: 15px;
            line-height: 1.45;
            overflow-wrap: anywhere;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .product-price {
            margin: 9px 0 7px;
            color: var(--orange-dark);
            font-size: 18px;
            font-weight: 850;
        }

        .product-seller {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 11px;
        }

        .product-info .button {
            width: 100%;
            min-height: 40px;
            margin-top: auto;
            font-size: 12px;
        }

        .empty-state {
            display: grid;
            min-height: 250px;
            justify-items: center;
            align-content: center;
            padding: 28px;
            border: 1px dashed #e6d9ce;
            border-radius: 20px;
            background: #fffdfb;
            text-align: center;
        }

        .empty-state > span {
            margin-bottom: 12px;
            font-size: 37px;
        }

        .empty-state h3 {
            margin: 0 0 7px;
            font-size: 19px;
        }

        .empty-state p {
            max-width: 430px;
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .site-footer {
            padding: 24px 0;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 12px;
        }

        .footer-inner {
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        @media (max-width: 900px) {
            .header-main,
            .nav-inner,
            main,
            .footer-inner {
                width: min(100% - 36px, 720px);
            }

            .header-main {
                flex-wrap: wrap;
                gap: 12px 18px;
                padding: 13px 0;
            }

            .search-form {
                order: 3;
                flex: 1 0 100%;
                max-width: none;
            }

            .account-actions {
                margin-left: auto;
            }

            .hero {
                min-height: 360px;
                padding: 42px 6%;
            }

            .hero-art {
                min-height: 215px;
            }

            .product-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .category-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 620px) {
            .header-main,
            .nav-inner,
            main,
            .footer-inner {
                width: calc(100% - 28px);
            }

            .header-main {
                gap: 12px;
            }

            .brand-mark {
                width: 38px;
                height: 38px;
            }

            .brand-copy strong {
                font-size: 12px;
            }

            .account-actions {
                gap: 6px;
            }

            .account-actions .button {
                min-height: 38px;
                padding: 0 10px;
                font-size: 11px;
            }

            .account-name {
                max-width: 75px;
                font-size: 11px;
            }

            .logout-form button {
                padding: 0 9px;
                font-size: 11px;
            }

            .nav-inner {
                gap: 21px;
                min-height: 42px;
                overflow-x: auto;
                white-space: nowrap;
            }

            .hero {
                grid-template-columns: 1fr;
                min-height: auto;
                margin-top: 16px;
                padding: 35px 24px 25px;
                border-radius: 23px;
            }

            .hero h1 {
                font-size: clamp(37px, 11vw, 54px);
            }

            .hero-copy p {
                margin: 14px 0 20px;
                font-size: 13px;
            }

            .hero-art {
                min-height: 190px;
                margin-top: 8px;
            }

            .swap-card {
                min-height: 155px;
            }

            .swap-icons {
                font-size: 34px;
            }

            .section {
                margin-top: 48px;
            }

            .section-heading {
                align-items: start;
                flex-direction: column;
                gap: 8px;
                margin-bottom: 17px;
            }

            .category-card {
                min-height: 110px;
                padding: 17px;
            }

            .category-grid,
            .filter-form {
                grid-template-columns: 1fr;
            }

            .filter-form .button,
            .filter-reset {
                width: 100%;
            }

            .trade-grid {
                grid-template-columns: 1fr;
            }

            .listing-toolbar {
                align-items: start;
                flex-direction: column;
                gap: 7px;
            }

            .product-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .product-card {
                padding: 8px;
                border-radius: 15px;
            }

            .product-image,
            .no-image {
                height: 150px;
                border-radius: 10px;
            }

            .product-info {
                padding: 11px 2px 3px;
            }

            .product-info h3 {
                min-height: 40px;
                font-size: 13px;
            }

            .product-price {
                font-size: 16px;
            }

            .product-info .button {
                min-height: 38px;
                padding: 0 7px;
                font-size: 11px;
            }

            .footer-inner {
                flex-direction: column;
            }
        }

        @media (max-width: 370px) {
            .brand-copy span {
                display: none;
            }

            .account-actions .button {
                padding: 0 8px;
            }

            .account-name {
                max-width: 58px;
            }
        }
    </style>
</head>
<body>
    <header class="site-header" id="home">
        <div class="header-main">
            <a class="brand" href="index.php" aria-label="Buy and Exchange homepage">
                <span class="brand-mark" aria-hidden="true">B</span>
                <span class="brand-copy">
                    <strong>BUY &amp; EXCHANGE</strong>
                    <span>Hybrid Marketplace</span>
                </span>
            </a>

            <form class="search-form" method="GET" action="index.php" role="search">
                <input
                    type="search"
                    name="search"
                    placeholder="<?= $escape(t("Search products...")) ?>"
                    aria-label="<?= $escape(t("Search")) ?>"
                    value="<?= $escape($search) ?>"
                >
                <input type="hidden" name="category" value="<?= $escape($category) ?>">
                <input type="hidden" name="transaction" value="<?= $escape($transaction) ?>">
                <button type="submit"><?= $escape(t("Search")) ?></button>
            </form>

            <div class="account-actions">
                <?php if ($isAuthenticated): ?>
                    <span class="account-name"><?= $escape(t("สวัสดี,")) ?> <?= htmlspecialchars($name, ENT_QUOTES, "UTF-8") ?></span>
                    <a class="button button--light" href="my_products.php"><?= $escape(t("My products")) ?></a>
                    <a class="button button--light" href="my_orders.php"><?= $escape(t("My orders")) ?></a>
                    <?php if ($notificationsAvailable): ?>
                        <a class="button button--light" href="notifications.php">
                            <?= $escape(t("Notifications")) ?><?= $notificationCount > 0 ? " ({$notificationCount})" : "" ?>
                        </a>
                    <?php endif; ?>
                    <a class="button button--light" href="my_offers.php"><?= $escape(t("My offers & orders")) ?></a>
                    <?php if (($_SESSION["user_role"] ?? null) === "admin"): ?>
                        <a class="button button--light" href="admin.php"><?= $escape(t("Manage")) ?></a>
                    <?php endif; ?>
                    <form class="logout-form" method="POST" action="logout.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>">
                        <button class="button button--dark" type="submit"><?= $escape(t("Logout")) ?></button>
                    </form>
                <?php else: ?>
                    <a class="button button--light" href="login.php"><?= $escape(t("Login")) ?></a>
                    <a class="button" href="register.php"><?= $escape(t("Register")) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <nav class="site-nav" aria-label="<?= $escape(t("Main menu")) ?>">
            <div class="nav-inner">
                <a href="#home"><?= $escape(t("Home")) ?></a>
                <a href="#categories"><?= $escape(t("Categories")) ?></a>
                <a href="#products"><?= $escape(t("Browse products")) ?></a>
                <?php if ($isAuthenticated): ?>
                    <a href="add_product.php"><?= $escape(t("Add Product")) ?></a>
                    <a href="my_products.php"><?= $escape(t("My products")) ?></a>
                    <a href="my_orders.php"><?= $escape(t("My orders")) ?></a>
                    <a href="my_offers.php"><?= $escape(t("My offers & orders")) ?></a>
                    <a href="my_chats.php"><?= $escape(t("My chats")) ?></a>
                <?php endif; ?>
                <a href="#trade"><?= $escape(t("Buy & Exchange")) ?></a>
                <?= language_switcher() ?>
            </div>
        </nav>
    </header>

    <main>
        <?php if ($productSuccess !== ""): ?>
            <p class="flash-success" role="status">
                <?= htmlspecialchars($productSuccess, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
            </p>
        <?php endif; ?>

        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-copy">
                <span class="eyebrow"><?= $escape(t("A marketplace with more possibilities")) ?></span>
                <h1 id="hero-title"><?= $escape(t("ของที่ใช่")) ?><br><?= $escape(t("เริ่มได้ที่")) ?> <span>Buy &amp; Exchange</span></h1>
                <p>
                <?= $escape(t("เลือกซื้อสินค้าที่ถูกใจวันนี้ และร่วมเปิดโอกาสให้การแลกเปลี่ยนเป็นอีกทางเลือก เรากำลังสร้างพื้นที่ซื้อขายที่ยืดหยุ่นสำหรับทุกคน")) ?>
                </p>
                <div class="hero-actions">
                <a class="button" href="#products"><?= $escape(t("เลือกดูสินค้า")) ?> <span aria-hidden="true">&nbsp;→</span></a>
                <a class="button button--light" href="#trade"><?= $escape(t("รู้จัก Buy & Exchange")) ?></a>
                </div>
            </div>
            <div class="hero-art" aria-hidden="true">
                <div class="art-circle"></div>
                <div class="swap-card">
                    <div class="swap-icons"><span>🛍️</span><span>↔</span><span>🎁</span></div>
                    <strong><?= $escape(t("Buy what you love")) ?></strong>
                    <small><?= $escape(t("Exchange is part of the journey")) ?></small>
                </div>
            </div>
        </section>

        <section class="section" id="categories" aria-labelledby="categories-title">
            <div class="section-heading">
                <div>
                    <h2 id="categories-title"><?= $escape(t("เลือกดูหมวดหมู่")) ?></h2>
                    <p><?= $escape(t("เลือกหมวดหมู่เพื่อกรองสินค้าล่าสุด โดยรายการจะแสดงเฉพาะสินค้าที่เปิดใช้งาน")) ?></p>
                </div>
            </div>
            <div class="category-grid">
                <a class="category-card<?= $category === "" ? " category-card--active" : "" ?>"
                   href="<?= $escape($categoryFilterUrl("")) ?>"
                   <?= $category === "" ? 'aria-current="true"' : "" ?>>
                    <span class="category-icon" aria-hidden="true">🧺</span>
                    <span><strong><?= $escape(t("All Categories")) ?></strong><span><?= $escape(t("Browse all active listings")) ?></span></span>
                    <span class="category-arrow" aria-hidden="true">→</span>
                </a>
                <?php foreach ($categories as $value => $label): ?>
                    <a class="category-card<?= $category === $value ? " category-card--active" : "" ?>"
                       href="<?= $escape($categoryFilterUrl($value)) ?>"
                       <?= $category === $value ? 'aria-current="true"' : "" ?>>
                        <span class="category-icon" aria-hidden="true">◇</span>
                        <span><strong><?= $escape($label) ?></strong><span><?= $escape(t("Browse active listings")) ?></span></span>
                        <span class="category-arrow" aria-hidden="true">→</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section" id="trade" aria-labelledby="trade-title">
            <div class="section-heading">
                <div>
                    <h2 id="trade-title"><?= $escape(t("ซื้อ หรือแลกเปลี่ยน — เลือกได้ตามต้องการ")) ?></h2>
                    <p><?= $escape(t("Buy & Exchange เชื่อมต่อการซื้อขายวันนี้กับการแลกเปลี่ยนในอนาคต")) ?></p>
                </div>
            </div>
            <div class="trade-grid">
                <article class="trade-card">
                    <span class="trade-icon" aria-hidden="true">🛒</span>
                    <div>
                        <h3><?= $escape(t("Shop for products")) ?></h3>
                        <p><?= $escape(t("เลือกสินค้า ระบุวิธีชำระเงิน และติดตามการยืนยันส่งมอบกับการรับสินค้าได้จากรายการซื้อขายของคุณ")) ?></p>
                    </div>
                </article>
                <article class="trade-card">
                    <span class="trade-icon" aria-hidden="true">↔</span>
                    <div>
                        <h3><?= $escape(t("Offer an exchange")) ?></h3>
                        <p><?= $escape(t("เลือกสินค้าที่ต้องการแลก ส่งข้อเสนอให้เจ้าของสินค้า และติดตามสถานะการแลกเปลี่ยนในระบบ")) ?></p>
                    </div>
                </article>
            </div>
        </section>

        <section class="section" id="products" aria-labelledby="products-title">
            <div class="listing-toolbar">
                <div class="section-heading">
                    <h2 id="products-title"><?= $escape(t($hasActiveFilters ? "ผลการค้นหา" : "สินค้าล่าสุด")) ?></h2>
                    <p><?= $escape(t($hasActiveFilters ? "รายการสินค้าที่ตรงกับตัวกรองที่เลือก" : "ค้นพบสินค้าที่น่าสนใจจากผู้ขายในชุมชน")) ?></p>
                </div>
                <span class="listing-count"><?= count($products) ?> <?= $escape(t("items")) ?></span>
            </div>

            <form class="filter-form" method="GET" action="index.php">
                <input type="hidden" name="search" value="<?= $escape($search) ?>">
                <label class="filter-field" for="category-filter">
                    <?= $escape(t("Category")) ?>
                    <select id="category-filter" name="category">
                        <option value=""<?= $category === "" ? " selected" : "" ?>><?= $escape(t("All Categories")) ?></option>
                        <?php foreach ($categories as $value => $label): ?>
                            <option value="<?= $escape($value) ?>"<?= $category === $value ? " selected" : "" ?>>
                                <?= $escape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filter-field" for="transaction-filter">
                    <?= $escape(t("Transaction type")) ?>
                    <select id="transaction-filter" name="transaction">
                        <option value="all"<?= $transaction === "all" ? " selected" : "" ?>><?= $escape(t("All")) ?></option>
                        <option value="buy"<?= $transaction === "buy" ? " selected" : "" ?>><?= $escape(t("Buy")) ?></option>
                        <option value="exchange"<?= $transaction === "exchange" ? " selected" : "" ?>><?= $escape(t("Exchange")) ?></option>
                        <option value="both"<?= $transaction === "both" ? " selected" : "" ?>><?= $escape(t("Both")) ?></option>
                    </select>
                </label>
                <button class="button" type="submit"><?= $escape(t("Apply filters")) ?></button>
                <a class="filter-reset" href="index.php#products"><?= $escape(t("Reset filters")) ?></a>
            </form>

            <?php if ($invalidFilters): ?>
                <p class="filter-notice" role="status"><?= $escape(t("An invalid filter was ignored. Any valid filters are still applied.")) ?></p>
            <?php endif; ?>

            <?php if ($hasActiveFilters): ?>
                <div class="active-filters" aria-label="<?= $escape(t("Active filters")) ?>">
                    <span><?= $escape(t("Active filters:")) ?></span>
                    <?php if ($search !== ""): ?>
                        <span class="filter-badge"><?= $escape(t("Search:")) ?> <?= $escape($search) ?></span>
                    <?php endif; ?>
                    <?php if ($category !== ""): ?>
                        <span class="filter-badge"><?= $escape($categories[$category]) ?></span>
                    <?php endif; ?>
                    <?php if ($transaction !== "all"): ?>
                        <span class="filter-badge"><?= $escape(t(ucfirst($transaction))) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($listingFailed): ?>
                <div class="empty-state" role="alert">
                    <h3><?= $escape(t("สินค้าไม่สามารถโหลดได้ในขณะนี้")) ?></h3>
                    <p><?= $escape(t("กรุณาลองใหม่อีกครั้งภายหลัง")) ?></p>
                </div>
            <?php elseif ($products !== []): ?>
                <div class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <?php
                        $imagePath = $product["image"] ?? "";
                        $hasImage = is_string($imagePath)
                            && preg_match(
                                '/\Auploads\/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)\z/i',
                                $imagePath
                            ) === 1
                            && is_file(__DIR__ . "/" . $imagePath);
                        ?>
                        <article class="product-card">
                            <?php if ($hasImage): ?>
                                <img
                                    class="product-image"
                                    src="<?= htmlspecialchars($imagePath, ENT_QUOTES, "UTF-8") ?>"
                                    alt="<?= htmlspecialchars($product["name"], ENT_QUOTES, "UTF-8") ?>"
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <div class="no-image" role="img" aria-label="<?= $escape(t("No image")) ?>">
                                    <span><b aria-hidden="true">◇</b><?= $escape(t("No image")) ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="product-info">
                                <h3><?= htmlspecialchars($product["name"], ENT_QUOTES, "UTF-8") ?></h3>
                                <p class="product-price"><?= number_format((float) $product["price"], 2) ?> <?= $escape(t("บาท")) ?></p>
                                <div class="product-badges" aria-label="Product details">
                                    <span class="product-badge"><?= $escape($categories[$product["category"] ?? ""] ?? "Other") ?></span>
                                    <span class="product-badge product-badge--condition"><?= $escape($conditions[$product["item_condition"] ?? ""] ?? t("Condition not set")) ?></span>
                                    <span class="product-badge product-badge--transaction"><?= $escape($transactionTypes[$product["transaction_type"] ?? ""] ?? t("Unknown type")) ?></span>
                                </div>
                                <p class="product-seller">
                                    <?= $escape(t("ผู้ขาย:")) ?> <?= htmlspecialchars($product["seller_name"] ?? t("ไม่ระบุ"), ENT_QUOTES, "UTF-8") ?>
                                </p>
                                <a class="button" href="product_detail.php?id=<?= (int) $product["id"] ?>">
                                    <?= $escape(t("View product details")) ?>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span aria-hidden="true">🛍️</span>
                    <h3><?= $escape(t($hasActiveFilters ? "ไม่พบสินค้าที่ตรงกับตัวกรอง" : "กำลังรอสินค้าชิ้นแรก")) ?></h3>
                    <p>
                        <?= $escape(t($hasActiveFilters
                            ? "ลองปรับคำค้นหาหรือตัวกรอง แล้วค้นหาอีกครั้ง"
                            : "ยังไม่มีสินค้าในตลาด ลองกลับมาใหม่อีกครั้งเพื่อพบกับรายการใหม่ ๆ")) ?>
                    </p>
                    <?php if ($hasActiveFilters): ?>
                        <a class="button button--light" href="index.php#products"><?= $escape(t("Reset filters")) ?></a>
                    <?php elseif ($isAuthenticated): ?>
                        <a class="button" href="add_product.php"><?= $escape(t("Add Product")) ?></a>
                    <?php else: ?>
                        <a class="button" href="register.php"><?= $escape(t("Get started")) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <span>BUY &amp; EXCHANGE · <?= $escape(t("Hybrid Marketplace")) ?></span>
            <span><?= $escape(t("ซื้อขายวันนี้ แลกเปลี่ยนได้ในอนาคต")) ?></span>
        </div>
    </footer>
</body>
</html>
