<?php
require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/db.php";

$message = "";
$registered = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_token_is_valid()) {
        http_response_code(400);
        $message = "คำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง";
    } elseif (
        !is_string($_POST["name"] ?? null)
        || !is_string($_POST["email"] ?? null)
        || !is_string($_POST["phone"] ?? null)
        || !is_string($_POST["address"] ?? null)
        || !is_string($_POST["password"] ?? null)
        || !is_string($_POST["confirm_password"] ?? null)
    ) {
        $message = "กรุณากรอกข้อมูลให้ครบทุกช่อง";
    } else {
        $name = trim($_POST["name"]);
        $email = trim($_POST["email"]);
        $phone = trim($_POST["phone"]);
        $address = trim($_POST["address"]);
        $password = $_POST["password"];
        $confirmPassword = $_POST["confirm_password"];

        if ($name === "" || $email === "" || $password === "" || $confirmPassword === "") {
            $message = "กรุณากรอกข้อมูลให้ครบทุกช่อง";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "รูปแบบอีเมลไม่ถูกต้อง";
        } elseif (
            preg_match('/\A(?=.*[0-9])[0-9+(). -]{7,32}\z/', $phone) !== 1
            || strlen($phone) > 32
        ) {
            $message = "กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง";
        } elseif (mb_strlen($address, "UTF-8") > 500) {
            $message = "ที่อยู่ต้องมีความยาวไม่เกิน 500 ตัวอักษร";
        } elseif (strlen($password) < 8) {
            $message = "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร";
        } elseif ($password !== $confirmPassword) {
            $message = "รหัสผ่านทั้งสองช่องไม่ตรงกัน";
        } else {
            try {
                $sql = "INSERT INTO users (name, email, password, phone, address)
                        VALUES (:name, :email, :password, :phone, :address)";
                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    "name" => $name,
                    "email" => $email,
                    "password" => password_hash($password, PASSWORD_DEFAULT),
                    "phone" => $phone,
                    "address" => $address === "" ? null : $address
                ]);

                $message = "สมัครสมาชิกสำเร็จ! บัญชีของคุณพร้อมใช้งานแล้ว";
                $registered = true;
            } catch (PDOException $e) {
                if ($e->getCode() === "23000") {
                    $message = "อีเมลนี้ถูกใช้แล้ว";
                } else {
                    error_log("Member registration failed (database code: {$e->getCode()}).");
                    $message = "เกิดข้อผิดพลาด กรุณาลองใหม่";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_language(), ENT_QUOTES, "UTF-8") ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fff7ed">
    <title><?= htmlspecialchars(t("Create Account"), ENT_QUOTES, "UTF-8") ?> | MARKETPLACE</title>
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

        .register-card {
            position: relative;
            z-index: 1;
            padding: 34px 44px 28px;
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
            margin: 22px 0 20px;
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
            margin-bottom: 14px;
        }

        .form-field label {
            display: block;
            margin-bottom: 7px;
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
            height: 49px;
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

        .submit-button {
            display: flex;
            width: 100%;
            min-height: 51px;
            align-items: center;
            justify-content: center;
            gap: 9px;
            margin-top: 20px;
            border: 0;
            border-radius: 15px;
            color: #fff;
            background: linear-gradient(110deg, #f59a4e, #f17032);
            box-shadow: 0 10px 20px rgba(231, 110, 47, .24);
            font-size: 14px;
            font-weight: 750;
            text-decoration: none;
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
        }

        .submit-button:hover {
            box-shadow: 0 13px 25px rgba(231, 110, 47, .32);
            filter: saturate(1.08);
            transform: translateY(-2px);
        }

        .submit-button svg {
            width: 17px;
            height: 17px;
        }

        .form-message {
            margin: 0 0 16px;
            padding: 11px 13px;
            border: 1px solid #ffd1c8;
            border-radius: 12px;
            color: #a83327;
            background: rgba(255, 241, 238, .92);
            font-size: 13px;
            line-height: 1.5;
        }

        .form-message--success {
            border-color: #b8e4c7;
            color: #28683b;
            background: rgba(238, 250, 241, .92);
        }

        .login-prompt {
            margin: 21px 0 0;
            color: #827a75;
            font-size: 12px;
            text-align: center;
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
            top: 80px;
            left: -134px;
        }

        .product-card--headphones {
            top: 280px;
            right: -142px;
            animation-delay: -1.6s;
        }

        .product-card--sneakers {
            bottom: 76px;
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
            margin: 16px 0 0;
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
                padding: 52px 18px;
            }

            .product-card--bag {
                top: -40px;
                left: -12px;
            }

            .product-card--headphones {
                top: 130px;
                right: -28px;
            }

            .product-card--sneakers {
                bottom: -36px;
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

            .register-card {
                padding: 36px 32px 28px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 62px 13px;
            }

            .stage {
                width: 100%;
            }

            .register-card {
                padding: 32px 22px 25px;
                border-radius: 30px;
            }

            .intro {
                margin: 19px 0 18px;
            }

            .product-card--bag {
                top: -42px;
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
<?= language_switcher() ?>
    <main class="stage">
        <div class="product-card product-card--bag" aria-hidden="true">
            <span class="product-emoji">👜</span>
            <span class="product-info"><strong><?= htmlspecialchars(t("Everyday tote"), ENT_QUOTES, "UTF-8") ?></strong><span>฿ 890</span></span>
        </div>
        <div class="product-card product-card--headphones" aria-hidden="true">
            <span class="product-emoji">🎧</span>
            <span class="product-info"><strong><?= htmlspecialchars(t("Studio sound"), ENT_QUOTES, "UTF-8") ?></strong><span>฿ 2,490</span></span>
        </div>
        <div class="product-card product-card--sneakers" aria-hidden="true">
            <span class="product-emoji">👟</span>
            <span class="product-info"><strong><?= htmlspecialchars(t("Daily sneakers"), ENT_QUOTES, "UTF-8") ?></strong><span>฿ 1,590</span></span>
        </div>

        <section class="register-card" aria-labelledby="register-title">
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
                <h1 id="register-title"><?= htmlspecialchars(t("Create your account"), ENT_QUOTES, "UTF-8") ?></h1>
                <p><?= htmlspecialchars(t("Discover. Shop. Enjoy."), ENT_QUOTES, "UTF-8") ?></p>
            </header>

            <?php if ($message !== ""): ?>
                <p
                    class="form-message<?= $registered ? " form-message--success" : "" ?>"
                    role="<?= $registered ? "status" : "alert" ?>"
                >
                    <?= htmlspecialchars(t($message), ENT_QUOTES, "UTF-8") ?>
                </p>
            <?php endif; ?>

            <?php if ($registered): ?>
                <a class="submit-button" href="login.php">
                    <span><?= htmlspecialchars(t("Go to Login"), ENT_QUOTES, "UTF-8") ?></span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 12h14"></path>
                        <path d="m13 6 6 6-6 6"></path>
                    </svg>
                </a>
            <?php else: ?>
                <form method="POST" action="register.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>">
                    <div class="form-field">
                        <label for="name"><?= htmlspecialchars(t("Your name"), ENT_QUOTES, "UTF-8") ?></label>
                        <div class="input-wrap">
                            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="8" r="4"></circle>
                                <path d="M5 21a7 7 0 0 1 14 0"></path>
                            </svg>
                            <input
                                id="name"
                                type="text"
                                name="name"
                                placeholder="<?= htmlspecialchars(t("Enter your name"), ENT_QUOTES, "UTF-8") ?>"
                                autocomplete="name"
                                value="<?= htmlspecialchars($_POST["name"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="email"><?= htmlspecialchars(t("Email address"), ENT_QUOTES, "UTF-8") ?></label>
                        <div class="input-wrap">
                            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="3"></rect>
                                <path d="m4 7 8 6 8-6"></path>
                            </svg>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                placeholder="you@example.com"
                                autocomplete="email"
                                value="<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="phone"><?= htmlspecialchars(t("Phone number"), ENT_QUOTES, "UTF-8") ?></label>
                        <div class="input-wrap">
                            <input
                                id="phone"
                                type="tel"
                                name="phone"
                                placeholder="<?= htmlspecialchars(t("Phone number"), ENT_QUOTES, "UTF-8") ?>"
                                autocomplete="tel"
                                value="<?= htmlspecialchars($_POST["phone"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="address"><?= htmlspecialchars(t("Address"), ENT_QUOTES, "UTF-8") ?></label>
                        <div class="input-wrap">
                            <input
                                id="address"
                                type="text"
                                name="address"
                                placeholder="<?= htmlspecialchars(t("Address (optional)"), ENT_QUOTES, "UTF-8") ?>"
                                autocomplete="street-address"
                                maxlength="500"
                                value="<?= htmlspecialchars($_POST["address"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                            >
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="password"><?= htmlspecialchars(t("Password"), ENT_QUOTES, "UTF-8") ?></label>
                        <div class="input-wrap">
                            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="4" y="10" width="16" height="11" rx="2.5"></rect>
                                <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                            </svg>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                placeholder="<?= htmlspecialchars(t("At least 8 characters"), ENT_QUOTES, "UTF-8") ?>"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >
                            <button class="password-toggle" type="button" aria-label="<?= htmlspecialchars(t("Show password"), ENT_QUOTES, "UTF-8") ?>" aria-pressed="false" data-password-toggle="password">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="confirm-password"><?= htmlspecialchars(t("Confirm password"), ENT_QUOTES, "UTF-8") ?></label>
                        <div class="input-wrap">
                            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="4" y="10" width="16" height="11" rx="2.5"></rect>
                                <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                            </svg>
                            <input
                                id="confirm-password"
                                type="password"
                                name="confirm_password"
                                placeholder="<?= htmlspecialchars(t("Enter your password again"), ENT_QUOTES, "UTF-8") ?>"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >
                            <button class="password-toggle" type="button" aria-label="<?= htmlspecialchars(t("Show password"), ENT_QUOTES, "UTF-8") ?>" aria-pressed="false" data-password-toggle="confirm-password">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button class="submit-button" type="submit">
                        <span><?= htmlspecialchars(t("Create Account"), ENT_QUOTES, "UTF-8") ?></span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 12h14"></path>
                            <path d="m13 6 6 6-6 6"></path>
                        </svg>
                    </button>
                </form>

                <p class="login-prompt">
                    <?= htmlspecialchars(t("Already have an account?"), ENT_QUOTES, "UTF-8") ?>
                    <a class="text-link" href="login.php"><?= htmlspecialchars(t("Login"), ENT_QUOTES, "UTF-8") ?></a>
                </p>
            <?php endif; ?>

            <p class="market-note"><?= htmlspecialchars(t("A little something for every part of your life."), ENT_QUOTES, "UTF-8") ?></p>
        </section>
    </main>

    <script>
        document.querySelectorAll("[data-password-toggle]").forEach((toggle) => {
            toggle.addEventListener("click", () => {
                const password = document.getElementById(toggle.dataset.passwordToggle);
                const isVisible = password.type === "text";
                password.type = isVisible ? "password" : "text";
                toggle.setAttribute("aria-pressed", String(!isVisible));
                toggle.setAttribute("aria-label", isVisible
                    ? <?= json_encode(t("Show password"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                    : <?= json_encode(t("Hide password"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
            });
        });
    </script>
</body>
</html>
