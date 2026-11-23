<?php
declare(strict_types=1);
namespace Sfera;
function startSession(): void
{
    $secure =
        config()["environment"] === "production" ||
        ($_SERVER["HTTPS"] ?? "") === "on";
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");
    ini_set("session.gc_maxlifetime", (string) config()["session_absolute"]);
    session_name($secure ? "__Host-sfera" : "sfera_local");
    session_save_path(config()["storage"] . "/sessions");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => $secure,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    session_start();
    if (
        isset($_SESSION["uid"]) &&
        (time() - ($_SESSION["seen"] ?? 0) > config()["session_idle"] ||
            time() - ($_SESSION["born"] ?? 0) > config()["session_absolute"])
    ) {
        destroySession();
        session_start();
    }
    csrfToken();
}
function destroySession(): void
{
    revokeCurrentSession();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    $p = session_get_cookie_params();
    setcookie(session_name(), "", [
        "expires" => time() - 3600,
        "path" => $p["path"],
        "secure" => $p["secure"],
        "httponly" => true,
        "samesite" => "Lax",
    ]);
}
function persistSessionCookie(): void
{
    if (!empty($_SESSION["persistent_cookie"])) {
        return;
    }
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        "expires" => $_SESSION["born"] + config()["session_absolute"],
        "path" => "/",
        "secure" => $p["secure"],
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    $_SESSION["persistent_cookie"] = true;
}
function currentUser(bool $required = true): ?array
{
    if (!isset($_SESSION["uid"])) {
        if ($required) {
            fail(401, "Войдите в аккаунт.");
        }
        return null;
    }
    $u = one("SELECT * FROM users WHERE id=?", [$_SESSION["uid"]]);
    if (
        !$u ||
        $u["banned_at"] ||
        (int) $u["session_version"] !== ($_SESSION["sv"] ?? 0) ||
        !checkSessionRegistry($u)
    ) {
        destroySession();
        session_start();
        csrfToken();
        if ($required) {
            fail(401, "Сессия завершена. Войдите снова.");
        }
        return null;
    }
    $_SESSION["seen"] = time();
    persistSessionCookie();
    return $u;
}
function signIn(array $u): void
{
    revokeCurrentSession();
    session_regenerate_id(true);
    $_SESSION = [
        "uid" => (int) $u["id"],
        "sv" => (int) $u["session_version"],
        "born" => time(),
        "seen" => time(),
        "csrf" => bin2hex(random_bytes(32)),
    ];
    registerSession($u);
    persistSessionCookie();
}
function publicUser(array $u, ?int $viewer = null): array
{
    return [
        "id" => (int) $u["id"],
        "username" => $u["username"],
        "display_name" => $u["display_name"],
        "verified" => (bool) $u["verified"],
        "avatar_id" => isset($u["avatar_id"]) ? (int) $u["avatar_id"] : null,
        "online" =>
            $viewer !== null
                ? ($activity = onlineStatus($viewer, $u)) !== null &&
                    strtotime($activity) >= time() - 120
                : (bool) ($u["online"] ?? false),
    ];
}
function userRow(int $id): array
{
    $u = one(
        "SELECT u.*,p.* FROM users u JOIN profiles p ON p.user_id=u.id WHERE u.id=? AND u.banned_at IS NULL",
        [$id],
    );
    if (!$u) {
        fail(404, "Пользователь не найден.");
    }
    return $u;
}
