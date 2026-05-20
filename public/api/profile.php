<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    $user = require_auth_user();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response([
            'ok' => true,
            'user' => build_account_user_payload($user),
        ]);
    }

    require_post();

    $input = request_input();
    $phone = normalize_phone((string) ($input['phone'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $gender = trim((string) ($input['gender'] ?? ''));
    $birthDate = parse_birth_date((string) ($input['birthDate'] ?? ''));

    if ($phone === null) {
        json_response([
            'ok' => false,
            'message' => 'Введите корректный номер телефона.',
        ], 422);
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_response([
            'ok' => false,
            'message' => 'Введите корректный e-mail.',
        ], 422);
    }

    if (($input['birthDate'] ?? '') !== '' && $birthDate === null) {
        json_response([
            'ok' => false,
            'message' => 'Введите дату рождения в формате ДД.ММ.ГГГГ.',
        ], 422);
    }

    $existingUser = find_user_by_phone($phone);

    if ($existingUser && (int) $existingUser['id'] !== (int) $user['id']) {
        json_response([
            'ok' => false,
            'message' => 'Этот телефон уже привязан к другому аккаунту.',
        ], 422);
    }

    $updatedUser = update_user_profile((int) $user['id'], [
        'phone' => $phone,
        'email' => $email,
        'gender' => $gender,
        'birth_date' => $birthDate,
    ]);

    json_response([
        'ok' => true,
        'message' => 'Профиль сохранён.',
        'user' => build_account_user_payload($updatedUser),
    ]);
} catch (PDOException $exception) {
    akino_log_exception($exception);

    $message = $exception->getCode() === '23000'
        ? 'Такой e-mail уже используется.'
        : 'Не удалось сохранить профиль. Попробуйте позже.';

    json_response([
        'ok' => false,
        'message' => $message,
    ], 422);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось обработать профиль. Попробуйте позже.',
    ], 500);
}
