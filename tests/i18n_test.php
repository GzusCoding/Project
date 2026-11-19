<?php

declare(strict_types=1);

function i18n_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

session_id("i18n-test-" . bin2hex(random_bytes(8)));
session_start();
$_GET = ["section" => "incoming", "lang" => "en"];
$_SERVER["REQUEST_URI"] = "/my_offers.php?section=incoming&lang=en";
require_once __DIR__ . "/../includes/i18n.php";

i18n_assert(app_language() === "en", "The requested English language was not saved.");
i18n_assert(t("แชทของฉัน") === "My chats", "Thai-to-English translation failed.");
$englishLinks = language_switcher();
i18n_assert(
    str_contains($englishLinks, "/my_offers.php?section=incoming&amp;lang=th")
        && str_contains($englishLinks, "/my_offers.php?section=incoming&amp;lang=en"),
    "Language links did not preserve the current page filters."
);

$_SESSION["language"] = "th";
i18n_assert(t("My chats") === "แชทของฉัน", "English-to-Thai translation failed.");
$_SESSION["language"] = "en";
i18n_assert(t("หมวดหมู่") === "Categories", "Thai text was not translated to English.");

session_destroy();
echo "i18n tests passed\n";
