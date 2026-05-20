<?php

declare(strict_types=1);

$localConfigPath = __DIR__ . '/admin.local.php';

if (is_file($localConfigPath)) {
    return require $localConfigPath;
}

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);

    return $value === false ? $default : (string) $value;
};

return [
    'login' => $env('AKINO_ADMIN_LOGIN', 'akino_admin'),
    'password' => $env('AKINO_ADMIN_PASSWORD', ''),
    'display_name' => $env('AKINO_ADMIN_DISPLAY_NAME', 'Akino admin'),
    'avatar_path' => $env('AKINO_ADMIN_AVATAR_PATH', 'img/people/image_2025-11-10_00-02-43.png'),
];
