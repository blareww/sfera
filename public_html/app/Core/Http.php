<?php
declare(strict_types=1);
namespace Sfera;
class HttpError extends \RuntimeException
{
    public function __construct(
        public int $status,
        string $message,
        public array $details = [],
    ) {
        parent::__construct($message);
    }
}
function fail(int $status, string $message, array $details = []): never
{
    throw new HttpError($status, $message, $details);
}
function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(
        ["data" => $data],
        JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE |
            JSON_THROW_ON_ERROR,
    );
    exit();
}
function input(): array
{
    static $data;
    if ($data !== null) {
        return $data;
    }
    if (str_starts_with($_SERVER["CONTENT_TYPE"] ?? "", "application/json")) {
        $raw = file_get_contents("php://input", false, null, 0, 6500001);
        if (strlen($raw) > 6500000) {
            fail(413, "Слишком большой запрос.");
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            fail(400, "Некорректный JSON.");
        }
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            fail(422, "Ожидается объект.");
        }
        return $data;
    }
    return $data = $_POST;
}
function id(mixed $value): int
{
    if (
        !(is_int($value) || is_string($value)) ||
        !preg_match('/^[1-9][0-9]{0,14}$/D', (string) $value)
    ) {
        fail(422, "Некорректный ID.");
    }
    return (int) $value;
}
function cursor(): int
{
    return isset($_GET["before"]) && $_GET["before"] !== ""
        ? id($_GET["before"])
        : PHP_INT_MAX;
}
function field(array $data, string $key, int $max, int $min = 0): string
{
    $value = $data[$key] ?? "";
    if (!is_string($value) || !mb_check_encoding($value, "UTF-8")) {
        fail(422, "Некорректное поле: " . $key);
    }
    $value = trim($value);
    if (
        mb_strlen($value) > $max ||
        mb_strlen($value) < $min ||
        str_contains($value, "\0")
    ) {
        fail(422, "Проверьте длину поля: " . $key);
    }
    return $value;
}
function choice(mixed $value, array $allowed): string
{
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        fail(422, "Недопустимое значение.");
    }
    return $value;
}
function boolValue(mixed $value): int
{
    if (!in_array($value, [true, false, 0, 1], true)) {
        fail(422, "Ожидается логическое значение.");
    }
    return (int) $value;
}
function only(array $data, array $allowed): void
{
    if (array_diff(array_keys($data), $allowed)) {
        fail(422, "Запрос содержит неподдерживаемые поля.");
    }
}
function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
function method(string ...$expected): void
{
    if (!in_array($_SERVER["REQUEST_METHOD"] ?? "GET", $expected, true)) {
        header("Allow: " . implode(", ", $expected));
        fail(405, "Метод не поддерживается.");
    }
}
