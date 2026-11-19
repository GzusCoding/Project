<?php
require_once __DIR__ . "/security.php";
require_once "db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_token_is_valid()) {
        http_response_code(400);
        $error = "คำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง";
    } elseif (!is_string($_POST["email"] ?? null) || !is_string($_POST["password"] ?? null)) {
        $error = "กรุณากรอกอีเมลหรือชื่อบัญชีและรหัสผ่าน";
    } else {
        $identity = trim($_POST["email"]);
        $password = $_POST["password"];
        $attemptKey = login_attempt_key($identity);

        if ($identity === "" || $password === "") {
            $error = "กรุณากรอกอีเมลหรือชื่อบัญชีและรหัสผ่าน";
        } elseif (login_is_rate_limited($pdo, $attemptKey)) {
            $error = "อีเมลหรือรหัสผ่านไม่ถูกต้อง";
        } else {
            $sql = "SELECT id, name, email, password, role
                    FROM users
                    WHERE email = :email OR name = :name";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                "email" => $identity,
                "name" => $identity
            ]);
            $matchingUsers = [];

            foreach ($stmt->fetchAll() as $candidate) {
                if (password_verify($password, $candidate["password"])) {
                    $matchingUsers[] = $candidate;
                }
            }

            if (count($matchingUsers) === 1) {
                $user = $matchingUsers[0];
                if (session_regenerate_id(true)) {
                    clear_login_failures($pdo, $attemptKey);
                    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["user_name"] = $user["name"];
                    $_SESSION["user_email"] = $user["email"];
                    $_SESSION["user_role"] = $user["role"];

                    header("Location: index.php");
                    exit;
                }

                error_log("Session ID regeneration failed after successful authentication.");
                $error = "เข้าสู่ระบบไม่สำเร็จ กรุณาลองอีกครั้ง";
            } else {
                record_login_failure($pdo, $attemptKey);
                $error = "อีเมลหรือรหัสผ่านไม่ถูกต้อง";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fff7ed">
    <title>เข้าสู่ระบบ | MARKETPLACE</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #24211f;
            background: #fff8f1;
            font-synthesis: none;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
            --orange: #f27b35;
            --orange-dark: #d95f20;
            --muted: #827a75;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-width: 320px;
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 48px 24px;
            overflow-x: hidden;
            background:
                radial-gradient(ellipse at 13% 14%, rgba(255, 213, 171, .7), transparent 35%),
                radial-gradient(ellipse at 89% 78%, rgba(255, 199, 157, .52), transparent 31%),
                linear-gradient(135deg, #fffaf5 0%, #fff4e9 49%, #fff9f3 100%);
        }

        body::before,
        body::after {
            position: fixed;
            z-index: -1;
            width: 310px;
            height: 310px;
            border: 1px solid rgba(255, 255, 255, .75);
            border-radius: 50%;
            content: "";
            pointer-events: none;
        }

        body::before {
            top: -150px;
            right: 12%;
            box-shadow: 0 0 0 34px rgba(255, 255, 255, .12), 0 0 0 72px rgba(255, 255, 255, .1);
        }

        body::after {
            bottom: -180px;
            left: 8%;
            width: 360px;
            height: 360px;
            box-shadow: 0 0 0 42px rgba(255, 255, 255, .14), 0 0 0 84px rgba(255, 255, 255, .08);
        }

        button,
        input {
            font: inherit;
        }

        .stage {
            position: relative;
            width: min(100%, 460px);
            animation: fade-in .7s ease both;
        }

        .login-card {
            position: relative;
            z-index: 1;
            padding: 42px 44px 32px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .9);
            border-radius: 38px;
            background: rgba(255, 255, 255, .68);
            box-shadow: 0 30px 90px rgba(128, 79, 45, .14), 0 8px 26px rgba(128, 79, 45, .06), inset 0 1px 0 rgba(255, 255, 255, .9);
            -webkit-backdrop-filter: blur(20px);
            backdrop-filter: blur(20px);
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #272320;
            font-size: 15px;
            font-weight: 850;
            letter-spacing: .19em;
        }

        .brand-mark {
            display: grid;
            width: 39px;
            height: 39px;
            place-items: center;
            border-radius: 13px;
            color: white;
            background: linear-gradient(145deg, #ffa65f, #f16d2e);
            box-shadow: 0 7px 16px rgba(242, 123, 53, .28);
        }

        .brand-mark svg {
            width: 21px;
            height: 21px;
        }

        .intro {
            margin: 30px 0 25px;
            text-align: center;
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(27px, 5vw, 32px);
            font-weight: 760;
            letter-spacing: -.045em;
        }

        .intro p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .form-field {
            margin-bottom: 17px;
        }

        .form-field label {
            display: block;
            margin-bottom: 8px;
            color: #514a45;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .015em;
        }

        .input-wrap {
            position: relative;
        }

        .field-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            width: 18px;
            height: 18px;
            color: #a39a93;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .input-wrap input {
            width: 100%;
            height: 51px;
            padding: 0 46px;
            border: 1px solid rgba(215, 205, 197, .78);
            border-radius: 14px;
            outline: 0;
            color: #312d2a;
            background: rgba(255, 255, 255, .68);
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .input-wrap input::placeholder {
            color: #b1aaa4;
        }

        .input-wrap input:focus {
            border-color: #f29a64;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(242, 123, 53, .12);
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            display: grid;
            width: 30px;
            height: 30px;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 8px;
            color: #948a83;
            background: transparent;
            cursor: pointer;
            transform: translateY(-50%);
            transition: color .2s ease, background .2s ease;
        }

        .password-toggle:hover {
            color: var(--orange-dark);
            background: #fff1e7;
        }

        .password-toggle svg {
            width: 18px;
            height: 18px;
        }

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 2px 0 22px;
            font-size: 12px;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #6f6761;
            cursor: pointer;
        }

        .remember input {
            width: 15px;
            height: 15px;
            margin: 0;
            accent-color: var(--orange);
        }

        .text-link {
            color: var(--orange-dark);
            font-weight: 700;
            text-decoration: none;
        }

        .text-link:hover {
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .login-button {
            display: flex;
            width: 100%;
            min-height: 52px;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border: 0;
            border-radius: 15px;
            color: #fff;
            background: linear-gradient(110deg, #f59a4e, #f17032);
            box-shadow: 0 10px 20px rgba(231, 110, 47, .24);
            font-size: 14px;
            font-weight: 750;
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
        }

        .login-button:hover {
            box-shadow: 0 13px 25px rgba(231, 110, 47, .32);
            filter: saturate(1.08);
            transform: translateY(-2px);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .login-button svg {
            width: 17px;
            height: 17px;
        }

        .error-message {
            margin: 0 0 17px;
            padding: 11px 13px;
            border: 1px solid #ffd1c8;
            border-radius: 12px;
            color: #a83327;
            background: rgba(255, 241, 238, .92);
            font-size: 13px;
        }

        .form-notice {
            min-height: 17px;
            margin: 10px 0 0;
            color: #8b5a35;
            font-size: 11px;
            line-height: 1.5;
            text-align: center;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 13px;
            margin: 18px 0;
            color: #a39b95;
            font-size: 11px;
        }

        .divider::before,
        .divider::after {
            height: 1px;
            flex: 1;
            background: rgba(205, 195, 186, .65);
            content: "";
        }

        .social-buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .social-button {
            display: flex;
            min-height: 45px;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 8px;
            border: 1px solid rgba(218, 210, 203, .85);
            border-radius: 13px;
            color: #554f4a;
            background: rgba(255, 255, 255, .62);
            font-size: 12px;
            font-weight: 650;
            cursor: pointer;
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .social-button:hover {
            border-color: rgba(242, 123, 53, .5);
            background: #fff;
            box-shadow: 0 6px 15px rgba(83, 55, 37, .08);
            transform: translateY(-2px);
        }

        .social-button svg {
            width: 17px;
            height: 17px;
            flex: 0 0 auto;
        }

        .register-prompt {
            margin: 24px 0 0;
            color: #827a75;
            font-size: 12px;
            text-align: center;
        }

        .register-prompt a {
            margin-left: 4px;
        }

        .product-card {
            position: absolute;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 144px;
            padding: 10px 13px;
            border: 1px solid rgba(255, 255, 255, .9);
            border-radius: 18px;
            background: rgba(255, 255, 255, .73);
            box-shadow: 0 13px 35px rgba(106, 73, 47, .12);
            -webkit-backdrop-filter: blur(14px);
            backdrop-filter: blur(14px);
            animation: float 5s ease-in-out infinite;
            pointer-events: none;
        }

        .product-card--bag {
            top: 92px;
            left: -134px;
        }

        .product-card--headphones {
            top: 238px;
            right: -142px;
            animation-delay: -1.6s;
        }

        .product-card--sneakers {
            bottom: 92px;
            left: -108px;
            animation-delay: -3.1s;
        }

        .product-emoji {
            display: grid;
            width: 42px;
            height: 42px;
            flex: 0 0 auto;
            place-items: center;
            border-radius: 13px;
            background: linear-gradient(145deg, #fff0df, #ffe2c8);
            font-size: 23px;
        }

        .product-info {
            display: grid;
            gap: 3px;
        }

        .product-info strong {
            color: #433c37;
            font-size: 11px;
            font-weight: 750;
        }

        .product-info span {
            color: var(--orange-dark);
            font-size: 10px;
            font-weight: 700;
        }

        .market-note {
            margin: 17px 0 0;
            color: #a09489;
            font-size: 10px;
            text-align: center;
        }

        @keyframes fade-in {
            from {
                opacity: 0;
                transform: translateY(14px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-8px);
            }
        }

        @media (max-width: 760px) {
            body {
                padding: 38px 18px;
            }

            .product-card--bag {
                top: -47px;
                left: -12px;
            }

            .product-card--headphones {
                top: 140px;
                right: -28px;
            }

            .product-card--sneakers {
                bottom: -35px;
                left: -19px;
            }

            .product-card {
                min-width: auto;
                padding: 7px 9px;
            }

            .product-emoji {
                width: 34px;
                height: 34px;
                font-size: 19px;
            }

            .product-info strong {
                font-size: 10px;
            }

            .product-info span {
                font-size: 9px;
            }

            .login-card {
                padding: 40px 32px 30px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 62px 13px;
            }

            .stage {
                width: 100%;
            }

            .login-card {
                padding: 34px 22px 26px;
                border-radius: 30px;
            }

            .intro {
                margin: 25px 0 22px;
            }

            .product-card--bag {
                top: -43px;
                left: 4px;
            }

            .product-card--headphones {
                top: auto;
                right: 3px;
                bottom: -39px;
            }

            .product-card--sneakers {
                display: none;
            }

            .market-note {
                margin-top: 21px;
            }
        }

        @media (max-width: 360px) {
            .login-card {
                padding-right: 17px;
                padding-left: 17px;
            }

            .social-buttons {
                gap: 6px;
            }

            .social-button {
                gap: 5px;
                padding: 0 5px;
                font-size: 11px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>
<body>
    <main class="stage">
        <div class="product-card product-card--bag" aria-hidden="true">
            <span class="product-emoji">👜</span>
            <span class="product-info"><strong>Everyday tote</strong><span>฿ 890</span></span>
        </div>
        <div class="product-card product-card--headphones" aria-hidden="true">
            <span class="product-emoji">🎧</span>
            <span class="product-info"><strong>Studio sound</strong><span>฿ 2,490</span></span>
        </div>
        <div class="product-card product-card--sneakers" aria-hidden="true">
            <span class="product-emoji">👟</span>
            <span class="product-info"><strong>Daily sneakers</strong><span>฿ 1,590</span></span>
        </div>

        <section class="login-card" aria-labelledby="login-title">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 8h14l1 12H4L5 8Z"></path>
                        <path d="M9 9V6a3 3 0 0 1 6 0v3"></path>
                    </svg>
                </span>
                <span>MARKETPLACE</span>
            </div>

            <header class="intro">
                <h1 id="login-title">Welcome back</h1>
                <p>Discover. Shop. Enjoy.</p>
            </header>

            <?php if ($error !== ""): ?>
                <p class="error-message" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
                </p>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>">
                <div class="form-field">
                    <label for="email">Email or Username</label>
                    <div class="input-wrap">
                        <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="5" width="18" height="14" rx="3"></rect>
                            <path d="m4 7 8 6 8-6"></path>
                        </svg>
                        <input
                            id="email"
                            type="text"
                            name="email"
                            placeholder="Enter your email or username"
                            autocomplete="username"
                            value="<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                            required
                        >
                    </div>
                </div>

                <div class="form-field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="4" y="10" width="16" height="11" rx="2.5"></rect>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                        </svg>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button class="password-toggle" type="button" aria-label="แสดงรหัสผ่าน" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember">
                        <input type="checkbox" name="remember_me" value="1">
                        <span>Remember me</span>
                    </label>
                    <a class="text-link" href="#password-recovery" data-notice="Password recovery is not configured yet. Please contact the marketplace administrator.">Forgot Password?</a>
                </div>

                <button class="login-button" type="submit">
                    <span>Login</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 12h14"></path>
                        <path d="m13 6 6 6-6 6"></path>
                    </svg>
                </button>
                <p class="form-notice" id="form-notice" role="status" aria-live="polite"></p>
            </form>

            <div class="divider"><span>Or continue with</span></div>

            <div class="social-buttons" aria-label="Social sign-in options">
                <button class="social-button" type="button" data-provider="Google" data-notice="Google sign-in is not configured yet.">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.39-.18-2.05H12v3.88h5.38a4.6 4.6 0 0 1-2 3.02v2.52h3.24c1.9-1.75 2.98-4.32 2.98-7.37Z"></path>
                        <path fill="#34A853" d="M12 22c2.7 0 4.97-.9 6.62-2.4l-3.24-2.52c-.9.6-2.05.96-3.38.96-2.6 0-4.8-1.76-5.59-4.13H3.06v2.6A10 10 0 0 0 12 22Z"></path>
                        <path fill="#FBBC05" d="M6.41 13.91a6 6 0 0 1 0-3.82v-2.6H3.06a10 10 0 0 0 0 9.02l3.35-2.6Z"></path>
                        <path fill="#EA4335" d="M12 5.96c1.47 0 2.79.5 3.83 1.52l2.87-2.87A9.6 9.6 0 0 0 12 2a10 10 0 0 0-8.94 5.49l3.35 2.6C7.2 7.72 9.4 5.96 12 5.96Z"></path>
                    </svg>
                    <span>Google</span>
                </button>
                <button class="social-button" type="button" data-provider="Facebook" data-notice="Facebook sign-in is not configured yet.">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#1877F2" d="M24 12a12 12 0 1 0-13.88 11.86v-8.4H7.08V12h3.04V9.36c0-3 1.79-4.66 4.52-4.66 1.31 0 2.68.23 2.68.23v2.95h-1.5c-1.48 0-1.94.92-1.94 1.87V12h3.3l-.53 3.46h-2.77v8.4A12 12 0 0 0 24 12Z"></path>
                    </svg>
                    <span>Facebook</span>
                </button>
                <button class="social-button" type="button" data-provider="Apple" data-notice="Apple sign-in is not configured yet.">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="currentColor" d="M16.37 12.33c.02 2.13 1.87 2.84 1.89 2.85-.02.05-.3 1.03-.98 2.03-.59.87-1.21 1.74-2.18 1.76-.95.02-1.26-.57-2.35-.57-1.1 0-1.44.55-2.33.59-.94.04-1.66-.94-2.26-1.8-1.23-1.78-2.17-5.04-.9-7.24a3.5 3.5 0 0 1 2.96-1.8c.92-.02 1.79.62 2.35.62.56 0 1.62-.77 2.73-.66.47.02 1.8.19 2.66 1.44-.07.04-1.59.93-1.58 2.78Zm-1.8-6.02c.5-.6.83-1.43.74-2.26-.72.03-1.58.48-2.1 1.08-.46.53-.87 1.38-.76 2.19.8.06 1.62-.41 2.12-1.01Z"></path>
                    </svg>
                    <span>Apple</span>
                </button>
            </div>

            <p class="register-prompt">
                Don't have an account?
                <a class="text-link" href="register.php">Create Account</a>
            </p>
            <p class="market-note">A little something for every part of your life.</p>
        </section>
    </main>

    <script>
        const passwordInput = document.getElementById("password");
        const passwordToggle = document.querySelector(".password-toggle");
        const formNotice = document.getElementById("form-notice");

        passwordToggle.addEventListener("click", () => {
            const isVisible = passwordInput.type === "text";
            passwordInput.type = isVisible ? "password" : "text";
            passwordToggle.setAttribute("aria-pressed", String(!isVisible));
            passwordToggle.setAttribute("aria-label", isVisible ? "แสดงรหัสผ่าน" : "ซ่อนรหัสผ่าน");
        });

        document.querySelectorAll("[data-notice]").forEach((control) => {
            control.addEventListener("click", (event) => {
                if (control.tagName === "A") {
                    event.preventDefault();
                }
                formNotice.textContent = control.dataset.notice;
            });
        });
    </script>
</body>
</html>
