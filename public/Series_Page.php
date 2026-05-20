<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/includes/movie-cards.php';

$filterOptions = fetch_catalog_filter_options('series');
$filters = normalize_catalog_filters($_GET, $filterOptions);
$series = fetch_filtered_catalog_movies('series', $filters);
$page = normalize_page_number($_GET['page'] ?? 1);
$pagination = paginate_items($series, $page, 20);
$pagedSeries = $pagination['items'];
$hasActiveFilters = catalog_filters_are_active($filters);
$sortLabel = $filterOptions['sorts'][$filters['sort']] ?? 'По рейтингу';
$genreLabel = $filters['genre'] !== '' ? $filters['genre'] : 'Жанры';
$yearLabel = $filters['year'] !== '' ? $filters['year'] : 'Года';
$countryLabel = $filters['country'] !== '' ? $filters['country'] : 'Страны';
$directorLabel = $filters['director'] !== '' ? $filters['director'] : 'Directors';
$seriesCount = (int) $pagination['totalItems'];

$pageTitle = 'Сериалы - AKINO';
$bodyClass = '';
$activeNav = 'series';

require __DIR__ . '/includes/page-top.php';

$buildCatalogUrl = static function (int $targetPage) use ($filters): string {
    $params = [
        'sort' => $filters['sort'],
        'genre' => $filters['genre'],
        'year' => $filters['year'],
        'country' => $filters['country'],
        'director' => $filters['director'],
    ];

    if ($targetPage > 1) {
        $params['page'] = $targetPage;
    }

    $params = array_filter(
        $params,
        static fn ($value): bool => $value !== ''
    );

    return 'Series_Page.php' . ($params ? '?' . http_build_query($params) : '');
};
?>
<main class="catalog-page catalog-listing-page">
  <div class="catalog-header-section">
    <h1 class="page-title">Сериалы</h1>
    <p class="catalog-results-summary">
      <?= $hasActiveFilters ? 'Найдено' : 'В каталоге' ?> <?= $seriesCount ?> <?= $seriesCount === 1 ? 'сериал' : (($seriesCount >= 2 && $seriesCount <= 4) ? 'сериала' : 'сериалов') ?>.
      <?php if ($seriesCount > 0): ?>
        Сейчас показано <?= (int) $pagination['from'] ?>-<?= (int) $pagination['to'] ?>.
      <?php endif; ?>
    </p>

    <form class="catalog-filter-form" method="get">
      <input type="hidden" name="sort" value="<?= htmlspecialchars($filters['sort'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-filter-input="sort">
      <input type="hidden" name="genre" value="<?= htmlspecialchars($filters['genre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-filter-input="genre">
      <input type="hidden" name="year" value="<?= htmlspecialchars($filters['year'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-filter-input="year">
      <input type="hidden" name="country" value="<?= htmlspecialchars($filters['country'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-filter-input="country">
      <input type="hidden" name="director" value="<?= htmlspecialchars($filters['director'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-filter-input="director">

      <div class="filters-container">
        <div class="sort-wrapper">
          <div class="custom-dropdown sort-dropdown" data-filter-key="sort">
            <div class="dropdown-trigger">
              <span class="dropdown-trigger-text"><?= htmlspecialchars($sortLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <span class="arrow">▼</span>
            </div>
            <ul class="dropdown-menu sort-menu">
              <?php foreach ($filterOptions['sorts'] as $value => $label): ?>
                <li class="<?= $filters['sort'] === $value ? 'is-selected' : '' ?>" data-value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-label="<?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

        <div class="filters-box">
          <div class="group-filters">
            <div class="filter-group">
              <div class="custom-dropdown filter-dropdown" data-filter-key="genre">
                <div class="dropdown-trigger">
                  <span class="dropdown-trigger-text"><?= htmlspecialchars($genreLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="arrow">▼</span>
                </div>
                <ul class="dropdown-menu">
                  <li class="<?= $filters['genre'] === '' ? 'is-selected' : '' ?>" data-value="" data-label="Жанры">Все жанры</li>
                  <?php foreach ($filterOptions['genres'] as $value => $label): ?>
                    <li class="<?= $filters['genre'] === $value ? 'is-selected' : '' ?>" data-value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-label="<?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>

            <div class="filter-group">
              <div class="custom-dropdown filter-dropdown" data-filter-key="year">
                <div class="dropdown-trigger">
                  <span class="dropdown-trigger-text"><?= htmlspecialchars($yearLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="arrow">▼</span>
                </div>
                <ul class="dropdown-menu">
                  <li class="<?= $filters['year'] === '' ? 'is-selected' : '' ?>" data-value="" data-label="Года">Все годы</li>
                  <?php foreach ($filterOptions['years'] as $value => $label): ?>
                    <li class="<?= $filters['year'] === $value ? 'is-selected' : '' ?>" data-value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-label="<?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>

            <div class="filter-group">
              <div class="custom-dropdown filter-dropdown" data-filter-key="country">
                <div class="dropdown-trigger">
                  <span class="dropdown-trigger-text"><?= htmlspecialchars($countryLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="arrow">▼</span>
                </div>
                <ul class="dropdown-menu">
                  <li class="<?= $filters['country'] === '' ? 'is-selected' : '' ?>" data-value="" data-label="Страны">Все страны</li>
                  <?php foreach ($filterOptions['countries'] as $value => $label): ?>
                    <li class="<?= $filters['country'] === $value ? 'is-selected' : '' ?>" data-value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-label="<?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>

            <div class="filter-group">
              <div class="custom-dropdown filter-dropdown" data-filter-key="director">
                <div class="dropdown-trigger">
                  <span class="dropdown-trigger-text"><?= htmlspecialchars($directorLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="arrow">в–ј</span>
                </div>
                <ul class="dropdown-menu">
                  <li class="<?= $filters['director'] === '' ? 'is-selected' : '' ?>" data-value="" data-label="Directors">All directors</li>
                  <?php foreach ($filterOptions['directors'] as $value => $label): ?>
                    <li class="<?= $filters['director'] === $value ? 'is-selected' : '' ?>" data-value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-label="<?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          </div>

          <div class="find-reset">
            <button class="find-btn" type="submit">Найти</button>
            <a href="Series_Page.php" class="reset-filter">Очистить фильтр ×</a>
          </div>
        </div>
      </div>
    </form>
  </div>

  <section class="catalog-grid">
    <?php if (!$series): ?>
      <div class="placeholder-content">По выбранным фильтрам сериалы не найдены.</div>
    <?php else: ?>
      <?php foreach ($pagedSeries as $movie): ?>
        <?php render_catalog_card($movie); ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
  <?php render_catalog_pagination($pagination, $buildCatalogUrl); ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="js/catalog.js?v=20260324-2"></script>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
