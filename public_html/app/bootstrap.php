<?php
declare(strict_types=1);
namespace Sfera;
function config(): array
{
    static $config;
    if (!$config) {
        $path = dirname(__DIR__) . "/config/local.php";
        if (!is_file($path)) {
            throw new \RuntimeException("Installation incomplete");
        }
        $config = require $path;
        // 0.5.1 removes the free-plan quotas without rewriting private config.
        $config["upload_max_bytes"] = $config["image_max_bytes"] ?? 20971520;
        $config["upload_max_pixels"] = $config["image_max_pixels"] ?? 64000000;
        $config["session_idle"] = $config["persistent_session_idle"] ?? 604800;
        $config["session_absolute"] =
            $config["persistent_session_absolute"] ?? 2592000;
        if (!preg_match('/^[a-f0-9]{64}$/D', $config["app_key"] ?? "")) {
            throw new \RuntimeException("Invalid app key");
        }
        if (
            !in_array(
                $config["environment"],
                ["production", "local", "testing"],
                true,
            )
        ) {
            throw new \RuntimeException("Invalid environment");
        }
    }
    return $config;
}
function siteSettings(): array
{
    return array_column(rows("SELECT setting_key, setting_value FROM site_settings"), "setting_value", "setting_key");
}
ini_set("display_errors", "0");
ini_set("log_errors", "0");
error_reporting(E_ALL);
date_default_timezone_set("UTC");
foreach (
    [
        "Core/Db",
        "Core/Http",
        "Core/Security",
        "Core/Auth",
        "Core/Sessions",
    ]
    as $file
) {
    require_once __DIR__ . "/" . $file . ".php";
}
set_exception_handler(function (\Throwable $e): void {
    $code = $e instanceof HttpError ? $e->status : 500;
    $message =
        $e instanceof HttpError
            ? $e->getMessage()
            : "Не удалось выполнить запрос. Попробуйте позже.";
    if ($code === 500) {
        $ref = bin2hex(random_bytes(8));
        // Deliberately omit exception message, query, paths and request payload.
        $path = config()["storage"] . "/logs/error.log";
        error_log(
            gmdate("c") . " " . $ref . " " . get_class($e) . PHP_EOL,
            3,
            $path,
        );
    }
    if (PHP_SAPI === "cli") {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode(
        ["error" => ["status" => $code, "message" => $message]],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
    );
});
if (PHP_SAPI !== "cli") {
    securityHeaders();
    $config = config();
    if (
        $config["environment"] === "production" &&
        ($_SERVER["HTTPS"] ?? "") !== "on"
    ) {
        header("Location: " . rtrim($config["base_url"], "/") . "/", true, 308);
        exit();
    }
    if (
        in_array($config["environment"], ["local", "testing"], true) &&
        !in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true)
    ) {
        fail(503, "Тестовое окружение недоступно.");
    }
    $postLimit = ini_get("post_max_size");
    $factor = match (strtolower(substr($postLimit, -1))) {
        "g" => 1073741824,
        "m" => 1048576,
        "k" => 1024,
        default => 1,
    };
    $maxBody = min(25165824, (int) $postLimit * $factor ?: 25165824);
    if ((int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > $maxBody) {
        fail(413, "Слишком большой запрос.");
    }
    startSession();
}
