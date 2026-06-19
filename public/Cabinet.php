<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/includes/movie-cards.php';

if (current_user_id() === null) {
    header('Location: Home.php?auth=required');
    exit;
}

$recommendedMovies = fetch_home_section_movies('recommended', 6);
$newMovies = fetch_home_section_movies('new', 6);
$editorsChoiceMovies = fetch_home_section_movies('editors_choice', 2);

$pageTitle = 'Личный кабинет - AKINO';
$bodyClass = '';
$activeNav = '';

require __DIR__ . '/includes/page-top.php';
?>
<section class="personal-account">
  <nav class="sidebar">
    <a href="Cabinet.php?tab=profile" class="nav-item active" data-target="tab-profile">Профиль</a>
    <a href="Cabinet.php?tab=subscription" class="nav-item" data-target="tab-subscription">Моя подписка</a>
    <a href="Cabinet.php?tab=history" class="nav-item" data-target="tab-history">История просмотров</a>
    <a href="Cabinet.php?tab=favorites" class="nav-item" data-target="tab-favorites">Избранное</a>
    <a href="Cabinet.php?tab=notifications" class="nav-item" data-target="tab-notifications">Уведомления</a>
    <a href="logout.php?csrf=<?= htmlspecialchars(akino_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="nav-item logout">Выйти</a>
  </nav>

  <div class="content-area">
    <div class="account-notice" id="accountNotice" hidden></div>

    <div id="tab-profile" class="tab-content active">
      <h1 class="page-title">Личный кабинет</h1>
      <div class="profile-card">
        <div class="avatar-panel">
          <div class="avatar-wrapper">
            <img src="<?= akino_escape(akino_default_avatar_path()) ?>" alt="Avatar" class="avatar-img">
          </div>
          <input id="profileAvatar" type="file" class="avatar-upload-input" accept="image/jpeg,image/png,image/webp">
          <label for="profileAvatar" class="avatar-upload-btn">Загрузить аватарку</label>
          <p class="avatar-upload-hint" id="avatarUploadHint">JPG, PNG или WEBP до 2 МБ</p>
        </div>

        <div class="user-details" id="userDetails">
          <div class="user-header">
            <h2 class="user-name">Имя пользователя</h2>
            <span class="subscription-tag">
              <span class="sub-name">AKINO</span>
              <span class="sub-date">(Не активна)</span>
            </span>
          </div>

          <div class="info-flex">
            <div class="info-group">
              <div class="info-item view-mode" data-view="email">E-mail не указан</div>
              <label for="profileEmail" class="edit-label edit-mode">E-mail</label>
              <input id="profileEmail" type="email" class="edit-input edit-mode" data-field="email" value="" placeholder="name@example.com" autocomplete="email">
            </div>

            <div class="info-group">
              <div class="info-item view-mode">Пол не указан</div>
              <label for="profileGender" class="edit-label edit-mode">Пол</label>
              <input id="profileGender" type="text" class="edit-input edit-mode" data-field="gender" value="" placeholder="Например: мужской / женский">
            </div>

            <div class="info-group">
              <div class="info-item view-mode" data-view="phone">+7 (999) 999-99-99</div>
              <label for="profilePhone" class="edit-label edit-mode">Телефон</label>
              <input id="profilePhone" type="tel" class="edit-input edit-mode" data-field="phone" value="" placeholder="+7 (999) 999-99-99" autocomplete="tel">
            </div>

            <div class="info-group">
              <div class="info-item view-mode" data-view="birthDate">Дата рождения не указана</div>
              <label for="profileBirthDate" class="edit-label edit-mode">Дата рождения</label>
              <input id="profileBirthDate" type="text" class="edit-input edit-mode" data-field="birthDate" value="" placeholder="дд.мм.гггг" autocomplete="bday">
            </div>

            <div class="info-group payment-link-group">
              <div class="info-item payment-link view-mode">AKINO / 499 ₽ / 30 дней</div>
            </div>
          </div>

          <div class="btn-group">
            <button class="edit-profile-btn" id="editBtn">Редактировать профиль</button>
            <button class="cancel-btn edit-mode" id="cancelBtn">Отменить</button>
          </div>
        </div>
      </div>
    </div>

    <div id="tab-subscription" class="tab-content">
      <h1 class="page-title">Моя подписка</h1>
      <div class="placeholder-content" id="subscriptionContent">
        <p>Подписка AKINO пока не активна.</p>
      </div>
    </div>

    <div id="tab-history" class="tab-content">
      <h1 class="page-title">История просмотров</h1>
      <div class="placeholder-content" id="historyContent">
        <p>Здесь будут отображаться фильмы и сериалы, которые вы недавно открывали.</p>
      </div>
    </div>

    <div id="tab-favorites" class="tab-content">
      <h1 class="page-title">Избранное</h1>
      <div class="placeholder-content" id="favoritesContent">
        <p>Избранное пока пусто.</p>
      </div>
    </div>

    <div id="tab-notifications" class="tab-content">
      <h1 class="page-title">Уведомления</h1>
      <div class="placeholder-content">
        <p>У вас пока нет новых уведомлений.</p>
      </div>
    </div>
  </div>
</section>

<div class="bottom-line"></div>

<section class="film-mini">
  <h1>Рекомендуем</h1>

  <?php render_mini_track($recommendedMovies); ?>
</section>

<section class="film-mini">
  <h1>Новое</h1>

  <?php render_mini_track($newMovies); ?>
</section>

<section class="film-select">
  <h1>Выбор редакции</h1>
  <div class="film-poster">
    <?php foreach ($editorsChoiceMovies as $movie): ?>
      <?php render_poster_card($movie); ?>
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

<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="js/cabinet.js?v=20260619-2"></script>
<?php require __DIR__ . '/includes/page-bottom.php'; ?>
