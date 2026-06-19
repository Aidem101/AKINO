<?php

declare(strict_types=1);

function movie_split_genres(string $value): array
{
    $parts = preg_split('/\s*(?:\/|,|;|\|)\s*/u', $value) ?: [];
    $genres = [];

    foreach ($parts as $part) {
        $genre = trim((string) preg_replace('/\s+/u', ' ', trim($part)));

        if ($genre === '') {
            continue;
        }

        $key = mb_strtolower($genre, 'UTF-8');

        if (!isset($genres[$key])) {
            $genres[$key] = $genre;
        }
    }

    return array_values($genres);
}

function movie_join_genres(array $genres): string
{
    $normalized = [];

    foreach ($genres as $genre) {
        foreach (movie_split_genres((string) $genre) as $part) {
            $key = mb_strtolower($part, 'UTF-8');

            if (!isset($normalized[$key])) {
                $normalized[$key] = $part;
            }
        }
    }

    return implode(' / ', array_values($normalized));
}

function movie_has_genre(array $movie, string $genre): bool
{
    $needle = mb_strtolower(trim($genre), 'UTF-8');

    if ($needle === '') {
        return false;
    }

    foreach (movie_split_genres((string) ($movie['genre'] ?? '')) as $movieGenre) {
        if (mb_strtolower($movieGenre, 'UTF-8') === $needle) {
            return true;
        }
    }

    return false;
}

function ensure_movie_library(): void
{
    static $bootstrapped = false;
    static $fallbackMode = false;

    if ($bootstrapped) {
        return;
    }

    $GLOBALS['akino_movie_fallback'] = false;

    try {
        $pdo = db();

        if (!akino_runtime_bootstrap_enabled()) {
            if (
                !akino_schema_ready(['movies', 'movie_favorites', 'watch_history'])
                || !akino_column_exists('movies', 'director')
                || !akino_column_exists('movies', 'media_path')
            ) {
                $fallbackMode = true;
                $GLOBALS['akino_movie_fallback'] = true;
            }

            $bootstrapped = true;

            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS movies (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(160) NOT NULL,
                title VARCHAR(160) NOT NULL,
                content_type ENUM("movie", "series") NOT NULL DEFAULT "movie",
                release_year SMALLINT UNSIGNED NOT NULL,
                rating DECIMAL(3,1) NOT NULL DEFAULT 0.0,
                genre VARCHAR(120) NOT NULL,
                country VARCHAR(120) DEFAULT NULL,
                director VARCHAR(160) DEFAULT NULL,
                duration_text VARCHAR(60) DEFAULT NULL,
                age_rating VARCHAR(20) DEFAULT NULL,
                description TEXT NOT NULL,
                poster_path VARCHAR(255) NOT NULL,
                card_path VARCHAR(255) NOT NULL,
                hero_path VARCHAR(255) NOT NULL,
                media_path VARCHAR(255) DEFAULT NULL,
                slider_order INT DEFAULT NULL,
                recommended_order INT DEFAULT NULL,
                new_order INT DEFAULT NULL,
                editors_choice_order INT DEFAULT NULL,
                for_you_order INT DEFAULT NULL,
                catalog_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY movies_slug_unique (slug),
                KEY movies_type_catalog_index (content_type, catalog_order),
                KEY movies_slider_order_index (slider_order),
                KEY movies_recommended_order_index (recommended_order),
                KEY movies_new_order_index (new_order),
                KEY movies_editors_choice_order_index (editors_choice_order),
                KEY movies_for_you_order_index (for_you_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS movie_favorites (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                movie_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY movie_favorites_user_movie_unique (user_id, movie_id),
                KEY movie_favorites_movie_id_index (movie_id),
                CONSTRAINT movie_favorites_user_id_foreign
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT movie_favorites_movie_id_foreign
                    FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS watch_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                movie_id BIGINT UNSIGNED NOT NULL,
                views_count INT UNSIGNED NOT NULL DEFAULT 1,
                first_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY watch_history_user_movie_unique (user_id, movie_id),
                KEY watch_history_movie_id_index (movie_id),
                KEY watch_history_last_viewed_at_index (last_viewed_at),
                CONSTRAINT watch_history_user_id_foreign
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT watch_history_movie_id_foreign
                    FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $statement = $pdo->prepare(
            'INSERT INTO movies (
                slug,
                title,
                content_type,
                release_year,
                rating,
                genre,
                country,
                duration_text,
                age_rating,
                description,
                poster_path,
                card_path,
                hero_path,
                slider_order,
                recommended_order,
                new_order,
                editors_choice_order,
                for_you_order,
                catalog_order
            ) VALUES (
                :slug,
                :title,
                :content_type,
                :release_year,
                :rating,
                :genre,
                :country,
                :duration_text,
                :age_rating,
                :description,
                :poster_path,
                :card_path,
                :hero_path,
                :slider_order,
                :recommended_order,
                :new_order,
                :editors_choice_order,
                :for_you_order,
                :catalog_order
            )
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                content_type = VALUES(content_type),
                release_year = VALUES(release_year),
                rating = VALUES(rating),
                genre = VALUES(genre),
                country = VALUES(country),
                duration_text = VALUES(duration_text),
                age_rating = VALUES(age_rating),
                description = VALUES(description),
                poster_path = VALUES(poster_path),
                card_path = VALUES(card_path),
                hero_path = VALUES(hero_path),
                slider_order = VALUES(slider_order),
                recommended_order = VALUES(recommended_order),
                new_order = VALUES(new_order),
                editors_choice_order = VALUES(editors_choice_order),
                for_you_order = VALUES(for_you_order),
                catalog_order = VALUES(catalog_order),
                updated_at = NOW()'
        );

        foreach (movie_seed_rows() as $movie) {
            $statement->execute($movie);
        }
    } catch (Throwable) {
        $fallbackMode = true;
        $GLOBALS['akino_movie_fallback'] = true;
    }

    $bootstrapped = true;
}

function movie_fallback_mode(): bool
{
    ensure_movie_library();
    static $result = null;

    if (!empty($GLOBALS['akino_movie_fallback'])) {
        return true;
    }

    if ($result !== null) {
        return $result;
    }

    try {
        db()->query('SELECT 1 FROM movies LIMIT 1');
        $result = false;
    } catch (Throwable) {
        $result = true;
    }

    return $result;
}

function fallback_movies(): array
{
    static $rows = null;

    if ($rows !== null) {
        return $rows;
    }

    $rows = [];

    foreach (movie_seed_rows() as $index => $movie) {
        $movie['id'] = $index + 1;
        $rows[] = $movie;
    }

    return $rows;
}

function movie_seed_rows(): array
{
    return [
        [
            'slug' => 'hobbit-bitva-pyati-voinstv',
            'title' => 'Хоббит: Битва пяти воинств',
            'content_type' => 'movie',
            'release_year' => 2014,
            'rating' => 7.9,
            'genre' => 'Фэнтези',
            'country' => 'Новая Зеландия / США',
            'duration_text' => '2 ч 24 мин',
            'age_rating' => '16+',
            'description' => 'Бильбо и отряд гномов возвращаются к Одинокой горе, чтобы отстоять Эребор и пережить битву, в которой решится судьба всего Средиземья.',
            'poster_path' => 'img/film1.png',
            'card_path' => 'img/film1.png',
            'hero_path' => 'img/film1.png',
            'slider_order' => 1,
            'recommended_order' => 4,
            'new_order' => null,
            'editors_choice_order' => null,
            'for_you_order' => 4,
            'catalog_order' => 10,
        ],
        [
            'slug' => 'garri-potter-i-filosofskiy-kamen',
            'title' => 'Гарри Поттер и философский камень',
            'content_type' => 'movie',
            'release_year' => 2001,
            'rating' => 8.3,
            'genre' => 'Фэнтези',
            'country' => 'Великобритания / США',
            'duration_text' => '2 ч 32 мин',
            'age_rating' => '12+',
            'description' => 'Одиннадцатилетний Гарри узнаёт, что он волшебник, и отправляется в Хогвартс, где его ждут новые друзья, опасности и первая встреча с тёмной силой.',
            'poster_path' => 'img/film2.png',
            'card_path' => 'img/film2.png',
            'hero_path' => 'img/film2.png',
            'slider_order' => 2,
            'recommended_order' => 1,
            'new_order' => null,
            'editors_choice_order' => null,
            'for_you_order' => 6,
            'catalog_order' => 20,
        ],
        [
            'slug' => 'dune',
            'title' => 'Дюна',
            'content_type' => 'movie',
            'release_year' => 2021,
            'rating' => 7.7,
            'genre' => 'Фантастика',
            'country' => 'США / Канада',
            'duration_text' => '2 ч 35 мин',
            'age_rating' => '12+',
            'description' => 'Пол Атрейдес прибывает на Арракис, где политика, пророчества и борьба за самую ценную специю во Вселенной меняют его судьбу.',
            'poster_path' => 'img/dune-poster-23.jpg',
            'card_path' => 'img/dune-poster-23.jpg',
            'hero_path' => 'img/dune-poster-23.jpg',
            'slider_order' => 3,
            'recommended_order' => 2,
            'new_order' => 3,
            'editors_choice_order' => 2,
            'for_you_order' => 2,
            'catalog_order' => 30,
        ],
        [
            'slug' => 'nazad-v-budushhee',
            'title' => 'Назад в будущее',
            'content_type' => 'movie',
            'release_year' => 1985,
            'rating' => 8.6,
            'genre' => 'Фантастика / Комедия / Приключение',
            'country' => 'США',
            'duration_text' => '1 ч 56 мин',
            'age_rating' => '12+',
            'description' => 'Марти МакФлай случайно переносится в прошлое и должен исправить временную петлю, чтобы сохранить собственное будущее.',
            'poster_path' => 'img/1681387020_papik-pro-p-nazad-v-budushchee-plakat-39.jpg',
            'card_path' => 'img/1681387020_papik-pro-p-nazad-v-budushchee-plakat-39.jpg',
            'hero_path' => 'img/1681387020_papik-pro-p-nazad-v-budushchee-plakat-39.jpg',
            'slider_order' => 4,
            'recommended_order' => 5,
            'new_order' => null,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 40,
        ],
        [
            'slug' => 'finansovyy-monstr',
            'title' => 'Финансовый монстр',
            'content_type' => 'movie',
            'release_year' => 2016,
            'rating' => 6.7,
            'genre' => 'Триллер',
            'country' => 'США',
            'duration_text' => '1 ч 38 мин',
            'age_rating' => '18+',
            'description' => 'Популярное телешоу о деньгах превращается в прямой эфир под угрозой, когда обманутый инвестор захватывает студию.',
            'poster_path' => 'img/moneymonster_6.jpg',
            'card_path' => 'img/moneymonster_6.jpg',
            'hero_path' => 'img/moneymonster_6.jpg',
            'slider_order' => 5,
            'recommended_order' => 6,
            'new_order' => null,
            'editors_choice_order' => null,
            'for_you_order' => 5,
            'catalog_order' => 50,
        ],
        [
            'slug' => 'it',
            'title' => 'Оно',
            'content_type' => 'movie',
            'release_year' => 2017,
            'rating' => 7.3,
            'genre' => 'Хоррор',
            'country' => 'США / Канада',
            'duration_text' => '2 ч 15 мин',
            'age_rating' => '18+',
            'description' => 'Группа подростков из Дерри сталкивается с древним злом, которое принимает облик их самых больших страхов.',
            'poster_path' => 'img/prew/640x360 (4).webp',
            'card_path' => 'img/prew/640x360 (4).webp',
            'hero_path' => 'img/prew/640x360 (4).webp',
            'slider_order' => null,
            'recommended_order' => 3,
            'new_order' => 4,
            'editors_choice_order' => null,
            'for_you_order' => 3,
            'catalog_order' => 60,
        ],
        [
            'slug' => 'comeback',
            'title' => 'Камбэк',
            'content_type' => 'series',
            'release_year' => 2025,
            'rating' => 8.1,
            'genre' => 'Драма',
            'country' => 'Россия',
            'duration_text' => '1 сезон',
            'age_rating' => '18+',
            'description' => 'История артиста, который пытается заново собрать свою жизнь после громкого падения и неожиданного возвращения на сцену.',
            'poster_path' => 'img/prew/640x360 (3).webp',
            'card_path' => 'img/prew/640x360 (3).webp',
            'hero_path' => 'img/prew/640x360 (3).webp',
            'slider_order' => 6,
            'recommended_order' => null,
            'new_order' => 1,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 10,
        ],
        [
            'slug' => 'mazhor',
            'title' => 'Мажор',
            'content_type' => 'series',
            'release_year' => 2014,
            'rating' => 8.0,
            'genre' => 'Детектив / Триллер / Драма',
            'country' => 'Россия',
            'duration_text' => '52 мин / серия',
            'age_rating' => '16+',
            'description' => 'Сын олигарха Игорь Соколовский после очередной выходки вынужден работать в полиции и постепенно становится настоящим следователем.',
            'poster_path' => 'img/prew/640x360 (6).webp',
            'card_path' => 'img/prew/640x360 (6).webp',
            'hero_path' => 'img/prew/640x360 (6).webp',
            'slider_order' => 7,
            'recommended_order' => null,
            'new_order' => 2,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 20,
        ],
        [
            'slug' => 'golodnye-igry-soyka-peresmeshnitsa-chast-1',
            'title' => 'Голодные игры: Сойка-пересмешница. Часть 1',
            'content_type' => 'movie',
            'release_year' => 2014,
            'rating' => 7.1,
            'genre' => 'Фантастика / Боевик / Триллер / Приключения',
            'country' => 'США',
            'duration_text' => '2 ч 02 мин',
            'age_rating' => '16+',
            'description' => 'Китнисс Эвердин становится символом сопротивления и присоединяется к восстанию дистриктов против Капитолия.',
            'poster_path' => 'img/prew/640x360 (7).webp',
            'card_path' => 'img/prew/640x360 (7).webp',
            'hero_path' => 'img/prew/640x360 (7).webp',
            'slider_order' => 8,
            'recommended_order' => null,
            'new_order' => 5,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 30,
        ],
        [
            'slug' => 'illyuziya-obmana-2',
            'title' => 'Иллюзия обмана 2',
            'content_type' => 'movie',
            'release_year' => 2016,
            'rating' => 7.5,
            'genre' => 'Боевик / Триллер / Комедия / Криминал / Детектив',
            'country' => 'США',
            'duration_text' => '2 ч 09 мин',
            'age_rating' => '16+',
            'description' => 'Четыре всадника возвращаются, чтобы восстановить репутацию и вывести на чистую воду технологического магната.',
            'poster_path' => 'img/prew/640x360 (8).webp',
            'card_path' => 'img/prew/640x360 (8).webp',
            'hero_path' => 'img/prew/640x360 (8).webp',
            'slider_order' => null,
            'recommended_order' => null,
            'new_order' => 4,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 40,
        ],
        [
            'slug' => 'akira',
            'title' => 'Акира',
            'content_type' => 'movie',
            'release_year' => 1988,
            'rating' => 8.4,
            'genre' => 'Аниме / Фантастика / Боевик',
            'country' => 'Япония',
            'duration_text' => '2 ч 04 мин',
            'age_rating' => '16+',
            'description' => 'В Нео-Токио байкер Канэда пытается спасти друга Тэцуо, получившего разрушительные психические способности.',
            'poster_path' => 'img/prew/468x264.jpg',
            'card_path' => 'img/prew/468x264.jpg',
            'hero_path' => 'img/prew/468x264.jpg',
            'slider_order' => null,
            'recommended_order' => 8,
            'new_order' => 6,
            'editors_choice_order' => 3,
            'for_you_order' => null,
            'catalog_order' => 50,
        ],
        [
            'slug' => 'priklyucheniya-paddingtona-3',
            'title' => 'Приключения Паддингтона 3',
            'content_type' => 'movie',
            'release_year' => 2024,
            'rating' => 8.4,
            'genre' => 'Комедия / Приключения / Семейный / Детектив',
            'country' => 'Великобритания / Франция / Япония',
            'duration_text' => '1 ч 43 мин',
            'age_rating' => '6+',
            'description' => 'Паддингтон вместе с семьёй Браунов отправляется в Перу на поиски пропавшей тёти Люси.',
            'poster_path' => 'img/prew/640x360 (9).webp',
            'card_path' => 'img/prew/640x360 (9).webp',
            'hero_path' => 'img/prew/640x360 (9).webp',
            'slider_order' => null,
            'recommended_order' => 7,
            'new_order' => 3,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 60,
        ],
        [
            'slug' => 'anatomiya-padeniya',
            'title' => 'Анатомия падения',
            'content_type' => 'movie',
            'release_year' => 2023,
            'rating' => 7.6,
            'genre' => 'Драма / Триллер / Криминал',
            'country' => 'Франция',
            'duration_text' => '2 ч 32 мин',
            'age_rating' => '18+',
            'description' => 'Писательница оказывается в центре судебного разбирательства после гибели мужа в альпийском шале. Каждая деталь их отношений становится уликой.',
            'poster_path' => 'img/prew/anatomy-of-a-fall.png',
            'card_path' => 'img/prew/anatomy-of-a-fall.png',
            'hero_path' => 'img/prew/anatomy-of-a-fall.png',
            'slider_order' => null,
            'recommended_order' => null,
            'new_order' => 7,
            'editors_choice_order' => 1,
            'for_you_order' => null,
            'catalog_order' => 70,
        ],
        [
            'slug' => 'drugie-novaya-obitel',
            'title' => 'Другие: Новая обитель',
            'content_type' => 'movie',
            'release_year' => 2021,
            'rating' => 6.4,
            'genre' => 'Хоррор / Мистика',
            'country' => 'США',
            'duration_text' => '1 ч 29 мин',
            'age_rating' => '18+',
            'description' => 'Семья переезжает в уединённый дом, надеясь начать всё сначала, но вскоре понимает, что у нового убежища есть собственные тайны.',
            'poster_path' => 'img/prew/640x360 (2).webp',
            'card_path' => 'img/prew/640x360 (2).webp',
            'hero_path' => 'img/prew/640x360 (2).webp',
            'slider_order' => null,
            'recommended_order' => null,
            'new_order' => 8,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 80,
        ],
        [
            'slug' => 'leyla-zhizn-lyubov-spravedlivost',
            'title' => 'Лейла: Жизнь, любовь, справедливость',
            'content_type' => 'series',
            'release_year' => 2024,
            'rating' => 7.8,
            'genre' => 'Драма / Мелодрама',
            'country' => 'Турция',
            'duration_text' => '1 сезон',
            'age_rating' => '16+',
            'description' => 'Лейла возвращается в город детства под новым именем, чтобы раскрыть правду о семье и встретиться лицом к лицу с прошлым.',
            'poster_path' => 'img/prew/640x360.webp',
            'card_path' => 'img/prew/640x360.webp',
            'hero_path' => 'img/prew/640x360.webp',
            'slider_order' => null,
            'recommended_order' => null,
            'new_order' => 9,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 30,
        ],
        [
            'slug' => 'gachiakuta',
            'title' => 'Гачиакута',
            'content_type' => 'series',
            'release_year' => 2025,
            'rating' => 8.2,
            'genre' => 'Аниме / Боевик / Фэнтези',
            'country' => 'Япония',
            'duration_text' => '1 сезон',
            'age_rating' => '16+',
            'description' => 'Рудо живёт на окраине небесного города. После ложного обвинения его сбрасывают в бездну, где мусор превращается в опасных чудовищ.',
            'poster_path' => 'img/prew/640x360 (1).webp',
            'card_path' => 'img/prew/640x360 (1).webp',
            'hero_path' => 'img/prew/640x360 (1).webp',
            'slider_order' => null,
            'recommended_order' => null,
            'new_order' => 10,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 40,
        ],
        [
            'slug' => 'kuhnya',
            'title' => 'Кухня',
            'content_type' => 'series',
            'release_year' => 2012,
            'rating' => 8.2,
            'genre' => 'Комедия',
            'country' => 'Россия',
            'duration_text' => '6 сезонов',
            'age_rating' => '16+',
            'description' => 'Молодой повар Максим Лавров устраивается в модный московский ресторан и узнаёт, что за закрытыми дверями кухни кипят нешуточные страсти.',
            'poster_path' => 'img/prew/640x360 (5).webp',
            'card_path' => 'img/prew/640x360 (5).webp',
            'hero_path' => 'img/prew/640x360 (5).webp',
            'slider_order' => null,
            'recommended_order' => null,
            'new_order' => 11,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 50,
        ],
        [
            'slug' => 'tri-kota',
            'title' => 'Три кота',
            'content_type' => 'series',
            'release_year' => 2015,
            'rating' => 8.1,
            'genre' => 'Мультфильм / Семейный',
            'country' => 'Россия',
            'duration_text' => '10 сезонов',
            'age_rating' => '0+',
            'description' => 'Коржик, Компот и Карамелька каждый день находят повод для нового приключения и вместе учатся дружбе, заботе и воображению.',
            'poster_path' => 'img/prew/468x264.webp',
            'card_path' => 'img/prew/468x264.webp',
            'hero_path' => 'img/prew/468x264.webp',
            'slider_order' => null,
            'recommended_order' => null,
            'new_order' => 12,
            'editors_choice_order' => null,
            'for_you_order' => null,
            'catalog_order' => 60,
        ],
    ];
}

function home_section_column(string $section): string
{
    return match ($section) {
        'slider' => 'slider_order',
        'recommended' => 'recommended_order',
        'new' => 'new_order',
        'editors_choice' => 'editors_choice_order',
        'for_you' => 'for_you_order',
        default => throw new InvalidArgumentException('Неизвестная секция фильмов.'),
    };
}

function fetch_home_section_movies(string $section, int $limit): array
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        $column = home_section_column($section);
        $movies = array_values(array_filter(
            fallback_movies(),
            static fn (array $movie): bool => $movie[$column] !== null
        ));
        usort($movies, static function (array $left, array $right) use ($column): int {
            return [$left[$column], -$left['release_year'], -((float) $left['rating'] * 10)]
                <=> [$right[$column], -$right['release_year'], -((float) $right['rating'] * 10)];
        });

        return array_slice($movies, 0, $limit);
    }

    $column = home_section_column($section);
    $statement = db()->prepare(
        "SELECT * FROM movies
         WHERE {$column} IS NOT NULL
         ORDER BY {$column} ASC, rating DESC, release_year DESC
         LIMIT :limit"
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function fetch_catalog_movies(string $contentType): array
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        $movies = array_values(array_filter(
            fallback_movies(),
            static fn (array $movie): bool => $movie['content_type'] === $contentType
        ));
        usort($movies, static function (array $left, array $right): int {
            return [$left['catalog_order'], -((float) $left['rating'] * 10), -$left['release_year']]
                <=> [$right['catalog_order'], -((float) $right['rating'] * 10), -$right['release_year']];
        });

        return $movies;
    }

    $statement = db()->prepare(
        'SELECT * FROM movies
         WHERE content_type = :content_type
         ORDER BY catalog_order ASC, rating DESC, release_year DESC'
    );
    $statement->execute(['content_type' => $contentType]);

    return $statement->fetchAll();
}

function movie_search_position(string $haystack, string $needle)
{
    if ($needle === '') {
        return 0;
    }

    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8');
    }

    return stripos($haystack, $needle);
}

function search_catalog_movies(string $query, ?string $contentType = null, int $limit = 24): array
{
    ensure_movie_library();

    $query = trim($query);
    $contentType = in_array($contentType, ['movie', 'series'], true) ? $contentType : null;

    if ($query === '' || $limit <= 0) {
        return [];
    }

    if (movie_fallback_mode()) {
        $movies = array_values(array_filter(
            fallback_movies(),
            static function (array $movie) use ($query, $contentType): bool {
                if ($contentType !== null && ($movie['content_type'] ?? null) !== $contentType) {
                    return false;
                }

                foreach ([
                    (string) ($movie['title'] ?? ''),
                    (string) ($movie['genre'] ?? ''),
                    (string) ($movie['country'] ?? ''),
                    (string) ($movie['director'] ?? ''),
                    (string) ($movie['description'] ?? ''),
                ] as $haystack) {
                    if (movie_search_position($haystack, $query) !== false) {
                        return true;
                    }
                }

                return false;
            }
        ));

        usort($movies, static function (array $left, array $right) use ($query): int {
            $leftTitlePosition = movie_search_position((string) ($left['title'] ?? ''), $query);
            $rightTitlePosition = movie_search_position((string) ($right['title'] ?? ''), $query);

            return [
                $leftTitlePosition === false ? 1 : 0,
                $leftTitlePosition === false ? PHP_INT_MAX : $leftTitlePosition,
                -((float) ($left['rating'] ?? 0) * 10),
                -((int) ($left['release_year'] ?? 0)),
                (int) ($left['catalog_order'] ?? PHP_INT_MAX),
            ] <=> [
                $rightTitlePosition === false ? 1 : 0,
                $rightTitlePosition === false ? PHP_INT_MAX : $rightTitlePosition,
                -((float) ($right['rating'] ?? 0) * 10),
                -((int) ($right['release_year'] ?? 0)),
                (int) ($right['catalog_order'] ?? PHP_INT_MAX),
            ];
        });

        return array_slice($movies, 0, $limit);
    }

    $sql = 'SELECT * FROM movies
            WHERE (
                title LIKE :title_term
                OR genre LIKE :genre_term
                OR country LIKE :country_term
                OR director LIKE :director_term
                OR description LIKE :description_term
            )';

    if ($contentType !== null) {
        $sql .= ' AND content_type = :content_type';
    }

    $sql .= '
        ORDER BY
            CASE WHEN title LIKE :title_prefix THEN 0 ELSE 1 END,
            CASE WHEN title LIKE :title_order_term THEN 0 ELSE 1 END,
            rating DESC,
            release_year DESC,
            catalog_order ASC
        LIMIT :limit';

    $statement = db()->prepare($sql);
    $statement->bindValue(':title_term', '%' . $query . '%');
    $statement->bindValue(':genre_term', '%' . $query . '%');
    $statement->bindValue(':country_term', '%' . $query . '%');
    $statement->bindValue(':director_term', '%' . $query . '%');
    $statement->bindValue(':description_term', '%' . $query . '%');
    $statement->bindValue(':title_prefix', $query . '%');
    $statement->bindValue(':title_order_term', '%' . $query . '%');

    if ($contentType !== null) {
        $statement->bindValue(':content_type', $contentType);
    }

    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function search_catalog_suggestions(string $query, int $limit = 8): array
{
    $movies = search_catalog_movies($query, null, $limit);

    return array_map(
        static function (array $movie): array {
            return [
                'id' => (int) ($movie['id'] ?? 0),
                'title' => (string) ($movie['title'] ?? ''),
                'type' => (string) ($movie['content_type'] ?? 'movie'),
                'typeLabel' => movie_type_label($movie),
                'year' => (int) ($movie['release_year'] ?? 0),
                'director' => (string) ($movie['director'] ?? ''),
                'url' => 'Film_Page.php?id=' . (int) ($movie['id'] ?? 0),
            ];
        },
        $movies
    );
}

function catalog_sort_options(): array
{
    return [
        'rating_desc' => 'Рекомендуемые',
        'year_desc' => 'Сначала новые',
        'year_asc' => 'Сначала классика',
        'title_asc' => 'По названию',
    ];
}

function fetch_catalog_filter_options(string $contentType): array
{
    $movies = fetch_catalog_movies($contentType);
    $genres = [];
    $countries = [];
    $directors = [];
    $years = [];

    foreach ($movies as $movie) {
        $country = trim((string) ($movie['country'] ?? ''));
        $director = trim((string) ($movie['director'] ?? ''));
        $year = (int) ($movie['release_year'] ?? 0);

        foreach (movie_split_genres((string) ($movie['genre'] ?? '')) as $genre) {
            $genres[mb_strtolower($genre, 'UTF-8')] = $genre;
        }

        if ($country !== '') {
            $countries[$country] = $country;
        }

        if ($director !== '') {
            $directors[$director] = $director;
        }

        if ($year > 0) {
            $years[(string) $year] = (string) $year;
        }
    }

    $genreValues = array_values($genres);
    $countryValues = array_values($countries);
    $directorValues = array_values($directors);
    $yearValues = array_values($years);

    sort($genreValues, SORT_NATURAL | SORT_FLAG_CASE);
    sort($countryValues, SORT_NATURAL | SORT_FLAG_CASE);
    sort($directorValues, SORT_NATURAL | SORT_FLAG_CASE);
    rsort($yearValues, SORT_NUMERIC);

    return [
        'sorts' => catalog_sort_options(),
        'genres' => array_combine($genreValues, $genreValues) ?: [],
        'countries' => array_combine($countryValues, $countryValues) ?: [],
        'directors' => array_combine($directorValues, $directorValues) ?: [],
        'years' => array_combine($yearValues, $yearValues) ?: [],
    ];
}

function normalize_catalog_filters(array $input, array $options): array
{
    $sort = (string) ($input['sort'] ?? 'rating_desc');
    $genre = trim((string) ($input['genre'] ?? ''));
    $year = trim((string) ($input['year'] ?? ''));
    $country = trim((string) ($input['country'] ?? ''));
    $director = trim((string) ($input['director'] ?? ''));

    if (!array_key_exists($sort, $options['sorts'] ?? [])) {
        $sort = 'rating_desc';
    }

    if (!array_key_exists($genre, $options['genres'] ?? [])) {
        $genre = '';
    }

    if (!array_key_exists($year, $options['years'] ?? [])) {
        $year = '';
    }

    if (!array_key_exists($country, $options['countries'] ?? [])) {
        $country = '';
    }

    if (!array_key_exists($director, $options['directors'] ?? [])) {
        $director = '';
    }

    return [
        'sort' => $sort,
        'genre' => $genre,
        'year' => $year,
        'country' => $country,
        'director' => $director,
    ];
}

function catalog_filters_are_active(array $filters): bool
{
    return ($filters['genre'] ?? '') !== ''
        || ($filters['year'] ?? '') !== ''
        || ($filters['country'] ?? '') !== ''
        || ($filters['director'] ?? '') !== ''
        || (($filters['sort'] ?? 'rating_desc') !== 'rating_desc');
}

function apply_catalog_filters(array $movies, array $filters): array
{
    $genre = (string) ($filters['genre'] ?? '');
    $year = (string) ($filters['year'] ?? '');
    $country = (string) ($filters['country'] ?? '');
    $director = (string) ($filters['director'] ?? '');
    $sort = (string) ($filters['sort'] ?? 'rating_desc');

    $filtered = array_values(array_filter(
        $movies,
        static function (array $movie) use ($genre, $year, $country, $director): bool {
            if ($genre !== '' && !movie_has_genre($movie, $genre)) {
                return false;
            }

            if ($year !== '' && (string) ($movie['release_year'] ?? '') !== $year) {
                return false;
            }

            if ($country !== '' && (string) ($movie['country'] ?? '') !== $country) {
                return false;
            }

            if ($director !== '' && (string) ($movie['director'] ?? '') !== $director) {
                return false;
            }

            return true;
        }
    ));

    usort($filtered, static function (array $left, array $right) use ($sort): int {
        return match ($sort) {
            'year_desc' => [
                -((int) ($left['release_year'] ?? 0)),
                -((float) ($left['rating'] ?? 0) * 10),
                (int) ($left['catalog_order'] ?? PHP_INT_MAX),
            ] <=> [
                -((int) ($right['release_year'] ?? 0)),
                -((float) ($right['rating'] ?? 0) * 10),
                (int) ($right['catalog_order'] ?? PHP_INT_MAX),
            ],
            'year_asc' => [
                (int) ($left['release_year'] ?? 0),
                -((float) ($left['rating'] ?? 0) * 10),
                (int) ($left['catalog_order'] ?? PHP_INT_MAX),
            ] <=> [
                (int) ($right['release_year'] ?? 0),
                -((float) ($right['rating'] ?? 0) * 10),
                (int) ($right['catalog_order'] ?? PHP_INT_MAX),
            ],
            'title_asc' => [
                (string) ($left['title'] ?? ''),
                -((float) ($left['rating'] ?? 0) * 10),
            ] <=> [
                (string) ($right['title'] ?? ''),
                -((float) ($right['rating'] ?? 0) * 10),
            ],
            default => [
                -((float) ($left['rating'] ?? 0) * 10),
                -((int) ($left['release_year'] ?? 0)),
                (int) ($left['catalog_order'] ?? PHP_INT_MAX),
            ] <=> [
                -((float) ($right['rating'] ?? 0) * 10),
                -((int) ($right['release_year'] ?? 0)),
                (int) ($right['catalog_order'] ?? PHP_INT_MAX),
            ],
        };
    });

    return $filtered;
}

function fetch_filtered_catalog_movies(string $contentType, array $filters): array
{
    return apply_catalog_filters(fetch_catalog_movies($contentType), $filters);
}

function normalize_page_number($value): int
{
    $page = is_numeric($value) ? (int) $value : 1;

    return max(1, $page);
}

function paginate_items(array $items, int $page, int $perPage = 12): array
{
    $perPage = max(1, $perPage);
    $totalItems = count($items);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;
    $pagedItems = array_slice($items, $offset, $perPage);
    $from = $totalItems > 0 ? $offset + 1 : 0;
    $to = $totalItems > 0 ? $offset + count($pagedItems) : 0;

    return [
        'items' => $pagedItems,
        'page' => $page,
        'perPage' => $perPage,
        'totalItems' => $totalItems,
        'totalPages' => $totalPages,
        'hasPrev' => $page > 1,
        'hasNext' => $page < $totalPages,
        'prevPage' => $page > 1 ? $page - 1 : 1,
        'nextPage' => $page < $totalPages ? $page + 1 : $totalPages,
        'from' => $from,
        'to' => $to,
    ];
}

function find_movie_by_id(int $movieId): ?array
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        foreach (fallback_movies() as $movie) {
            if ((int) $movie['id'] === $movieId) {
                return $movie;
            }
        }

        return null;
    }

    $statement = db()->prepare('SELECT * FROM movies WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $movieId]);
    $movie = $statement->fetch();

    return $movie ?: null;
}

function fetch_related_movies(int $movieId, string $contentType, int $limit = 6): array
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        $movies = array_values(array_filter(
            fallback_movies(),
            static fn (array $movie): bool => $movie['content_type'] === $contentType && (int) $movie['id'] !== $movieId
        ));
        usort($movies, static function (array $left, array $right): int {
            return [-((float) $left['rating'] * 10), -$left['release_year'], $left['catalog_order']]
                <=> [-((float) $right['rating'] * 10), -$right['release_year'], $right['catalog_order']];
        });

        return array_slice($movies, 0, $limit);
    }

    $statement = db()->prepare(
        'SELECT * FROM movies
         WHERE content_type = :content_type AND id <> :id
         ORDER BY rating DESC, release_year DESC, catalog_order ASC
         LIMIT :limit'
    );
    $statement->bindValue(':content_type', $contentType);
    $statement->bindValue(':id', $movieId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function movie_type_label(array $movie): string
{
    return ($movie['content_type'] ?? 'movie') === 'series' ? 'Сериал' : 'Фильм';
}

function movie_catalog_url(array $movie): string
{
    return ($movie['content_type'] ?? 'movie') === 'series' ? 'Series_Page.php' : 'Films_Catalog.php';
}

function build_movie_card_payload(array $movie): array
{
    return [
        'id' => (int) ($movie['id'] ?? 0),
        'title' => (string) ($movie['title'] ?? ''),
        'type' => (string) ($movie['content_type'] ?? 'movie'),
        'typeLabel' => movie_type_label($movie),
        'releaseYear' => (int) ($movie['release_year'] ?? 0),
        'rating' => (float) ($movie['rating'] ?? 0),
        'ratingDisplay' => number_format((float) ($movie['rating'] ?? 0), 1, '.', ''),
        'genre' => (string) ($movie['genre'] ?? ''),
        'cardPath' => (string) ($movie['card_path'] ?? ''),
        'posterPath' => (string) ($movie['poster_path'] ?? ''),
        'url' => 'Film_Page.php?id=' . (int) ($movie['id'] ?? 0),
    ];
}

function fetch_user_favorite_movie_ids(int $userId): array
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT movie_id
         FROM movie_favorites
         WHERE user_id = :user_id'
    );
    $statement->execute(['user_id' => $userId]);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function movie_is_favorite_for_user(?int $userId, int $movieId): bool
{
    if ($userId === null || $movieId <= 0) {
        return false;
    }

    static $lookupByUser = [];

    if (!array_key_exists($userId, $lookupByUser)) {
        $lookupByUser[$userId] = array_fill_keys(fetch_user_favorite_movie_ids($userId), true);
    }

    return isset($lookupByUser[$userId][$movieId]);
}

function is_movie_favorite(int $userId, int $movieId): bool
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        return false;
    }

    $statement = db()->prepare(
        'SELECT id FROM movie_favorites WHERE user_id = :user_id AND movie_id = :movie_id LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
        'movie_id' => $movieId,
    ]);

    return (bool) $statement->fetchColumn();
}

function toggle_movie_favorite(int $userId, int $movieId): bool
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        return false;
    }

    $db = db();
    $db->beginTransaction();

    try {
        if (is_movie_favorite($userId, $movieId)) {
            $statement = $db->prepare(
                'DELETE FROM movie_favorites WHERE user_id = :user_id AND movie_id = :movie_id'
            );
            $statement->execute([
                'user_id' => $userId,
                'movie_id' => $movieId,
            ]);
            $active = false;
        } else {
            $statement = $db->prepare(
                'INSERT INTO movie_favorites (user_id, movie_id, created_at)
                 VALUES (:user_id, :movie_id, NOW())'
            );
            $statement->execute([
                'user_id' => $userId,
                'movie_id' => $movieId,
            ]);
            $active = true;
        }

        $db->commit();

        return $active;
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}

function remove_movie_favorite(int $userId, int $movieId): bool
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        return false;
    }

    $statement = db()->prepare(
        'DELETE FROM movie_favorites WHERE user_id = :user_id AND movie_id = :movie_id'
    );
    $statement->execute([
        'user_id' => $userId,
        'movie_id' => $movieId,
    ]);

    return $statement->rowCount() > 0;
}

function record_watch_history(int $userId, int $movieId): void
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        return;
    }

    $statement = db()->prepare(
        'INSERT INTO watch_history (user_id, movie_id, views_count, first_viewed_at, last_viewed_at)
         VALUES (:user_id, :movie_id, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            views_count = views_count + 1,
            last_viewed_at = NOW()'
    );
    $statement->execute([
        'user_id' => $userId,
        'movie_id' => $movieId,
    ]);
}

function fetch_user_favorites_payload(int $userId, int $limit = 12): array
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT m.*, mf.created_at AS favorited_at
         FROM movie_favorites mf
         INNER JOIN movies m ON m.id = mf.movie_id
         WHERE mf.user_id = :user_id
         ORDER BY mf.created_at DESC
         LIMIT :limit'
    );
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return array_map(
        static function (array $movie): array {
            $payload = build_movie_card_payload($movie);
            $payload['favoritedAt'] = $movie['favorited_at'] ?? null;
            $payload['favoritedAtDisplay'] = !empty($movie['favorited_at'])
                ? (new DateTimeImmutable((string) $movie['favorited_at']))->format('d.m.Y H:i')
                : '';

            return $payload;
        },
        $statement->fetchAll()
    );
}

function fetch_user_watch_history_payload(int $userId, int $limit = 12): array
{
    ensure_movie_library();

    if (movie_fallback_mode()) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT m.*, wh.last_viewed_at, wh.views_count
         FROM watch_history wh
         INNER JOIN movies m ON m.id = wh.movie_id
         WHERE wh.user_id = :user_id
         ORDER BY wh.last_viewed_at DESC
         LIMIT :limit'
    );
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return array_map(
        static function (array $movie): array {
            $payload = build_movie_card_payload($movie);
            $payload['viewsCount'] = (int) ($movie['views_count'] ?? 0);
            $payload['viewedAt'] = $movie['last_viewed_at'] ?? null;
            $payload['viewedAtDisplay'] = !empty($movie['last_viewed_at'])
                ? (new DateTimeImmutable((string) $movie['last_viewed_at']))->format('d.m.Y H:i')
                : '';

            return $payload;
        },
        $statement->fetchAll()
    );
}

function build_account_user_payload(array $user): array
{
    $payload = build_user_payload($user);
    $payload['favorites'] = fetch_user_favorites_payload((int) $user['id']);
    $payload['history'] = fetch_user_watch_history_payload((int) $user['id']);
    $payload['continueWatching'] = fetch_continue_watching_payload((int) $user['id']);

    return $payload;
}
