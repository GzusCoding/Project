<?php

$isHttps = !empty($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) !== "off";
ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "secure" => $isHttps,
    "httponly" => true,
    "samesite" => "Lax"
]);
session_start();
require_once __DIR__ . "/i18n.php";

function csrf_token(): string
{
    if (!isset($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrf_token_is_valid(): bool
{
    $submittedToken = $_POST["csrf_token"] ?? null;

    return is_string($submittedToken)
        && isset($_SESSION["csrf_token"])
        && is_string($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $submittedToken);
}

function require_authenticated_user(): void
{
    if (!isset($_SESSION["user_id"])) {
        header("Location: login.php");
        exit;
    }

    global $pdo;
    $userId = filter_var($_SESSION["user_id"], FILTER_VALIDATE_INT);
    if (
        $pdo instanceof PDO
        && ($userId === false || $userId === null || $userId <= 0
            || !user_account_is_active($pdo, $userId))
    ) {
        $_SESSION = [];
        session_destroy();
        http_response_code(403);
        exit("บัญชีนี้ถูกระงับหรือไม่สามารถใช้งานได้");
    }
}

function user_account_is_active(PDO $pdo, int $userId): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") {
        return true;
    }
    $columns = array_column(
        $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );
    if (!in_array("account_status", $columns, true)) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT account_status FROM users WHERE id = :id");
    $stmt->execute(["id" => $userId]);

    return $stmt->fetchColumn() === "active";
}

function require_admin_user(PDO $pdo): void
{
    require_authenticated_user();

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute(["id" => $_SESSION["user_id"]]);
    $role = $stmt->fetchColumn();

    if ($role === false) {
        $_SESSION = [];
        session_destroy();
        header("Location: login.php");
        exit;
    }

    if ($role !== "admin") {
        http_response_code(403);
        exit("ไม่มีสิทธิ์เข้าถึงหน้านี้");
    }
}

function login_attempt_key(string $identity): string
{
    $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "";

    return hash("sha256", strtolower(trim($identity)) . "\0" . $ipAddress);
}

function login_is_rate_limited(PDO $pdo, string $attemptKey): bool
{
    $stmt = $pdo->prepare(
        "SELECT locked_until
         FROM login_attempts
         WHERE attempt_key = :attempt_key"
    );
    $stmt->execute(["attempt_key" => $attemptKey]);
    $lockedUntil = $stmt->fetchColumn();

    return $lockedUntil !== false && $lockedUntil !== null && (int) $lockedUntil > time();
}

function record_login_failure(PDO $pdo, string $attemptKey): void
{
    $now = time();
    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite";

    if ($isSqlite) {
        $pdo->exec("BEGIN IMMEDIATE");
    } else {
        $pdo->beginTransaction();
    }

    try {
        $sql = "SELECT failed_attempts, window_started_at, locked_until
                FROM login_attempts
                WHERE attempt_key = :attempt_key";
        if (!$isSqlite) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(["attempt_key" => $attemptKey]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($attempt === false || $now - (int) $attempt["window_started_at"] >= 900) {
            if ($isSqlite) {
                $stmt = $pdo->prepare(
                    "INSERT INTO login_attempts
                        (attempt_key, failed_attempts, window_started_at, locked_until)
                     VALUES (:attempt_key, 1, :now, NULL)
                     ON CONFLICT(attempt_key) DO UPDATE SET
                        failed_attempts = 1,
                        window_started_at = excluded.window_started_at,
                        locked_until = NULL"
                );
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO login_attempts
                        (attempt_key, failed_attempts, window_started_at, locked_until)
                     VALUES (:attempt_key, 1, :now, NULL)
                     ON DUPLICATE KEY UPDATE
                        failed_attempts = 1,
                        window_started_at = VALUES(window_started_at),
                        locked_until = NULL"
                );
            }
            $stmt->execute(["attempt_key" => $attemptKey, "now" => $now]);
        } else {
            $attemptCount = (int) $attempt["failed_attempts"] + 1;
            $lockedUntil = $attemptCount >= 5 ? $now + 900 : $attempt["locked_until"];
            $stmt = $pdo->prepare(
                "UPDATE login_attempts
                 SET failed_attempts = :failed_attempts, locked_until = :locked_until
                 WHERE attempt_key = :attempt_key"
            );
            $stmt->execute([
                "failed_attempts" => $attemptCount,
                "locked_until" => $lockedUntil,
                "attempt_key" => $attemptKey
            ]);
        }

        $pdo->prepare("DELETE FROM login_attempts WHERE window_started_at < :expiry")
            ->execute(["expiry" => $now - 86400]);

        if ($isSqlite) {
            $pdo->exec("COMMIT");
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($isSqlite) {
            $pdo->exec("ROLLBACK");
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function clear_login_failures(PDO $pdo, string $attemptKey): void
{
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempt_key = :attempt_key");
    $stmt->execute(["attempt_key" => $attemptKey]);
}
