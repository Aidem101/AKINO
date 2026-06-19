<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = 'О сервисе - AKINO';
$bodyClass = 'info-page-body';
$activeNav = '';
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$sections = [
    [
        'title' => 'О AKINO',
        'paragraphs' => [
            'AKINO — онлайн-кинотеатр с библиотекой фильмов и сериалов, созданный для удобного поиска, сохранения избранного и продолжения просмотра с того места, где вы остановились.',
            'Сервис объединяет каталог, персональный кабинет и инструменты управления доступом в едином, понятном интерфейсе.',
        ],
    ],
    [
        'title' => 'Что доступно пользователю',
        'items' => [
            'каталог фильмов и сериалов с поиском, сортировкой и фильтрами;',
            'детальные карточки тайтлов с описанием, жанром, годом выпуска и возрастным рейтингом;',
            'личный кабинет, избранное, история и сохранение прогресса просмотра;',
            'единая подписка для доступа к функциям, доступным в текущей версии сервиса.',
        ],
    ],
    [
        'title' => 'Принципы сервиса',
        'paragraphs' => [
            'Мы стремимся к прозрачным правилам, уважительному отношению к данным пользователя и спокойному просмотру без перегруженного интерфейса.',
            'Состав каталога, доступность отдельных функций и условия использования могут обновляться по мере развития AKINO. Актуальные правила публикуются в справочных разделах сайта.',
        ],
    ],
];

require __DIR__ . '/includes/page-top.php';
?>
<main class="info-page about-page">
  <article class="info-page-card">
    <header class="info-page-header">
      <p class="info-page-document">О сервисе</p>
      <h1>AKINO — кино, к которому легко вернуться</h1>
      <p class="info-page-lead">Собираем библиотеку фильмов и сериалов в одном месте и делаем путь от выбора до просмотра понятным и спокойным.</p>
    </header>

    <div class="info-page-sections">
      <?php foreach ($sections as $section): ?>
        <section class="info-page-section">
          <h2><?= $escape($section['title']) ?></h2>
          <?php foreach ($section['paragraphs'] ?? [] as $paragraph): ?>
            <p><?= $escape($paragraph) ?></p>
          <?php endforeach; ?>
          <?php if (!empty($section['items'])): ?>
            <ul>
              <?php foreach ($section['items'] as $item): ?>
                <li><?= $escape($item) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>

    <aside class="info-page-note" aria-label="Возрастное ограничение">
      <h2>Возрастная маркировка</h2>
      <p>Возрастной рейтинг указан в карточке каждого тайтла. Пожалуйста, учитывайте его при выборе контента.</p>
    </aside>

    <div class="info-page-actions">
      <a href="Films_Catalog.php" class="subscribe-btn">Открыть каталог</a>
      <a href="Info.php?page=contacts" class="info-page-secondary">Связаться с поддержкой</a>
    </div>
  </article>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
