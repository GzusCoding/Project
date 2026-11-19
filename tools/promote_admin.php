<?php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../includes/db.php";

$email = trim($argv[1] ?? "");
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php promote_admin.php <existing-account-email>\n");
    exit(1);
}

$stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = :email");
$stmt->execute(["email" => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user === false) {
    fwrite(STDERR, "No existing account was found for that email.\n");
    exit(1);
}

if ($user["role"] === "admin") {
    fwrite(STDOUT, "This account is already an Admin.\n");
    exit;
}

fwrite(STDOUT, "Promote {$email} to Admin? Type PROMOTE {$email} to confirm: ");
$confirmation = fgets(STDIN);
if ($confirmation === false || !hash_equals("PROMOTE {$email}", trim($confirmation))) {
    fwrite(STDERR, "Promotion cancelled.\n");
    exit(1);
}

$update = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = :id");
$update->execute(["id" => $user["id"]]);
fwrite(STDOUT, "Account promoted to Admin.\n");
