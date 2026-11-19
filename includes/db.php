<?php
$driver = getenv("DB_DRIVER") ?: "sqlite";

try {
    if ($driver === "mysql") {
        $host = getenv("DB_HOST") ?: "localhost";
        $dbname = getenv("DB_NAME") ?: "hybrid_marketplace";
        $username = getenv("DB_USER") ?: "root";
        $password = getenv("DB_PASSWORD") ?: "";

        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if ($roleColumn === false) {
            $pdo->exec(
                "ALTER TABLE users
                 ADD COLUMN role ENUM('user', 'admin') NOT NULL DEFAULT 'user'"
            );
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                attempt_key CHAR(64) PRIMARY KEY,
                failed_attempts INTEGER NOT NULL,
                window_started_at BIGINT NOT NULL,
                locked_until BIGINT NULL
            )"
        );
    } elseif ($driver === "sqlite") {
        $dbPath = getenv("DB_PATH") ?: dirname(__DIR__) . "/hybrid_marketplace.sqlite";
        $pdo = new PDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON");
        $pdo->exec("PRAGMA busy_timeout = 5000");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT,
                price REAL NOT NULL,
                image TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )"
        );

        $userColumns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array("role", $userColumns, true)) {
            $pdo->exec(
                "ALTER TABLE users
                 ADD COLUMN role TEXT NOT NULL DEFAULT 'user'
                 CHECK (role IN ('user', 'admin'))"
            );
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                attempt_key TEXT PRIMARY KEY,
                failed_attempts INTEGER NOT NULL,
                window_started_at INTEGER NOT NULL,
                locked_until INTEGER
            )"
        );
    } else {
        http_response_code(500);
        exit("ระบบไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้");
    }
} catch (PDOException $e) {
    error_log("Database connection or migration failed (driver: {$driver}, code: {$e->getCode()}).");
    http_response_code(500);
    exit("ระบบไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้");
}
