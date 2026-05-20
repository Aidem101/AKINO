<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/includes/movie-cards.php';

$homeSliderMovies = fetch_home_section_movies('slider', 8);
$recommendedMovies = fetch_home_section_movies('recommended', 6);
$newMovies = fetch_home_section_movies('new', 6);
$editorsChoiceMovies = fetch_home_section_movies('editors_choice', 2);
$forYouMovies = fetch_home_section_movies('for_you', 6);

$pageTitle = 'AKINO';
$bodyClass = 'Black_page';
$activeNav = 'home';

require __DIR__ . '/includes/page-top.php';
?>
<section class="film-slider">
  <img src="film-strip.svg" alt="film-strip" class="film-strip-bg">

  <div class="film-track">
    <?php foreach ($homeSliderMovies as $movie): ?>
      <?php render_home_slider_card($movie); ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="film-mini">
  <h1>Рекомендуем</h1>

  <div class="mini-track">
    <?php foreach ($recommendedMovies as $movie): ?>
      <?php render_mini_card($movie); ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="film-mini">
  <h1>Новое</h1>

  <div class="mini-track">
    <?php foreach ($newMovies as $movie): ?>
      <?php render_mini_card($movie); ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="film-select">
  <h1>Выбор редакции</h1>
  <div class="film-poster">
    <?php foreach ($editorsChoiceMovies as $movie): ?>
      <?php render_poster_card($movie); ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="film-mini">
  <h1>Фильмы для вас</h1>

  <div class="mini-track">
    <?php foreach ($forYouMovies as $movie): ?>
      <?php render_mini_card($movie); ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="genars">
  <h1>Жанры</h1>
  <div class="container">
    <a class="tile" href="Catalog.php?q=Фантастика">
      <div class="tile-text">Фантастика</div>
    </a>
    <a class="tile" href="Catalog.php?q=Боевик">
      <div class="tile-text">Боевик</div>
    </a>
    <a class="tile" href="Catalog.php?q=Комедия">
      <div class="tile-text">Комедия</div>
    </a>
    <a class="tile" href="Catalog.php?q=Драма">
      <div class="tile-text">Драма</div>
    </a>
    <a class="tile" href="Catalog.php?q=Триллер">
      <div class="tile-text">Триллер</div>
    </a>
    <a class="tile" href="Catalog.php?q=Романтика">
      <div class="tile-text">Романтика</div>
    </a>
    <a class="tile" href="Catalog.php?q=Детектив">
      <div class="tile-text">Детектив</div>
    </a>
    <a class="tile" href="Catalog.php?q=Анимация">
      <div class="tile-text">Анимация</div>
    </a>
  </div>
</section>

<?php require __DIR__ . '/includes/auth-modal.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="js/home.js?v=20260420-1"></script>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
