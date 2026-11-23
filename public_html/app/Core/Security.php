<?php
declare(strict_types=1);
namespace Sfera;
function securityHeaders(): void
{
    header(
        "Content-Security-Policy: default-src 'none'; script-src 'self'; worker-src 'self'; style-src 'self'; img-src 'self' blob:; media-src 'self' blob:; connect-src 'self'; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
    );
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: no-referrer");
    header(
        "Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()",
    );
    header("X-Frame-Options: DENY");
    header("Cross-Origin-Resource-Policy: same-origin");
    header("X-Robots-Tag: noindex, nofollow, noarchive");
    header("Cache-Control: private, no-store, max-age=0");
    if (config()["environment"] === "production" && config()["hsts"]) {
        header("Strict-Transport-Security: max-age=31536000");
    }
}
function csrfToken(): string
{
    return $_SESSION["csrf"] ??= bin2hex(random_bytes(32));
}
function csrfCheck(): void
{
    $origin = $_SERVER["HTTP_ORIGIN"] ?? null;
    if ($origin !== null && $origin !== rtrim(config()["base_url"], "/")) {
        fail(403, "Запрос с другого сайта запрещён.");
    }
    if (($_SERVER["HTTP_SEC_FETCH_SITE"] ?? "") === "cross-site") {
        fail(403, "Запрос с другого сайта запрещён.");
    }
    $token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        fail(403, "Сессия формы устарела. Обновите страницу.");
    }
}
function ipHash(): string
{
    return hash_hmac(
        "sha256",
        $_SERVER["REMOTE_ADDR"] ?? "unknown",
        config()["app_key"],
    );
}
function rateLimit(string $scope, string $key, int $max, int $seconds): void
{
    $bucket = hash_hmac("sha256", $scope . ":" . $key, config()["app_key"]);
    $window = (int) floor(time() / $seconds);
    query(
        "INSERT INTO rate_limits(bucket,window_id,hits,expires_at) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1",
        [$bucket, $window, gmdate("Y-m-d H:i:s", ($window + 2) * $seconds)],
    );
    $hits = (int) one(
        "SELECT hits FROM rate_limits WHERE bucket=? AND window_id=?",
        [$bucket, $window],
    )["hits"];
    if ($hits > $max) {
        header("Retry-After: " . (($window + 1) * $seconds - time()));
        fail(429, "Слишком много запросов. Попробуйте немного позже.");
    }
}
function audit(
    string $action,
    string $type = "",
    ?int $target = null,
    ?int $actor = null,
): void {
    query(
        "INSERT INTO admin_audit_log(actor_id,action,target_type,target_id,ip_hash) VALUES(?,?,?,?,?)",
        [
            $actor ?? ($_SESSION["uid"] ?? null),
            $action,
            $type,
            $target,
            ipHash(),
        ],
    );
}
function hashPassword(string $password): string
{
    return defined("PASSWORD_ARGON2ID")
        ? password_hash($password, PASSWORD_ARGON2ID, [
            "memory_cost" => 65536,
            "time_cost" => 3,
            "threads" => 1,
        ])
        : password_hash($password, PASSWORD_BCRYPT, ["cost" => 12]);
}
function validatePassword(mixed $password): string
{
    if (
        !is_string($password) ||
        strlen($password) < 12 ||
        strlen($password) > 72 ||
        str_contains($password, "\0")
    ) {
        fail(
            422,
            "Пароль: от 12 до 72 байт. Используйте длинную уникальную фразу.",
        );
    }
    return $password;
}
function permission(array $user, string $permission): bool
{
    $roles = [
        "user" => [],
        "moderator" => [
            "admin.access",
            "users.read",
            "reports.manage",
            "content.moderate",
            "audit.read",
        ],
        "admin" => [
            "admin.access",
            "users.read",
            "reports.manage",
            "content.moderate",
            "audit.read",
            "users.ban",
            "users.verify",
            "roles.manage",
            "site.manage",
            "security.read",
        ],
    ];
    return in_array($permission, $roles[$user["role"]] ?? [], true);
}
function requirePermission(array $user, string $name): void
{
    if (!permission($user, $name)) {
        fail(403, "Недостаточно прав.");
    }
}
