<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    require_post();

    $user = require_auth_user();
    $input = request_input();
    $movieId = (int) ($input['movieId'] ?? 0);
    $action = trim((string) ($input['action'] ?? 'toggle'));
    $movie = $movieId > 0 ? find_movie_by_id($movieId) : null;

    if (movie_fallback_mode()) {
        json_response([
            'ok' => false,
            'message' => 'Избранное временно недоступно без подключения к базе данных.',
        ], 503);
    }

    if ($movie === null) {
        json_response([
            'ok' => false,
            'message' => 'Фильм или сериал не найден.',
        ], 404);
    }

    if ($action === 'remove') {
        remove_movie_favorite((int) $user['id'], $movieId);
        $active = false;
        $message = 'Удалено из избранного.';
    } else {
        $active = toggle_movie_favorite((int) $user['id'], $movieId);
        $message = $active ? 'Добавлено в избранное.' : 'Удалено из избранного.';
    }

    $updatedUser = find_user_by_id((int) $user['id']) ?? $user;

    json_response([
        'ok' => true,
        'active' => $active,
        'message' => $message,
        'movie' => build_movie_card_payload($movie),
        'user' => build_account_user_payload($updatedUser),
    ]);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось обновить избранное. Попробуйте позже.',
    ], 500);
}
