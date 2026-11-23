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
        <section class="feed" aria-labelledby="feed-title">
            <h2 id="feed-title">Лента</h2>
            <div id="empty-feed" class="empty-feed">
                <p class="empty-feed-title">Публикаций пока нет</p>
                <p>Начните с первой записи.</p>
                <button id="open-composer" class="primary-button" type="button">Создать публикацию</button>
            </div>
            <form id="composer" class="composer" aria-labelledby="composer-title" hidden>
                <h3 id="composer-title">Новая публикация</h3>
                <label for="post-text">Текст публикации</label>
                <textarea id="post-text" rows="5" maxlength="2000" aria-describedby="composer-hint" required></textarea>
                <p id="composer-hint">На этом этапе запись можно только посмотреть. Она не сохраняется.</p>
                <div class="composer-actions">
                    <button id="preview-button" class="primary-button" type="submit" disabled>Предпросмотр</button>
                    <button id="close-composer" type="button">Назад к ленте</button>
                </div>
                <div id="post-preview" class="post-preview" role="status" hidden>
                    <h4>Предпросмотр публикации</h4>
                    <p id="preview-text"></p>
                </div>
            </form>
        </section>
    </section>
    <noscript>Для входа включите JavaScript.</noscript>
</main>
</body>
</html>
