<?php
require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

require_admin_user($pdo);
$openDisputes = [];
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
$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8"
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin | Hybrid Marketplace</title>
</head>
<body>
    <main>
        <h1>พื้นที่ผู้ดูแลระบบ</h1>
        <p>เข้าสู่ระบบในฐานะ Admin แล้ว</p>
        <h2>Open transaction disputes</h2>
        <?php if ($openDisputes === []): ?>
            <p>No open disputes.</p>
        <?php else: ?>
            <?php foreach ($openDisputes as $dispute): ?>
                <article style="margin: 16px 0; padding: 16px; border: 1px solid #ddd; border-radius: 8px;">
                    <h3>Transaction #<?= (int) $dispute["transaction_id"] ?></h3>
                    <p>Participants: <?= $escape($dispute["creator_name"]) ?> and <?= $escape($dispute["recipient_name"]) ?></p>
                    <p>Reported by <?= $escape($dispute["reporter_name"]) ?> at <?= $escape($dispute["reported_at"]) ?></p>
                    <p>Reason: <?= $escape($dispute["reason"]) ?></p>
                    <form class="resolution-form" method="post" action="admin_dispute_action.php">
                        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
                        <input type="hidden" name="transaction_id" value="<?= (int) $dispute["transaction_id"] ?>">
                        <input type="hidden" name="dispute_id" value="<?= (int) $dispute["dispute_id"] ?>">
                        <label>
                            Decision
                            <select name="resolution" required>
                                <option value="in_progress">Resume in progress</option>
                                <option value="cancelled">Cancel transaction</option>
                            </select>
                        </label>
                        <label>
                            Resolution reason
                            <textarea name="resolution_reason" minlength="1" maxlength="2000" required></textarea>
                        </label>
                        <button type="submit">Record decision</button>
                        <p class="resolution-state" aria-live="polite"></p>
                    </form>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        <a href="index.php">กลับหน้าหลัก</a>
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
                    if (!response.ok) throw new Error(result.error || "The decision could not be saved.");
                    window.location.reload();
                } catch (error) {
                    state.textContent = error.message || "The decision could not be saved.";
                }
            });
        });
    </script>
</body>
</html>
