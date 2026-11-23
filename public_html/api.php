<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/config/local.php')) {
    http_response_code(503);
    header('Cache-Control: no-store');
    exit;
}
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Http/AuthController.php';

$route = $_GET['r'] ?? '';
if (!in_array($route, ['bootstrap', 'login', 'logout'], true)) {
    Sfera\fail(404, 'Адрес не найден.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Sfera\csrfCheck();
}
Sfera\rateLimit('request-ip', Sfera\ipHash(), 12000, 60);
Sfera\authController($route);
