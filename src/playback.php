<?php

declare(strict_types=1);

const AKINO_DEMO_STREAM_URL = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

function ensure_playback_library(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    $GLOBALS['akino_playback_fallback'] = false;

    try {
        ensure_movie_library();

        if (movie_fallback_mode()) {
            $GLOBALS['akino_playback_fallback'] = true;
            $bootstrapped = true;

            return;
        }

        $pdo = db();

        if (!akino_runtime_bootstrap_enabled()) {
            if (!akino_schema_ready(['seasons', 'episodes', 'watch_progress'])) {
                $GLOBALS['akino_playback_fallback'] = true;
            }

            $bootstrapped = true;

            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS seasons (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                series_id BIGINT UNSIGNED NOT NULL,
                season_number SMALLINT UNSIGNED NOT NULL,
                title VARCHAR(160) NOT NULL,
                description TEXT DEFAULT NULL,
                poster_path VARCHAR(255) DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY seasons_series_season_unique (series_id, season_number),
                KEY seasons_series_sort_index (series_id, sort_order),
                CONSTRAINT seasons_series_id_foreign
                    FOREIGN KEY (series_id) REFERENCES movies (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS episodes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                season_id BIGINT UNSIGNED NOT NULL,
                episode_number SMALLINT UNSIGNED NOT NULL,
                title VARCHAR(160) NOT NULL,
                description TEXT DEFAULT NULL,
                duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                video_path VARCHAR(255) DEFAULT NULL,
                preview_path VARCHAR(255) DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY episodes_season_episode_unique (season_id, episode_number),
                KEY episodes_season_sort_index (season_id, sort_order),
                CONSTRAINT episodes_season_id_foreign
                    FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS watch_progress (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                movie_id BIGINT UNSIGNED NOT NULL,
                episode_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                completed_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                is_completed TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY watch_progress_user_item_unique (user_id, movie_id, episode_id),
                KEY watch_progress_user_updated_index (user_id, updated_at),
                KEY watch_progress_movie_episode_index (movie_id, episode_id),
                CONSTRAINT watch_progress_user_id_foreign
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT watch_progress_movie_id_foreign
                    FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $seriesRows = $pdo->query('SELECT id, slug, card_path, hero_path FROM movies WHERE content_type = "series"')
            ->fetchAll();
        $seriesMap = [];

        foreach ($seriesRows as $row) {
            $seriesMap[(string) $row['slug']] = $row;
        }

        $seasonStatement = $pdo->prepare(
            'INSERT INTO seasons (
                series_id,
                season_number,
                title,
                description,
                poster_path,
                sort_order
            ) VALUES (
                :series_id,
                :season_number,
                :title,
                :description,
                :poster_path,
                :sort_order
            )
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                poster_path = VALUES(poster_path),
                sort_order = VALUES(sort_order),
                updated_at = NOW()'
        );

        $seasonKeys = [];

        foreach (playback_episode_seed_rows() as $row) {
            $series = $seriesMap[$row['series_slug']] ?? null;

            if (!$series) {
                continue;
            }

            $key = $row['series_slug'] . ':' . $row['season_number'];

            if (isset($seasonKeys[$key])) {
                continue;
            }

            $seasonKeys[$key] = true;
            $seasonStatement->execute([
                'series_id' => (int) $series['id'],
                'season_number' => (int) $row['season_number'],
                'title' => $row['season_title'],
                'description' => $row['season_description'],
                'poster_path' => $row['poster_path'] ?: ($series['hero_path'] ?: $series['card_path']),
                'sort_order' => (int) $row['season_sort_order'],
            ]);
        }

        $seasonRows = $pdo->query(
            'SELECT s.id, s.season_number, m.slug
             FROM seasons s
             INNER JOIN movies m ON m.id = s.series_id'
        )->fetchAll();
        $seasonMap = [];

        foreach ($seasonRows as $row) {
            $seasonMap[(string) $row['slug'] . ':' . (int) $row['season_number']] = (int) $row['id'];
        }

        $episodeStatement = $pdo->prepare(
            'INSERT INTO episodes (
                season_id,
                episode_number,
                title,
                description,
                duration_seconds,
                video_path,
                preview_path,
                sort_order
            ) VALUES (
                :season_id,
                :episode_number,
                :title,
                :description,
                :duration_seconds,
                :video_path,
                :preview_path,
                :sort_order
            )
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                duration_seconds = VALUES(duration_seconds),
                video_path = VALUES(video_path),
                preview_path = VALUES(preview_path),
                sort_order = VALUES(sort_order),
                updated_at = NOW()'
        );

        foreach (playback_episode_seed_rows() as $row) {
            $seasonId = $seasonMap[$row['series_slug'] . ':' . $row['season_number']] ?? null;

            if ($seasonId === null) {
                continue;
            }

            $episodeStatement->execute([
                'season_id' => $seasonId,
                'episode_number' => (int) $row['episode_number'],
                'title' => $row['title'],
                'description' => $row['description'],
                'duration_seconds' => (int) $row['duration_seconds'],
                'video_path' => $row['video_path'],
                'preview_path' => $row['preview_path'],
                'sort_order' => (int) $row['sort_order'],
            ]);
        }
    } catch (Throwable) {
        $GLOBALS['akino_playback_fallback'] = true;
    }

    $bootstrapped = true;
}

function playback_fallback_mode(): bool
{
    ensure_playback_library();

    return (bool) ($GLOBALS['akino_playback_fallback'] ?? movie_fallback_mode());
}

function playback_demo_stream_url(): string
{
    return AKINO_DEMO_STREAM_URL;
}

function playback_episode_seed_rows(): array
{
    return [
        [
            'series_slug' => 'comeback',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Возвращение героя на сцену после долгого молчания.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (3).webp',
            'episode_number' => 1,
            'title' => 'Серия 1. Первый эфир',
            'description' => 'Герой пытается вернуться в индустрию и сталкивается с первыми последствиями прошлого.',
            'duration_seconds' => 2640,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (3).webp',
            'sort_order' => 10,
        ],
        [
            'series_slug' => 'comeback',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Возвращение героя на сцену после долгого молчания.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (3).webp',
            'episode_number' => 2,
            'title' => 'Серия 2. Новые лица',
            'description' => 'Команда собирается заново, а старые конфликты только обостряются.',
            'duration_seconds' => 2580,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (3).webp',
            'sort_order' => 20,
        ],
        [
            'series_slug' => 'comeback',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Возвращение героя на сцену после долгого молчания.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (3).webp',
            'episode_number' => 3,
            'title' => 'Серия 3. Прямой эфир',
            'description' => 'Решающая трансляция ставит под удар карьеру и отношения внутри группы.',
            'duration_seconds' => 2760,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (3).webp',
            'sort_order' => 30,
        ],
        [
            'series_slug' => 'fisher',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Следствие с каждым эпизодом становится всё мрачнее.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (6).webp',
            'episode_number' => 1,
            'title' => 'Серия 1. След',
            'description' => 'Следователи выходят на первый ключевой след и понимают масштаб дела.',
            'duration_seconds' => 2940,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (6).webp',
            'sort_order' => 10,
        ],
        [
            'series_slug' => 'fisher',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Следствие с каждым эпизодом становится всё мрачнее.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (6).webp',
            'episode_number' => 2,
            'title' => 'Серия 2. Ночная смена',
            'description' => 'Новый свидетель меняет направление расследования.',
            'duration_seconds' => 2880,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (6).webp',
            'sort_order' => 20,
        ],
        [
            'series_slug' => 'fisher',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Следствие с каждым эпизодом становится всё мрачнее.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (6).webp',
            'episode_number' => 3,
            'title' => 'Серия 3. Давление',
            'description' => 'Команда сталкивается с давлением сверху и вынуждена рисковать.',
            'duration_seconds' => 3000,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (6).webp',
            'sort_order' => 30,
        ],
        [
            'series_slug' => 'barankiny-and-the-stones-of-power',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Комедийное приключение вокруг странного артефакта.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (7).webp',
            'episode_number' => 1,
            'title' => 'Серия 1. Находка',
            'description' => 'Семья случайно находит артефакт и запускает цепочку нелепых событий.',
            'duration_seconds' => 2460,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (7).webp',
            'sort_order' => 10,
        ],
        [
            'series_slug' => 'barankiny-and-the-stones-of-power',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Комедийное приключение вокруг странного артефакта.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (7).webp',
            'episode_number' => 2,
            'title' => 'Серия 2. Погоня',
            'description' => 'За находкой начинают охотиться неожиданные соперники.',
            'duration_seconds' => 2520,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (7).webp',
            'sort_order' => 20,
        ],
        [
            'series_slug' => 'others',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Тихий город скрывает слишком много странностей.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (8).webp',
            'episode_number' => 1,
            'title' => 'Серия 1. Чужие',
            'description' => 'Первые необъяснимые события сводят героев вместе.',
            'duration_seconds' => 2700,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (8).webp',
            'sort_order' => 10,
        ],
        [
            'series_slug' => 'others',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Тихий город скрывает слишком много странностей.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (8).webp',
            'episode_number' => 2,
            'title' => 'Серия 2. Радиошум',
            'description' => 'Сигнал из леса заставляет героев пересмотреть всё, что они знали.',
            'duration_seconds' => 2820,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (8).webp',
            'sort_order' => 20,
        ],
        [
            'series_slug' => 'leila',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Личная драма с сильной героиней и трудным выбором.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/468x264.jpg',
            'episode_number' => 1,
            'title' => 'Серия 1. Новый дом',
            'description' => 'Лейла пытается начать новую жизнь и защищает своё право на выбор.',
            'duration_seconds' => 2580,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/468x264.jpg',
            'sort_order' => 10,
        ],
        [
            'series_slug' => 'leila',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Личная драма с сильной героиней и трудным выбором.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/468x264.jpg',
            'episode_number' => 2,
            'title' => 'Серия 2. Цена свободы',
            'description' => 'Каждое решение начинает слишком дорого обходиться.',
            'duration_seconds' => 2640,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/468x264.jpg',
            'sort_order' => 20,
        ],
        [
            'series_slug' => 'gachiakuta',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Аниме-боевик о выживании на дне мира.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (9).webp',
            'episode_number' => 1,
            'title' => 'Серия 1. Свалка',
            'description' => 'Рудо оказывается внизу и впервые сталкивается с новым миром.',
            'duration_seconds' => 1500,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (9).webp',
            'sort_order' => 10,
        ],
        [
            'series_slug' => 'gachiakuta',
            'season_number' => 1,
            'season_title' => 'Сезон 1',
            'season_description' => 'Аниме-боевик о выживании на дне мира.',
            'season_sort_order' => 10,
            'poster_path' => 'img/prew/640x360 (9).webp',
            'episode_number' => 2,
            'title' => 'Серия 2. Пожиратели мусора',
            'description' => 'Герой учится драться и находит первый шанс выбраться.',
            'duration_seconds' => 1440,
            'video_path' => playback_demo_stream_url(),
            'preview_path' => 'img/prew/640x360 (9).webp',
            'sort_order' => 20,
        ],
    ];
}

function playback_fallback_seasons(): array
{
    static $rows = null;

    if ($rows !== null) {
        return $rows;
    }

    $seriesMap = [];

    foreach (fallback_movies() as $movie) {
        if (($movie['content_type'] ?? 'movie') === 'series') {
            $seriesMap[(string) $movie['slug']] = $movie;
        }
    }

    $rows = [];
    $known = [];
    $nextId = 1;

    foreach (playback_episode_seed_rows() as $row) {
        $series = $seriesMap[$row['series_slug']] ?? null;

        if (!$series) {
            continue;
        }

        $key = $row['series_slug'] . ':' . $row['season_number'];

        if (isset($known[$key])) {
            continue;
        }

        $known[$key] = $nextId;
        $rows[] = [
            'id' => $nextId,
            'series_id' => (int) $series['id'],
            'season_number' => (int) $row['season_number'],
            'title' => $row['season_title'],
            'description' => $row['season_description'],
            'poster_path' => $row['poster_path'] ?: ((string) ($series['hero_path'] ?? '') ?: (string) ($series['card_path'] ?? '')),
            'sort_order' => (int) $row['season_sort_order'],
        ];
        $nextId++;
    }

    return $rows;
}

function playback_fallback_episodes(): array
{
    static $rows = null;

    if ($rows !== null) {
        return $rows;
    }

    $seasonMap = [];

    foreach (playback_fallback_seasons() as $season) {
        $seasonMap[(int) $season['series_id'] . ':' . (int) $season['season_number']] = $season;
    }

    $seriesMap = [];

    foreach (fallback_movies() as $movie) {
        if (($movie['content_type'] ?? 'movie') === 'series') {
            $seriesMap[(string) $movie['slug']] = $movie;
        }
    }

    $rows = [];
    $nextId = 1;

    foreach (playback_episode_seed_rows() as $row) {
        $series = $seriesMap[$row['series_slug']] ?? null;

        if (!$series) {
            continue;
        }

        $season = $seasonMap[(int) $series['id'] . ':' . (int) $row['season_number']] ?? null;

        if (!$season) {
            continue;
        }

        $rows[] = [
            'id' => $nextId,
            'season_id' => (int) $season['id'],
            'series_id' => (int) $series['id'],
            'season_number' => (int) $season['season_number'],
            'season_title' => $season['title'],
            'episode_number' => (int) $row['episode_number'],
            'title' => $row['title'],
            'description' => $row['description'],
            'duration_seconds' => (int) $row['duration_seconds'],
            'video_path' => $row['video_path'],
            'preview_path' => $row['preview_path'],
            'sort_order' => (int) $row['sort_order'],
        ];
        $nextId++;
    }

    return $rows;
}

function playback_format_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0 мин';
    }

    if ($seconds < 60) {
        return 'меньше минуты';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return sprintf('%d ч %02d мин', $hours, max(1, $minutes));
    }

    return sprintf('%d мин', max(1, $minutes));
}

function playback_format_timestamp(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainder = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $remainder);
    }

    return sprintf('%02d:%02d', $minutes, $remainder);
}

function playback_movie_stream_url(array $movie): string
{
    $mediaPath = trim((string) ($movie['media_path'] ?? ''));

    return $mediaPath !== '' ? $mediaPath : playback_demo_stream_url();
}

function playback_stream_mime_type(string $streamUrl): string
{
    $path = (string) (parse_url($streamUrl, PHP_URL_PATH) ?: $streamUrl);
    $path = strtolower($path);

    if (str_ends_with($path, '.m3u8')) {
        return 'application/vnd.apple.mpegurl';
    }

    if (str_ends_with($path, '.webm')) {
        return 'video/webm';
    }

    if (str_ends_with($path, '.ogv') || str_ends_with($path, '.ogg')) {
        return 'video/ogg';
    }

    return 'video/mp4';
}

function build_watch_season_payload(array $season, int $movieId, ?string $firstEpisodeUrl = null, bool $selected = false): array
{
    return [
        'id' => (int) $season['id'],
        'movieId' => $movieId,
        'seasonNumber' => (int) $season['season_number'],
        'title' => (string) $season['title'],
        'description' => (string) ($season['description'] ?? ''),
        'posterPath' => (string) ($season['poster_path'] ?? ''),
        'selected' => $selected,
        'firstEpisodeUrl' => $firstEpisodeUrl,
    ];
}

function build_watch_episode_payload(array $episode, int $movieId, bool $selected = false): array
{
    return [
        'id' => (int) $episode['id'],
        'movieId' => $movieId,
        'seasonId' => (int) $episode['season_id'],
        'seasonNumber' => (int) ($episode['season_number'] ?? 0),
        'seasonTitle' => (string) ($episode['season_title'] ?? ''),
        'episodeNumber' => (int) $episode['episode_number'],
        'title' => (string) $episode['title'],
        'description' => (string) ($episode['description'] ?? ''),
        'durationSeconds' => (int) ($episode['duration_seconds'] ?? 0),
        'durationDisplay' => playback_format_duration((int) ($episode['duration_seconds'] ?? 0)),
        'videoPath' => (string) ($episode['video_path'] ?? ''),
        'previewPath' => (string) ($episode['preview_path'] ?? ''),
        'selected' => $selected,
        'url' => 'Watch.php?id=' . $movieId . '&episode=' . (int) $episode['id'],
    ];
}

function fetch_series_seasons(int $seriesId): array
{
    ensure_playback_library();

    if (playback_fallback_mode()) {
        $seasons = array_values(array_filter(
            playback_fallback_seasons(),
            static fn (array $season): bool => (int) $season['series_id'] === $seriesId
        ));
        usort($seasons, static fn (array $left, array $right): int => [$left['sort_order'], $left['season_number']] <=> [$right['sort_order'], $right['season_number']]);

        return $seasons;
    }

    $statement = db()->prepare(
        'SELECT *
         FROM seasons
         WHERE series_id = :series_id
         ORDER BY sort_order ASC, season_number ASC'
    );
    $statement->execute(['series_id' => $seriesId]);

    return $statement->fetchAll();
}

function fetch_season_episodes(int $seasonId): array
{
    ensure_playback_library();

    if (playback_fallback_mode()) {
        $episodes = array_values(array_filter(
            playback_fallback_episodes(),
            static fn (array $episode): bool => (int) $episode['season_id'] === $seasonId
        ));
        usort($episodes, static fn (array $left, array $right): int => [$left['sort_order'], $left['episode_number']] <=> [$right['sort_order'], $right['episode_number']]);

        return $episodes;
    }

    $statement = db()->prepare(
        'SELECT e.*, s.series_id, s.season_number, s.title AS season_title
         FROM episodes e
         INNER JOIN seasons s ON s.id = e.season_id
         WHERE e.season_id = :season_id
         ORDER BY e.sort_order ASC, e.episode_number ASC'
    );
    $statement->execute(['season_id' => $seasonId]);

    return $statement->fetchAll();
}

function find_episode_by_id(int $episodeId): ?array
{
    ensure_playback_library();

    if (playback_fallback_mode()) {
        foreach (playback_fallback_episodes() as $episode) {
            if ((int) $episode['id'] === $episodeId) {
                return $episode;
            }
        }

        return null;
    }

    $statement = db()->prepare(
        'SELECT e.*, s.series_id, s.season_number, s.title AS season_title
         FROM episodes e
         INNER JOIN seasons s ON s.id = e.season_id
         WHERE e.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $episodeId]);
    $episode = $statement->fetch();

    return $episode ?: null;
}

function fetch_all_series_episodes(int $seriesId): array
{
    ensure_playback_library();

    if (playback_fallback_mode()) {
        $episodes = array_values(array_filter(
            playback_fallback_episodes(),
            static fn (array $episode): bool => (int) $episode['series_id'] === $seriesId
        ));
        usort($episodes, static fn (array $left, array $right): int => [
            $left['season_number'],
            $left['sort_order'],
            $left['episode_number'],
        ] <=> [
            $right['season_number'],
            $right['sort_order'],
            $right['episode_number'],
        ]);

        return $episodes;
    }

    $statement = db()->prepare(
        'SELECT e.*, s.series_id, s.season_number, s.title AS season_title, s.sort_order AS season_sort_order
         FROM episodes e
         INNER JOIN seasons s ON s.id = e.season_id
         WHERE s.series_id = :series_id
         ORDER BY s.sort_order ASC, s.season_number ASC, e.sort_order ASC, e.episode_number ASC'
    );
    $statement->execute(['series_id' => $seriesId]);

    return $statement->fetchAll();
}

function find_first_episode_for_series(int $seriesId): ?array
{
    $episodes = fetch_all_series_episodes($seriesId);

    return $episodes[0] ?? null;
}

function find_next_episode_for_series(int $seriesId, int $currentEpisodeId): ?array
{
    if ($currentEpisodeId <= 0) {
        return null;
    }

    $episodes = fetch_all_series_episodes($seriesId);

    foreach ($episodes as $index => $episode) {
        if ((int) $episode['id'] === $currentEpisodeId) {
            return $episodes[$index + 1] ?? null;
        }
    }

    return null;
}

function watch_progress_default(): array
{
    return [
        'positionSeconds' => 0,
        'positionDisplay' => '00:00',
        'durationSeconds' => 0,
        'durationDisplay' => '00:00',
        'completedPercent' => 0,
        'isCompleted' => false,
        'updatedAt' => null,
        'updatedAtDisplay' => '',
    ];
}

function build_watch_progress_payload(?array $row): array
{
    if (!$row) {
        return watch_progress_default();
    }

    $positionSeconds = (int) ($row['position_seconds'] ?? 0);
    $durationSeconds = (int) ($row['duration_seconds'] ?? 0);
    $updatedAt = !empty($row['updated_at']) ? (string) $row['updated_at'] : null;

    return [
        'positionSeconds' => $positionSeconds,
        'positionDisplay' => playback_format_timestamp($positionSeconds),
        'durationSeconds' => $durationSeconds,
        'durationDisplay' => playback_format_timestamp($durationSeconds),
        'completedPercent' => (float) ($row['completed_percent'] ?? 0),
        'isCompleted' => (bool) ($row['is_completed'] ?? false),
        'updatedAt' => $updatedAt,
        'updatedAtDisplay' => $updatedAt ? (new DateTimeImmutable($updatedAt))->format('d.m.Y H:i') : '',
    ];
}

function fetch_watch_progress(int $userId, int $movieId, ?int $episodeId = null): array
{
    ensure_playback_library();

    if ($userId <= 0 || playback_fallback_mode()) {
        return watch_progress_default();
    }

    $episodeKey = $episodeId !== null && $episodeId > 0 ? $episodeId : 0;
    $statement = db()->prepare(
        'SELECT *
         FROM watch_progress
         WHERE user_id = :user_id AND movie_id = :movie_id AND episode_id = :episode_id
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
        'movie_id' => $movieId,
        'episode_id' => $episodeKey,
    ]);

    return build_watch_progress_payload($statement->fetch() ?: null);
}

function save_watch_progress(
    int $userId,
    int $movieId,
    ?int $episodeId,
    int $positionSeconds,
    int $durationSeconds
): array {
    ensure_playback_library();

    if ($userId <= 0 || playback_fallback_mode()) {
        return watch_progress_default();
    }

    $episodeKey = $episodeId !== null && $episodeId > 0 ? $episodeId : 0;
    $positionSeconds = max(0, $positionSeconds);
    $durationSeconds = max($durationSeconds, $positionSeconds);
    $completedPercent = $durationSeconds > 0
        ? min(100, round(($positionSeconds / max(1, $durationSeconds)) * 100, 2))
        : 0.0;
    $remainingSeconds = max(0, $durationSeconds - $positionSeconds);
    $isCompleted = $completedPercent >= 92 || ($durationSeconds >= 300 && $remainingSeconds <= 45);

    $statement = db()->prepare(
        'INSERT INTO watch_progress (
            user_id,
            movie_id,
            episode_id,
            position_seconds,
            duration_seconds,
            completed_percent,
            is_completed,
            created_at,
            updated_at
        ) VALUES (
            :user_id,
            :movie_id,
            :episode_id,
            :position_seconds,
            :duration_seconds,
            :completed_percent,
            :is_completed,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            position_seconds = VALUES(position_seconds),
            duration_seconds = VALUES(duration_seconds),
            completed_percent = VALUES(completed_percent),
            is_completed = VALUES(is_completed),
            updated_at = NOW()'
    );
    $statement->execute([
        'user_id' => $userId,
        'movie_id' => $movieId,
        'episode_id' => $episodeKey,
        'position_seconds' => $positionSeconds,
        'duration_seconds' => $durationSeconds,
        'completed_percent' => $completedPercent,
        'is_completed' => $isCompleted ? 1 : 0,
    ]);

    return fetch_watch_progress($userId, $movieId, $episodeId);
}

function build_continue_watching_payload(array $row): array
{
    $payload = build_movie_card_payload($row);
    $episodeId = (int) ($row['episode_id'] ?? 0);
    $seasonNumber = (int) ($row['season_number'] ?? 0);
    $episodeNumber = (int) ($row['episode_number'] ?? 0);
    $positionSeconds = max(0, (int) ($row['position_seconds'] ?? 0));
    $durationSeconds = max($positionSeconds, (int) ($row['duration_seconds'] ?? 0));
    $completedPercent = (float) ($row['completed_percent'] ?? 0);
    $updatedAt = !empty($row['progress_updated_at']) ? (string) $row['progress_updated_at'] : null;

    if ($completedPercent <= 0 && $durationSeconds > 0) {
        $completedPercent = round(($positionSeconds / max(1, $durationSeconds)) * 100, 2);
    }

    $progressBarWidth = $completedPercent > 0
        ? max(6.0, min(100.0, $completedPercent))
        : 0.0;
    $episodeMeta = $episodeId > 0
        ? trim(sprintf('Сезон %d • Серия %d', max(1, $seasonNumber), max(1, $episodeNumber)))
        : (($payload['type'] ?? 'movie') === 'series' ? 'Сериал' : 'Фильм');
    $statusLabel = $episodeId > 0
        ? ((string) ($row['episode_title'] ?? '') !== '' ? (string) $row['episode_title'] : $episodeMeta)
        : trim($payload['typeLabel'] . (!empty($payload['releaseYear']) ? ' • ' . (int) $payload['releaseYear'] : ''));
    $coverPath = (string) ($row['episode_preview_path'] ?? '');

    if ($coverPath === '') {
        $coverPath = (string) ($row['card_path'] ?? ($row['poster_path'] ?? ''));
    }

    $secondaryMeta = '';

    if ($durationSeconds > $positionSeconds) {
        $secondaryMeta = 'Осталось ' . playback_format_duration($durationSeconds - $positionSeconds);
    } elseif ($updatedAt) {
        $secondaryMeta = 'Обновлено ' . (new DateTimeImmutable($updatedAt))->format('d.m.Y H:i');
    }

    return $payload + [
        'coverPath' => $coverPath,
        'continueUrl' => 'Watch.php?id=' . (int) $payload['id'] . ($episodeId > 0 ? '&episode=' . $episodeId : ''),
        'episodeId' => $episodeId > 0 ? $episodeId : null,
        'episodeTitle' => (string) ($row['episode_title'] ?? ''),
        'episodeMeta' => $episodeMeta,
        'statusLabel' => $statusLabel,
        'positionSeconds' => $positionSeconds,
        'durationSeconds' => $durationSeconds,
        'positionDisplay' => playback_format_timestamp($positionSeconds),
        'durationDisplay' => playback_format_timestamp($durationSeconds),
        'progressDisplay' => playback_format_timestamp($positionSeconds) . ' / ' . playback_format_timestamp($durationSeconds),
        'progressPercent' => $completedPercent,
        'progressPercentWidth' => $progressBarWidth,
        'updatedAt' => $updatedAt,
        'updatedAtDisplay' => $updatedAt ? (new DateTimeImmutable($updatedAt))->format('d.m.Y H:i') : '',
        'secondaryMeta' => $secondaryMeta,
        'actionLabel' => $positionSeconds > 0 ? 'Продолжить просмотр' : 'Смотреть',
    ];
}

function fetch_continue_watching_payload(int $userId, int $limit = 6): array
{
    ensure_playback_library();

    if ($userId <= 0 || $limit <= 0 || playback_fallback_mode()) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT
            m.*,
            wp.episode_id,
            wp.position_seconds,
            wp.duration_seconds,
            wp.completed_percent,
            wp.is_completed,
            wp.updated_at AS progress_updated_at,
            e.title AS episode_title,
            e.episode_number,
            e.preview_path AS episode_preview_path,
            s.season_number,
            s.title AS season_title
         FROM watch_progress wp
         INNER JOIN movies m ON m.id = wp.movie_id
         LEFT JOIN episodes e ON e.id = wp.episode_id AND wp.episode_id <> 0
         LEFT JOIN seasons s ON s.id = e.season_id
         WHERE wp.user_id = :user_id
           AND wp.is_completed = 0
           AND (wp.position_seconds > 0 OR wp.duration_seconds > 0)
         ORDER BY wp.updated_at DESC, wp.id DESC'
    );
    $statement->execute(['user_id' => $userId]);

    $items = [];
    $seenMovies = [];

    foreach ($statement->fetchAll() as $row) {
        $movieId = (int) ($row['id'] ?? 0);

        if ($movieId <= 0 || isset($seenMovies[$movieId])) {
            continue;
        }

        $seenMovies[$movieId] = true;
        $items[] = build_continue_watching_payload($row);

        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function resolve_playback_context(int $movieId, ?int $episodeId = null): ?array
{
    ensure_playback_library();

    $movie = find_movie_by_id($movieId);

    if (!$movie) {
        return null;
    }

    if (($movie['content_type'] ?? 'movie') !== 'series') {
        $streamUrl = playback_movie_stream_url($movie);

        return [
            'movie' => $movie,
            'playbackType' => 'movie',
            'posterPath' => (string) ($movie['hero_path'] ?? $movie['card_path'] ?? ''),
            'streamUrl' => $streamUrl,
            'selectedSeason' => null,
            'selectedEpisode' => null,
            'seasons' => [],
            'episodes' => [],
            'nextEpisode' => null,
            'demoMode' => $streamUrl === playback_demo_stream_url(),
        ];
    }

    $seasonsRaw = fetch_series_seasons($movieId);

    if (!$seasonsRaw) {
        $streamUrl = playback_movie_stream_url($movie);

        return [
            'movie' => $movie,
            'playbackType' => 'series',
            'posterPath' => (string) ($movie['hero_path'] ?? $movie['card_path'] ?? ''),
            'streamUrl' => $streamUrl,
            'selectedSeason' => null,
            'selectedEpisode' => null,
            'seasons' => [],
            'episodes' => [],
            'nextEpisode' => null,
            'demoMode' => $streamUrl === playback_demo_stream_url(),
        ];
    }

    $selectedEpisodeRaw = $episodeId !== null && $episodeId > 0 ? find_episode_by_id($episodeId) : null;

    if ($selectedEpisodeRaw && (int) ($selectedEpisodeRaw['series_id'] ?? 0) !== $movieId) {
        $selectedEpisodeRaw = null;
    }

    if (!$selectedEpisodeRaw) {
        $selectedEpisodeRaw = find_first_episode_for_series($movieId);
    }

    $selectedSeasonId = (int) ($selectedEpisodeRaw['season_id'] ?? $seasonsRaw[0]['id']);
    $episodesRaw = fetch_season_episodes($selectedSeasonId);
    $selectedSeasonRaw = null;
    $seasons = [];

    foreach ($seasonsRaw as $season) {
        $isSelected = (int) $season['id'] === $selectedSeasonId;
        $firstEpisode = fetch_season_episodes((int) $season['id'])[0] ?? null;

        if ($isSelected) {
            $selectedSeasonRaw = $season;
        }

        $seasons[] = build_watch_season_payload(
            $season,
            $movieId,
            $firstEpisode ? 'Watch.php?id=' . $movieId . '&episode=' . (int) $firstEpisode['id'] : null,
            $isSelected
        );
    }

    $selectedEpisodeId = (int) ($selectedEpisodeRaw['id'] ?? 0);
    $episodes = array_map(
        static fn (array $episode): array => build_watch_episode_payload($episode, $movieId, (int) $episode['id'] === $selectedEpisodeId),
        $episodesRaw
    );

    $selectedEpisode = $selectedEpisodeRaw
        ? build_watch_episode_payload($selectedEpisodeRaw, $movieId, true)
        : null;
    $nextEpisodeRaw = $selectedEpisodeRaw ? find_next_episode_for_series($movieId, $selectedEpisodeId) : null;
    $nextEpisode = $nextEpisodeRaw ? build_watch_episode_payload($nextEpisodeRaw, $movieId) : null;

    return [
        'movie' => $movie,
        'playbackType' => 'series',
        'posterPath' => $selectedEpisode['previewPath'] ?? (string) ($movie['hero_path'] ?? $movie['card_path'] ?? ''),
        'streamUrl' => (string) ($selectedEpisode['videoPath'] ?? ''),
        'selectedSeason' => $selectedSeasonRaw ? build_watch_season_payload($selectedSeasonRaw, $movieId, null, true) : null,
        'selectedEpisode' => $selectedEpisode,
        'seasons' => $seasons,
        'episodes' => $episodes,
        'nextEpisode' => $nextEpisode,
        'demoMode' => !empty($selectedEpisode['videoPath']) && (string) $selectedEpisode['videoPath'] === playback_demo_stream_url(),
    ];
}
