<?php

declare(strict_types=1);

$publicRoot = dirname(__DIR__) . '/public';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';

if ($requestPath === '/' || $requestPath === '') {
    $requestPath = '/index.php';
}

$relativePath = ltrim($requestPath, '/');

if (!str_ends_with($relativePath, '.php')) {
    $relativePath = rtrim($relativePath, '/') . '.php';
}

$target = realpath($publicRoot . '/' . $relativePath);
$publicRootReal = realpath($publicRoot);

if (
    $target === false
    || $publicRootReal === false
    || !str_starts_with($target, $publicRootReal . DIRECTORY_SEPARATOR)
    || pathinfo($target, PATHINFO_EXTENSION) !== 'php'
) {
    http_response_code(404);
    require $publicRoot . '/Error.php';
    return;
}

require $target;
