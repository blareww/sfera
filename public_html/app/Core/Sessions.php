<?php
declare(strict_types=1);
namespace Sfera;

// Keep login/admin usable while an existing 0.2 installation awaits migration.
function mobileSchemaReady(): bool
{
    static $ready;
    return $ready ??= (bool) one(
        "SELECT 1 FROM schema_migrations WHERE version=?",
        ["004_mobile_refinements.sql"],
    );
}
function requireMobileSchema(): void
{
    if (!mobileSchemaReady()) {
        fail(
            503,
            "Администратору нужно обновить базу: Админка → Обслуживание → Применить обновление БД.",
        );
    }
}
function sessionFingerprint(): string
{
    return hash_hmac("sha256", session_id(), config()["app_key"]);
}
function sessionDevice(): string
{
    $ua = substr($_SERVER["HTTP_USER_AGENT"] ?? "", 0, 512);
    $browser = match (true) {
        str_contains($ua, "Edg") => "Edge",
        str_contains($ua, "Firefox") || str_contains($ua, "FxiOS") => "Firefox",
        str_contains($ua, "Chrome") || str_contains($ua, "CriOS") => "Chrome",
        str_contains($ua, "Safari") => "Safari",
        default => "Браузер",
    };
    $device = match (true) {
        str_contains($ua, "iPhone") => "iPhone",
        str_contains($ua, "iPad") => "iPad",
        str_contains($ua, "Android") => "Android",
        str_contains($ua, "Windows") => "Windows",
        str_contains($ua, "Macintosh") => "Mac",
        str_contains($ua, "Linux") => "Linux",
        default => "устройство",
    };
    return $browser . " · " . $device;
}
function sessionIpHint(): string
{
    $ip = $_SERVER["REMOTE_ADDR"] ?? "";
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return implode(".", array_slice(explode(".", $ip), 0, 2)) . ".*.*";
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return bin2hex(substr(inet_pton($ip), 0, 4)) . "::/32";
    }
    return "Не определён";
}
function registerSession(array $u): void
{
    if (!mobileSchemaReady()) {
        return;
    }
    query(
        "INSERT INTO user_sessions(user_id,session_hash,session_version,device_name,ip_hint,created_at,last_activity,expires_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(),?)",
        [
            $u["id"],
            sessionFingerprint(),
            $u["session_version"],
            sessionDevice(),
            sessionIpHint(),
            gmdate("Y-m-d H:i:s", $_SESSION["born"]),
            gmdate(
                "Y-m-d H:i:s",
                $_SESSION["born"] + config()["session_absolute"],
            ),
        ],
    );
    $_SESSION["tracked"] = true;
}
function checkSessionRegistry(array $u): bool
{
    if (!mobileSchemaReady()) {
        return true;
    }
    $s = one("SELECT * FROM user_sessions WHERE session_hash=? AND user_id=?", [
        sessionFingerprint(),
        $u["id"],
    ]);
    if (!$s) {
        // Adopt pre-upgrade sessions once, never re-create revoked/missing tracked sessions.
        if (!empty($_SESSION["tracked"])) {
            return false;
        }
        registerSession($u);
        return true;
    }
    $_SESSION["tracked"] = true;
    if (
        $s["revoked_at"] ||
        (int) $s["session_version"] !== (int) $u["session_version"] ||
        strtotime($s["expires_at"]) <= time()
    ) {
        return false;
    }
    if (time() - strtotime($s["last_activity"]) >= 60) {
        query(
            "UPDATE user_sessions SET last_activity=UTC_TIMESTAMP() WHERE id=? AND revoked_at IS NULL",
            [$s["id"]],
        );
    }
    // Adopt a still-valid pre-update login; never revive expired/revoked rows.
    if (empty($_SESSION["persistent_cookie"])) {
        query(
            "UPDATE user_sessions SET expires_at=? WHERE id=? AND revoked_at IS NULL",
            [
                gmdate(
                    "Y-m-d H:i:s",
                    $_SESSION["born"] + config()["session_absolute"],
                ),
                $s["id"],
            ],
        );
    }
    return true;
}
function revokeCurrentSession(): void
{
    if (isset($_SESSION["uid"]) && mobileSchemaReady()) {
        query(
            "UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP() WHERE session_hash=? AND user_id=? AND revoked_at IS NULL",
            [sessionFingerprint(), $_SESSION["uid"]],
        );
    }
}
function sessionDto(array $s, array $u): array
{
    $expired =
        strtotime($s["expires_at"] . " UTC") <= time() ||
        strtotime($s["last_activity"] . " UTC") <=
            time() - config()["session_idle"];
    $closed =
        $s["revoked_at"] ||
        (int) $s["session_version"] !== (int) $u["session_version"];
    return [
        "id" => (int) $s["id"],
        "current" => hash_equals($s["session_hash"], sessionFingerprint()),
        "device" => $s["device_name"],
        "ip_hint" => $s["ip_hint"],
        "created_at" => $s["created_at"],
        "last_activity" => $s["last_activity"],
        "status" => $closed ? "closed" : ($expired ? "expired" : "active"),
    ];
}
function sessionsController(array $u, array $parts): never
{
    requireMobileSchema();
    $uid = (int) $u["id"];
    if (count($parts) === 2 && $parts[1] === "history") {
        method("GET");
        $sessions = rows(
            "SELECT * FROM user_sessions WHERE user_id=? AND id<? AND created_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY ORDER BY id DESC LIMIT 30",
            [$uid, cursor()],
        );
        respond([
            "items" => array_map(fn($s) => sessionDto($s, $u), $sessions),
            "next_cursor" =>
                count($sessions) === 30 ? (int) end($sessions)["id"] : null,
        ]);
    }
    if (count($parts) === 1) {
        method("GET");
        $sessions = rows(
            "SELECT * FROM user_sessions WHERE user_id=? AND session_version=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() AND last_activity>? ORDER BY last_activity DESC LIMIT 100",
            [
                $uid,
                $u["session_version"],
                gmdate("Y-m-d H:i:s", time() - config()["session_idle"]),
            ],
        );
        respond([
            "items" => array_map(fn($s) => sessionDto($s, $u), $sessions),
        ]);
    }
    if (count($parts) !== 2) {
        fail(404, "Адрес не найден.");
    }
    method("DELETE");
    only(input(), []);
    $s = one("SELECT * FROM user_sessions WHERE id=? AND user_id=?", [
        id($parts[1]),
        $uid,
    ]);
    if (!$s) {
        fail(404, "Сессия не найдена.");
    }
    query(
        "UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND revoked_at IS NULL",
        [$s["id"], $uid],
    );
    $current = hash_equals($s["session_hash"], sessionFingerprint());
    if ($current) {
        destroySession();
    }
    respond(["ok" => true, "signed_out" => $current]);
}
