<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/includes/movie-cards.php';

$filters = [
    'sort' => 'year_desc',
    'genre' => '',
    'year' => '',
    'country' => '',
];

$items = array_merge(
    fetch_filtered_catalog_movies('movie', $filters),
    fetch_filtered_catalog_movies('series', $filters)
);

usort($items, static function (array $left, array $right): int {
    return [
        -((int) ($left['release_year'] ?? 0)),
        -((float) ($left['rating'] ?? 0) * 10),
        (int) ($left['catalog_order'] ?? PHP_INT_MAX),
    ] <=> [
        -((int) ($right['release_year'] ?? 0)),
        -((float) ($right['rating'] ?? 0) * 10),
        (int) ($right['catalog_order'] ?? PHP_INT_MAX),
    ];
});

$page = normalize_page_number($_GET['page'] ?? 1);
$pagination = paginate_items($items, $page, 20);
$pagedItems = $pagination['items'];

$pageTitle = 'Новинки - AKINO';
$bodyClass = '';
$activeNav = 'new';

$buildUrl = static function (int $targetPage): string {
    return 'New.php' . ($targetPage > 1 ? '?page=' . $targetPage : '');
};

require __DIR__ . '/includes/page-top.php';
?>
<main class="catalog-page">
  <div class="catalog-header-section">
    <h1 class="page-title">Новинки</h1>
    <p class="catalog-results-summary">
      Свежие фильмы и сериалы AKINO, отсортированные по году выпуска и рейтингу.
      <?php if ((int) $pagination['totalItems'] > 0): ?>
        Сейчас показано <?= (int) $pagination['from'] ?>-<?= (int) $pagination['to'] ?>.
      <?php endif; ?>
    </p>
  </div>

  <section class="catalog-grid">
    <?php foreach ($pagedItems as $movie): ?>
      <?php render_catalog_card($movie); ?>
    <?php endforeach; ?>
  </section>

  <?php render_catalog_pagination($pagination, $buildUrl); ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
