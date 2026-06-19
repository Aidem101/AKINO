<?php

declare(strict_types=1);

$localConfigPath = __DIR__ . '/database.local.php';

if (is_file($localConfigPath)) {
    return require $localConfigPath;
}

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);

    return $value === false ? $default : (string) $value;
};

$envInt = static function (string $name, int $default) use ($env): int {
    $value = trim($env($name));

    return $value !== '' && ctype_digit($value) ? (int) $value : $default;
};

$envList = static function (string $name) use ($env): array {
    $value = trim($env($name));

    if ($value === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
};

return [
    'host' => $env('AKINO_DB_HOST', 'mysql-8.0'),
    'port' => $envInt('AKINO_DB_PORT', 3306),
    'database' => $env('AKINO_DB_DATABASE', 'akino_app'),
    'username' => $env('AKINO_DB_USERNAME', 'akino_app_user'),
    'password' => $env('AKINO_DB_PASSWORD', ''),
    'charset' => $env('AKINO_DB_CHARSET', 'utf8mb4'),
    'socket' => $env('AKINO_DB_SOCKET', ''),
    'fallback_hosts' => $envList('AKINO_DB_FALLBACK_HOSTS'),
    'timeout' => $envInt('AKINO_DB_TIMEOUT', 1),
    'ssl_ca' => $env('AKINO_DB_SSL_CA', ''),
    'ssl_verify_server_cert' => !in_array(
        strtolower(trim($env('AKINO_DB_SSL_VERIFY_SERVER_CERT', '1'))),
        ['0', 'false', 'no', 'off'],
        true
    ),
];
