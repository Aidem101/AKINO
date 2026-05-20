<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pageTitle = $pageTitle ?? 'AKINO';
$bodyClass = trim((string) ($bodyClass ?? ''));
$activeNav = (string) ($activeNav ?? '');
$currentUser = null;

try {
    $currentUser = current_user_payload();
} catch (Throwable) {
    $currentUser = null;
}

$defaultAvatar = 'img/people/image_2025-11-10_00-02-43.png';
$profileLabel = $currentUser['name'] ?? 'Логин';
$profileAvatar = $currentUser['avatar'] ?? $defaultAvatar;
$profileHref = $currentUser ? 'Cabinet.php' : 'Home.php?auth=required';
$subscriptionHref = $currentUser ? 'Cabinet.php?tab=subscription' : 'Home.php?auth=required';
$subscriptionLabel = !empty($currentUser['subscription']['active']) ? 'Продлить' : 'Подписка';
$headerSearchQuery = trim((string) ($_GET['q'] ?? ($headerSearchQuery ?? '')));
$assetVersion = '20260521-5';
$bodyClassAttribute = $bodyClass !== ''
    ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
    : '';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="style.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body<?= $bodyClassAttribute ?> data-authenticated="<?= $currentUser ? '1' : '0' ?>">
  <header class="main-header">
    <a href="Home.php" class="logo" aria-label="AKINO — на главную">
      <img src="logo.svg" alt="AKINO">
    </a>

    <nav class="nav desktop-only">
      <a href="Home.php"<?= $activeNav === 'home' ? ' class="active"' : '' ?>>Главная</a>
      <a href="Films_Catalog.php"<?= $activeNav === 'films' ? ' class="active"' : '' ?>>Фильмы</a>
      <a href="Series_Page.php"<?= $activeNav === 'series' ? ' class="active"' : '' ?>>Сериалы</a>
      <a href="New.php"<?= $activeNav === 'new' ? ' class="active"' : '' ?>>Новинки</a>
      <?php if (!empty($currentUser['isAdmin'])): ?>
        <a href="Admin.php">Админка</a>
      <?php endif; ?>
    </nav>

    <form class="search-box desktop-only" action="Catalog.php" method="get">
      <button type="submit" class="search-box-submit" aria-label="Искать">
        <img src="img/svg/search_icon.svg" alt="search-icon">
      </button>
      <input
        type="text"
        name="q"
        value="<?= htmlspecialchars($headerSearchQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
        placeholder="Фильмы и сериалы"
        autocomplete="off"
      >
    </form>

    <a href="<?= htmlspecialchars($subscriptionHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="subscribe-btn desktop-only">
      <?= htmlspecialchars($subscriptionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </a>

    <a href="<?= htmlspecialchars($profileHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="profile desktop-only">
      <span><?= htmlspecialchars($profileLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      <img src="<?= htmlspecialchars($profileAvatar, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="user">
    </a>

    <div class="mobile-controls">
      <button class="mobile-search-btn">
        <img src="img/svg/search_icon.svg" alt="search">
      </button>

      <a href="<?= htmlspecialchars($profileHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mobile-avatar">
        <img src="<?= htmlspecialchars($profileAvatar, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="user">
      </a>

      <button class="hamburger-btn" id="openMenu">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>

    <div class="top-slide-menu" id="mobileMenu">
      <div class="menu-header">
        <a href="Home.php" class="logo-in-menu" aria-label="AKINO — на главную">
          <img src="logo.svg" alt="AKINO">
        </a>
        <button class="close-menu-btn" id="closeMenu">&times;</button>
      </div>

      <nav class="menu-nav-list">
        <a href="<?= htmlspecialchars($profileHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="menu-link"><i class="fa-solid fa-user"></i> Личный кабинет</a>
        <a href="Home.php" class="menu-link"><i class="fa-solid fa-house"></i> Главная</a>
        <a href="Films_Catalog.php" class="menu-link"><i class="fa-solid fa-film"></i> Фильмы</a>
        <a href="Series_Page.php" class="menu-link"><i class="fa-solid fa-tv"></i> Сериалы</a>
        <a href="New.php" class="menu-link"><i class="fa-solid fa-tags"></i> Новинки</a>
        <?php if (!empty($currentUser['isAdmin'])): ?>
          <a href="Admin.php" class="menu-link"><i class="fa-solid fa-screwdriver-wrench"></i> Админка</a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($subscriptionHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="menu-link"><i class="fa-solid fa-bag-shopping"></i> Подписка</a>
      </nav>
    </div>
  </header>
