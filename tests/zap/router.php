<?php

declare(strict_types=1);

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$requestPath = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
$candidate = $documentRoot !== false
    ? realpath($documentRoot . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $requestPath), DIRECTORY_SEPARATOR))
    : false;

if (
    $documentRoot !== false
    && $candidate !== false
    && is_file($candidate)
    && str_starts_with($candidate, $documentRoot . DIRECTORY_SEPARATOR)
    && strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION)) !== 'php'
) {
    $basename = basename($candidate);

    if (str_starts_with($basename, '.')) {
        http_response_code(404);
        exit;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('X-Permitted-Cross-Domain-Policies: none');
    header("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");

    $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
    $contentTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($candidate));
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($candidate)) . ' GMT');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($candidate);
    }

    exit;
}

return false;
