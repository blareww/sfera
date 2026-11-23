<?php
declare(strict_types=1);

// Router for `php -S 127.0.0.1:8080` only. Apache uses public_html/.htaccess.
$root = dirname(__DIR__) . '/public_html';
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = is_string($requestPath) ? rawurldecode($requestPath) : '';
$entry = match ($path) {
    '/', '/index.php' => '/index.php',
    '/api.php', '/install.php' => $path,
    default => null,
};

if ($entry !== null) {
    $_SERVER['SCRIPT_NAME'] = $entry;
    $_SERVER['SCRIPT_FILENAME'] = $root . $entry;
    require $root . $entry;
    return true;
}
if (
    preg_match('~^/assets/(?:favicon\.svg|css/[a-z0-9-]+\.css|js/[a-z0-9-]+\.js)$~D', $path) &&
    is_file($root . $path)
) {
    return false;
}
http_response_code(404);
return true;
