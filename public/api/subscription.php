<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    require_post();

    $user = require_auth_user();
    activate_subscription((int) $user['id']);
    $updatedUser = find_user_by_id((int) $user['id']) ?? $user;

    json_response([
        'ok' => true,
        'message' => 'Подписка AKINO активирована.',
        'user' => build_account_user_payload($updatedUser),
    ]);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось активировать подписку. Попробуйте позже.',
    ], 500);
}
