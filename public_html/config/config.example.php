<?php
// Установщик создаёт config/local.php автоматически. Этот файл — справка для разработчика.
return [
    "environment" => "production", // HTTPS обязателен. local/testing — только для разработки.
    "base_url" => "https://example.com",
    "db" => [
        "dsn" => "mysql:host=localhost;dbname=ACCOUNT_sfera;charset=utf8mb4",
        "user" => "ACCOUNT_sfera",
        "password" => "",
    ],
    "app_key" => "", // автоматически random_bytes(32)
    "message_key_file" =>
        "/absolute/path/outside/public_html/sfera-vault/message-key.php",
    "storage" => "/absolute/path/outside/public_html/sfera-vault",
    "persistent_session_idle" => 604800,
    "persistent_session_absolute" => 2592000,
    "image_max_bytes" => 20971520,
    "image_max_pixels" => 64000000,
    "hsts" => true,
];
