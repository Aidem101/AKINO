<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/includes/movie-cards.php';

$currentUserId = current_user_id();

if ($currentUserId === null) {
    header('Location: Home.php?auth=required');
    exit;
}

$user = find_user_by_id($currentUserId);

if (!$user) {
    unset($_SESSION['user_id']);
    header('Location: Home.php?auth=required');
    exit;
}

$userId = (int) $user['id'];
$movieId = (int) ($_GET['id'] ?? 0);
$episodeId = (int) ($_GET['episode'] ?? 0);
$context = $movieId > 0 ? resolve_playback_context($movieId, $episodeId > 0 ? $episodeId : null) : null;

if ($context === null) {
    http_response_code(404);
}

$movie = $context['movie'] ?? null;
$subscription = get_user_subscription_payload($userId);
$hasAccess = !empty($subscription['active']);
$selectedEpisode = $context['selectedEpisode'] ?? null;
$progress = ($context !== null && $hasAccess)
    ? fetch_watch_progress($userId, $movieId, $selectedEpisode['id'] ?? null)
    : watch_progress_default();
$hasStream = $context !== null && $hasAccess && !empty($context['streamUrl']);
$streamMimeType = $context !== null ? playback_stream_mime_type((string) ($context['streamUrl'] ?? '')) : 'video/mp4';

if ($context !== null && $hasAccess) {
    try {
        record_watch_history($userId, $movieId);
    } catch (Throwable) {
        // Keep the watch page available even if history update fails.
    }
}

$relatedMovies = $movie ? fetch_related_movies((int) $movie['id'], (string) $movie['content_type'], 6) : [];
$pageTitle = $movie
    ? (($selectedEpisode ? $selectedEpisode['title'] . ' - ' : '') . $movie['title'] . ' - AKINO')
    : 'Просмотр недоступен - AKINO';
$bodyClass = '';
$activeNav = $movie && ($movie['content_type'] ?? 'movie') === 'series' ? 'series' : 'films';

require __DIR__ . '/includes/page-top.php';
?>
<?php if ($context === null || $movie === null): ?>
  <main class="catalog-page">
    <div class="catalog-header-section">
      <h1 class="page-title">Контент не найден</h1>
      <div class="placeholder-content">
        <p>Мы не нашли фильм или сериал для просмотра.</p>
        <p><a href="Films_Catalog.php" class="subscribe-btn">Вернуться в каталог</a></p>
      </div>
    </div>
  </main>
<?php else: ?>
  <?php
    $watchState = [
        'movieId' => (int) $movie['id'],
        'episodeId' => $selectedEpisode['id'] ?? 0,
        'hasAccess' => $hasAccess,
        'progress' => $progress,
        'nextEpisodeUrl' => $context['nextEpisode']['url'] ?? null,
    ];
  ?>

  <section class="watch-page">
    <div class="watch-main">
      <div class="watch-headline">
        <div>
          <a href="Film_Page.php?id=<?= (int) $movie['id'] ?>" class="watch-back-link">
            <i class="fa-solid fa-arrow-left"></i> Назад к карточке
          </a>
          <h1><?= akino_escape((string) $movie['title']) ?></h1>
          <?php if ($selectedEpisode): ?>
            <p class="watch-subtitle">
              <?= akino_escape($selectedEpisode['seasonTitle']) ?> · Серия <?= (int) $selectedEpisode['episodeNumber'] ?> · <?= akino_escape((string) $selectedEpisode['title']) ?>
            </p>
          <?php else: ?>
            <p class="watch-subtitle">
              <?= akino_escape(movie_type_label($movie)) ?> · <?= (int) $movie['release_year'] ?> · <?= akino_escape((string) $movie['genre']) ?>
            </p>
          <?php endif; ?>
        </div>

        <?php if (!empty($context['demoMode'])): ?>
          <span class="watch-demo-badge">Демо-поток</span>
        <?php endif; ?>
      </div>

      <div class="watch-player-card">
        <div class="watch-player-frame<?= $hasStream ? '' : ' has-lock' ?>">
          <?php if ($hasStream): ?>
            <video
              id="akinoPlayer"
              class="watch-video"
              controls
              preload="metadata"
              poster="<?= akino_escape((string) ($context['posterPath'] ?? '')) ?>"
              controlsList="nodownload"
            >
              <source src="<?= akino_escape((string) $context['streamUrl']) ?>" type="<?= akino_escape($streamMimeType) ?>">
            </video>
            <div class="watch-end-overlay" id="watchEndOverlay" hidden>
              <div class="watch-end-card">
                <h2>Просмотр завершен</h2>
                <?php if (!empty($context['nextEpisode'])): ?>
                  <p>Следующая серия будет открыта автоматически.</p>
                  <a href="<?= akino_escape((string) $context['nextEpisode']['url']) ?>" class="subscribe-btn" id="watchEndNextLink">Открыть сейчас</a>
                  <button type="button" class="watch-secondary-action" id="watchCancelAutoNext">Остаться здесь</button>
                <?php else: ?>
                  <p>Можно вернуться к карточке или выбрать похожий тайтл ниже.</p>
                  <a href="Film_Page.php?id=<?= (int) $movie['id'] ?>" class="subscribe-btn">К карточке</a>
                <?php endif; ?>
              </div>
            </div>
          <?php elseif ($hasAccess): ?>
            <div class="watch-player-lock">
              <div class="watch-lock-copy">
                <h2>Медиапоток ещё не подключён</h2>
                <p>Экран просмотра уже готов, осталось подставить реальный файл или HLS-поток.</p>
              </div>
            </div>
          <?php else: ?>
            <div class="watch-player-lock">
              <img
                src="<?= akino_escape((string) ($context['posterPath'] ?? '')) ?>"
                alt=""
                class="watch-player-lock-backdrop"
              >
              <div class="watch-lock-copy">
                <h2>Нужна активная подписка</h2>
                <p>Подключите AKINO, чтобы открыть просмотр и сохранять прогресс между фильмами и сериями.</p>
                <div class="watch-lock-actions">
                  <a href="Cabinet.php?tab=subscription" class="subscribe-btn">Оформить</a>
                  <span class="watch-lock-note"><?= akino_escape((string) $subscription['priceDisplay']) ?> / <?= (int) $subscription['durationDays'] ?> дней</span>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <?php if (false): ?>
        <div class="watch-progress-panel" hidden>
          <div class="watch-progress-header">
            <div>
              <strong>Прогресс просмотра</strong>
              <span id="watchProgressValue"><?= akino_escape((string) $progress['positionDisplay']) ?> / <?= akino_escape((string) $progress['durationDisplay']) ?></span>
            </div>
            <span class="watch-save-state" id="watchSaveState">
              <?= !empty($progress['updatedAtDisplay']) ? 'Сохранено ' . akino_escape((string) $progress['updatedAtDisplay']) : 'Прогресс ещё не сохранён' ?>
            </span>
          </div>
          <div class="watch-progress-bar">
            <progress
              class="watch-progress-fill"
              id="watchProgressFill"
              max="100"
              value="<?= number_format((float) ($progress['completedPercent'] ?? 0), 2, '.', '') ?>"
            ></progress>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="watch-summary-card">
        <div class="watch-summary-meta">
          <span><?= akino_escape(movie_type_label($movie)) ?></span>
          <span><?= (int) $movie['release_year'] ?></span>
          <span><?= akino_escape((string) $movie['genre']) ?></span>
          <?php if (!empty($movie['age_rating'])): ?>
            <span><?= akino_escape((string) $movie['age_rating']) ?></span>
          <?php endif; ?>
        </div>

        <p class="watch-summary-text">
          <?= akino_escape((string) ($selectedEpisode['description'] ?? $movie['description'])) ?>
        </p>

        <?php if (!empty($context['nextEpisode'])): ?>
          <a href="<?= akino_escape((string) $context['nextEpisode']['url']) ?>" class="watch-next-link" id="watchNextEpisodeLink">
            Следующая серия: <?= akino_escape((string) $context['nextEpisode']['title']) ?>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <details class="watch-episode-picker">
      <summary class="watch-episode-picker-summary">
        <span><?= (!empty($context['episodes']) || !empty($context['seasons'])) ? 'Сезоны и серии' : 'О просмотре' ?></span>
        <strong>
          <?php if ($selectedEpisode): ?>
            <?= akino_escape($selectedEpisode['seasonTitle']) ?> · Серия <?= (int) $selectedEpisode['episodeNumber'] ?> · <?= akino_escape((string) $selectedEpisode['title']) ?>
          <?php else: ?>
            <?= akino_escape(movie_type_label($movie)) ?> · <?= (int) $movie['release_year'] ?>
          <?php endif; ?>
        </strong>
      </summary>

      <div class="watch-episode-picker-body">
      <?php if (!empty($context['seasons'])): ?>
        <div class="watch-sidebar-block">
          <h2>Сезоны</h2>
          <div class="watch-season-tabs">
            <?php foreach ($context['seasons'] as $season): ?>
              <?php if (!empty($season['firstEpisodeUrl'])): ?>
                <a href="<?= akino_escape((string) $season['firstEpisodeUrl']) ?>" class="watch-season-chip<?= !empty($season['selected']) ? ' is-active' : '' ?>">
                  <?= akino_escape((string) $season['title']) ?>
                </a>
              <?php else: ?>
                <span class="watch-season-chip<?= !empty($season['selected']) ? ' is-active' : '' ?> is-disabled">
                  <?= akino_escape((string) $season['title']) ?>
                </span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($context['episodes'])): ?>
        <div class="watch-sidebar-block">
          <h2>Серии</h2>
          <div class="watch-episode-list">
            <?php foreach ($context['episodes'] as $episode): ?>
              <a href="<?= akino_escape((string) $episode['url']) ?>" class="watch-episode-card<?= !empty($episode['selected']) ? ' is-active' : '' ?>">
                <img src="<?= akino_escape((string) $episode['previewPath']) ?>" alt="<?= akino_escape((string) $episode['title']) ?>" class="watch-episode-thumb">
                <div class="watch-episode-copy">
                  <span>Серия <?= (int) $episode['episodeNumber'] ?></span>
                  <h3><?= akino_escape((string) $episode['title']) ?></h3>
                  <p><?= akino_escape((string) $episode['durationDisplay']) ?></p>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="watch-sidebar-block">
          <h2>О просмотре</h2>
          <p class="watch-sidebar-text">
            Для фильма доступен единый экран просмотра, автосохранение прогресса и возврат к последней позиции.
          </p>
        </div>
      <?php endif; ?>
      </div>
    </details>
  </section>

  <?php if ($relatedMovies): ?>
    <section class="film-mini">
      <h1>Смотрите также</h1>
      <?php render_mini_track($relatedMovies); ?>
    </section>
  <?php endif; ?>

  <script nonce="<?= akino_escape(akino_csp_nonce()) ?>">
    window.AKINO_WATCH_STATE = <?= json_encode(
        $watchState,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ) ?>;
  </script>
  <script src="js/watch.js?v=20260614-1"></script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
