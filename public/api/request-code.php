<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    require_post();

    $input = request_input();
    $phone = normalize_phone((string) ($input['phone'] ?? ''));
    $intent = ($input['intent'] ?? 'login') === 'subscribe' ? 'subscribe' : 'login';

    if ($phone === null) {
        json_response([
            'ok' => false,
            'message' => 'Введите корректный номер телефона.',
        ], 422);
    }

    $existingUser = find_user_by_phone($phone);

    if ($existingUser && user_is_blocked($existingUser)) {
        json_response([
            'ok' => false,
            'message' => 'Аккаунт по этому номеру временно заблокирован. Обратитесь в поддержку AKINO.',
        ], 403);
    }

    $request = create_auth_code_request($phone, $intent);
    $demoAuthEnabled = akino_demo_auth_enabled();

    $payload = [
        'ok' => true,
        'message' => $demoAuthEnabled
            ? ($existingUser
            ? 'Код входа создан. В локальной версии он показан ниже.'
            : 'Новый аккаунт будет создан после подтверждения кода.')
            : 'Код входа создан.',
        'requestId' => $request['id'],
        'phone' => format_phone($phone),
        'intent' => $intent,
    ];

    if ($demoAuthEnabled) {
        $payload['demoCode'] = $request['code'];
    }

    json_response($payload);
} catch (AuthCodeException $exception) {
    json_response([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 429);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось создать код входа. Попробуйте позже.',
    ], 500);
}
