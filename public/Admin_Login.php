<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/html; charset=UTF-8');

if (admin_current_account()) {
    header('Location: Admin.php');
    exit;
}

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$assetVersion = '20260614-1';
$pageTitle = 'Вход в админку AKINO';
$loginValue = '';
$errorMessage = null;
$flash = admin_pull_flash();

if (!$flash && isset($_GET['logout'])) {
    $flash = [
        'type' => 'success',
        'message' => 'Вы вышли из админки.',
    ];
}

try {
    ensure_admin_support();

    if (!admin_support_available()) {
        $errorMessage = 'Админ-панель недоступна без подключения к базе данных.';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $loginValue = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $csrfToken = $_POST['csrf_token'] ?? null;

        if (!admin_verify_csrf_token(is_string($csrfToken) ? $csrfToken : null)) {
            security_event_log('csrf_rejected', 'warning', 'admin', null, admin_normalize_login($loginValue));
            $errorMessage = 'Сессия входа устарела. Обновите страницу и попробуйте снова.';
        } elseif (admin_login_rate_limited()) {
            security_event_log('admin_login_blocked', 'warning', 'admin', null, admin_normalize_login($loginValue));
            $errorMessage = 'Слишком много попыток входа. Попробуйте снова через некоторое время.';
        } elseif (!admin_login_attempt($loginValue, $password)) {
            $errorMessage = 'Неверный логин или пароль.';
        } else {
            admin_flash('success', 'Вход выполнен.');
            header('Location: Admin.php');
            exit;
        }
    }
} catch (Throwable $exception) {
    akino_log_exception($exception);
    $errorMessage = 'Не удалось выполнить вход. Попробуйте позже.';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $escape($pageTitle) ?></title>
  <link rel="stylesheet" href="admin.css?v=<?= $escape($assetVersion) ?>">
</head>
<body class="admin-shell admin-login-shell">
  <main class="admin-login-main">
    <section class="admin-login-card">
      <a href="Home.php" class="admin-logo admin-login-logo" aria-label="На главную AKINO">
        <img src="logo.svg" alt="AKINO">
      </a>

      <div class="admin-login-copy">
        <p class="admin-eyebrow">AKINO ADMIN</p>
        <h1>Вход в админку</h1>
        <p>Панель управления доступна только после отдельной авторизации по логину и паролю.</p>
      </div>

      <?php if ($flash): ?>
        <div class="admin-alert admin-alert-<?= $escape((string) ($flash['type'] ?? 'info')) ?>">
          <?= $escape((string) ($flash['message'] ?? '')) ?>
        </div>
      <?php endif; ?>

      <?php if ($errorMessage !== null): ?>
        <div class="admin-alert admin-alert-error">
          <?= $escape($errorMessage) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="admin-login-form">
        <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">

        <label class="admin-field admin-field-full">
          <span>Логин</span>
          <input type="text" name="login" value="<?= $escape($loginValue) ?>" autocomplete="username" maxlength="60" required>
        </label>

        <label class="admin-field admin-field-full">
          <span>Пароль</span>
          <input type="password" name="password" autocomplete="current-password" maxlength="128" required>
        </label>

        <div class="admin-login-actions">
          <button type="submit" class="admin-btn admin-btn-primary admin-login-submit">Войти</button>
          <a href="Home.php" class="admin-btn admin-btn-secondary">Вернуться на сайт</a>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
