
<?php

require_once __DIR__ . "/includes/security.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !csrf_token_is_valid()) {
    http_response_code(400);
    exit("คำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง");
}

$cookieParams = session_get_cookie_params();
if (ini_get("session.use_cookies")) {
    setcookie(session_name(), "", [
        "expires" => time() - 42000,
        "path" => $cookieParams["path"],
        "domain" => $cookieParams["domain"],
        "secure" => $cookieParams["secure"],
        "httponly" => $cookieParams["httponly"],
        "samesite" => $cookieParams["samesite"] ?? "Lax"
    ]);
}

$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
