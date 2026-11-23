<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/config/local.php')) {
    header('Location: /install.php', true, 302);
    exit;
}
require __DIR__ . '/app/bootstrap.php';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sfera</title>
<link rel="stylesheet" href="/assets/css/starter.css">
<script type="module" src="/assets/js/starter.js"></script>
</head>
<body>
<main>
    <h1>Sfera</h1>
    <p id="status" role="status">Загрузка…</p>
    <form id="login" hidden>
        <label>Логин или email <input name="login" autocomplete="username" required></label>
        <label>Пароль <input name="password" type="password" autocomplete="current-password" required></label>
        <button>Войти</button>
    </form>
    <section id="home" hidden>
        <h2>Главная</h2>
        <p id="greeting"></p>
        <button id="logout" type="button">Выйти</button>
    </section>
    <noscript>Для входа включите JavaScript.</noscript>
</main>
</body>
</html>
