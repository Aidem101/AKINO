<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/includes/movie-cards.php';

$query = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($query, 'UTF-8') > 80) {
    $query = mb_substr($query, 0, 80, 'UTF-8');
}

$type = trim((string) ($_GET['type'] ?? ''));
$page = normalize_page_number($_GET['page'] ?? 1);
$selectedType = in_array($type, ['movie', 'series'], true) ? $type : '';
$results = $query !== '' ? search_catalog_movies($query, $selectedType !== '' ? $selectedType : null, 48) : [];
$pagination = paginate_items($results, $page, 20);
$resultsCount = (int) $pagination['totalItems'];
$pagedResults = $pagination['items'];

$pageTitle = $query !== ''
    ? 'Поиск: ' . $query . ' - AKINO'
    : 'Поиск - AKINO';
$bodyClass = '';
$activeNav = '';

$buildSearchUrl = static function (string $searchQuery, string $searchType = '', int $searchPage = 1): string {
    $params = ['q' => $searchQuery];

    if ($searchType !== '') {
        $params['type'] = $searchType;
    }

    if ($searchPage > 1) {
        $params['page'] = $searchPage;
    }

    return 'Catalog.php?' . http_build_query($params);
};

$resultsWord = static function (int $count): string {
    $mod10 = $count % 10;
    $mod100 = $count % 100;

    if ($mod10 === 1 && $mod100 !== 11) {
        return 'результат';
    }

    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
        return 'результата';
    }

    return 'результатов';
};

require __DIR__ . '/includes/page-top.php';
?>
<main class="catalog-page search-catalog-page">
  <div class="catalog-header-section">
    <h1 class="page-title"><?= $query !== '' ? 'Результаты поиска' : 'Поиск по AKINO' ?></h1>

    <?php if ($query !== ''): ?>
      <p class="search-results-summary">
        По запросу «<?= htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>» найдено
        <strong><?= $resultsCount ?></strong> <?= $resultsWord($resultsCount) ?>.
        <?php if ($resultsCount > 0): ?>
          Сейчас показано <?= (int) $pagination['from'] ?>-<?= (int) $pagination['to'] ?>.
        <?php endif; ?>
      </p>

      <div class="search-filter-chips">
        <a href="<?= htmlspecialchars($buildSearchUrl($query), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="search-filter-chip<?= $selectedType === '' ? ' is-active' : '' ?>">Все</a>
        <a href="<?= htmlspecialchars($buildSearchUrl($query, 'movie'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="search-filter-chip<?= $selectedType === 'movie' ? ' is-active' : '' ?>">Фильмы</a>
        <a href="<?= htmlspecialchars($buildSearchUrl($query, 'series'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="search-filter-chip<?= $selectedType === 'series' ? ' is-active' : '' ?>">Сериалы</a>
      </div>
    <?php else: ?>
      <p class="search-results-summary">
        Введите название фильма или сериала в строке поиска выше, и AKINO покажет подходящие тайтлы.
      </p>
    <?php endif; ?>
  </div>

  <?php if ($query === ''): ?>
    <section class="placeholder-content search-empty-state">
      <p>Попробуйте найти конкретное название или перейдите в готовые разделы каталога.</p>
      <div class="search-empty-links">
        <a href="Films_Catalog.php" class="search-filter-chip">Каталог фильмов</a>
        <a href="Series_Page.php" class="search-filter-chip">Каталог сериалов</a>
      </div>
    </section>
  <?php elseif (!$results): ?>
    <section class="placeholder-content search-empty-state">
      <p>По вашему запросу пока ничего не найдено. Попробуйте другое название или откройте каталоги ниже.</p>
      <div class="search-empty-links">
        <a href="Films_Catalog.php" class="search-filter-chip">Фильмы</a>
        <a href="Series_Page.php" class="search-filter-chip">Сериалы</a>
      </div>
    </section>
  <?php else: ?>
    <section class="catalog-grid">
      <?php foreach ($pagedResults as $movie): ?>
        <?php render_catalog_card($movie); ?>
      <?php endforeach; ?>
    </section>
    <?php render_catalog_pagination($pagination, static fn (int $targetPage): string => $buildSearchUrl($query, $selectedType, $targetPage)); ?>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
