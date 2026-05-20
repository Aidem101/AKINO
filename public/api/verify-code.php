<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    require_post();

    $input = request_input();
    $requestId = (int) ($input['requestId'] ?? 0);
    $code = trim((string) ($input['code'] ?? ''));

    if ($requestId < 1 || !preg_match('/^\d{4}$/', $code)) {
        json_response([
            'ok' => false,
            'message' => 'Введите корректный код из 4 цифр.',
        ], 422);
    }

    $request = verify_auth_code_request($requestId, $code);
    $user = find_user_by_phone($request['phone']) ?? create_user($request['phone']);

    login_user($user);

    if ($request['intent'] === 'subscribe') {
        activate_subscription((int) $user['id']);
    }

    $payload = build_user_payload(find_user_by_id((int) $user['id']) ?? $user);

    json_response([
        'ok' => true,
        'message' => $request['intent'] === 'subscribe'
            ? 'Подписка AKINO активирована.'
            : 'Вход выполнен.',
        'user' => $payload,
        'redirect' => $request['intent'] === 'subscribe'
            ? 'Cabinet.php?tab=subscription&subscribed=1'
            : 'Cabinet.php',
    ]);
} catch (AuthCodeException $exception) {
    json_response([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 422);
} catch (RuntimeException $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось выполнить вход. Попробуйте позже.',
    ], 500);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось подтвердить код. Попробуйте позже.',
    ], 500);
}
