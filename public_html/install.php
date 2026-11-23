<?php
declare(strict_types=1);
use function Sfera\{hashPassword, migrateDatabase};

ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if (is_file(__DIR__ . '/config/local.php')) {
    http_response_code(404);
    exit;
}
if (($_SERVER['HTTPS'] ?? '') !== 'on') {
    http_response_code(403);
    exit('Откройте установщик по HTTPS.');
}
if (PHP_VERSION_ID < 80200) {
    exit('Включите PHP 8.2 или новее в настройках сайта.');
}
$tokenFile = dirname(__DIR__) . '/sfera-install-token.txt';
$setupToken = is_file($tokenFile) ? trim((string) @file_get_contents($tokenFile, false, null, 0, 256)) : '';
if (!preg_match('/^[a-zA-Z0-9_-]{32,128}$/D', $setupToken)) {
    http_response_code(503);
    exit('Создайте файл sfera-install-token.txt рядом с public_html по инструкции.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || !hash_equals($setupToken, trim($token))) {
        http_response_code(403);
        exit('Проверьте код установки.');
    }
    $options = array_intersect_key($_POST, array_flip(['db-name','admin','email']));
    $options['db-user'] = $options['db-name'] ?? '';
    $mysql8 = ($_POST['mysql'] ?? '') === '8';
    $options['db-host'] = $mysql8 ? '127.0.0.1' : 'localhost';
    $options['db-port'] = $mysql8 ? '3308' : '3306';
    $options['url'] = 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
    $web = __DIR__;
    $root = dirname($web);
    require $web . '/app/Core/Migrations.php';
    require $web . '/app/Core/Security.php';
    header('Content-Type: text/plain; charset=utf-8');
    umask(0077);

    $stage = 'проверка параметров';
    $temporaryLoader = null;
    $vault = null;
    $published = false;
    $status = 0;
    try {
        foreach (['pdo_mysql', 'gd', 'fileinfo', 'mbstring', 'openssl'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new InvalidArgumentException("Включите расширение PHP $extension.");
            }
        }
        $host = $options['db-host'] ?? '127.0.0.1';
        $port = $options['db-port'] ?? '3306';
        $database = $options['db-name'] ?? '';
        $dbUser = $options['db-user'] ?? '';
        $username = $options['admin'] ?? '';
        $email = $options['email'] ?? '';
        $url = $options['url'] ?? 'http://127.0.0.1:8080';
        $dbPassword = ($_POST['db-password'] ?? false);
        $password = ($_POST['password'] ?? false);
        if (
            !preg_match('/^[a-z0-9_.-]+$/iD', $host) ||
            !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 ||
            !preg_match('/^[a-z0-9_-]+$/iD', $database) ||
            $dbUser === '' || $dbPassword === false || $dbPassword === ''
        ) {
            throw new InvalidArgumentException('Укажите имя новой БД, хост, порт, пользователя и пароль базы.');
        }
        if (
            !preg_match('/^[a-z][a-z0-9_]{2,31}$/D', $username) ||
            strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            $password === false || strlen($password) < 12 || strlen($password) > 72
        ) {
            throw new InvalidArgumentException('Нужны логин администратора (3–32 символа a–z, 0–9, _, первая буква), email и пароль администратора (12–72 байта).');
        }
        $local = $url === 'http://127.0.0.1:8080';
        if (!$local && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?/?$~iD', $url))) {
            throw new InvalidArgumentException('Укажите адрес HTTPS без пути или используйте локальный адрес по умолчанию.');
        }

        $stage = 'блокировка установки';
        $lock = @fopen($web . '/config/.installing', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new InvalidArgumentException('Проверьте права на config и завершите другие процессы установки.');
        }
        $loader = $web . '/config/local.php';
        if (file_exists($loader) || is_link($loader)) {
            throw new InvalidArgumentException('В этой папке Sfera уже настроена. Используйте отдельную чистую копию.');
        }

        $stage = 'подключение к БД';
        $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbLock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $dbLock->execute(['sfera-install:' . substr(hash('sha256', $database), 0, 40)]);
        if ((int) $dbLock->fetchColumn() !== 1) {
            throw new InvalidArgumentException('На этой базе уже выполняется установка.');
        }
        $dbLock->closeCursor();
        if ($pdo->query('SHOW FULL TABLES')->fetchColumn() !== false) {
            throw new InvalidArgumentException('База содержит таблицы или представления. Создайте отдельную пустую БД.');
        }

        $stage = 'подготовка закрытого хранилища';
        $vaultName = 'sfera-vault-' . bin2hex(random_bytes(12));
        $vaultPath = $root . '/' . $vaultName;
        if (!mkdir($vaultPath, 0700)) {
            throw new RuntimeException('Cannot create vault');
        }
        $vault = $vaultPath;
        foreach (['private', 'sessions', 'logs', 'cache'] as $folder) {
            if (!mkdir($vault . '/' . $folder, 0700)) {
                throw new RuntimeException('Cannot create vault directory');
            }
        }
        $keyFile = $vault . '/message-key.php';
        $config = require $web . '/config/config.example.php';
        $config['environment'] = $local ? 'local' : 'production';
        $config['base_url'] = rtrim($url, '/');
        $config['db'] = ['dsn' => $dsn, 'user' => $dbUser, 'password' => $dbPassword];
        $config['app_key'] = bin2hex(random_bytes(32));
        $config['storage'] = $vault;
        $config['message_key_file'] = $keyFile;
        $config['hsts'] = !$local;

        $temporaryLoader = $web . '/config/.sfera-config-' . bin2hex(random_bytes(12));
        $files = [
            $keyFile => '<?php return ' . var_export(bin2hex(random_bytes(32)), true) . ";\n",
            $vault . '/config.php' => '<?php return ' . var_export($config, true) . ";\n",
            $temporaryLoader => "<?php return require dirname(__DIR__, 2) . '/$vaultName/config.php';\n",
        ];
        foreach ($files as $path => $contents) {
            if (file_put_contents($path, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot write configuration');
            }
        }
        $passwordHash = hashPassword($password);

        $stage = 'применение миграций';
        $migrations = migrateDatabase($pdo, $web . '/database/migrations');
        $stage = 'создание администратора';
        $pdo->beginTransaction();
        $statement = $pdo->prepare("INSERT INTO users(username,email,display_name,password_hash,role) VALUES(?,?,?,?,'admin')");
        $statement->execute([$username, strtolower($email), $username, $passwordHash]);
        $statement = $pdo->prepare('INSERT INTO profiles(user_id) VALUES(?)');
        $statement->execute([$pdo->lastInsertId()]);
        $pdo->exec("UPDATE site_settings SET setting_value='0.1.0' WHERE setting_key='version'");
        $pdo->commit();

        $stage = 'активация сайта';
        if (!rename($temporaryLoader, $loader)) {
            throw new RuntimeException('Cannot activate configuration');
        }
        $published = true;
        echo 'Установка завершена. Применено миграций: ' . count($migrations) . ".\n";
    } catch (InvalidArgumentException $error) {
        printf("%s", $error->getMessage() . "\n");
        $status = 1;
    } catch (Throwable $error) {
        printf("%s", "Сбой на этапе «$stage» (код " . $error->getCode() . ").\n");
        if (in_array($stage, ['применение миграций', 'создание администратора', 'активация сайта'], true)) {
            printf("%s", "База могла измениться. Повторите установку с новой пустой БД.\n");
        }
        $status = 1;
    } finally {
        if (!$published) {
            if ($temporaryLoader !== null && is_file($temporaryLoader)) {
                @unlink($temporaryLoader);
            }
            if ($vault !== null) {
                foreach (['config.php', 'message-key.php'] as $file) {
                    if (is_file($vault . '/' . $file)) {
                        @unlink($vault . '/' . $file);
                    }
                }
                foreach (['private', 'sessions', 'logs', 'cache'] as $folder) {
                    if (is_dir($vault . '/' . $folder)) {
                        @rmdir($vault . '/' . $folder);
                    }
                }
                @rmdir($vault);
            }
        }
    }
    exit;
}
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Установка Sfera</title>
<main><h1>Установка Sfera</h1>
<form method="post" action="/install.php">
<p><label>Код установки <input name="token" type="password" required></label></p>
<fieldset><legend>Пустая база SpaceWeb</legend>
<p><label>Версия MySQL <select name="mysql"><option value="8">8</option><option value="5.7">5.7</option></select></label></p>
<p><label>Полное имя базы <input name="db-name" required></label></p>
<p><label>Пароль базы <input name="db-password" type="password" required></label></p></fieldset>
<fieldset><legend>Новый администратор Sfera</legend>
<p><label>Логин <input name="admin" pattern="[a-z][a-z0-9_]{2,31}" required></label></p>
<p><label>Email <input name="email" type="email" required></label></p>
<p><label>Пароль (12–72 байта) <input name="password" type="password" autocomplete="new-password" required></label></p></fieldset>
<p><button>Установить Sfera</button></p></form></main></html>
