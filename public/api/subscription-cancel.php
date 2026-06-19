<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    require_post();

    $user = require_auth_user();
    $userId = (int) $user['id'];
    $wasActive = get_active_subscription_row($userId) !== null;

    cancel_subscription($userId);
    $updatedUser = find_user_by_id($userId) ?? $user;

    json_response([
        'ok' => true,
        'message' => $wasActive
            ? 'Подписка AKINO отменена. Доступ прекращён.'
            : 'Подписка уже не активна.',
        'user' => build_account_user_payload($updatedUser),
    ]);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось отменить подписку. Попробуйте ещё раз.',
    ], 500);
}
