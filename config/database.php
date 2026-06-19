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

$databaseUrl = trim($env('AKINO_DATABASE_URL'));

if ($databaseUrl === '') {
    $databaseUrl = trim($env('DATABASE_URL'));
}

if ($databaseUrl === '') {
    $databaseUrl = trim($env('MYSQL_URL'));
}

$urlConfig = [];

if ($databaseUrl !== '') {
    $parts = parse_url($databaseUrl);

    if (is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['mysql', 'mariadb'], true)) {
        $urlConfig = [
            'host' => (string) ($parts['host'] ?? ''),
            'port' => (int) ($parts['port'] ?? 3306),
            'database' => rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/')),
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
        ];
    }
}

$envOrUrl = static function (string $envName, string $urlKey, string $default = '') use ($env, $urlConfig): string {
    $value = trim($env($envName));

    if ($value !== '') {
        return $value;
    }

    $urlValue = trim((string) ($urlConfig[$urlKey] ?? ''));

    return $urlValue !== '' ? $urlValue : $default;
};

return [
    'host' => $envOrUrl('AKINO_DB_HOST', 'host', 'mysql-8.0'),
    'port' => $envInt('AKINO_DB_PORT', (int) ($urlConfig['port'] ?? 3306)),
    'database' => $envOrUrl('AKINO_DB_DATABASE', 'database', 'akino_app'),
    'username' => $envOrUrl('AKINO_DB_USERNAME', 'username', 'akino_app_user'),
    'password' => $envOrUrl('AKINO_DB_PASSWORD', 'password'),
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
