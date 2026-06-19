<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

ob_start();

$allowSkipDb = in_array('--allow-skip-db', $argv, true);

final class SmokeTestFailure extends RuntimeException
{
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new SmokeTestFailure($message);
    }
}

function test_section(string $name): void
{
    echo PHP_EOL . '[test] ' . $name . PHP_EOL;
}

function test_line(string $message): void
{
    echo '  - ' . $message . PHP_EOL;
}

function test_cleanup_movie(int $movieId): void
{
    if ($movieId > 0) {
        delete_admin_movie($movieId);
    }
}

function test_cleanup_user(int $userId): void
{
    if ($userId > 0) {
        admin_delete_user_account($userId);
    }
}

try {
    test_section('security primitives');
    $csrfToken = akino_csrf_token();
    test_assert(strlen($csrfToken) === 64, 'CSRF token has an unexpected length.');
    test_assert(akino_verify_csrf_token($csrfToken), 'CSRF token verification failed.');
    test_assert(!akino_verify_csrf_token(str_repeat('0', 64)), 'Invalid CSRF token was accepted.');
    test_assert(parse_birth_date('31.02.2025') === null, 'Invalid calendar date was accepted.');
    test_assert(strlen(akino_auth_cookie_secret()) >= 32, 'AKINO_AUTH_SECRET is missing or too short.');
    test_line('CSRF, strict dates and persistent auth secret are configured');

    ensure_admin_support();

    if (!admin_support_available()) {
        if (!$allowSkipDb) {
            test_assert(false, 'Admin support is unavailable. Check MySQL/OpenServer connection.');
        }

        test_section('database');
        test_line('MySQL/OpenServer is unavailable, DB-dependent functional tests skipped.');

        test_section('fallback render data');
        $fallbackSliderMovies = fetch_home_section_movies('slider', 3);
        test_assert($fallbackSliderMovies !== [], 'Fallback slider movies are empty.');
        $fallbackSearchQuery = mb_substr((string) ($fallbackSliderMovies[0]['title'] ?? ''), 0, 2, 'UTF-8');
        test_assert($fallbackSearchQuery !== '', 'Fallback search query could not be built.');
        test_assert(search_catalog_movies($fallbackSearchQuery, null, 3) !== [], 'Fallback search movies are empty.');
        test_assert(search_catalog_suggestions($fallbackSearchQuery, 3) !== [], 'Fallback search suggestions are empty.');
        $fallbackContext = resolve_playback_context(1, null);
        test_assert($fallbackContext !== null, 'Fallback playback context was not resolved.');
        test_line('fallback movie data works');

        echo PHP_EOL . '[ok] smoke tests passed with DB skipped' . PHP_EOL;
        exit(0);
    }

    test_section('home sections');
    test_assert(akino_table_exists('security_rate_limits'), 'Security rate-limit table is missing.');
    test_assert(akino_table_exists('security_events'), 'Security event table is missing.');
    test_assert(akino_table_exists('security_backups'), 'Security backup table is missing.');
    test_assert(akino_table_exists('security_file_integrity'), 'File-integrity table is missing.');
    test_assert(akino_column_exists('auth_codes', 'attempt_count'), 'Auth-code attempt counter is missing.');
    test_assert(akino_column_exists('admin_accounts', 'role'), 'Admin role column is missing.');

    $rateIdentity = 'smoke-' . bin2hex(random_bytes(8));
    security_rate_limit_record_failure('smoke_security', $rateIdentity, 2, 60, 60);
    test_assert(
        !security_rate_limit_exceeded('smoke_security', $rateIdentity, 2, 60),
        'Rate limit activated too early.'
    );
    security_rate_limit_record_failure('smoke_security', $rateIdentity, 2, 60, 60);
    test_assert(
        security_rate_limit_exceeded('smoke_security', $rateIdentity, 2, 60),
        'Rate limit did not activate.'
    );
    security_rate_limit_clear('smoke_security', $rateIdentity);
    test_line('database-backed rate limiting works');

    test_assert(admin_can(['role' => 'owner'], 'security.manage'), 'Owner role has no security management access.');
    test_assert(admin_can(['role' => 'editor'], 'content.manage'), 'Editor role has no content access.');
    test_assert(!admin_can(['role' => 'editor'], 'users.manage'), 'Editor role received user management access.');
    test_assert(admin_can(['role' => 'moderator'], 'users.manage'), 'Moderator role has no user access.');
    test_assert(admin_can(['role' => 'auditor'], 'security.view'), 'Auditor role has no security view access.');
    test_assert(!admin_can(['role' => 'auditor'], 'security.manage'), 'Auditor role received security management access.');
    test_line('role permissions are separated');

    security_event_log('smoke_security_event', 'warning', 'system', null, 'Smoke test');
    $securityEvents = fetch_security_events(10);
    test_assert(
        in_array('smoke_security_event', array_column($securityEvents, 'event_type'), true),
        'Security event was not recorded.'
    );
    test_assert(security_integrity_file_list() !== [], 'File-integrity source list is empty.');
    test_line('security journal and integrity inventory work');

    $backupTestId = 0;
    $backupTestPath = '';
    $backup = create_encrypted_database_backup();
    $backupTestId = (int) ($backup['id'] ?? 0);
    $backupTestPath = security_backup_directory() . DIRECTORY_SEPARATOR . basename((string) ($backup['filename'] ?? ''));
    test_assert($backupTestId > 0 && is_file($backupTestPath), 'Encrypted database backup was not created.');
    test_assert(verify_encrypted_database_backup($backupTestId), 'Encrypted database backup verification failed.');
    @unlink($backupTestPath);
    db()->prepare('DELETE FROM security_backups WHERE id = :id')->execute(['id' => $backupTestId]);
    $backupTestId = 0;
    $backupTestPath = '';
    test_line('encrypted backup creation and verification work');

    $genres = admin_genre_options();
    test_assert($genres !== [], 'No genres available for content form test.');
    test_assert(
        admin_normalize_genres_input(['Детектив / Триллер / Драма', 'Драма']) === ['Детектив', 'Триллер', 'Драма'],
        'Admin genre input does not split combined values.'
    );

    foreach ($genres as $genre) {
        test_assert(count(movie_split_genres((string) $genre)) === 1, 'Admin genre options contain a combined value.');
    }
    test_assert(!in_array('Хоррор', $genres, true), 'Admin genres contain the duplicate alias "Хоррор".');
    test_assert(!in_array('Приключение', $genres, true), 'Admin genres contain the duplicate alias "Приключение".');

    foreach (['movie', 'series'] as $contentType) {
        $genreOptions = fetch_catalog_filter_options($contentType)['genres'];

        foreach ($genreOptions as $genre) {
            test_assert(count(movie_split_genres((string) $genre)) === 1, 'Catalog genre options contain a combined value.');
        }

        foreach (fetch_catalog_movies($contentType) as $catalogMovie) {
            foreach (movie_split_genres((string) ($catalogMovie['genre'] ?? '')) as $movieGenre) {
                test_assert(isset($genreOptions[$movieGenre]), 'Movie genre is missing from catalog options.');
                $filteredByGenre = apply_catalog_filters(
                    [$catalogMovie],
                    [
                        'sort' => 'rating_desc',
                        'genre' => $movieGenre,
                        'year' => '',
                        'country' => '',
                        'director' => '',
                    ]
                );
                test_assert($filteredByGenre !== [], 'Catalog does not match an individual movie genre.');
            }
        }
    }
    test_line('genres are split into individual catalog filters');

    test_section('movie images');
    $allMovies = array_merge(fetch_catalog_movies('movie'), fetch_catalog_movies('series'));
    $imageUsage = [];

    foreach ($allMovies as $movie) {
        foreach (['poster_path', 'card_path', 'hero_path'] as $field) {
            $reference = trim((string) ($movie[$field] ?? ''));
            test_assert($reference !== '', sprintf('Movie #%d has empty %s.', (int) ($movie['id'] ?? 0), $field));
            test_assert(
                admin_movie_image_reference_error($reference) === null,
                sprintf('Movie #%d has invalid %s: %s', (int) ($movie['id'] ?? 0), $field, $reference)
            );

            $imageUsage[$reference][(int) ($movie['id'] ?? 0)] = (string) ($movie['title'] ?? '');
        }
    }

    foreach ($imageUsage as $reference => $moviesUsingImage) {
        test_assert(
            count($moviesUsingImage) === 1,
            sprintf('Image is assigned to multiple movies: %s (%s)', $reference, implode(', ', $moviesUsingImage))
        );
    }

    test_line('movie image files exist and are not shared across titles');

    foreach ($allMovies as $movie) {
        $existingMovieErrors = admin_validate_movie_form(
            admin_movie_form_from_movie($movie),
            admin_genre_options()
        );
        test_assert(
            $existingMovieErrors === [],
            sprintf(
                'Movie #%d cannot be saved again: %s',
                (int) ($movie['id'] ?? 0),
                implode(' ', $existingMovieErrors)
            )
        );
    }

    test_line('existing movie cards pass admin validation');

    $movieId = 0;
    $form = admin_movie_form_defaults();
    $form['title'] = 'AKINO smoke home section ' . date('YmdHis');
    $form['contentType'] = 'movie';
    $form['posterUrl'] = 'img/film1.png';
    $form['releaseYear'] = (int) date('Y');
    $form['director'] = 'AKINO Smoke';
    $form['rating'] = '8.2';
    $form['mediaPath'] = 'https://example.com/smoke.mp4';
    $form['durationMinutes'] = '100';
    $form['description'] = 'Temporary smoke-test movie for AKINO home section controls.';
    $form['genres'] = [$genres[0]];
    $form['country'] = 'Smoke';
    $form['ageRating'] = '16+';
    $form['homeSections'] = ['slider', 'recommended', 'for_you'];

    $savedMovie = save_admin_movie($form, null);
    $movieId = (int) ($savedMovie['id'] ?? 0);
    test_assert($movieId > 0, 'Movie was not created by admin save flow.');

    $staleMovieForm = $form;
    $staleMovieForm['id'] = PHP_INT_MAX;
    test_assert(
        admin_validate_movie_form($staleMovieForm, $genres) !== [],
        'Stale movie edit id passed validation.'
    );

    try {
        save_admin_movie($staleMovieForm, PHP_INT_MAX);
        test_assert(false, 'Stale movie edit id created a new card.');
    } catch (RuntimeException) {
        test_line('stale movie edit is rejected');
    }

    $sliderIds = array_map(static fn (array $movie): int => (int) ($movie['id'] ?? 0), fetch_home_section_movies('slider', 50));
    $recommendedIds = array_map(static fn (array $movie): int => (int) ($movie['id'] ?? 0), fetch_home_section_movies('recommended', 50));
    $forYouIds = array_map(static fn (array $movie): int => (int) ($movie['id'] ?? 0), fetch_home_section_movies('for_you', 50));

    test_assert(in_array($movieId, $sliderIds, true), 'Movie was not added to slider film-track.');
    test_assert(in_array($movieId, $recommendedIds, true), 'Movie was not added to recommended home track.');
    test_assert(in_array($movieId, $forYouIds, true), 'Movie was not added to for-you home track.');
    test_line('add to home tracks works');

    $editForm = admin_movie_form_from_movie(fetch_admin_movie_by_id($movieId) ?? []);
    $editForm['homeSections'] = [];
    save_admin_movie($editForm, $movieId);

    $sliderIdsAfter = array_map(static fn (array $movie): int => (int) ($movie['id'] ?? 0), fetch_home_section_movies('slider', 50));
    $recommendedIdsAfter = array_map(static fn (array $movie): int => (int) ($movie['id'] ?? 0), fetch_home_section_movies('recommended', 50));
    $forYouIdsAfter = array_map(static fn (array $movie): int => (int) ($movie['id'] ?? 0), fetch_home_section_movies('for_you', 50));

    test_assert(!in_array($movieId, $sliderIdsAfter, true), 'Movie was not removed from slider film-track.');
    test_assert(!in_array($movieId, $recommendedIdsAfter, true), 'Movie was not removed from recommended home track.');
    test_assert(!in_array($movieId, $forYouIdsAfter, true), 'Movie was not removed from for-you home track.');
    test_line('remove from home tracks works');

    test_section('admin episodes');
    $seriesId = 0;
    $seriesForm = $form;
    $seriesForm['id'] = 0;
    $seriesForm['title'] = 'AKINO smoke series ' . date('YmdHis');
    $seriesForm['contentType'] = 'series';
    $seriesForm['homeSections'] = [];
    $savedSeries = save_admin_movie($seriesForm);
    $seriesId = (int) ($savedSeries['id'] ?? 0);
    test_assert($seriesId > 0, 'Series was not created for episode smoke test.');

    $episodeForm = admin_episode_form_defaults();
    $episodeForm['seriesId'] = $seriesId;
    $episodeForm['seasonTitle'] = 'Smoke season';
    $episodeForm['title'] = 'Smoke episode';
    $episodeForm['videoPath'] = 'https://example.com/smoke-episode.mp4';
    $savedEpisode = admin_save_episode($episodeForm);
    $episodeId = (int) ($savedEpisode['id'] ?? 0);
    test_assert($episodeId > 0, 'Episode was not created by admin save flow.');
    test_assert(
        admin_validate_episode_form($episodeForm) !== [],
        'Duplicate episode number passed validation.'
    );

    $staleEpisodeForm = $episodeForm;
    $staleEpisodeForm['id'] = PHP_INT_MAX;
    $staleEpisodeForm['episodeNumber'] = 2;
    test_assert(
        admin_validate_episode_form($staleEpisodeForm) !== [],
        'Stale episode edit id passed validation.'
    );
    test_line('duplicate and stale episode edits are rejected');

    test_section('search');
    $searchSuggestions = search_catalog_suggestions('AKINO', 5);
    test_assert(is_array($searchSuggestions), 'Search suggestions did not return an array.');
    test_line('search suggestions API payload builds');

    test_section('playback');
    $context = resolve_playback_context(1, null);
    test_assert($context !== null, 'Playback context for movie #1 was not resolved.');
    test_assert((string) ($context['streamUrl'] ?? '') !== '', 'Playback context has empty stream URL.');
    test_line('movie playback context resolves');

    test_section('user auth');
    $authUserId = 0;
    $authPhone = normalize_phone('+7998' . random_int(1000000, 9999999));
    test_assert($authPhone !== null, 'Smoke auth phone could not be normalized.');
    $authRequest = create_auth_code_request($authPhone, 'login');
    test_assert((int) ($authRequest['id'] ?? 0) > 0, 'Auth code request was not created.');
    test_assert((string) ($authRequest['code'] ?? '') !== '', 'Auth code was not returned for local smoke flow.');
    $verifiedRequest = verify_auth_code_request((int) $authRequest['id'], (string) $authRequest['code']);
    $authUser = find_user_by_phone((string) $verifiedRequest['phone']) ?? create_user((string) $verifiedRequest['phone']);
    $authUserId = (int) ($authUser['id'] ?? 0);
    test_assert($authUserId > 0, 'Smoke auth user was not created.');
    login_user($authUser);
    test_assert(current_user_id() === $authUserId, 'Smoke auth user was not stored in the session.');

    $activeSubscription = activate_subscription($authUserId);
    test_assert(!empty($activeSubscription['active']), 'Subscription activation should grant access.');

    $cancelledSubscription = cancel_subscription($authUserId);
    test_assert(empty($cancelledSubscription['active']), 'Subscription cancellation should revoke access.');
    test_assert(get_active_subscription_row($authUserId) === null, 'Cancelled subscription should not remain active.');

    $reactivatedSubscription = activate_subscription($authUserId);
    test_assert(!empty($reactivatedSubscription['active']), 'Cancelled subscription should be available for reactivation.');
    cancel_subscription($authUserId);
    test_line('subscription cancellation and reactivation work');

    unset($_SESSION['user_id']);
    test_cleanup_user($authUserId);
    $authUserId = 0;
    test_line('code login flow works');

    $blockedCodePhone = normalize_phone('+7997' . random_int(1000000, 9999999));
    test_assert($blockedCodePhone !== null, 'Smoke brute-force phone could not be normalized.');
    $blockedCodeRequest = create_auth_code_request($blockedCodePhone, 'login');
    $wrongCode = (string) $blockedCodeRequest['code'] === '0000' ? '9999' : '0000';

    for ($attempt = 0; $attempt < AKINO_AUTH_CODE_ATTEMPT_LIMIT; $attempt++) {
        try {
            verify_auth_code_request((int) $blockedCodeRequest['id'], $wrongCode);
            test_assert(false, 'Invalid auth code was accepted.');
        } catch (AuthCodeException) {
            // Expected.
        }
    }

    $blockedCodeRow = db()->prepare(
        'SELECT attempt_count, used_at FROM auth_codes WHERE id = :id LIMIT 1'
    );
    $blockedCodeRow->execute(['id' => (int) $blockedCodeRequest['id']]);
    $blockedCodeState = $blockedCodeRow->fetch() ?: [];
    test_assert(
        (int) ($blockedCodeState['attempt_count'] ?? 0) >= AKINO_AUTH_CODE_ATTEMPT_LIMIT,
        'Failed auth-code attempts were not persisted.'
    );
    test_assert(!empty($blockedCodeState['used_at']), 'Exhausted auth code was not invalidated.');
    db()->prepare('DELETE FROM auth_codes WHERE phone = :phone')->execute(['phone' => $blockedCodePhone]);
    test_line('auth-code brute-force protection works across sessions');

    test_section('admin auth');
    $adminPassword = (string) (getenv('AKINO_SMOKE_ADMIN_PASSWORD') ?: getenv('AKINO_ADMIN_PASSWORD') ?: '');

    if ($adminPassword !== '') {
        test_assert(admin_login_attempt('akino_admin', $adminPassword), 'Default admin login failed.');
        test_assert(admin_current_account() !== null, 'Admin session was not created.');
        admin_logout();
        test_assert(admin_current_account() === null, 'Admin logout did not clear session.');
        test_line('admin login/logout works');
    } else {
        test_line('admin login skipped because AKINO_ADMIN_PASSWORD is not set');
    }

    test_section('blocked users');
    $userId = 0;
    $phone = normalize_phone('+7999' . random_int(1000000, 9999999));
    test_assert($phone !== null, 'Smoke phone could not be normalized.');
    $user = create_user($phone);
    $userId = (int) ($user['id'] ?? 0);
    test_assert($userId > 0, 'Smoke user was not created.');

    $blockedUser = admin_set_user_block_state($userId, true);
    test_assert((int) ($blockedUser['is_blocked'] ?? 0) === 1, 'User was not blocked.');

    try {
        login_user($blockedUser);
        test_assert(false, 'Blocked user was allowed to log in.');
    } catch (RuntimeException) {
        test_line('blocked user login is rejected');
    }

    admin_set_user_block_state($userId, false);

    test_section('cleanup');
    test_cleanup_movie($seriesId);
    $seriesId = 0;
    test_cleanup_movie($movieId);
    $movieId = 0;
    test_cleanup_user($userId);
    $userId = 0;
    test_line('temporary rows removed');

    echo PHP_EOL . '[ok] smoke tests passed' . PHP_EOL;

    if (ob_get_level() > 0) {
        ob_end_flush();
    }

    exit(0);
} catch (Throwable $exception) {
    if (isset($movieId)) {
        test_cleanup_movie((int) $movieId);
    }

    if (isset($seriesId)) {
        test_cleanup_movie((int) $seriesId);
    }

    if (isset($userId)) {
        test_cleanup_user((int) $userId);
    }

    if (isset($authUserId)) {
        test_cleanup_user((int) $authUserId);
    }

    if (!empty($backupTestPath) && is_file($backupTestPath)) {
        @unlink($backupTestPath);
    }

    if (!empty($backupTestId)) {
        db()->prepare('DELETE FROM security_backups WHERE id = :id')->execute(['id' => (int) $backupTestId]);
    }

    if (ob_get_level() > 0) {
        ob_end_flush();
    }

    fwrite(STDERR, PHP_EOL . '[fail] ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);
    exit(1);
}
