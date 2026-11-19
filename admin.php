<?php
require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";

require_admin_user($pdo);
$openDisputes = [];
$openReports = [];
$adminNotice = $_SESSION["admin_notice"] ?? null;
unset($_SESSION["admin_notice"]);
$managementReady = false;
$members = [];
$categories = [];
$statusHistory = [];
$stats = [
    "members" => 0,
    "active_products" => 0,
    "buy_orders" => 0,
    "completed_exchanges" => 0
];
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
    $tableExists = static function (string $table) use ($pdo): bool {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $stmt->execute(["name" => $table]);

        return $stmt->fetchColumn() !== false;
    };
    $categoryColumns = array_column(
        $pdo->query("PRAGMA table_info(categories)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
    $hasStatusHistory = $tableExists("admin_user_status_history");
    $managementReady = in_array("category_name_th", $categoryColumns, true)
        && $hasStatusHistory;
    if ($managementReady) {
        $members = $pdo->query(
            "SELECT id, name, email, role, account_status, created_at
             FROM users
             ORDER BY created_at DESC, id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $categories = $pdo->query(
            "SELECT categories.category_code, categories.category_name,
                    categories.category_name_th, categories.description,
                    COUNT(products.id) AS product_count
             FROM categories
             LEFT JOIN products ON products.category = categories.category_code
             GROUP BY categories.category_code
             ORDER BY categories.category_name, categories.category_code"
        )->fetchAll(PDO::FETCH_ASSOC);
        $statusHistory = $pdo->query(
            "SELECT history.user_id, history.old_status, history.new_status,
                    history.reason, history.changed_at, member.name AS member_name,
                    admin.name AS admin_name
             FROM admin_user_status_history AS history
             INNER JOIN users AS member ON member.id = history.user_id
             INNER JOIN users AS admin ON admin.id = history.admin_id
             ORDER BY history.changed_at DESC, history.id DESC
             LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $stats["members"] = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'user'"
    )->fetchColumn();
    $stats["active_products"] = (int) $pdo->query(
        "SELECT COUNT(*) FROM products WHERE status = 'active'"
    )->fetchColumn();
    if ($tableExists("buy_orders")) {
        $stats["buy_orders"] = (int) $pdo->query(
            "SELECT COUNT(*) FROM buy_orders"
        )->fetchColumn();
    }
    if ($tableExists("exchange_transactions")) {
        $stats["completed_exchanges"] = (int) $pdo->query(
            "SELECT COUNT(*) FROM exchange_transactions WHERE status = 'completed'"
        )->fetchColumn();
    }
}
$maxStat = max(1, ...array_values($stats));
if (
    $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
    && $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'transaction_disputes'"
    )->fetchColumn() !== false
) {
    $stmt = $pdo->query(
        "SELECT transaction_disputes.id AS dispute_id,
                transaction_disputes.transaction_id,
                transaction_disputes.reason,
                transaction_disputes.reported_at,
                reporter.name AS reporter_name,
                creator.name AS creator_name,
                recipient.name AS recipient_name,
                exchange_transactions.payment_status,
                exchange_transactions.cash_amount_minor,
                exchange_transactions.cash_currency,
                exchange_transactions.currency_exponent
         FROM transaction_disputes
         INNER JOIN exchange_transactions
           ON exchange_transactions.id = transaction_disputes.transaction_id
         INNER JOIN users AS reporter ON reporter.id = transaction_disputes.reported_by
         INNER JOIN users AS creator ON creator.id = exchange_transactions.creator_id
         INNER JOIN users AS recipient ON recipient.id = exchange_transactions.recipient_id
         WHERE transaction_disputes.resolved_at IS NULL
           AND exchange_transactions.status = 'disputed'
         ORDER BY transaction_disputes.reported_at, transaction_disputes.id"
    );
    $openDisputes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if (
    $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
    && $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'content_reports'"
    )->fetchColumn() !== false
) {
    $stmt = $pdo->query(
        "SELECT content_reports.id, content_reports.report_type,
                content_reports.reason, content_reports.created_at,
                reporter.name AS reporter_name, reported.name AS reported_name,
                products.name AS product_name, content_reports.product_id
         FROM content_reports
         INNER JOIN users AS reporter ON reporter.id = content_reports.reporter_id
         INNER JOIN users AS reported ON reported.id = content_reports.reported_user_id
         LEFT JOIN products ON products.id = content_reports.product_id
         WHERE content_reports.status = 'pending'
         ORDER BY content_reports.created_at, content_reports.id"
    );
    $openReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
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
    <title><?= $escape(t("Admin")) ?> | <?= $escape(t("Hybrid Marketplace")) ?></title>
    <link rel="stylesheet" href="assets/css/marketplace.css">
</head>
<body class="marketplace-page">
<?= language_switcher() ?>
    <main>
        <h1><?= $escape(t("Admin workspace")) ?></h1>
        <p><?= $escape(t("You are signed in as an admin.")) ?></p>
        <?php if (is_array($adminNotice) && is_string($adminNotice["message"] ?? null)): ?>
            <p class="notice <?= $escape($adminNotice["type"] ?? "success") ?>" role="status">
                <?= $escape(t($adminNotice["message"])) ?>
            </p>
        <?php endif; ?>
        <section aria-labelledby="admin-overview-title">
            <h2 id="admin-overview-title"><?= $escape(t("Administration overview")) ?></h2>
            <div class="admin-statistics">
                <?php foreach ([
                    "members" => "Members",
                    "active_products" => "Active listings",
                    "buy_orders" => "Buy orders",
                    "completed_exchanges" => "Completed exchanges"
                ] as $key => $label): ?>
                    <article>
                        <h3><?= $escape(t($label)) ?></h3>
                        <p><?= (int) $stats[$key] ?></p>
                        <meter min="0" max="<?= $maxStat ?>" value="<?= (int) $stats[$key] ?>">
                            <?= (int) $stats[$key] ?>
                        </meter>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <section id="management" aria-labelledby="member-management-title">
            <h2 id="member-management-title"><?= $escape(t("Member management")) ?></h2>
            <?php if (!$managementReady): ?>
                <p role="status"><?= $escape(t("Member and category management requires database migration 11.")) ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th><?= $escape(t("Member")) ?></th>
                                <th><?= $escape(t("Email")) ?></th>
                                <th><?= $escape(t("Account status")) ?></th>
                                <th><?= $escape(t("Joined")) ?></th>
                                <th><?= $escape(t("Management action")) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <td><?= $escape($member["name"]) ?></td>
                                    <td><?= $escape($member["email"]) ?></td>
                                    <td><?= $escape(t(ucfirst((string) $member["account_status"]))) ?></td>
                                    <td><?= $escape($member["created_at"]) ?></td>
                                    <td>
                                        <?php if ($member["role"] === "user"): ?>
                                            <form method="post" action="admin_management_action.php">
                                                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                                                <input type="hidden" name="admin_action" value="set_user_status">
                                                <input type="hidden" name="user_id" value="<?= (int) $member["id"] ?>">
                                                <input type="hidden" name="account_status"
                                                       value="<?= $member["account_status"] === "active" ? "suspended" : "active" ?>">
                                                <label>
                                                    <?= $escape(t("Reason")) ?>
                                                    <input name="reason" maxlength="2000" required>
                                                </label>
                                                <button type="submit">
                                                    <?= $escape(t($member["account_status"] === "active" ? "Suspend account" : "Reactivate account")) ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <?= $escape(t("Administrator account")) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <h3><?= $escape(t("Category management")) ?></h3>
                <form method="post" action="admin_management_action.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                    <input type="hidden" name="admin_action" value="save_category">
                    <label>
                        <?= $escape(t("Category code")) ?>
                        <input name="category_code" pattern="[a-z][a-z0-9_]{1,39}" maxlength="40" required>
                    </label>
                    <label>
                        <?= $escape(t("Name in English")) ?>
                        <input name="category_name" maxlength="100" required>
                    </label>
                    <label>
                        <?= $escape(t("Name in Thai")) ?>
                        <input name="category_name_th" maxlength="100" required>
                    </label>
                    <label>
                        <?= $escape(t("Description")) ?>
                        <textarea name="description" maxlength="1000"></textarea>
                    </label>
                    <button type="submit"><?= $escape(t("Add or update category")) ?></button>
                </form>
                <?php if ($categories === []): ?>
                    <p><?= $escape(t("No categories found.")) ?></p>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <article>
                            <form method="post" action="admin_management_action.php">
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                                <input type="hidden" name="admin_action" value="save_category">
                                <input type="hidden" name="category_code" value="<?= $escape($category["category_code"]) ?>">
                                <h4><?= $escape($category["category_code"]) ?> · <?= (int) $category["product_count"] ?>
                                    <?= $escape(t("products")) ?></h4>
                                <label>
                                    <?= $escape(t("Name in English")) ?>
                                    <input name="category_name" maxlength="100" required
                                           value="<?= $escape($category["category_name"]) ?>">
                                </label>
                                <label>
                                    <?= $escape(t("Name in Thai")) ?>
                                    <input name="category_name_th" maxlength="100" required
                                           value="<?= $escape($category["category_name_th"] ?? "") ?>">
                                </label>
                                <label>
                                    <?= $escape(t("Description")) ?>
                                    <textarea name="description" maxlength="1000"><?= $escape($category["description"] ?? "") ?></textarea>
                                </label>
                                <button type="submit"><?= $escape(t("Save category")) ?></button>
                            </form>
                            <form method="post" action="admin_management_action.php">
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                                <input type="hidden" name="admin_action" value="delete_category">
                                <input type="hidden" name="category_code" value="<?= $escape($category["category_code"]) ?>">
                                <button type="submit"<?= (int) $category["product_count"] > 0 ? " disabled" : "" ?>>
                                    <?= $escape(t("Delete category")) ?>
                                </button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
                <h3><?= $escape(t("Recent account status history")) ?></h3>
                <?php if ($statusHistory === []): ?>
                    <p><?= $escape(t("No account status changes recorded.")) ?></p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th><?= $escape(t("Member")) ?></th>
                                    <th><?= $escape(t("Previous status")) ?></th>
                                    <th><?= $escape(t("New status")) ?></th>
                                    <th><?= $escape(t("Changed by")) ?></th>
                                    <th><?= $escape(t("Reason")) ?></th>
                                    <th><?= $escape(t("Changed at")) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statusHistory as $history): ?>
                                    <tr>
                                        <td><?= $escape($history["member_name"]) ?></td>
                                        <td><?= $escape(t(ucfirst((string) $history["old_status"]))) ?></td>
                                        <td><?= $escape(t(ucfirst((string) $history["new_status"]))) ?></td>
                                        <td><?= $escape($history["admin_name"]) ?></td>
                                        <td><?= $escape($history["reason"]) ?></td>
                                        <td><?= $escape($history["changed_at"]) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <h2><?= $escape(t("Open transaction disputes")) ?></h2>
        <?php if ($openDisputes === []): ?>
            <p><?= $escape(t("No open disputes.")) ?></p>
        <?php else: ?>
            <?php foreach ($openDisputes as $dispute): ?>
                <article>
                    <h3><?= $escape(t("Transaction")) ?> #<?= (int) $dispute["transaction_id"] ?></h3>
                    <p><?= $escape(t("Participants:")) ?> <?= $escape($dispute["creator_name"]) ?> <?= $escape(t("and")) ?> <?= $escape($dispute["recipient_name"]) ?></p>
                    <p><?= $escape(t("Reported by")) ?> <?= $escape($dispute["reporter_name"]) ?> <?= $escape(t("at")) ?> <?= $escape($dispute["reported_at"]) ?></p>
                    <p><?= $escape(t("Reason:")) ?> <?= $escape($dispute["reason"]) ?></p>
                    <form class="resolution-form" method="post" action="admin_dispute_action.php">
                        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                        <input type="hidden" name="transaction_id" value="<?= (int) $dispute["transaction_id"] ?>">
                        <input type="hidden" name="dispute_id" value="<?= (int) $dispute["dispute_id"] ?>">
                        <label>
                            <?= $escape(t("Decision")) ?>
                            <select name="resolution" required>
                                <option value="in_progress"><?= $escape(t("Resume in progress")) ?></option>
                                <option value="cancelled"><?= $escape(t("Cancel transaction")) ?></option>
                            </select>
                        </label>
                        <label>
                            <?= $escape(t("Resolution reason")) ?>
                            <textarea name="resolution_reason" minlength="1" maxlength="2000" required></textarea>
                        </label>
                        <button type="submit"><?= $escape(t("Record decision")) ?></button>
                        <p class="resolution-state" aria-live="polite"></p>
                    </form>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        <h2><?= $escape(t("Product and behavior reports")) ?></h2>
        <?php if ($openReports === []): ?>
            <p><?= $escape(t("No reports are waiting for review.")) ?></p>
        <?php else: ?>
            <?php foreach ($openReports as $report): ?>
                <article>
                    <h3><?= $escape(t("Report #")) ?><?= (int) $report["id"] ?> · <?= $escape(t($report["report_type"] === "listing" ? "Listing" : "User behavior")) ?></h3>
                    <p><?= $escape(t("Reported by")) ?>: <?= $escape($report["reporter_name"]) ?> · <?= $escape(t("Reported user")) ?>: <?= $escape($report["reported_name"]) ?></p>
                    <?php if ($report["product_id"] !== null): ?>
                        <p><?= $escape(t("Product")) ?>: <?= $escape($report["product_name"] ?? t("Product not found.")) ?></p>
                    <?php endif; ?>
                    <p><?= $escape(t("Details")) ?>: <?= nl2br($escape($report["reason"])) ?></p>
                    <p><?= $escape(t("Submitted")) ?> <?= $escape($report["created_at"]) ?></p>
                    <form class="report-form" method="post" action="admin_report_action.php">
                        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                        <input type="hidden" name="report_id" value="<?= (int) $report["id"] ?>">
                        <label>
                            <?= $escape(t("Review")) ?>
                            <select name="admin_action" required>
                                <option value="dismiss"><?= $escape(t("Dismiss report")) ?></option>
                                <?php if ($report["product_id"] !== null): ?>
                                    <option value="remove_listing"><?= $escape(t("Remove listing")) ?></option>
                                <?php endif; ?>
                                <option value="suspend_account"><?= $escape(t("Suspend user account")) ?></option>
                            </select>
                        </label>
                        <label>
                            <?= $escape(t("Admin reason:")) ?>
                            <textarea name="admin_reason" minlength="1" maxlength="2000" required></textarea>
                        </label>
                        <button type="submit"><?= $escape(t("Save decision")) ?></button>
                        <p class="report-state" aria-live="polite"></p>
                    </form>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        <a href="index.php"><?= $escape(t("Back to home")) ?></a>
    </main>
    <script>
        document.querySelectorAll(".resolution-form").forEach(form => {
            form.addEventListener("submit", async event => {
                event.preventDefault();
                const state = form.querySelector(".resolution-state");
                try {
                    const response = await fetch(form.action, {
                        method: "POST",
                        credentials: "same-origin",
                        headers: { "Accept": "application/json" },
                        body: new FormData(form)
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || <?= json_encode(t("The decision could not be saved."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    window.location.reload();
                } catch (error) {
                    state.textContent = error.message || <?= json_encode(t("The decision could not be saved."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                }
            });
        });
        document.querySelectorAll(".report-form").forEach(form => {
            form.addEventListener("submit", async event => {
                event.preventDefault();
                const state = form.querySelector(".report-state");
                try {
                    const response = await fetch(form.action, {
                        method: "POST",
                        credentials: "same-origin",
                        headers: { "Accept": "application/json" },
                        body: new FormData(form)
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || <?= json_encode(t("บันทึกผลรายงานไม่สำเร็จ"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    window.location.reload();
                } catch (error) {
                    state.textContent = error.message || <?= json_encode(t("บันทึกผลรายงานไม่สำเร็จ"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                }
            });
        });
    </script>
</body>
</html>
