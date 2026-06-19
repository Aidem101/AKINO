<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

try {
    require_post();

    $user = require_auth_user();
    $subscription = get_user_subscription_payload((int) $user['id']);

    if (empty($subscription['active'])) {
        json_response([
            'ok' => false,
            'message' => 'Для просмотра нужна активная подписка AKINO.',
        ], 403);
    }

    $input = request_input();
    $movieId = (int) ($input['movieId'] ?? 0);
    $episodeId = (int) ($input['episodeId'] ?? 0);
    $maxPlaybackSeconds = 60 * 60 * 24 * 7;
    $positionSeconds = min($maxPlaybackSeconds, max(0, (int) ($input['positionSeconds'] ?? 0)));
    $durationSeconds = min($maxPlaybackSeconds, max(0, (int) ($input['durationSeconds'] ?? 0)));
    $context = $movieId > 0 ? resolve_playback_context($movieId, $episodeId > 0 ? $episodeId : null) : null;

    if ($context === null) {
        json_response([
            'ok' => false,
            'message' => 'Контент для сохранения прогресса не найден.',
        ], 404);
    }

    $progress = save_watch_progress(
        (int) $user['id'],
        $movieId,
        $episodeId > 0 ? $episodeId : null,
        $positionSeconds,
        $durationSeconds
    );

    json_response([
        'ok' => true,
        'progress' => $progress,
    ]);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось сохранить прогресс просмотра. Попробуйте позже.',
    ], 500);
}
