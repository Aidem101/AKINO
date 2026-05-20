<?php

declare(strict_types=1);

if (!function_exists('akino_escape')) {
    function akino_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('akino_movie_url')) {
    function akino_movie_url(array $movie): string
    {
        return 'Film_Page.php?id=' . (int) ($movie['id'] ?? 0);
    }
}

if (!function_exists('akino_movie_is_favorite')) {
    function akino_movie_is_favorite(array $movie): bool
    {
        if (isset($movie['is_favorite'])) {
            return (bool) $movie['is_favorite'];
        }

        $movieId = (int) ($movie['id'] ?? 0);
        $userId = current_user_id();

        return movie_is_favorite_for_user($userId, $movieId);
    }
}

if (!function_exists('render_movie_favorite_button')) {
    function render_movie_favorite_button(array $movie, string $variant = 'default'): void
    {
        if (movie_fallback_mode()) {
            return;
        }

        $movieId = (int) ($movie['id'] ?? 0);

        if ($movieId <= 0) {
            return;
        }

        $active = akino_movie_is_favorite($movie);
        $buttonClass = 'movie-favorite-toggle';

        if ($variant === 'compact') {
            $buttonClass .= ' is-compact';
        }

        if ($active) {
            $buttonClass .= ' is-active';
        }
        ?>
        <button
          type="button"
          class="<?= akino_escape($buttonClass) ?>"
          data-favorite-button
          data-movie-id="<?= $movieId ?>"
          data-active="<?= $active ? '1' : '0' ?>"
          data-add-label="В избранное"
          data-added-label="В избранном"
          aria-label="<?= akino_escape($active ? 'Убрать из избранного' : 'Добавить в избранное') ?>"
          title="<?= akino_escape($active ? 'Убрать из избранного' : 'Добавить в избранное') ?>"
        >
          <i class="fa-solid fa-heart" aria-hidden="true"></i>
        </button>
        <?php
    }
}

if (!function_exists('render_home_slider_card')) {
    function render_home_slider_card(array $movie): void
    {
        ?>
        <article class="film-card movie-card-shell">
          <a href="<?= akino_escape(akino_movie_url($movie)) ?>" class="movie-card-link">
            <img src="<?= akino_escape((string) ($movie['poster_path'] ?? '')) ?>" alt="<?= akino_escape((string) ($movie['title'] ?? 'Фильм')) ?>">
            <div class="info-container">
              <div class="film-info">
                <h3><?= akino_escape((string) ($movie['title'] ?? 'Фильм')) ?></h3>
                <p><?= (int) ($movie['release_year'] ?? 0) ?></p>
              </div>
              <span class="rating"><?= number_format((float) ($movie['rating'] ?? 0), 1, '.', '') ?></span>
            </div>
          </a>
        </article>
        <?php
    }
}

if (!function_exists('render_mini_card')) {
    function render_mini_card(array $movie): void
    {
        ?>
        <article class="mini-card movie-card-shell">
          <?php render_movie_favorite_button($movie, 'compact'); ?>
          <a href="<?= akino_escape(akino_movie_url($movie)) ?>" class="movie-card-link">
            <img src="<?= akino_escape((string) ($movie['card_path'] ?? '')) ?>" alt="<?= akino_escape((string) ($movie['title'] ?? 'Фильм')) ?>">
            <div class="mini-info">
              <h3><?= akino_escape((string) ($movie['title'] ?? 'Фильм')) ?></h3>
              <p><?= (int) ($movie['release_year'] ?? 0) ?></p>
            </div>
          </a>
        </article>
        <?php
    }
}

if (!function_exists('render_poster_card')) {
    function render_poster_card(array $movie): void
    {
        ?>
        <article class="film-card-poster movie-card-shell">
          <?php render_movie_favorite_button($movie); ?>
          <a href="<?= akino_escape(akino_movie_url($movie)) ?>" class="movie-card-link">
            <img src="<?= akino_escape((string) ($movie['card_path'] ?? '')) ?>" alt="<?= akino_escape((string) ($movie['title'] ?? 'Фильм')) ?>">
            <div class="info-container">
              <div class="film-info">
                <h3><?= akino_escape((string) ($movie['title'] ?? 'Фильм')) ?></h3>
                <p><?= (int) ($movie['release_year'] ?? 0) ?></p>
              </div>
              <span class="rating"><?= number_format((float) ($movie['rating'] ?? 0), 1, '.', '') ?></span>
            </div>
          </a>
        </article>
        <?php
    }
}

if (!function_exists('render_catalog_card')) {
    function render_catalog_card(array $movie): void
    {
        ?>
        <article class="catalog-card movie-card-shell">
          <a href="<?= akino_escape(akino_movie_url($movie)) ?>" class="movie-card-link">
            <div class="card-image">
              <img src="<?= akino_escape((string) ($movie['card_path'] ?? '')) ?>" alt="<?= akino_escape((string) ($movie['title'] ?? 'Фильм')) ?>">
              <div class="hover-overlay"></div>
            </div>
            <div class="card-info">
              <h3><?= akino_escape((string) ($movie['title'] ?? 'Фильм')) ?></h3>
            </div>
          </a>
        </article>
        <?php
    }
}

if (!function_exists('render_continue_watching_card')) {
    function render_continue_watching_card(array $item): void
    {
        $progressWidth = max(0, min(100, (float) ($item['progressPercentWidth'] ?? 0)));
        ?>
        <article class="continue-watch-card">
          <a href="<?= akino_escape((string) ($item['continueUrl'] ?? 'Watch.php')) ?>" class="continue-watch-link">
            <div class="continue-watch-media">
              <img src="<?= akino_escape((string) ($item['coverPath'] ?? ($item['cardPath'] ?? ''))) ?>" alt="<?= akino_escape((string) ($item['title'] ?? 'Фильм')) ?>">
            </div>
            <div class="continue-watch-copy">
              <h3><?= akino_escape((string) ($item['title'] ?? 'Фильм')) ?></h3>
              <div class="continue-watch-progress" aria-hidden="true">
                <span style="width: <?= number_format($progressWidth, 2, '.', '') ?>%;"></span>
              </div>
            </div>
          </a>
        </article>
        <?php
    }
}

if (!function_exists('render_catalog_pagination')) {
    function render_catalog_pagination(array $pagination, callable $urlBuilder): void
    {
        $totalPages = (int) ($pagination['totalPages'] ?? 1);

        if ($totalPages <= 1) {
            return;
        }

        $page = (int) ($pagination['page'] ?? 1);
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        ?>
        <nav class="catalog-pagination" aria-label="Навигация по страницам">
          <a href="<?= akino_escape($urlBuilder(max(1, $page - 1))) ?>" class="catalog-pagination-link<?= $page <= 1 ? ' is-disabled' : '' ?>"<?= $page <= 1 ? ' aria-disabled="true"' : '' ?>>Назад</a>
          <?php for ($index = $start; $index <= $end; $index++): ?>
            <a href="<?= akino_escape($urlBuilder($index)) ?>" class="catalog-pagination-link<?= $index === $page ? ' is-active' : '' ?>"<?= $index === $page ? ' aria-current="page"' : '' ?>><?= $index ?></a>
          <?php endfor; ?>
          <a href="<?= akino_escape($urlBuilder(min($totalPages, $page + 1))) ?>" class="catalog-pagination-link<?= $page >= $totalPages ? ' is-disabled' : '' ?>"<?= $page >= $totalPages ? ' aria-disabled="true"' : '' ?>>Вперёд</a>
        </nav>
        <?php
    }
}
