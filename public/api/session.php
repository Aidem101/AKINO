<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    $user = current_user_payload();

    json_response([
        'ok' => true,
        'authenticated' => $user !== null,
        'user' => $user,
    ]);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось прочитать сессию. Попробуйте позже.',
    ], 500);
}
