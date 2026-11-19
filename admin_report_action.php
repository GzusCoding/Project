<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/buy_order_support.php";

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Allow: POST");
    buy_order_json_response(["error" => "Method not allowed."], 405);
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
    buy_order_json_response(["error" => "Report review is temporarily unavailable."], 503);
}
$adminId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
if ($adminId === false || $adminId === null || $adminId <= 0) {
    buy_order_json_response(["error" => "Authentication required."], 401);
}
if (!user_account_is_active($pdo, (int) $adminId)) {
    buy_order_json_response(["error" => "This account is suspended or unavailable."], 403);
}
$roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$roleStmt->execute(["id" => $adminId]);
if ($roleStmt->fetchColumn() !== "admin") {
    buy_order_json_response(["error" => "Administrator access is required."], 403);
}
if (!csrf_token_is_valid()) {
    buy_order_json_response(
        ["error" => "Your request could not be verified. Reload the page and try again."],
        400
    );
}
$reportId = buy_order_positive_id($_POST["report_id"] ?? null);
$action = $_POST["admin_action"] ?? null;
$reason = $_POST["admin_reason"] ?? null;
if (
    $reportId === false
    || !is_string($action)
    || !in_array($action, ["dismiss", "remove_listing", "suspend_account"], true)
    || !is_string($reason)
    || preg_match('//u', $reason) !== 1
) {
    buy_order_json_response(["error" => "Enter a valid decision and reason."], 400);
}
$reasonLength = preg_match_all('/./us', $reason);
if (
    $reasonLength === false
    || $reasonLength < 1
    || $reasonLength > 2000
    || strlen($reason) > 8000
    || preg_match('/^\s*$/u', $reason) === 1
) {
    buy_order_json_response(["error" => "The decision reason must contain 1 to 2,000 characters."], 400);
}

$transactionStarted = false;
try {
    buy_order_begin($pdo);
    $transactionStarted = true;
    $stmt = $pdo->prepare(
        "SELECT id, reporter_id, reported_user_id, product_id, status
         FROM content_reports WHERE id = :id LIMIT 1"
    );
    $stmt->execute(["id" => $reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($report === false) {
        throw new DomainException("Report not found.", 404);
    }
    if ($report["status"] !== "pending") {
        throw new DomainException("This report has already been reviewed.", 409);
    }
    if ($action === "remove_listing") {
        if ($report["product_id"] === null) {
            throw new DomainException("Only a listing report can remove a product.", 400);
        }
        $stmt = $pdo->prepare(
            "UPDATE products SET status = 'inactive'
             WHERE id = :product_id
               AND user_id = :user_id
               AND status = 'active'"
        );
        $stmt->execute([
            "product_id" => (int) $report["product_id"],
            "user_id" => (int) $report["reported_user_id"]
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException(
                "The listing is no longer active; it was not removed.",
                409
            );
        }
    } elseif ($action === "suspend_account") {
        $stmt = $pdo->prepare(
            "UPDATE users SET account_status = 'suspended'
             WHERE id = :user_id AND account_status = 'active'"
        );
        $stmt->execute(["user_id" => (int) $report["reported_user_id"]]);
        $statusChanged = $stmt->rowCount() === 1;
        if (!$statusChanged) {
            $stmt = $pdo->prepare(
                "SELECT account_status FROM users WHERE id = :user_id"
            );
            $stmt->execute(["user_id" => (int) $report["reported_user_id"]]);
            if ($stmt->fetchColumn() !== "suspended") {
                throw new RuntimeException("The reported account could not be suspended.");
            }
        }
        if (
            $statusChanged
            && $pdo->query(
                "SELECT 1 FROM sqlite_master
                 WHERE type = 'table' AND name = 'admin_user_status_history'"
            )->fetchColumn() !== false
        ) {
            $stmt = $pdo->prepare(
                "INSERT INTO admin_user_status_history (
                    user_id, admin_id, old_status, new_status, reason
                 ) VALUES (
                    :user_id, :admin_id, 'active', 'suspended', :reason
                 )"
            );
            $stmt->execute([
                "user_id" => (int) $report["reported_user_id"],
                "admin_id" => (int) $adminId,
                "reason" => trim($reason)
            ]);
        }
    }

    $status = $action === "dismiss" ? "dismissed" : "action_taken";
    $stmt = $pdo->prepare(
        "UPDATE content_reports
         SET status = :status,
             admin_id = :admin_id,
             admin_action = :admin_action,
             admin_reason = :admin_reason,
             reviewed_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = 'pending'"
    );
    $stmt->execute([
        "status" => $status,
        "admin_id" => (int) $adminId,
        "admin_action" => $action,
        "admin_reason" => trim($reason),
        "id" => $reportId
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new DomainException("This report has already been reviewed.", 409);
    }
    $decisionMessage = match ($action) {
        "dismiss" => "Your report was reviewed and dismissed.",
        "remove_listing" => "Your report was reviewed and the reported listing was removed.",
        "suspend_account" => "Your report was reviewed and action was taken on the reported account."
    };
    notification_create(
        $pdo,
        (int) $report["reporter_id"],
        "report_decision",
        "content_report",
        $reportId,
        "Report decision",
        $decisionMessage
    );
    $pdo->exec("COMMIT");
    $transactionStarted = false;
    buy_order_json_response(["success" => true, "status" => $status]);
} catch (DomainException $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    buy_order_json_response(
        ["error" => $e->getMessage()],
        $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400
    );
} catch (Throwable $e) {
    if ($transactionStarted) {
        buy_order_rollback($pdo);
    }
    error_log("Admin report decision failed (code: {$e->getCode()}).");
    buy_order_json_response(["error" => "The report decision could not be saved."], 503);
}
