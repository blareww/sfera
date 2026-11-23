<?php
declare(strict_types=1);
namespace Sfera;
function authController(string $route): never
{
    if ($route === "bootstrap") {
        method("GET");
        $u = currentUser(false);
        respond([
            "csrf" => csrfToken(),
            "user" => $u ? publicUser($u) : null,
        ]);
    }
    method("POST");
    $d = input();
    if ($route === "logout") {
        destroySession();
        respond(["ok" => true]);
    }
    if ($route === "login") {
        only($d, ["login", "password"]);
        $login = strtolower(field($d, "login", 254, 1));
        $password = $d["password"] ?? "";
        if (!is_string($password) || strlen($password) > 1024) {
            fail(422, "Проверьте пароль.");
        }
        rateLimit("login-ip", ipHash(), 30, 900);
        rateLimit("login-account", hash("sha256", $login), 10, 900);
        $u = one("SELECT * FROM users WHERE username=? OR email=?", [
            $login,
            $login,
        ]);
        // A real fixed bcrypt hash equalizes unknown-account verification work.
        $valid = password_verify(
            $password,
            $u["password_hash"] ??
                '$2y$12$O8EvTrYi.LbtSyRcxeKK6OAisMd47DHNCuiy5Tm51OmuXD.nNu5WW',
        );
        $ok = $u && $valid && !$u["banned_at"];
        query(
            "INSERT INTO login_attempts(account_hash,ip_hash,success) VALUES(?,?,?)",
            [
                hash_hmac("sha256", $login, config()["app_key"]),
                ipHash(),
                (int) $ok,
            ],
        );
        if (!$ok) {
            if ($u && in_array($u["role"], ["admin", "moderator"], true)) {
                audit("admin.login_failed", "user", (int) $u["id"], null);
            }
            usleep(random_int(120000, 250000));
            fail(401, "Неверные данные входа.");
        }
        if (
            siteSettings()["maintenance"] === "1" &&
            !permission($u, "admin.access")
        ) {
            fail(503, "Ведутся технические работы.");
        }
        if (
            password_needs_rehash(
                $u["password_hash"],
                defined("PASSWORD_ARGON2ID")
                    ? PASSWORD_ARGON2ID
                    : PASSWORD_BCRYPT,
                defined("PASSWORD_ARGON2ID")
                    ? ["memory_cost" => 65536, "time_cost" => 3, "threads" => 1]
                    : ["cost" => 12],
            )
        ) {
            query("UPDATE users SET password_hash=? WHERE id=?", [
                hashPassword($password),
                $u["id"],
            ]);
        }
        signIn($u);
        if (permission($u, "admin.access")) {
            audit("admin.login", "user", (int) $u["id"]);
        }
        respond([
            "user" => publicUser($u),
            "csrf" => csrfToken(),
        ]);
    }
    fail(404, "Адрес не найден.");
}
