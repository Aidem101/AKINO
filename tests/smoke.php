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
    $genres = admin_genre_options();
    test_assert($genres !== [], 'No genres available for content form test.');

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
    unset($_SESSION['user_id']);
    test_cleanup_user($authUserId);
    $authUserId = 0;
    test_line('code login flow works');

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

    if (isset($userId)) {
        test_cleanup_user((int) $userId);
    }

    if (isset($authUserId)) {
        test_cleanup_user((int) $authUserId);
    }

    if (ob_get_level() > 0) {
        ob_end_flush();
    }

    fwrite(STDERR, PHP_EOL . '[fail] ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);
    exit(1);
}
