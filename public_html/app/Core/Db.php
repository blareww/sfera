<?php
declare(strict_types=1);
namespace Sfera;
use PDO;
function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $c = config()["db"];
        $pdo = new PDO($c["dsn"], $c["user"], $c["password"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec(
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        );
    }
    return $pdo;
}
function query(string $sql, array $params = []): \PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function one(string $sql, array $params = []): ?array
{
    return query($sql, $params)->fetch() ?: null;
}
function rows(string $sql, array $params = []): array
{
    return query($sql, $params)->fetchAll();
}
function transaction(callable $callback): mixed
{
    db()->beginTransaction();
    try {
        $result = $callback();
        db()->commit();
        return $result;
    } catch (\Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}
function placeholders(array $items): string
{
    return implode(",", array_fill(0, count($items), "?"));
}
