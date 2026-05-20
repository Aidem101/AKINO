<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    $query = trim((string) ($_GET['q'] ?? ''));

    if (mb_strlen($query, 'UTF-8') > 80) {
        $query = mb_substr($query, 0, 80, 'UTF-8');
    }

    if (mb_strlen($query, 'UTF-8') < 2) {
        json_response([
            'ok' => true,
            'items' => [],
        ]);
    }

    json_response([
        'ok' => true,
        'items' => search_catalog_suggestions($query, 8),
    ]);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'items' => [],
    ], 500);
}
