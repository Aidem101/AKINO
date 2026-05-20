<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/includes/movie-cards.php';

$movieId = (int) ($_GET['id'] ?? 0);
$movie = $movieId > 0 ? find_movie_by_id($movieId) : null;
$currentUserId = current_user_id();

if ($movie === null) {
    http_response_code(404);
}

$relatedMovies = $movie ? fetch_related_movies((int) $movie['id'], (string) $movie['content_type'], 6) : [];
$watchHref = $movie !== null && $currentUserId !== null ? 'Watch.php?id=' . (int) $movie['id'] : 'Home.php?auth=required';
$isFavorite = $movie !== null && $currentUserId !== null
    ? is_movie_favorite($currentUserId, (int) $movie['id'])
    : false;

$pageTitle = $movie ? $movie['title'] . ' - AKINO' : 'Карточка не найдена - AKINO';
$bodyClass = '';
$activeNav = $movie && ($movie['content_type'] ?? 'movie') === 'series' ? 'series' : 'films';

require __DIR__ . '/includes/page-top.php';
?>
<?php if ($movie === null): ?>
  <main class="catalog-page">
    <div class="catalog-header-section">
      <h1 class="page-title">Карточка не найдена</h1>
      <div class="placeholder-content">
        <p>Мы не нашли фильм или сериал с таким идентификатором.</p>
        <p><a href="Films_Catalog.php" class="subscribe-btn">Вернуться в каталог</a></p>
      </div>
    </div>
  </main>
<?php else: ?>
  <section class="movie-hero">
    <div class="movie-hero-visual">
      <img src="<?= akino_escape((string) $movie['hero_path']) ?>" alt="<?= akino_escape((string) $movie['title']) ?>" class="hero-bg-img">
      <img src="film-strip.svg" alt="" class="hero-strip-img">
      <div class="hero-fade-overlay"></div>
    </div>

    <div class="movie-hero-content">
      <h1 class="hero-title"><?= akino_escape((string) $movie['title']) ?></h1>

      <div class="hero-meta">
        <span class="hero-rating"><?= number_format((float) $movie['rating'], 1, '.', '') ?></span>
        <span class="hero-year"><?= (int) $movie['release_year'] ?></span>
        <span class="hero-genre"><?= akino_escape((string) $movie['genre']) ?></span>
      </div>

      <div class="hero-meta">
        <span><?= akino_escape(movie_type_label($movie)) ?></span>
        <?php if (!empty($movie['country'])): ?>
          <span><?= akino_escape((string) $movie['country']) ?></span>
        <?php endif; ?>
        <?php if (!empty($movie['duration_text'])): ?>
          <span><?= akino_escape((string) $movie['duration_text']) ?></span>
        <?php endif; ?>
        <?php if (!empty($movie['age_rating'])): ?>
          <span><?= akino_escape((string) $movie['age_rating']) ?></span>
        <?php endif; ?>
      </div>

      <p class="hero-desc"><?= akino_escape((string) $movie['description']) ?></p>

      <div class="hero-actions">
        <a href="<?= akino_escape($watchHref) ?>" class="hero-watch-btn">
          <i class="fa-solid fa-play"></i> Смотреть
        </a>

        <?php if (!movie_fallback_mode()): ?>
          <button
            type="button"
            class="hero-favorite-btn<?= $isFavorite ? ' is-active' : '' ?>"
            data-favorite-button
            data-movie-id="<?= (int) $movie['id'] ?>"
            data-active="<?= $isFavorite ? '1' : '0' ?>"
            data-add-label="В избранное"
            data-added-label="В избранном"
            data-feedback-target="movieActionFeedback"
          >
            <?= $isFavorite ? 'В избранном' : 'В избранное' ?>
          </button>
        <?php endif; ?>
      </div>

      <div class="movie-inline-feedback" id="movieActionFeedback" hidden></div>
    </div>
  </section>

  <section class="movie-details-section">
    <h1>О фильме</h1>
    <div class="movie-details-copy">
      <p><?= akino_escape((string) $movie['description']) ?></p>
      <p><?= akino_escape(movie_type_label($movie)) ?> доступен в подписке AKINO. Добавьте карточку в избранное, чтобы быстро вернуться к ней из личного кабинета.</p>
      <?php if (!empty($movie['director'])): ?>
        <p>Режиссёр: <?= akino_escape((string) $movie['director']) ?>.</p>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($relatedMovies): ?>
    <section class="film-mini">
      <h1>Смотрите также</h1>

      <div class="mini-track">
        <?php foreach ($relatedMovies as $relatedMovie): ?>
          <?php render_mini_card($relatedMovie); ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
