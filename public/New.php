<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/includes/movie-cards.php';

$sortOptions = catalog_sort_options();
$sort = (string) ($_GET['sort'] ?? 'year_desc');

if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'year_desc';
}

$filters = [
    'sort' => $sort,
    'genre' => '',
    'year' => '',
    'country' => '',
    'director' => '',
];

$items = apply_catalog_filters(
    array_merge(
        fetch_catalog_movies('movie'),
        fetch_catalog_movies('series')
    ),
    $filters
);

$page = normalize_page_number($_GET['page'] ?? 1);
$pagination = paginate_items($items, $page, 20);
$pagedItems = $pagination['items'];

$pageTitle = 'Новинки - AKINO';
$bodyClass = '';
$activeNav = 'new';
$sortLabel = $sortOptions[$sort] ?? 'Сначала новые';

$buildUrl = static function (int $targetPage) use ($sort): string {
    $params = [];

    if ($sort !== 'year_desc') {
        $params['sort'] = $sort;
    }

    if ($targetPage > 1) {
        $params['page'] = $targetPage;
    }

    return 'New.php' . ($params ? '?' . http_build_query($params) : '');
};

require __DIR__ . '/includes/page-top.php';
?>
<main class="catalog-page">
  <div class="catalog-header-section">
    <h1 class="page-title">Новинки</h1>
    <p class="catalog-results-summary">
      Свежие фильмы и сериалы AKINO. Выберите удобный порядок сортировки.
      <?php if ((int) $pagination['totalItems'] > 0): ?>
        Сейчас показано <?= (int) $pagination['from'] ?>-<?= (int) $pagination['to'] ?>.
      <?php endif; ?>
    </p>

    <form class="catalog-filter-form new-sort-form" method="get">
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-filter-input="sort">
      <div class="filters-container">
        <div class="sort-wrapper">
          <div class="custom-dropdown sort-dropdown" data-filter-key="sort">
            <div class="dropdown-trigger">
              <span class="dropdown-trigger-text"><?= htmlspecialchars($sortLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <span class="arrow">▼</span>
            </div>
            <ul class="dropdown-menu sort-menu">
              <?php foreach ($sortOptions as $value => $label): ?>
                <li class="<?= $sort === $value ? 'is-selected' : '' ?>" data-value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-label="<?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

        <div class="find-reset">
          <button class="find-btn" type="submit">Применить</button>
          <a href="New.php" class="reset-filter">Сбросить ×</a>
        </div>
      </div>
    </form>
  </div>

  <section class="catalog-grid">
    <?php foreach ($pagedItems as $movie): ?>
      <?php render_catalog_card($movie); ?>
    <?php endforeach; ?>
  </section>

  <?php render_catalog_pagination($pagination, $buildUrl); ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="js/catalog.js?v=20260324-2"></script>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
