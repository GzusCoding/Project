<?php

declare(strict_types=1);

function chat_media_schema_available(PDO $pdo, string $table): bool
{
    if (
        $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite"
        || !in_array($table, ["chat_messages", "buy_order_chat_messages"], true)
    ) {
        return false;
    }
    $columns = array_column(
        $pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC),
        "name"
    );

    return in_array("image_storage_key", $columns, true)
        && in_array("image_mime_type", $columns, true);
}

function chat_media_validate_upload(mixed $upload): ?array
{
    if ($upload === null) {
        return null;
    }
    if (!is_array($upload) || !is_int($upload["error"] ?? null)) {
        throw new DomainException("The selected image could not be uploaded.", 400);
    }
    if ($upload["error"] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($upload["error"] === UPLOAD_ERR_INI_SIZE || $upload["error"] === UPLOAD_ERR_FORM_SIZE) {
        throw new DomainException("Chat images must be no larger than 5 MB.", 413);
    }
    if ($upload["error"] !== UPLOAD_ERR_OK) {
        throw new DomainException("The image upload failed. Please try again.", 400);
    }
    if (
        !is_string($upload["tmp_name"] ?? null)
        || !is_uploaded_file($upload["tmp_name"])
        || !is_int($upload["size"] ?? null)
        || $upload["size"] < 1
        || $upload["size"] > 5 * 1024 * 1024
    ) {
        throw new DomainException("Chat images must be between 1 byte and 5 MB.", 413);
    }
    $actualSize = filesize($upload["tmp_name"]);
    if ($actualSize === false || $actualSize < 1 || $actualSize > 5 * 1024 * 1024) {
        throw new DomainException("Chat images must be between 1 byte and 5 MB.", 413);
    }
    if (!function_exists("finfo_open") || !function_exists("getimagesize")) {
        throw new RuntimeException("Image inspection is unavailable.");
    }
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        throw new RuntimeException("Image MIME inspection is unavailable.");
    }
    $mimeType = finfo_file($fileInfo, $upload["tmp_name"]);
    finfo_close($fileInfo);
    $allowedTypes = [
        "image/jpeg" => IMAGETYPE_JPEG,
        "image/png" => IMAGETYPE_PNG,
        "image/webp" => IMAGETYPE_WEBP
    ];
    $imageInfo = @getimagesize($upload["tmp_name"]);
    if (
        !is_string($mimeType)
        || !isset($allowedTypes[$mimeType])
        || !is_array($imageInfo)
        || ($imageInfo["mime"] ?? null) !== $mimeType
        || ($imageInfo[2] ?? null) !== $allowedTypes[$mimeType]
        || !is_int($imageInfo[0] ?? null)
        || !is_int($imageInfo[1] ?? null)
        || $imageInfo[0] < 1
        || $imageInfo[1] < 1
        || $imageInfo[0] > 10000
        || $imageInfo[1] > 10000
        || $imageInfo[0] > intdiv(40_000_000, $imageInfo[1])
    ) {
        throw new DomainException("Only valid JPEG, PNG, and WebP images are supported.", 400);
    }

    return ["tmp_name" => $upload["tmp_name"], "mime_type" => $mimeType];
}

function chat_media_storage_directory(): string
{
    $configured = getenv("CHAT_MEDIA_DIR");
    $directory = is_string($configured) && $configured !== ""
        ? $configured
        : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "private-chat-images";
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("The private chat image directory could not be created.");
    }
    $resolved = realpath($directory);
    if ($resolved === false || !is_dir($resolved) || !chmod($resolved, 0700)) {
        throw new RuntimeException("The private chat image directory could not be secured.");
    }
    $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? "";
    $publicRoot = is_string($documentRoot) && $documentRoot !== "" ? realpath($documentRoot) : false;
    if (
        $publicRoot !== false
        && ($resolved === $publicRoot || str_starts_with(
            $resolved . DIRECTORY_SEPARATOR,
            rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        ))
    ) {
        throw new RuntimeException("Chat images must be stored outside the public web root.");
    }

    return $resolved;
}

function chat_media_store_upload(array $upload): array
{
    $directory = chat_media_storage_directory();
    $storageKey = bin2hex(random_bytes(32));
    $path = $directory . DIRECTORY_SEPARATOR . $storageKey . ".image";
    $reservation = fopen($path, "x");
    if ($reservation === false) {
        throw new RuntimeException("A unique chat image filename could not be reserved.");
    }
    fclose($reservation);
    if (!chmod($path, 0600)) {
        if (!unlink($path)) {
            error_log("Failed to remove a chat image reservation with unsafe permissions.");
        }
        throw new RuntimeException("The chat image file could not be secured.");
    }
    if (!move_uploaded_file($upload["tmp_name"], $path)) {
        if (!unlink($path)) {
            error_log("Failed to remove a reserved chat image after upload failed.");
        }
        throw new RuntimeException("The uploaded chat image could not be stored.");
    }
    if (!chmod($path, 0600)) {
        if (!unlink($path)) {
            error_log("Failed to remove an unsecured chat image.");
        }
        throw new RuntimeException("The stored chat image could not be secured.");
    }

    return ["storage_key" => $storageKey, "path" => $path];
}
