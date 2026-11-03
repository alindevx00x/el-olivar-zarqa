<?php
/* Developer: Alindevx00x (https://alindevx00x.me) */
declare(strict_types=1);

/**
 * Lightweight .env reader to load environment variables without external dependencies.
 */
function olivar_load_env(string $path): void
{
    static $loaded = false;
    if ($loaded || !file_exists($path) || !is_readable($path)) {
        return;
    }
    $loaded = true;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (getenv($key) === false) {
                putenv("{$key}={$val}");
            }
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $val;
            }
            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = $val;
            }
        }
    }
}

/**
 * Establish a PDO database connection using .env, server environment,
 * CloudPanel fastcgi_params, or sensible local fallbacks.
 */
function database_connection(): PDO
{
    olivar_load_env(dirname(__DIR__) . '/.env');

    $isLocalMamp = is_dir('/Applications/MAMP') && !file_exists(dirname(__DIR__) . '/.env');

    $host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? '127.0.0.1');
    $port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? ($isLocalMamp ? '8889' : '3306'));
    $database = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? ($isLocalMamp ? 'el_olivar_zarqa' : 'elolivarzarqadb'));
    $username = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? ($isLocalMamp ? 'root' : 'elolivarzarqadbusr'));

    $passwordRaw = getenv('DB_PASSWORD');
    if ($passwordRaw === false) {
        $passwordRaw = $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? ($isLocalMamp ? 'root' : '');
    }
    $password = (string) $passwordRaw;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
