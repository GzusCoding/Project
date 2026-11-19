<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";

require_admin_user($pdo);

function admin_management_redirect(string $type, string $message): never
{
    $_SESSION["admin_notice"] = ["type" => $type, "message" => $message];
    header("Location: admin.php#management", true, 303);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !csrf_token_is_valid()) {
    http_response_code(400);
    exit("Your request could not be verified.");
}

$adminId = (int) $_SESSION["user_id"];
$action = $_POST["admin_action"] ?? null;
if (!is_string($action)) {
    admin_management_redirect("error", "Choose a valid management action.");
}

$transactionStarted = false;
try {
    $pdo->exec("BEGIN IMMEDIATE");
    $transactionStarted = true;
    if ($action === "save_category") {
        $categoryCode = $_POST["category_code"] ?? null;
        $englishName = $_POST["category_name"] ?? null;
        $thaiName = $_POST["category_name_th"] ?? null;
        $description = $_POST["description"] ?? "";
        if (
            !is_string($categoryCode)
            || preg_match('/\A[a-z][a-z0-9_]{1,39}\z/', $categoryCode) !== 1
            || !is_string($englishName)
            || mb_strlen(trim($englishName), "UTF-8") < 1
            || mb_strlen(trim($englishName), "UTF-8") > 100
            || !is_string($thaiName)
            || mb_strlen(trim($thaiName), "UTF-8") < 1
            || mb_strlen(trim($thaiName), "UTF-8") > 100
            || !is_string($description)
            || mb_strlen(trim($description), "UTF-8") > 1000
        ) {
            throw new DomainException("Enter a valid category code and Thai/English names.");
        }

        $stmt = $pdo->prepare(
            "INSERT INTO categories (category_code, category_name, category_name_th, description)
             VALUES (:code, :english, :thai, :description)
             ON CONFLICT(category_code) DO UPDATE SET
                category_name = excluded.category_name,
                category_name_th = excluded.category_name_th,
                description = excluded.description"
        );
        $stmt->execute([
            "code" => $categoryCode,
            "english" => trim($englishName),
            "thai" => trim($thaiName),
            "description" => trim($description) === "" ? null : trim($description)
        ]);
        $pdo->exec("COMMIT");
        $transactionStarted = false;
        admin_management_redirect("success", "Category saved.");
    }

    if ($action === "delete_category") {
        $categoryCode = $_POST["category_code"] ?? null;
        if (
            !is_string($categoryCode)
            || preg_match('/\A[a-z][a-z0-9_]{1,39}\z/', $categoryCode) !== 1
        ) {
            throw new DomainException("Choose a valid category.");
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM products WHERE category = :category_code"
        );
        $stmt->execute(["category_code" => $categoryCode]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new DomainException("A category used by products cannot be deleted.");
        }
        $stmt = $pdo->prepare(
            "DELETE FROM categories WHERE category_code = :category_code"
        );
        $stmt->execute(["category_code" => $categoryCode]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("The selected category was not found.");
        }
        $pdo->exec("COMMIT");
        $transactionStarted = false;
        admin_management_redirect("success", "Category deleted.");
    }

    if ($action === "set_user_status") {
        $userIdInput = $_POST["user_id"] ?? null;
        $status = $_POST["account_status"] ?? null;
        $reason = $_POST["reason"] ?? null;
        $userId = is_string($userIdInput) && preg_match('/\A[1-9][0-9]*\z/', $userIdInput) === 1
            ? filter_var($userIdInput, FILTER_VALIDATE_INT)
            : false;
        if (
            $userId === false
            || $userId === null
            || $userId === $adminId
            || !is_string($status)
            || !in_array($status, ["active", "suspended"], true)
            || !is_string($reason)
            || mb_strlen(trim($reason), "UTF-8") < 1
            || mb_strlen(trim($reason), "UTF-8") > 2000
        ) {
            throw new DomainException("Enter a valid account status and reason.");
        }

        $stmt = $pdo->prepare(
            "SELECT role, account_status FROM users WHERE id = :id LIMIT 1"
        );
        $stmt->execute(["id" => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false || $user["role"] !== "user") {
            throw new DomainException("Only another member account can be changed here.");
        }
        if ($user["account_status"] === $status) {
            throw new DomainException("The member already has that account status.");
        }
        $stmt = $pdo->prepare(
            "UPDATE users SET account_status = :status
             WHERE id = :id AND role = 'user' AND account_status = :old_status"
        );
        $stmt->execute([
            "status" => $status,
            "id" => $userId,
            "old_status" => $user["account_status"]
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException("The member account changed before this update.");
        }
        $stmt = $pdo->prepare(
            "INSERT INTO admin_user_status_history (
                user_id, admin_id, old_status, new_status, reason
             ) VALUES (
                :user_id, :admin_id, :old_status, :new_status, :reason
             )"
        );
        $stmt->execute([
            "user_id" => $userId,
            "admin_id" => $adminId,
            "old_status" => $user["account_status"],
            "new_status" => $status,
            "reason" => trim($reason)
        ]);
        $pdo->exec("COMMIT");
        $transactionStarted = false;
        admin_management_redirect("success", "Member account status updated.");
    }

    throw new DomainException("Choose a valid management action.");
} catch (DomainException $e) {
    if ($transactionStarted) {
        $pdo->exec("ROLLBACK");
    }
    admin_management_redirect("error", $e->getMessage());
} catch (PDOException $e) {
    if ($transactionStarted) {
        $pdo->exec("ROLLBACK");
    }
    error_log("Admin management action failed (code: {$e->getCode()}).");
    admin_management_redirect("error", "The management change could not be saved.");
}
