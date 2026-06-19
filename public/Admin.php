<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/html; charset=UTF-8');

$tabs = [
    'episodes' => 'Серии',
    'content' => 'Контент',
    'users' => 'Пользователи',
    'admins' => 'Администраторы',
    'subscriptions' => 'Подписки и платежи',
    'security' => 'Безопасность',
    'settings' => 'Настройки',
];

$activeTab = (string) ($_GET['tab'] ?? 'content');

if (!isset($tabs[$activeTab])) {
    $activeTab = 'content';
}

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$buildTabUrl = static function (string $tab, array $extra = []): string {
    $params = ['tab' => $tab] + $extra;

    return 'Admin.php?' . http_build_query($params);
};
$userFilters = admin_normalize_user_filters($_GET);
$userSubscriptionOptions = [
    'all' => 'Все пользователи',
    'active' => 'С активной подпиской',
    'inactive' => 'Без активной подписки',
];
$userSortOptions = [
    'newest' => 'Сначала новые',
    'last_login' => 'По последнему входу',
    'subscription_end' => 'По окончанию подписки',
    'name' => 'По имени',
];
$selectedUserId = max(0, (int) ($_GET['user'] ?? 0));
$buildUserTabUrl = static function (array $extra = []) use ($buildTabUrl, $userFilters): string {
    $params = [];

    if ($userFilters['q'] !== '') {
        $params['q'] = $userFilters['q'];
    }

    if ($userFilters['subscription'] !== 'all') {
        $params['subscription'] = $userFilters['subscription'];
    }

    if ($userFilters['sort'] !== 'newest') {
        $params['sort'] = $userFilters['sort'];
    }

    return $buildTabUrl('users', array_merge($params, $extra));
};

$sidebarAdmin = null;
$adminUser = null;
$adminError = null;

try {
    $sidebarAdmin = admin_current_account();
} catch (Throwable) {
    $sidebarAdmin = null;
}

try {
    $adminUser = require_admin_panel_user();
    $sidebarAdmin = $adminUser;
} catch (Throwable $exception) {
    $adminError = $exception->getMessage();
}

$accessWarning = null;

if ($adminUser !== null) {
    foreach (array_keys($tabs) as $tabKey) {
        if (!admin_can($adminUser, admin_tab_permission($tabKey))) {
            unset($tabs[$tabKey]);
        }
    }

    if (!isset($tabs[$activeTab])) {
        $accessWarning = 'Для запрошенного раздела недостаточно прав.';
        $activeTab = admin_first_allowed_tab($adminUser);
    }
}

$flash = $adminError === null ? admin_pull_flash() : null;

if ($flash === null && $accessWarning !== null) {
    $flash = ['type' => 'warning', 'message' => $accessWarning];
}
$stats = [
    'contentCount' => 0,
    'movieCount' => 0,
    'seriesCount' => 0,
    'userCount' => 0,
    'adminCount' => 0,
    'activeSubscriptionCount' => 0,
];
$genreOptions = [];
$homeSectionDefinitions = [];
$contentList = [];
$episodeList = [];
$seriesOptions = [];
$recentSubscriptions = [];
$adminAccounts = [];
$userOverview = [
    'totalUsers' => 0,
    'activeSubscriptionCount' => 0,
    'inactiveSubscriptionCount' => 0,
    'recentLoginCount' => 0,
    'emailCount' => 0,
];
$userDirectory = [
    'items' => [],
    'total' => 0,
    'limit' => 24,
];
$selectedUser = null;
$userProfileErrors = [];
$userProfileForm = null;
$formErrors = [];
$editingMovie = null;
$form = admin_movie_form_defaults();
$episodeErrors = [];
$editingEpisode = null;
$episodeForm = admin_episode_form_defaults();
$newAdminErrors = [];
$passwordErrors = [];
$roleErrors = [];
$roleDefinitions = admin_role_definitions();
$securityDashboard = [
    'events24h' => 0,
    'warnings7d' => 0,
    'blockedNow' => 0,
    'changedFiles' => 0,
    'trend' => [],
    'topSources' => [],
    'events' => [],
];
$securityBackups = [];
$integrityFiles = [];
$newAdminForm = [
    'login' => '',
    'display_name' => '',
    'avatar_path' => '',
    'role' => 'auditor',
];

if ($adminError === null) {
    $stats = fetch_admin_dashboard_stats();
    $genreOptions = admin_genre_options();
    $homeSectionDefinitions = admin_home_section_definitions();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($_POST['admin_action'] ?? '');
        $csrfToken = $_POST['csrf_token'] ?? null;

        if (!admin_verify_csrf_token(is_string($csrfToken) ? $csrfToken : null)) {
            security_event_log(
                'csrf_rejected',
                'warning',
                'admin',
                (int) ($adminUser['id'] ?? 0),
                (string) ($adminUser['login'] ?? '')
            );
            admin_flash('error', 'Сессия администратора устарела. Обновите страницу и повторите попытку.');
            header('Location: ' . $buildTabUrl($activeTab));
            exit;
        }

        $requiredPermission = admin_action_permission($action);

        if (!admin_can($adminUser, $requiredPermission)) {
            security_event_log(
                'permission_denied',
                'warning',
                'admin',
                (int) ($adminUser['id'] ?? 0),
                (string) ($adminUser['login'] ?? ''),
                ['action' => $action, 'required_permission' => $requiredPermission]
            );
            admin_flash('error', 'Недостаточно прав для выполнения этого действия.');
            header('Location: ' . $buildTabUrl(admin_first_allowed_tab($adminUser)));
            exit;
        }

        if ($action === 'delete_movie' || $action === 'save_movie') {
            $activeTab = 'content';
        } elseif ($action === 'delete_episode' || $action === 'save_episode') {
            $activeTab = 'episodes';
        } elseif (in_array($action, ['toggle_user_block', 'extend_user_subscription', 'delete_user', 'save_user_profile'], true)) {
            $activeTab = 'users';
        } elseif ($action === 'create_admin' || $action === 'change_password') {
            $activeTab = 'admins';
        } elseif ($action === 'change_admin_role') {
            $activeTab = 'admins';
        } elseif (in_array($action, ['create_backup', 'verify_backup', 'record_integrity_baseline', 'scan_integrity'], true)) {
            $activeTab = 'security';
        }

        if ($action === 'save_user_profile') {
            $userProfileForm = admin_user_profile_form_from_input($_POST);
            $selectedUserId = max(0, (int) ($userProfileForm['id'] ?? 0));
            $userProfileErrors = admin_validate_user_profile_form($userProfileForm);

            if (!$userProfileErrors) {
                $updatedUser = admin_update_user_profile($userProfileForm);

                if ($updatedUser === null) {
                    $userProfileErrors[] = 'Пользователь для сохранения не найден.';
                } else {
                    admin_flash('success', 'Профиль пользователя обновлён.');
                    header('Location: ' . $buildUserTabUrl(['user' => (int) ($updatedUser['id'] ?? 0)]) . '#userDetailsPanel');
                    exit;
                }
            }
        } elseif ($action === 'toggle_user_block') {
            $userId = max(0, (int) ($_POST['user_id'] ?? 0));
            $nextStateBlocked = (int) ($_POST['block_state'] ?? 0) === 1;
            $updatedUser = admin_set_user_block_state($userId, $nextStateBlocked);

            if ($updatedUser === null) {
                admin_flash('error', 'Пользователь для изменения статуса не найден.');
                header('Location: ' . $buildUserTabUrl());
                exit;
            }

            admin_flash(
                'success',
                $nextStateBlocked
                    ? 'Пользователь ' . (string) ($updatedUser['name_display'] ?? 'AKINO') . ' заблокирован.'
                    : 'Пользователь ' . (string) ($updatedUser['name_display'] ?? 'AKINO') . ' разблокирован.'
            );
            header('Location: ' . $buildUserTabUrl(['user' => (int) ($updatedUser['id'] ?? 0)]) . '#userDetailsPanel');
            exit;
        } elseif ($action === 'extend_user_subscription') {
            $userId = max(0, (int) ($_POST['user_id'] ?? 0));
            $updatedUser = admin_extend_user_subscription($userId);

            if ($updatedUser === null) {
                admin_flash('error', 'Пользователь для продления подписки не найден.');
                header('Location: ' . $buildUserTabUrl());
                exit;
            }

            $subscriptionPayload = get_user_subscription_payload($userId);
            admin_flash(
                'success',
                'Подписка пользователя ' . (string) ($updatedUser['name_display'] ?? 'AKINO') . ' продлена до ' . (string) ($subscriptionPayload['endsAtDisplay'] ?? 'новой даты') . '.'
            );
            header('Location: ' . $buildUserTabUrl(['user' => (int) ($updatedUser['id'] ?? 0)]) . '#userDetailsPanel');
            exit;
        } elseif ($action === 'delete_user') {
            $userId = max(0, (int) ($_POST['user_id'] ?? 0));
            $deletedUser = admin_delete_user_account($userId);

            if ($deletedUser === null) {
                admin_flash('error', 'Пользователь для удаления не найден.');
            } else {
                admin_flash('success', 'Пользователь ' . (string) ($deletedUser['name_display'] ?? 'AKINO') . ' удалён вместе с его активностью.');
            }

            header('Location: ' . $buildUserTabUrl());
            exit;
        } elseif ($action === 'delete_movie') {
            $movieId = max(0, (int) ($_POST['movie_id'] ?? 0));
            $deletedMovie = $movieId > 0 ? fetch_admin_movie_by_id($movieId) : null;

            if ($movieId <= 0 || $deletedMovie === null) {
                $formErrors[] = 'Карточка для удаления не найдена.';
            } else {
                try {
                    $deleted = delete_admin_movie($movieId);
                } catch (Throwable) {
                    $deleted = false;
                }

                if (!$deleted) {
                    admin_flash('error', 'Карточку не удалось удалить. Обновите страницу и попробуйте ещё раз.');
                    header('Location: ' . $buildTabUrl('content'));
                    exit;
                }

                admin_flash('success', 'Контент удалён из каталога.');
                security_event_log(
                    'content_deleted',
                    'warning',
                    'admin',
                    (int) ($adminUser['id'] ?? 0),
                    (string) ($adminUser['login'] ?? ''),
                    ['movie_id' => $movieId, 'title' => (string) ($deletedMovie['title'] ?? '')]
                );
                header('Location: ' . $buildTabUrl('content'));
                exit;
            }
        } elseif ($action === 'save_movie') {
            $form = admin_movie_form_from_input($_POST);
            $movieId = $form['id'] > 0 ? (int) $form['id'] : null;
            $editingMovie = $movieId ? fetch_admin_movie_by_id($movieId) : null;
            $formErrors = admin_validate_movie_form($form, $genreOptions);

            if (!$formErrors) {
                try {
                    $savedMovie = save_admin_movie($form, $movieId);
                    admin_flash(
                        'success',
                        $movieId ? 'Контент обновлён.' : 'Новый контент добавлен в каталог.'
                    );
                    security_event_log(
                        $movieId ? 'content_updated' : 'content_created',
                        'info',
                        'admin',
                        (int) ($adminUser['id'] ?? 0),
                        (string) ($adminUser['login'] ?? ''),
                        [
                            'movie_id' => (int) ($savedMovie['id'] ?? 0),
                            'title' => (string) ($savedMovie['title'] ?? ''),
                        ]
                    );
                    header('Location: ' . $buildTabUrl('content', ['edit' => (int) ($savedMovie['id'] ?? 0)]) . '#adminContentForm');
                    exit;
                } catch (Throwable $exception) {
                    akino_log_exception($exception);
                    $formErrors[] = 'Не удалось сохранить карточку. Обновите страницу и повторите попытку.';
                }
            }
        } elseif ($action === 'delete_episode') {
            $episodeId = max(0, (int) ($_POST['episode_id'] ?? 0));
            $deletedEpisode = $episodeId > 0 ? admin_fetch_episode_by_id($episodeId) : null;

            if ($episodeId <= 0 || $deletedEpisode === null) {
                $episodeErrors[] = 'Серия для удаления не найдена.';
            } else {
                admin_delete_episode($episodeId);
                admin_flash('success', 'Серия удалена.');
                security_event_log(
                    'episode_deleted',
                    'warning',
                    'admin',
                    (int) ($adminUser['id'] ?? 0),
                    (string) ($adminUser['login'] ?? ''),
                    ['episode_id' => $episodeId, 'title' => (string) ($deletedEpisode['title'] ?? '')]
                );
                header('Location: ' . $buildTabUrl('episodes'));
                exit;
            }
        } elseif ($action === 'save_episode') {
            $episodeForm = admin_episode_form_from_input($_POST);
            $episodeId = $episodeForm['id'] > 0 ? (int) $episodeForm['id'] : null;
            $editingEpisode = $episodeId ? admin_fetch_episode_by_id($episodeId) : null;
            $episodeErrors = admin_validate_episode_form($episodeForm);

            if (!$episodeErrors) {
                try {
                    $savedEpisode = admin_save_episode($episodeForm, $episodeId);
                    admin_flash('success', $episodeId ? 'Серия обновлена.' : 'Серия добавлена.');
                    security_event_log(
                        $episodeId ? 'episode_updated' : 'episode_created',
                        'info',
                        'admin',
                        (int) ($adminUser['id'] ?? 0),
                        (string) ($adminUser['login'] ?? ''),
                        [
                            'episode_id' => (int) ($savedEpisode['id'] ?? 0),
                            'title' => (string) ($savedEpisode['title'] ?? ''),
                        ]
                    );
                    header('Location: ' . $buildTabUrl('episodes', ['edit_episode' => (int) ($savedEpisode['id'] ?? 0)]) . '#adminEpisodeForm');
                    exit;
                } catch (Throwable $exception) {
                    akino_log_exception($exception);
                    $episodeErrors[] = $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'Не удалось сохранить серию. Обновите страницу и повторите попытку.';
                }
            }
        } elseif ($action === 'create_admin') {
            $newAdminForm = [
                'login' => trim((string) ($_POST['login'] ?? '')),
                'display_name' => trim((string) ($_POST['display_name'] ?? '')),
                'avatar_path' => trim((string) ($_POST['avatar_path'] ?? '')),
                'role' => admin_normalize_role((string) ($_POST['role'] ?? 'auditor')),
            ];
            $newAdminErrors = admin_validate_new_account($_POST);

            if (!$newAdminErrors) {
                $createdAdmin = create_admin_account($_POST);
                admin_flash('success', 'Администратор @' . (string) ($createdAdmin['login'] ?? '') . ' создан.');
                header('Location: ' . $buildTabUrl('admins'));
                exit;
            }
        } elseif ($action === 'change_password') {
            $passwordErrors = admin_change_password(
                (int) ($adminUser['id'] ?? 0),
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_password_confirm'] ?? '')
            );

            if (!$passwordErrors) {
                admin_flash('success', 'Пароль администратора обновлён.');
                header('Location: ' . $buildTabUrl('admins') . '#adminPasswordForm');
                exit;
            }
        } elseif ($action === 'change_admin_role') {
            $roleErrors = admin_change_account_role(
                max(0, (int) ($_POST['account_id'] ?? 0)),
                (string) ($_POST['role'] ?? ''),
                (int) ($adminUser['id'] ?? 0)
            );

            if ($roleErrors) {
                admin_flash('error', implode(' ', $roleErrors));
            } else {
                admin_flash('success', 'Роль администратора обновлена.');
            }

            header('Location: ' . $buildTabUrl('admins'));
            exit;
        } elseif ($action === 'create_backup') {
            try {
                create_encrypted_database_backup((int) ($adminUser['id'] ?? 0));
                admin_flash('success', 'Зашифрованная резервная копия создана.');
            } catch (Throwable $exception) {
                akino_log_exception($exception);
                admin_flash('error', $exception->getMessage());
            }

            header('Location: ' . $buildTabUrl('security') . '#securityBackups');
            exit;
        } elseif ($action === 'verify_backup') {
            $backupId = max(0, (int) ($_POST['backup_id'] ?? 0));

            try {
                $verified = verify_encrypted_database_backup($backupId, (int) ($adminUser['id'] ?? 0));
                admin_flash(
                    $verified ? 'success' : 'error',
                    $verified
                        ? 'Контрольная сумма и расшифровка резервной копии проверены.'
                        : 'Резервная копия повреждена или не может быть расшифрована.'
                );
            } catch (Throwable $exception) {
                akino_log_exception($exception);
                admin_flash('error', $exception->getMessage());
            }

            header('Location: ' . $buildTabUrl('security') . '#securityBackups');
            exit;
        } elseif ($action === 'record_integrity_baseline') {
            try {
                $integrityResult = record_file_integrity_baseline((int) ($adminUser['id'] ?? 0));
                admin_flash('success', 'Эталон сохранён: ' . (int) $integrityResult['total'] . ' файлов.');
            } catch (Throwable $exception) {
                akino_log_exception($exception);
                admin_flash('error', $exception->getMessage());
            }

            header('Location: ' . $buildTabUrl('security') . '#fileIntegrity');
            exit;
        } elseif ($action === 'scan_integrity') {
            try {
                $integrityResult = scan_file_integrity((int) ($adminUser['id'] ?? 0));
                $changedCount = (int) $integrityResult['changed'] + (int) $integrityResult['missing'];
                admin_flash(
                    $changedCount === 0 ? 'success' : 'error',
                    $changedCount === 0
                        ? 'Все контролируемые файлы соответствуют эталону.'
                        : 'Обнаружено изменённых или отсутствующих файлов: ' . $changedCount . '.'
                );
            } catch (Throwable $exception) {
                akino_log_exception($exception);
                admin_flash('error', $exception->getMessage());
            }

            header('Location: ' . $buildTabUrl('security') . '#fileIntegrity');
            exit;
        }
    }

    if (!$formErrors && $activeTab === 'content') {
        $editMovieId = max(0, (int) ($_GET['edit'] ?? 0));

        if ($editMovieId > 0) {
            $editingMovie = fetch_admin_movie_by_id($editMovieId);

            if ($editingMovie !== null) {
                $form = admin_movie_form_from_movie($editingMovie);
            } else {
                $flash = [
                    'type' => 'warning',
                    'message' => 'Карточка для редактирования не найдена, открыта форма создания нового контента.',
                ];
            }
        }
    }

    if (!$episodeErrors && $activeTab === 'episodes') {
        $editEpisodeId = max(0, (int) ($_GET['edit_episode'] ?? 0));

        if ($editEpisodeId > 0) {
            $editingEpisode = admin_fetch_episode_by_id($editEpisodeId);

            if ($editingEpisode !== null) {
                $episodeForm = admin_episode_form_from_episode($editingEpisode);
            } else {
                $flash = [
                    'type' => 'warning',
                    'message' => 'Серия для редактирования не найдена. Открыта форма создания новой серии.',
                ];
            }
        }
    }

    if (admin_can($adminUser, 'content.manage')) {
        $contentList = fetch_admin_content_list(24);
    }

    if (admin_can($adminUser, 'episodes.manage')) {
        $seriesOptions = fetch_admin_series_options();
        $episodeList = fetch_admin_episode_list(60);
    }

    if (admin_can($adminUser, 'subscriptions.view')) {
        $recentSubscriptions = fetch_admin_recent_subscriptions(10);
    }

    if (admin_can($adminUser, 'admins.manage')) {
        $adminAccounts = fetch_admin_accounts(20);
    }

    if ($activeTab === 'users') {
        $userOverview = fetch_admin_user_overview();
        $userDirectory = fetch_admin_user_directory($userFilters, 24);

        if ($selectedUserId > 0) {
            $selectedUser = fetch_admin_user_detail($selectedUserId);

            if ($selectedUser === null) {
                $flash = [
                    'type' => 'warning',
                    'message' => 'Выбранный пользователь не найден или уже удалён.',
                ];
            } elseif ($userProfileForm === null) {
                $userProfileForm = admin_user_profile_form_from_user($selectedUser);
            }
        }
    }

    if ($activeTab === 'security') {
        $securityDashboard = fetch_security_dashboard();
        $securityBackups = fetch_security_backups(20);
        $integrityFiles = fetch_file_integrity_status(60);
    }
}

$pageTitle = 'Панель администратора AKINO';
$assetVersion = '20260614-2';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $escape($pageTitle) ?></title>
  <link rel="stylesheet" href="admin.css?v=<?= $escape($assetVersion) ?>">
</head>
<body class="admin-shell">
  <div class="admin-layout">
    <aside class="admin-sidebar">
      <a href="Home.php" class="admin-logo" aria-label="На главную AKINO">
        <img src="logo.svg" alt="AKINO">
      </a>

      <div class="admin-user-card">
        <img
          src="<?= $escape(akino_avatar_display_path($sidebarAdmin['avatar_path'] ?? null)) ?>"
          alt="<?= $escape((string) ($sidebarAdmin['display_name'] ?? 'Администратор')) ?>"
          class="admin-user-avatar"
        >
        <div>
          <span class="admin-user-role"><?= $escape($sidebarAdmin ? admin_role_label((string) ($sidebarAdmin['role'] ?? 'auditor')) : 'Админ-аккаунт') ?></span>
          <strong><?= $escape((string) ($sidebarAdmin['display_name'] ?? 'Вход требуется')) ?></strong>
          <small><?= $sidebarAdmin ? '@' . $escape((string) ($sidebarAdmin['login'] ?? 'admin')) : 'Защищённый вход' ?></small>
        </div>
      </div>

      <nav class="admin-nav">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
          <a href="<?= $escape($buildTabUrl($tabKey)) ?>" class="admin-nav-link<?= $activeTab === $tabKey ? ' is-active' : '' ?>">
            <?= $escape($tabLabel) ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="admin-sidebar-footer">
        <a href="Home.php">Открыть витрину</a>
        <a href="Admin_Login.php">Страница входа</a>
        <a href="admin_logout.php?csrf=<?= $escape(admin_csrf_token()) ?>">Выйти</a>
      </div>
    </aside>

    <main class="admin-main">
      <header class="admin-main-header">
        <div>
          <p class="admin-eyebrow">AKINO CMS</p>
          <h1>Панель администратора</h1>
        </div>

        <?php if ($adminError === null): ?>
          <div class="admin-header-summary">
            <div class="admin-summary-pill">
              <span>Контент</span>
              <strong><?= (int) $stats['contentCount'] ?></strong>
            </div>
            <div class="admin-summary-pill">
              <span>Админы</span>
              <strong><?= (int) $stats['adminCount'] ?></strong>
            </div>
            <div class="admin-summary-pill">
              <span>Подписки</span>
              <strong><?= (int) $stats['activeSubscriptionCount'] ?></strong>
            </div>
          </div>
        <?php endif; ?>
      </header>

      <?php if ($flash): ?>
        <div class="admin-alert admin-alert-<?= $escape((string) ($flash['type'] ?? 'info')) ?>">
          <?= $escape((string) ($flash['message'] ?? '')) ?>
        </div>
      <?php endif; ?>

      <?php if ($adminError !== null): ?>
        <section class="admin-empty-state">
          <h2>Панель сейчас недоступна</h2>
          <p><?= $escape($adminError) ?></p>
          <div class="admin-empty-actions">
            <a href="Admin_Login.php" class="admin-btn admin-btn-primary">Ко входу</a>
            <a href="Home.php" class="admin-btn admin-btn-secondary">На главную</a>
          </div>
        </section>
      <?php else: ?>
        <section class="admin-stats-grid">
          <article class="admin-stat-card">
            <span>Всего контента</span>
            <strong><?= (int) $stats['contentCount'] ?></strong>
            <small><?= (int) $stats['movieCount'] ?> фильмов и <?= (int) $stats['seriesCount'] ?> сериалов</small>
          </article>
          <article class="admin-stat-card">
            <span>Пользователи</span>
            <strong><?= (int) $stats['userCount'] ?></strong>
            <small>Только пользовательские аккаунты AKINO</small>
          </article>
          <article class="admin-stat-card">
            <span>Администраторы</span>
            <strong><?= (int) $stats['adminCount'] ?></strong>
            <small>Отдельные учётные записи панели</small>
          </article>
        </section>

        <?php if ($activeTab === 'content'): ?>
          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Контент</h2>
                <p>Управление карточками фильмов и сериалов. Постер, файл и метаданные сохраняются сразу в каталог.</p>
              </div>

              <?php if ($editingMovie !== null): ?>
                <a href="<?= $escape($buildTabUrl('content')) ?>#adminContentForm" class="admin-btn admin-btn-secondary">Создать новую карточку</a>
              <?php endif; ?>
            </div>

            <?php if ($formErrors): ?>
              <div class="admin-alert admin-alert-error">
                <strong>Форма заполнена не полностью.</strong>
                <ul class="admin-error-list">
                  <?php foreach ($formErrors as $error): ?>
                    <li><?= $escape($error) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <section class="admin-form-card" id="adminContentForm">
              <div class="admin-form-head">
                <div>
                  <h3><?= $editingMovie !== null ? 'Редактирование контента' : 'Управление контентом' ?></h3>
                  <p><?= $editingMovie !== null ? 'Измените карточку и сохраните её без перехода на другую страницу.' : 'Новая карточка автоматически появится в каталоге и будет доступна на витрине.' ?></p>
                </div>
                <span class="admin-form-kicker"><?= $editingMovie !== null ? 'Режим редактирования' : 'Новый контент' ?></span>
              </div>

              <form method="post" class="admin-content-form">
                <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                <input type="hidden" name="admin_action" value="save_movie">
                <input type="hidden" name="movie_id" value="<?= (int) $form['id'] ?>">

                <div class="admin-form-grid">
                  <label class="admin-field admin-field-wide">
                    <span>Название*</span>
                    <input type="text" name="title" value="<?= $escape((string) $form['title']) ?>" placeholder="Введите название" maxlength="160" required>
                  </label>

                  <label class="admin-field">
                    <span>Тип контента*</span>
                    <select name="content_type" required>
                      <option value="movie"<?= $form['contentType'] === 'movie' ? ' selected' : '' ?>>Фильм</option>
                      <option value="series"<?= $form['contentType'] === 'series' ? ' selected' : '' ?>>Сериал</option>
                    </select>
                  </label>

                  <label class="admin-field admin-field-wide">
                    <span>URL постера*</span>
                    <input type="text" name="poster_url" value="<?= $escape((string) $form['posterUrl']) ?>" placeholder="img/posters/example.webp" required>
                  </label>

                  <label class="admin-field admin-field-small">
                    <span>Год выпуска*</span>
                    <input type="number" name="release_year" min="1900" max="<?= (int) date('Y') + 2 ?>" value="<?= (int) $form['releaseYear'] ?>" required>
                  </label>

                  <label class="admin-field admin-field-wide">
                    <span>Режиссёр</span>
                    <input type="text" name="director" value="<?= $escape((string) $form['director']) ?>" placeholder="Имя режиссёра" maxlength="160">
                  </label>

                  <label class="admin-field admin-field-small">
                    <span>Рейтинг (0-10)</span>
                    <input type="number" name="rating" min="0" max="10" step="0.1" value="<?= $escape((string) $form['rating']) ?>">
                  </label>

                  <label class="admin-field admin-field-wide">
                    <span>URL файла / потока</span>
                    <input type="text" name="media_path" value="<?= $escape((string) $form['mediaPath']) ?>" placeholder="https://example.com/stream.m3u8 или /media/movie.mp4">
                  </label>

                  <label class="admin-field admin-field-small">
                    <span>Длительность (мин)</span>
                    <input type="number" name="duration_minutes" min="0" step="1" value="<?= $escape((string) $form['durationMinutes']) ?>" placeholder="120">
                  </label>

                  <label class="admin-field">
                    <span>Страна</span>
                    <input type="text" name="country" value="<?= $escape((string) $form['country']) ?>" placeholder="Россия">
                  </label>

                  <label class="admin-field admin-field-small">
                    <span>Возрастной рейтинг</span>
                    <input type="text" name="age_rating" value="<?= $escape((string) $form['ageRating']) ?>" placeholder="16+">
                  </label>

                  <label class="admin-field admin-field-full">
                    <span>Описание*</span>
                    <textarea name="description" rows="6" placeholder="Введите описание" required><?= $escape((string) $form['description']) ?></textarea>
                  </label>
                </div>

                <fieldset class="admin-genres">
                  <legend>Жанры* (можно выбрать несколько)</legend>
                  <div class="admin-genre-list">
                    <?php foreach ($genreOptions as $genre): ?>
                      <?php $checked = in_array($genre, $form['genres'], true); ?>
                      <label class="admin-genre-chip<?= $checked ? ' is-checked' : '' ?>">
                        <input type="checkbox" name="genres[]" value="<?= $escape($genre) ?>"<?= $checked ? ' checked' : '' ?>>
                        <span><?= $escape($genre) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </fieldset>

                <fieldset class="admin-genres admin-home-sections">
                  <legend>Главная страница</legend>
                  <p class="admin-field-help">Отметьте дорожки, в которых карточка должна отображаться на главной странице AKINO.</p>
                  <div class="admin-genre-list">
                    <?php foreach ($homeSectionDefinitions as $sectionKey => $sectionMeta): ?>
                      <?php $checked = in_array($sectionKey, $form['homeSections'], true); ?>
                      <label class="admin-genre-chip<?= $checked ? ' is-checked' : '' ?>">
                        <input type="checkbox" name="home_sections[]" value="<?= $escape($sectionKey) ?>"<?= $checked ? ' checked' : '' ?>>
                        <span><?= $escape((string) ($sectionMeta['label'] ?? $sectionKey)) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <div class="admin-home-section-notes">
                    <?php foreach ($homeSectionDefinitions as $sectionMeta): ?>
                      <p>
                        <strong><?= $escape((string) ($sectionMeta['label'] ?? '')) ?>:</strong>
                        <?= $escape((string) ($sectionMeta['hint'] ?? '')) ?>
                      </p>
                    <?php endforeach; ?>
                  </div>
                </fieldset>

                <div class="admin-form-actions">
                  <button type="submit" class="admin-btn admin-btn-primary">
                    <?= $editingMovie !== null ? 'Сохранить изменения' : 'Добавить контент' ?>
                  </button>
                  <?php if ($editingMovie !== null): ?>
                    <a href="<?= $escape($buildTabUrl('content')) ?>#adminContentForm" class="admin-btn admin-btn-secondary">Сбросить форму</a>
                  <?php endif; ?>
                </div>
              </form>
            </section>
          </section>

          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Добавленный контент</h2>
                <p>Последние карточки каталога. Каждую можно сразу отредактировать или удалить.</p>
              </div>
            </div>

            <?php if (!$contentList): ?>
              <div class="admin-empty-card">Пока нет ни одной карточки контента.</div>
            <?php else: ?>
              <div class="admin-content-grid">
                <?php foreach ($contentList as $item): ?>
                  <?php
                  $homeBadges = [];

                  foreach ($homeSectionDefinitions as $sectionKey => $sectionMeta) {
                      $sectionColumn = home_section_column($sectionKey);

                      if (($item[$sectionColumn] ?? null) !== null) {
                          $homeBadges[] = (string) ($sectionMeta['label'] ?? $sectionKey);
                      }
                  }
                  ?>
                  <article class="admin-content-card">
                    <img
                      src="<?= $escape((string) ($item['card_path'] ?: $item['poster_path'])) ?>"
                      alt="<?= $escape((string) $item['title']) ?>"
                      class="admin-content-poster"
                    >

                    <div class="admin-content-body">
                      <div class="admin-content-topline">
                        <span class="admin-type-pill"><?= $escape(movie_type_label($item)) ?></span>
                        <span class="admin-rating-pill"><?= number_format((float) ($item['rating'] ?? 0), 1, '.', '') ?></span>
                      </div>

                      <h3><?= $escape((string) $item['title']) ?></h3>
                      <p class="admin-content-meta">
                        <?= (int) ($item['release_year'] ?? 0) ?> · <?= $escape((string) ($item['genre'] ?? '')) ?>
                      </p>
                      <p class="admin-content-submeta">
                        <?= $escape((string) ($item['country'] ?: 'Страна не указана')) ?>
                        <?php if (!empty($item['director'])): ?>
                          · <?= $escape((string) $item['director']) ?>
                        <?php endif; ?>
                      </p>
                      <p class="admin-content-description"><?= $escape((string) ($item['description'] ?? '')) ?></p>

                      <div class="admin-content-groups">
                        <?php if ($homeBadges): ?>
                          <?php foreach ($homeBadges as $badge): ?>
                            <span class="admin-home-pill"><?= $escape($badge) ?></span>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="admin-home-pill is-muted">Не на главной</span>
                        <?php endif; ?>
                      </div>

                      <div class="admin-content-footer">
                        <small>Обновлено <?= $escape(admin_format_datetime($item['updated_at'] ?? null)) ?></small>
                        <div class="admin-content-actions">
                          <a href="<?= $escape($buildTabUrl('content', ['edit' => (int) $item['id']])) ?>#adminContentForm" class="admin-btn admin-btn-secondary">Редактировать</a>
                          <form method="post" action="<?= $escape($buildTabUrl('content')) ?>" data-confirm="Удалить эту карточку из каталога AKINO?">
                            <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                            <input type="hidden" name="admin_action" value="delete_movie">
                            <input type="hidden" name="movie_id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" class="admin-btn admin-btn-danger">Удалить</button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php elseif ($activeTab === 'episodes'): ?>
          <?php
            $invalidEpisodeCount = count(array_filter(
                $episodeList,
                static fn (array $episode): bool => ($episode['series_content_type'] ?? '') !== 'series'
            ));
          ?>
          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Серии</h2>
                <p>Управляйте сезонами и сериями отдельно от основной карточки сериала. Все изменения сохраняются в базе данных.</p>
              </div>

              <?php if ($editingEpisode !== null): ?>
                <a href="<?= $escape($buildTabUrl('episodes')) ?>#adminEpisodeForm" class="admin-btn admin-btn-secondary">Создать серию</a>
              <?php endif; ?>
            </div>

            <?php if ($invalidEpisodeCount > 0): ?>
              <div class="admin-alert admin-alert-warning">
                В базе найдено серий, привязанных к карточкам типа «Фильм»: <?= $invalidEpisodeCount ?>.
                Такие записи отмечены ниже и не показываются зрителям как сериал.
              </div>
            <?php endif; ?>

            <?php if ($episodeErrors): ?>
              <div class="admin-alert admin-alert-error">
                <strong>Проверьте форму серии.</strong>
                <ul class="admin-error-list">
                  <?php foreach ($episodeErrors as $error): ?>
                    <li><?= $escape($error) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <section class="admin-form-card" id="adminEpisodeForm">
              <div class="admin-form-head">
                <div>
                  <h3><?= $editingEpisode !== null ? 'Редактирование серии' : 'Новая серия' ?></h3>
                  <p>Выберите сериал, укажите сезон, номер серии и ссылку на видео. Новый сезон будет создан автоматически.</p>
                </div>
                <span class="admin-form-kicker"><?= $editingEpisode !== null ? 'Редактирование' : 'Библиотека серий' ?></span>
              </div>

              <?php if (!$seriesOptions): ?>
                <div class="admin-empty-card">Сначала создайте хотя бы одну карточку с типом «Сериал».</div>
              <?php else: ?>
                <form method="post" class="admin-content-form">
                  <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                  <input type="hidden" name="admin_action" value="save_episode">
                  <input type="hidden" name="episode_id" value="<?= (int) $episodeForm['id'] ?>">

                  <div class="admin-form-grid">
                    <label class="admin-field admin-field-wide">
                      <span>Сериал*</span>
                      <select name="series_id" required>
                        <option value="">Выберите сериал</option>
                        <?php foreach ($seriesOptions as $seriesOption): ?>
                          <option value="<?= (int) $seriesOption['id'] ?>"<?= (int) $episodeForm['seriesId'] === (int) $seriesOption['id'] ? ' selected' : '' ?>>
                            <?= $escape((string) $seriesOption['title']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>

                    <label class="admin-field admin-field-small">
                      <span>Номер сезона*</span>
                      <input type="number" name="season_number" min="1" value="<?= (int) $episodeForm['seasonNumber'] ?>" required>
                    </label>

                    <label class="admin-field admin-field-wide">
                      <span>Название сезона*</span>
                      <input type="text" name="season_title" value="<?= $escape((string) $episodeForm['seasonTitle']) ?>" maxlength="160" required>
                    </label>

                    <label class="admin-field admin-field-full">
                      <span>Описание сезона</span>
                      <textarea name="season_description" rows="3"><?= $escape((string) $episodeForm['seasonDescription']) ?></textarea>
                    </label>

                    <label class="admin-field admin-field-small">
                      <span>Номер серии*</span>
                      <input type="number" name="episode_number" min="1" value="<?= (int) $episodeForm['episodeNumber'] ?>" required>
                    </label>

                    <label class="admin-field admin-field-wide">
                      <span>Название серии*</span>
                      <input type="text" name="episode_title" value="<?= $escape((string) $episodeForm['title']) ?>" maxlength="160" required>
                    </label>

                    <label class="admin-field admin-field-small">
                      <span>Длительность, мин</span>
                      <input type="number" name="duration_minutes" min="0" step="1" value="<?= $escape((string) $episodeForm['durationMinutes']) ?>">
                    </label>

                    <label class="admin-field admin-field-wide">
                      <span>URL видео*</span>
                      <input type="text" name="video_path" value="<?= $escape((string) $episodeForm['videoPath']) ?>" placeholder="https://example.com/episode.mp4 or .m3u8" required>
                    </label>

                    <label class="admin-field admin-field-wide">
                      <span>Изображение-превью</span>
                      <input type="text" name="preview_path" value="<?= $escape((string) $episodeForm['previewPath']) ?>" placeholder="img/prew/example.webp">
                    </label>

                    <label class="admin-field admin-field-small">
                      <span>Порядок сортировки</span>
                      <input type="number" name="sort_order" min="0" step="1" value="<?= $escape((string) $episodeForm['sortOrder']) ?>">
                    </label>

                    <label class="admin-field admin-field-full">
                      <span>Описание серии</span>
                      <textarea name="episode_description" rows="5"><?= $escape((string) $episodeForm['description']) ?></textarea>
                    </label>
                  </div>

                  <div class="admin-form-actions">
                    <button type="submit" class="admin-btn admin-btn-primary">
                      <?= $editingEpisode !== null ? 'Сохранить серию' : 'Добавить серию' ?>
                    </button>
                    <?php if ($editingEpisode !== null): ?>
                      <a href="<?= $escape($buildTabUrl('episodes')) ?>#adminEpisodeForm" class="admin-btn admin-btn-secondary">Сбросить форму</a>
                    <?php endif; ?>
                  </div>
                </form>
              <?php endif; ?>
            </section>
          </section>

          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Библиотека серий</h2>
                <p>Серии сгруппированы по сериалам и сезонам и доступны для редактирования.</p>
              </div>
            </div>

            <?php if (!$episodeList): ?>
              <div class="admin-empty-card">Серии ещё не добавлены.</div>
            <?php else: ?>
              <div class="admin-content-grid">
                <?php foreach ($episodeList as $episodeItem): ?>
                  <?php $hasInvalidParent = ($episodeItem['series_content_type'] ?? '') !== 'series'; ?>
                  <article class="admin-content-card">
                    <img
                      src="<?= $escape((string) ($episodeItem['preview_path'] ?: $episodeItem['series_card_path'])) ?>"
                      alt="<?= $escape((string) $episodeItem['title']) ?>"
                      class="admin-content-poster"
                    >

                    <div class="admin-content-body">
                      <div class="admin-content-topline">
                        <span class="admin-type-pill<?= $hasInvalidParent ? ' is-danger' : '' ?>">
                          <?= $hasInvalidParent ? 'Ошибка типа' : 'Сезон ' . (int) $episodeItem['season_number'] . ' · Серия ' . (int) $episodeItem['episode_number'] ?>
                        </span>
                        <span class="admin-rating-pill"><?= $escape(playback_format_duration((int) ($episodeItem['duration_seconds'] ?? 0))) ?></span>
                      </div>

                      <h3><?= $escape((string) $episodeItem['title']) ?></h3>
                      <p class="admin-content-meta">
                        <?= $escape((string) $episodeItem['series_title']) ?> · <?= $escape((string) $episodeItem['season_title']) ?>
                      </p>
                      <p class="admin-content-description"><?= $escape((string) ($episodeItem['description'] ?? '')) ?></p>
                      <?php if ($hasInvalidParent): ?>
                        <p class="admin-content-warning">Родительская карточка имеет тип «Фильм». Перенесите серию в настоящий сериал или удалите запись.</p>
                      <?php endif; ?>

                      <div class="admin-content-footer">
                        <small>Обновлено <?= $escape(admin_format_datetime($episodeItem['updated_at'] ?? null)) ?></small>
                        <div class="admin-content-actions">
                          <a href="<?= $escape($buildTabUrl('episodes', ['edit_episode' => (int) $episodeItem['id']])) ?>#adminEpisodeForm" class="admin-btn admin-btn-secondary">Редактировать</a>
                          <form method="post" data-confirm="Удалить эту серию?">
                            <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                            <input type="hidden" name="admin_action" value="delete_episode">
                            <input type="hidden" name="episode_id" value="<?= (int) $episodeItem['id'] ?>">
                            <button type="submit" class="admin-btn admin-btn-danger">Удалить</button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

        <?php elseif ($activeTab === 'users'): ?>
          <section class="admin-section">
            <?php
              $visibleUsers = $userDirectory['items'];
              $matchedUsers = (int) ($userDirectory['total'] ?? 0);
              $visibleUserCount = count($visibleUsers);
              $totalUsersCount = (int) ($userOverview['totalUsers'] ?? 0);
              $activeUsersCount = (int) ($userOverview['activeSubscriptionCount'] ?? 0);
              $activeShare = $totalUsersCount > 0 ? (int) round(($activeUsersCount / $totalUsersCount) * 100) : 0;
            ?>
            <div class="admin-section-head">
              <div>
                <h2>Пользователи</h2>
                <p>Только пользовательские аккаунты витрины AKINO: поиск, фильтры и быстрая оценка активности без смешивания с учётными записями админки.</p>
              </div>
              <div class="admin-form-kicker">Показано <?= $visibleUserCount ?> из <?= $matchedUsers ?></div>
            </div>

            <div class="admin-stats-grid admin-user-summary-grid">
              <article class="admin-stat-card">
                <span>Всего пользователей</span>
                <strong><?= $totalUsersCount ?></strong>
                <small>Без активной подписки: <?= (int) ($userOverview['inactiveSubscriptionCount'] ?? 0) ?></small>
              </article>
              <article class="admin-stat-card">
                <span>Активные подписки</span>
                <strong><?= $activeUsersCount ?></strong>
                <small><?= $activeShare ?>% пользовательской базы</small>
              </article>
              <article class="admin-stat-card">
                <span>Заходили за 30 дней</span>
                <strong><?= (int) ($userOverview['recentLoginCount'] ?? 0) ?></strong>
                <small>Живая аудитория витрины</small>
              </article>
              <article class="admin-stat-card">
                <span>С указанным e-mail</span>
                <strong><?= (int) ($userOverview['emailCount'] ?? 0) ?></strong>
                <small>Можно использовать для будущих уведомлений</small>
              </article>
            </div>

            <section class="admin-panel-card admin-user-filter-card">
              <div class="admin-form-head">
                <div>
                  <h3>Фильтры пользователей</h3>
                  <p>Ищите по имени, телефону или e-mail и сразу смотрите, у кого активна подписка и кто недавно заходил в сервис.</p>
                </div>
              </div>

              <form method="get" class="admin-user-filters">
                <input type="hidden" name="tab" value="users">

                <label class="admin-field">
                  <span>Поиск</span>
                  <input type="search" name="q" value="<?= $escape((string) $userFilters['q']) ?>" placeholder="Имя, e-mail или телефон">
                </label>

                <label class="admin-field">
                  <span>Подписка</span>
                  <select name="subscription">
                    <?php foreach ($userSubscriptionOptions as $optionValue => $optionLabel): ?>
                      <option value="<?= $escape($optionValue) ?>"<?= $userFilters['subscription'] === $optionValue ? ' selected' : '' ?>>
                        <?= $escape($optionLabel) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>

                <label class="admin-field">
                  <span>Сортировка</span>
                  <select name="sort">
                    <?php foreach ($userSortOptions as $optionValue => $optionLabel): ?>
                      <option value="<?= $escape($optionValue) ?>"<?= $userFilters['sort'] === $optionValue ? ' selected' : '' ?>>
                        <?= $escape($optionLabel) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>

                <div class="admin-user-filter-actions">
                  <button type="submit" class="admin-btn admin-btn-primary">Применить</button>
                  <a href="<?= $escape($buildTabUrl('users')) ?>" class="admin-btn admin-btn-secondary">Сбросить</a>
                </div>
              </form>
            </section>

            <?php if (!$visibleUsers): ?>
              <div class="admin-empty-card">По текущим фильтрам пользователи не найдены.</div>
            <?php else: ?>
              <div class="admin-user-directory-grid">
                <?php foreach ($visibleUsers as $userRow): ?>
                  <?php
                    $hasActiveSubscription = !empty($userRow['has_active_subscription']);
                    $hasSubscriptionHistory = !empty($userRow['subscription_ends_at']);
                    $isBlocked = !empty($userRow['is_blocked']);
                    $subscriptionStatusClass = $hasActiveSubscription
                      ? ' is-active'
                      : ($hasSubscriptionHistory ? ' is-expired' : ' is-inactive');
                    $subscriptionStatusText = $hasActiveSubscription
                      ? 'Подписка активна'
                      : ($hasSubscriptionHistory ? 'Подписка истекла' : 'Без подписки');
                    $accountStatusClass = $isBlocked ? ' is-blocked' : $subscriptionStatusClass;
                    $accountStatusText = $isBlocked ? 'Аккаунт заблокирован' : $subscriptionStatusText;
                    $subscriptionMeta = $hasActiveSubscription
                      ? ((string) ($userRow['subscription_plan_name'] ?: 'AKINO') . ' до ' . admin_format_date($userRow['subscription_ends_at'] ?? null))
                      : ($hasSubscriptionHistory
                        ? 'Истекла ' . admin_format_date($userRow['subscription_ends_at'] ?? null)
                        : 'Подписка ещё не оформлялась');
                  ?>
                  <article class="admin-user-directory-card">
                    <div class="admin-user-directory-head">
                      <div class="admin-user-directory-profile">
                        <img
                          src="<?= $escape((string) $userRow['avatar_display']) ?>"
                          alt="<?= $escape((string) $userRow['name_display']) ?>"
                          class="admin-user-directory-avatar"
                        >
                        <div>
                          <h3><?= $escape((string) $userRow['name_display']) ?></h3>
                          <p><?= $escape((string) $userRow['phone_display']) ?></p>
                        </div>
                      </div>
                      <span class="admin-status-pill<?= $accountStatusClass ?>"><?= $escape($accountStatusText) ?></span>
                    </div>

                    <div class="admin-user-directory-meta">
                      <div class="admin-user-directory-meta-item">
                        <span>E-mail</span>
                        <strong><?= $escape((string) $userRow['email_display']) ?></strong>
                      </div>
                      <div class="admin-user-directory-meta-item">
                        <span>Подписка</span>
                        <strong><?= $escape($subscriptionMeta) ?></strong>
                      </div>
                      <div class="admin-user-directory-meta-item">
                        <span>Последний вход</span>
                        <strong><?= $escape($userRow['last_login_at'] ? admin_format_datetime($userRow['last_login_at']) : 'Ещё не входил') ?></strong>
                      </div>
                      <div class="admin-user-directory-meta-item">
                        <span>Регистрация</span>
                        <strong><?= $escape(admin_format_datetime($userRow['created_at'] ?? null)) ?></strong>
                      </div>
                    </div>

                    <div class="admin-user-directory-metrics">
                      <span class="admin-metric-chip">Избранное: <?= (int) $userRow['favorites_count'] ?></span>
                      <span class="admin-metric-chip">История: <?= (int) $userRow['history_count'] ?></span>
                      <span class="admin-metric-chip">Продолжить просмотр: <?= (int) $userRow['continue_count'] ?></span>
                      <?php if (!empty($userRow['playback_activity_at'])): ?>
                        <span class="admin-metric-chip is-muted">Плеер активен: <?= $escape(admin_format_datetime($userRow['playback_activity_at'])) ?></span>
                      <?php endif; ?>
                    </div>

                    <div class="admin-user-directory-actions">
                      <a href="<?= $escape($buildUserTabUrl(['user' => (int) $userRow['id']])) ?>#userDetailsPanel" class="admin-btn admin-btn-secondary">Открыть профиль</a>

                      <form method="post" action="<?= $escape($buildUserTabUrl(['user' => (int) $userRow['id']])) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                        <input type="hidden" name="admin_action" value="extend_user_subscription">
                        <input type="hidden" name="user_id" value="<?= (int) $userRow['id'] ?>">
                        <button type="submit" class="admin-btn admin-btn-primary">Продлить на 30 дней</button>
                      </form>

                      <form
                        method="post"
                        action="<?= $escape($buildUserTabUrl(['user' => (int) $userRow['id']])) ?>"
                        data-confirm="<?= $escape($isBlocked ? 'Разблокировать пользователя?' : 'Заблокировать пользователя?') ?>"
                      >
                        <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                        <input type="hidden" name="admin_action" value="toggle_user_block">
                        <input type="hidden" name="user_id" value="<?= (int) $userRow['id'] ?>">
                        <input type="hidden" name="block_state" value="<?= $isBlocked ? '0' : '1' ?>">
                        <button type="submit" class="admin-btn <?= $isBlocked ? 'admin-btn-secondary' : 'admin-btn-danger' ?>">
                          <?= $isBlocked ? 'Разблокировать' : 'Заблокировать' ?>
                        </button>
                      </form>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>

              <?php if ($selectedUser !== null): ?>
                <?php
                  $selectedSubscription = $selectedUser['subscriptionPayload'] ?? ['active' => false, 'name' => 'AKINO', 'endsAtDisplay' => 'Не активна'];
                  $selectedBlocked = !empty($selectedUser['is_blocked']);
                  $currentUserForm = $userProfileForm ?? admin_user_profile_form_from_user($selectedUser);
                ?>
                <section class="admin-panel-card admin-user-detail-panel" id="userDetailsPanel">
                  <div class="admin-section-head">
                    <div>
                      <h3>Профиль пользователя</h3>
                      <p>Подробная карточка аккаунта: состояние подписки, история просмотров, избранное и быстрые административные действия.</p>
                    </div>
                    <div class="admin-form-kicker">ID #<?= (int) ($selectedUser['id'] ?? 0) ?></div>
                  </div>

                  <div class="admin-user-detail-hero">
                    <div class="admin-user-detail-profile">
                      <img
                        src="<?= $escape(akino_avatar_display_path($selectedUser['avatar_display'] ?? null)) ?>"
                        alt="<?= $escape((string) ($selectedUser['name_display'] ?? 'Пользователь AKINO')) ?>"
                        class="admin-user-detail-avatar"
                      >
                      <div class="admin-user-detail-copy">
                        <h3><?= $escape((string) ($selectedUser['name_display'] ?? 'Пользователь AKINO')) ?></h3>
                        <p><?= $escape((string) ($selectedUser['phone_display'] ?? '')) ?></p>
                        <p><?= $escape((string) ($selectedUser['email_display'] ?? 'Не указан')) ?></p>
                        <div class="admin-user-directory-metrics">
                          <span class="admin-status-pill<?= $selectedBlocked ? ' is-blocked' : (!empty($selectedSubscription['active']) ? ' is-active' : ' is-inactive') ?>">
                            <?= $escape($selectedBlocked ? 'Аккаунт заблокирован' : (!empty($selectedSubscription['active']) ? 'Подписка активна' : 'Без активной подписки')) ?>
                          </span>
                          <?php if (!empty($selectedUser['blocked_at'])): ?>
                            <span class="admin-metric-chip is-muted">Блокировка: <?= $escape(admin_format_datetime($selectedUser['blocked_at'])) ?></span>
                          <?php endif; ?>
                          <span class="admin-metric-chip is-muted">Регистрация: <?= $escape(admin_format_datetime($selectedUser['created_at'] ?? null)) ?></span>
                          <span class="admin-metric-chip is-muted">Последний вход: <?= $escape(!empty($selectedUser['last_login_at']) ? admin_format_datetime($selectedUser['last_login_at']) : 'Ещё не входил') ?></span>
                        </div>
                      </div>
                    </div>

                    <div class="admin-user-detail-actions">
                      <form method="post" action="<?= $escape($buildUserTabUrl(['user' => (int) $selectedUser['id']])) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                        <input type="hidden" name="admin_action" value="extend_user_subscription">
                        <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                        <button type="submit" class="admin-btn admin-btn-primary">Продлить подписку</button>
                      </form>

                      <form
                        method="post"
                        action="<?= $escape($buildUserTabUrl(['user' => (int) $selectedUser['id']])) ?>"
                        data-confirm="<?= $escape($selectedBlocked ? 'Разблокировать пользователя?' : 'Заблокировать пользователя?') ?>"
                      >
                        <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                        <input type="hidden" name="admin_action" value="toggle_user_block">
                        <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                        <input type="hidden" name="block_state" value="<?= $selectedBlocked ? '0' : '1' ?>">
                        <button type="submit" class="admin-btn <?= $selectedBlocked ? 'admin-btn-secondary' : 'admin-btn-danger' ?>">
                          <?= $selectedBlocked ? 'Разблокировать аккаунт' : 'Заблокировать аккаунт' ?>
                        </button>
                      </form>

                      <form
                        method="post"
                        action="<?= $escape($buildUserTabUrl()) ?>"
                        data-confirm="Удалить пользователя и всю его активность без возможности восстановления?"
                      >
                        <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                        <input type="hidden" name="admin_action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                        <button type="submit" class="admin-btn admin-btn-danger">Удалить пользователя</button>
                      </form>
                    </div>
                  </div>

                  <div class="admin-user-directory-meta admin-user-detail-meta">
                    <div class="admin-user-directory-meta-item">
                      <span>Текущая подписка</span>
                      <strong><?= $escape(!empty($selectedSubscription['active']) ? ((string) ($selectedSubscription['name'] ?? 'AKINO') . ' до ' . (string) ($selectedSubscription['endsAtDisplay'] ?? '')) : 'Не активна') ?></strong>
                    </div>
                    <div class="admin-user-directory-meta-item">
                      <span>Избранное</span>
                      <strong><?= (int) ($selectedUser['favorites_count'] ?? 0) ?> тайтлов</strong>
                    </div>
                    <div class="admin-user-directory-meta-item">
                      <span>История просмотров</span>
                      <strong><?= (int) ($selectedUser['history_count'] ?? 0) ?> записей</strong>
                    </div>
                    <div class="admin-user-directory-meta-item">
                      <span>Продолжить просмотр</span>
                      <strong><?= (int) ($selectedUser['continue_count'] ?? 0) ?> активных карточек</strong>
                    </div>
                  </div>

                  <section class="admin-user-edit-card">
                    <div class="admin-user-detail-section-head">
                      <h4>Редактирование профиля</h4>
                      <small>Имя, контакты, дата рождения, аватар и базовые данные аккаунта.</small>
                    </div>

                    <?php if ($userProfileErrors): ?>
                      <div class="admin-alert admin-alert-error">
                        <ul class="admin-error-list">
                          <?php foreach ($userProfileErrors as $error): ?>
                            <li><?= $escape($error) ?></li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    <?php endif; ?>

                    <form method="post" action="<?= $escape($buildUserTabUrl(['user' => (int) $selectedUser['id']])) ?>" class="admin-content-form">
                      <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                      <input type="hidden" name="admin_action" value="save_user_profile">
                      <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">

                      <div class="admin-inline-grid">
                        <label class="admin-field admin-field-full">
                          <span>Имя пользователя</span>
                          <input type="text" name="name" value="<?= $escape((string) ($currentUserForm['name'] ?? '')) ?>" placeholder="Имя пользователя" required>
                        </label>
                        <label class="admin-field admin-field-full">
                          <span>Телефон</span>
                          <input type="text" name="phone" value="<?= $escape((string) ($currentUserForm['phoneInput'] ?? '')) ?>" placeholder="+7 (999) 999-99-99" required>
                        </label>
                        <label class="admin-field admin-field-full">
                          <span>E-mail</span>
                          <input type="email" name="email" value="<?= $escape((string) ($currentUserForm['email'] ?? '')) ?>" placeholder="user@akino.local">
                        </label>
                        <label class="admin-field admin-field-full">
                          <span>Пол</span>
                          <input type="text" name="gender" value="<?= $escape((string) ($currentUserForm['gender'] ?? '')) ?>" placeholder="Например: мужской">
                        </label>
                        <label class="admin-field admin-field-full">
                          <span>Дата рождения</span>
                          <input type="text" name="birth_date" value="<?= $escape((string) ($currentUserForm['birthDateInput'] ?? '')) ?>" placeholder="ДД.ММ.ГГГГ или YYYY-MM-DD">
                        </label>
                        <label class="admin-field admin-field-full">
                          <span>Аватар</span>
                          <input type="text" name="avatar_path" value="<?= $escape((string) ($currentUserForm['avatarPath'] ?? '')) ?>" placeholder="<?= $escape(akino_default_avatar_path()) ?>">
                        </label>
                      </div>

                      <div class="admin-form-actions">
                        <button type="submit" class="admin-btn admin-btn-primary">Сохранить профиль</button>
                        <a href="<?= $escape($buildUserTabUrl(['user' => (int) $selectedUser['id']])) ?>#userDetailsPanel" class="admin-btn admin-btn-secondary">Сбросить изменения</a>
                      </div>
                    </form>
                  </section>

                  <section class="admin-user-history-card">
                    <div class="admin-user-detail-section-head">
                      <h4>Журнал действий</h4>
                      <small>Блокировки, продления подписки и изменения профиля по этому аккаунту.</small>
                    </div>

                    <?php if (empty($selectedUser['actionLogs'])): ?>
                      <div class="admin-empty-card">По этому пользователю ещё нет записанных действий.</div>
                    <?php else: ?>
                      <div class="admin-action-timeline">
                        <?php foreach ($selectedUser['actionLogs'] as $log): ?>
                          <?php
                            $logDetails = $log['details'] ?? [];
                            $logChanges = is_array($logDetails['changes'] ?? null) ? $logDetails['changes'] : [];
                            $logAdminLogin = trim((string) ($log['adminLogin'] ?? ''));
                          ?>
                          <article class="admin-action-timeline-item">
                            <div class="admin-action-timeline-head">
                              <div>
                                <h5><?= $escape((string) ($log['summary'] ?? 'Действие администратора')) ?></h5>
                                <p>
                                  <?= $escape((string) ($log['adminName'] ?? 'Системное действие')) ?>
                                  <?php if ($logAdminLogin !== ''): ?>
                                    <span>@<?= $escape($logAdminLogin) ?></span>
                                  <?php endif; ?>
                                </p>
                              </div>
                              <span class="admin-metric-chip is-muted"><?= $escape((string) ($log['createdAtDisplay'] ?? '')) ?></span>
                            </div>

                            <?php if ($logChanges): ?>
                              <div class="admin-action-change-list">
                                <?php foreach ($logChanges as $change): ?>
                                  <div class="admin-action-change-item">
                                    <strong><?= $escape((string) ($change['label'] ?? 'Поле')) ?></strong>
                                    <small>
                                      <?= $escape((string) (($change['before'] ?? '') !== '' ? $change['before'] : 'Не указано')) ?>
                                      →
                                      <?= $escape((string) (($change['after'] ?? '') !== '' ? $change['after'] : 'Не указано')) ?>
                                    </small>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php else: ?>
                              <div class="admin-action-meta-list">
                                <?php if (!empty($logDetails['plan_name'] ?? null)): ?>
                                  <span class="admin-metric-chip">Тариф: <?= $escape((string) $logDetails['plan_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($logDetails['new_ends_at'] ?? null)): ?>
                                  <span class="admin-metric-chip">До: <?= $escape(admin_format_datetime((string) $logDetails['new_ends_at'])) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($logDetails['phone'] ?? null)): ?>
                                  <span class="admin-metric-chip is-muted">Телефон: <?= $escape(format_phone((string) $logDetails['phone'])) ?></span>
                                <?php endif; ?>
                              </div>
                            <?php endif; ?>
                          </article>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </section>

                  <div class="admin-user-detail-sections">
                    <section class="admin-user-detail-section">
                      <div class="admin-user-detail-section-head">
                        <h4>Продолжить просмотр</h4>
                        <small>Последняя активность в плеере</small>
                      </div>

                      <?php if (empty($selectedUser['continueWatching'])): ?>
                        <div class="admin-empty-card">У пользователя нет незавершённого просмотра.</div>
                      <?php else: ?>
                        <div class="admin-mini-media-list">
                          <?php foreach ($selectedUser['continueWatching'] as $item): ?>
                            <a href="<?= $escape((string) ($item['continueUrl'] ?? '#')) ?>" class="admin-mini-media-card">
                              <img src="<?= $escape((string) ($item['coverPath'] ?? '')) ?>" alt="<?= $escape((string) ($item['title'] ?? 'Контент')) ?>">
                              <div>
                                <strong><?= $escape((string) ($item['title'] ?? 'Контент AKINO')) ?></strong>
                                <small><?= $escape((string) ($item['statusLabel'] ?? '')) ?></small>
                                <small><?= $escape((string) ($item['progressDisplay'] ?? '')) ?></small>
                              </div>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </section>

                    <section class="admin-user-detail-section">
                      <div class="admin-user-detail-section-head">
                        <h4>Избранное</h4>
                        <small>Сохранённые фильмы и сериалы</small>
                      </div>

                      <?php if (empty($selectedUser['favorites'])): ?>
                        <div class="admin-empty-card">Избранное пока пустое.</div>
                      <?php else: ?>
                        <div class="admin-mini-media-list">
                          <?php foreach ($selectedUser['favorites'] as $item): ?>
                            <a href="<?= $escape((string) ($item['url'] ?? '#')) ?>" class="admin-mini-media-card">
                              <img src="<?= $escape((string) ($item['cardPath'] ?? $item['posterPath'] ?? '')) ?>" alt="<?= $escape((string) ($item['title'] ?? 'Контент')) ?>">
                              <div>
                                <strong><?= $escape((string) ($item['title'] ?? 'Контент AKINO')) ?></strong>
                                <small><?= $escape((string) ($item['typeLabel'] ?? '')) ?></small>
                                <small><?= $escape((string) ($item['favoritedAtDisplay'] ?? '')) ?></small>
                              </div>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </section>

                    <section class="admin-user-detail-section">
                      <div class="admin-user-detail-section-head">
                        <h4>История просмотров</h4>
                        <small>Последние просмотренные карточки</small>
                      </div>

                      <?php if (empty($selectedUser['history'])): ?>
                        <div class="admin-empty-card">История просмотров пока пустая.</div>
                      <?php else: ?>
                        <div class="admin-mini-media-list">
                          <?php foreach ($selectedUser['history'] as $item): ?>
                            <a href="<?= $escape((string) ($item['url'] ?? '#')) ?>" class="admin-mini-media-card">
                              <img src="<?= $escape((string) ($item['cardPath'] ?? $item['posterPath'] ?? '')) ?>" alt="<?= $escape((string) ($item['title'] ?? 'Контент')) ?>">
                              <div>
                                <strong><?= $escape((string) ($item['title'] ?? 'Контент AKINO')) ?></strong>
                                <small><?= $escape((string) ($item['typeLabel'] ?? '')) ?></small>
                                <small><?= $escape((string) ($item['viewedAtDisplay'] ?? '')) ?></small>
                              </div>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </section>
                  </div>
                </section>
              <?php endif; ?>

              <?php if ($matchedUsers > $visibleUserCount): ?>
                <p class="admin-results-note">Показаны первые <?= $visibleUserCount ?> пользователей. Сузьте фильтры, если нужен более узкий список.</p>
              <?php endif; ?>
            <?php endif; ?>
          </section>
        <?php elseif ($activeTab === 'admins'): ?>
          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Администраторы</h2>
                <p>Здесь живут только учётные записи панели управления: отдельный вход, отдельные пароли и отдельные списки от обычных пользователей.</p>
              </div>
            </div>

            <div class="admin-management-grid">
              <?php if (admin_can($adminUser, 'admins.manage')): ?>
              <section class="admin-panel-card">
                <div class="admin-form-head">
                  <div>
                    <h3>Создать администратора</h3>
                    <p>Новый админ сможет войти в панель через отдельную страницу логина.</p>
                  </div>
                </div>

                <?php if ($newAdminErrors): ?>
                  <div class="admin-alert admin-alert-error">
                    <ul class="admin-error-list">
                      <?php foreach ($newAdminErrors as $error): ?>
                        <li><?= $escape($error) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>

                <form method="post" class="admin-content-form">
                  <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                  <input type="hidden" name="admin_action" value="create_admin">

                  <div class="admin-inline-grid">
                    <label class="admin-field admin-field-full">
                      <span>Логин</span>
                      <input type="text" name="login" value="<?= $escape((string) $newAdminForm['login']) ?>" placeholder="editor_akino" maxlength="60" required>
                    </label>
                    <label class="admin-field admin-field-full">
                      <span>Имя администратора</span>
                      <input type="text" name="display_name" value="<?= $escape((string) $newAdminForm['display_name']) ?>" placeholder="Редактор AKINO" required>
                    </label>
                    <label class="admin-field admin-field-full">
                      <span>Аватар</span>
                      <input type="text" name="avatar_path" value="<?= $escape((string) $newAdminForm['avatar_path']) ?>" placeholder="<?= $escape(akino_default_avatar_path()) ?>">
                    </label>
                    <label class="admin-field admin-field-full">
                      <span>Роль и доступ</span>
                      <select name="role" required>
                        <?php foreach ($roleDefinitions as $roleKey => $roleMeta): ?>
                          <option value="<?= $escape($roleKey) ?>"<?= $newAdminForm['role'] === $roleKey ? ' selected' : '' ?>>
                            <?= $escape((string) $roleMeta['label']) ?> — <?= $escape((string) $roleMeta['description']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label class="admin-field admin-field-full">
                      <span>Пароль</span>
                      <input type="password" name="password" minlength="12" maxlength="128" required>
                    </label>
                    <label class="admin-field admin-field-full">
                      <span>Повтор пароля</span>
                      <input type="password" name="password_confirm" minlength="12" maxlength="128" required>
                    </label>
                  </div>

                  <div class="admin-form-actions">
                    <button type="submit" class="admin-btn admin-btn-primary">Создать администратора</button>
                  </div>
                </form>
              </section>
              <?php endif; ?>

              <section class="admin-panel-card" id="adminPasswordForm">
                <div class="admin-form-head">
                  <div>
                    <h3>Сменить пароль</h3>
                    <p>Пароль меняется только для текущего админ-аккаунта `@<?= $escape((string) ($adminUser['login'] ?? 'admin')) ?>`.</p>
                  </div>
                </div>

                <?php if ($passwordErrors): ?>
                  <div class="admin-alert admin-alert-error">
                    <ul class="admin-error-list">
                      <?php foreach ($passwordErrors as $error): ?>
                        <li><?= $escape($error) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>

                <form method="post" class="admin-content-form">
                  <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                  <input type="hidden" name="admin_action" value="change_password">

                  <div class="admin-inline-grid">
                    <label class="admin-field admin-field-full">
                      <span>Текущий пароль</span>
                      <input type="password" name="current_password" maxlength="128" required>
                    </label>
                    <label class="admin-field admin-field-full">
                      <span>Новый пароль</span>
                      <input type="password" name="new_password" minlength="12" maxlength="128" required>
                    </label>
                    <label class="admin-field admin-field-full">
                      <span>Повтор нового пароля</span>
                      <input type="password" name="new_password_confirm" minlength="12" maxlength="128" required>
                    </label>
                  </div>

                  <div class="admin-form-actions">
                    <button type="submit" class="admin-btn admin-btn-primary">Обновить пароль</button>
                  </div>
                </form>
              </section>
            </div>
          </section>

          <?php if (admin_can($adminUser, 'admins.manage')): ?>
          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Список администраторов</h2>
                <p>Отдельный список входов в панель, не смешанный с пользовательскими аккаунтами сайта.</p>
              </div>
            </div>

            <?php if (!$adminAccounts): ?>
              <div class="admin-empty-card">Администраторы ещё не созданы.</div>
            <?php else: ?>
              <div class="admin-account-grid">
                <?php foreach ($adminAccounts as $account): ?>
                  <article class="admin-account-card">
                    <div class="admin-account-head">
                      <img
                        src="<?= $escape(akino_avatar_display_path($account['avatar_path'] ?? null)) ?>"
                        alt="<?= $escape((string) ($account['display_name'] ?? 'Администратор')) ?>"
                        class="admin-user-avatar"
                      >
                      <div>
                        <h3><?= $escape((string) ($account['display_name'] ?? 'Администратор')) ?></h3>
                        <p>@<?= $escape((string) ($account['login'] ?? 'admin')) ?></p>
                      </div>
                    </div>

                    <div class="admin-account-meta">
                      <span>Роль: <?= $escape(admin_role_label((string) ($account['role'] ?? 'auditor'))) ?></span>
                      <span>Последний вход: <?= $escape(admin_format_datetime($account['last_login_at'] ?? null)) ?></span>
                      <span>Создан: <?= $escape(admin_format_datetime($account['created_at'] ?? null)) ?></span>
                    </div>

                    <form method="post" class="admin-account-role-form">
                      <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                      <input type="hidden" name="admin_action" value="change_admin_role">
                      <input type="hidden" name="account_id" value="<?= (int) ($account['id'] ?? 0) ?>">
                      <label class="admin-field admin-field-full">
                        <span>Изменить роль</span>
                        <select name="role">
                          <?php foreach ($roleDefinitions as $roleKey => $roleMeta): ?>
                            <option value="<?= $escape($roleKey) ?>"<?= (string) ($account['role'] ?? 'auditor') === $roleKey ? ' selected' : '' ?>>
                              <?= $escape((string) $roleMeta['label']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <button type="submit" class="admin-btn admin-btn-secondary">Сохранить роль</button>
                    </form>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
          <?php endif; ?>
        <?php elseif ($activeTab === 'security'): ?>
          <?php
          $trendCounts = array_map(static fn (array $item): int => (int) ($item['count'] ?? 0), $securityDashboard['trend']);
          $trendMax = max(1, $trendCounts ? max($trendCounts) : 1);
          ?>
          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Центр безопасности</h2>
                <p>Журнал событий, заблокированные попытки, контроль файлов и зашифрованные резервные копии в одном разделе.</p>
              </div>
              <span class="admin-security-live"><i></i> Мониторинг активен</span>
            </div>

            <div class="admin-security-stats">
              <article class="admin-security-stat">
                <span>События за 24 часа</span>
                <strong><?= (int) $securityDashboard['events24h'] ?></strong>
                <small>Все входы и защитные проверки</small>
              </article>
              <article class="admin-security-stat is-warning">
                <span>Предупреждения за 7 дней</span>
                <strong><?= (int) $securityDashboard['warnings7d'] ?></strong>
                <small>Подозрительные или отклонённые действия</small>
              </article>
              <article class="admin-security-stat is-danger">
                <span>Активные блокировки</span>
                <strong><?= (int) $securityDashboard['blockedNow'] ?></strong>
                <small>Ограничения по частоте запросов</small>
              </article>
              <article class="admin-security-stat<?= (int) $securityDashboard['changedFiles'] > 0 ? ' is-danger' : ' is-success' ?>">
                <span>Изменённые файлы</span>
                <strong><?= (int) $securityDashboard['changedFiles'] ?></strong>
                <small>По последней проверке целостности</small>
              </article>
            </div>

            <div class="admin-security-overview">
              <article class="admin-panel-card">
                <div class="admin-form-head">
                  <div>
                    <h3>События за 7 дней</h3>
                    <p>Наглядная динамика для отчёта и дипломной демонстрации.</p>
                  </div>
                </div>
                <div class="admin-security-chart" aria-label="График событий безопасности за семь дней">
                  <?php foreach ($securityDashboard['trend'] as $trendItem): ?>
                    <?php $barHeight = max(5, (int) round(((int) $trendItem['count'] / $trendMax) * 100)); ?>
                    <div class="admin-security-chart-column">
                      <strong><?= (int) $trendItem['count'] ?></strong>
                      <svg class="admin-security-chart-bar" viewBox="0 0 34 100" preserveAspectRatio="none" aria-hidden="true">
                        <rect x="0" y="<?= 100 - $barHeight ?>" width="34" height="<?= $barHeight ?>"></rect>
                      </svg>
                      <small><?= $escape((string) $trendItem['label']) ?></small>
                    </div>
                  <?php endforeach; ?>
                </div>
              </article>

              <article class="admin-panel-card">
                <div class="admin-form-head">
                  <div>
                    <h3>Активные источники</h3>
                    <p>IP-адреса маскируются, полный адрес в интерфейсе не хранится.</p>
                  </div>
                </div>
                <?php if (!$securityDashboard['topSources']): ?>
                  <div class="admin-empty-card">За последние семь дней источники атак не обнаружены.</div>
                <?php else: ?>
                  <div class="admin-security-source-list">
                    <?php foreach ($securityDashboard['topSources'] as $source): ?>
                      <div>
                        <strong><?= $escape((string) ($source['ip_masked'] ?? 'unknown')) ?></strong>
                        <span><?= (int) ($source['event_count'] ?? 0) ?> событий</span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </article>
            </div>
          </section>

          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Журнал событий</h2>
                <p>Чувствительные значения, пароли, токены и коды подтверждения в журнал не записываются.</p>
              </div>
            </div>

            <?php if (!$securityDashboard['events']): ?>
              <div class="admin-empty-card">События безопасности пока не зафиксированы.</div>
            <?php else: ?>
              <div class="admin-security-event-list">
                <?php foreach ($securityDashboard['events'] as $event): ?>
                  <article class="admin-security-event is-<?= $escape((string) ($event['severity'] ?? 'info')) ?>">
                    <span class="admin-security-event-level"><?= $escape((string) ($event['severity'] ?? 'info')) ?></span>
                    <div>
                      <h3><?= $escape((string) ($event['label'] ?? 'Событие безопасности')) ?></h3>
                      <p>
                        <?= $escape((string) ($event['actor_label'] ?: $event['actor_type'] ?? 'system')) ?>
                        · <?= $escape((string) ($event['ip_masked'] ?? 'unknown')) ?>
                        <?php if (!empty($event['request_path'])): ?>
                          · <?= $escape((string) $event['request_path']) ?>
                        <?php endif; ?>
                      </p>
                    </div>
                    <time><?= $escape(admin_format_datetime((string) ($event['created_at'] ?? ''))) ?></time>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="admin-section" id="securityBackups">
            <div class="admin-section-head">
              <div>
                <h2>Резервные копии</h2>
                <p>Данные сохраняются вне публичной папки, шифруются AES-256-GCM и проверяются по SHA-256.</p>
              </div>
              <?php if (admin_can($adminUser, 'security.manage')): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                  <input type="hidden" name="admin_action" value="create_backup">
                  <button type="submit" class="admin-btn admin-btn-primary">Создать копию</button>
                </form>
              <?php endif; ?>
            </div>

            <?php if (!$securityBackups): ?>
              <div class="admin-empty-card">Резервные копии ещё не создавались.</div>
            <?php else: ?>
              <div class="admin-backup-grid">
                <?php foreach ($securityBackups as $backup): ?>
                  <?php
                  $backupStatus = (string) ($backup['status'] ?? 'created');
                  $backupSize = (int) ($backup['size_bytes'] ?? 0);
                  ?>
                  <article class="admin-backup-card">
                    <div class="admin-backup-card-head">
                      <div>
                        <h3>Копия #<?= (int) ($backup['id'] ?? 0) ?></h3>
                        <p><?= $escape(admin_format_datetime((string) ($backup['created_at'] ?? ''))) ?></p>
                      </div>
                      <span class="admin-status-pill<?= $backupStatus === 'verified' ? ' is-active' : ($backupStatus === 'failed' ? ' is-blocked' : ' is-inactive') ?>">
                        <?= $backupStatus === 'verified' ? 'Проверена' : ($backupStatus === 'failed' ? 'Ошибка' : 'Создана') ?>
                      </span>
                    </div>
                    <div class="admin-account-meta">
                      <span>Размер: <?= number_format($backupSize / 1024, 1, '.', ' ') ?> КБ</span>
                      <span>SHA-256: <?= $escape(substr((string) ($backup['checksum'] ?? ''), 0, 16)) ?>…</span>
                      <span>Создал: <?= $escape((string) ($backup['admin_name'] ?: 'Система')) ?></span>
                    </div>
                    <?php if (admin_can($adminUser, 'security.manage')): ?>
                      <form method="post" class="admin-backup-actions">
                        <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                        <input type="hidden" name="admin_action" value="verify_backup">
                        <input type="hidden" name="backup_id" value="<?= (int) ($backup['id'] ?? 0) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary">Проверить копию</button>
                      </form>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="admin-section" id="fileIntegrity">
            <div class="admin-section-head">
              <div>
                <h2>Контроль целостности файлов</h2>
                <p>Эталонные SHA-256 хеши позволяют обнаружить подмену или удаление серверного кода.</p>
              </div>
              <?php if (admin_can($adminUser, 'security.manage')): ?>
                <div class="admin-form-actions">
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                    <input type="hidden" name="admin_action" value="record_integrity_baseline">
                    <button type="submit" class="admin-btn admin-btn-secondary">Обновить эталон</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $escape(admin_csrf_token()) ?>">
                    <input type="hidden" name="admin_action" value="scan_integrity">
                    <button type="submit" class="admin-btn admin-btn-primary">Запустить проверку</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>

            <?php if (!$integrityFiles): ?>
              <div class="admin-empty-card">Эталон файлов ещё не создан.</div>
            <?php else: ?>
              <div class="admin-integrity-list">
                <?php foreach ($integrityFiles as $fileState): ?>
                  <?php $fileStatus = (string) ($fileState['status'] ?? 'clean'); ?>
                  <div class="admin-integrity-row">
                    <span class="admin-integrity-indicator is-<?= $escape($fileStatus) ?>"></span>
                    <strong><?= $escape((string) ($fileState['path'] ?? '')) ?></strong>
                    <span><?= $fileStatus === 'clean' ? 'Без изменений' : ($fileStatus === 'missing' ? 'Файл отсутствует' : 'Файл изменён') ?></span>
                    <time><?= $escape(admin_format_datetime((string) ($fileState['checked_at'] ?? ''))) ?></time>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php elseif ($activeTab === 'subscriptions'): ?>
          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Подписки и платежи</h2>
                <p>Пока без реального эквайринга, но с рабочим списком активных и завершённых подписок.</p>
              </div>
            </div>

            <?php if (!$recentSubscriptions): ?>
              <div class="admin-empty-card">Подписки ещё не оформлялись.</div>
            <?php else: ?>
              <div class="admin-data-table admin-data-table-subscriptions">
                <div class="admin-data-row admin-data-row-head">
                  <span>Пользователь</span>
                  <span>Тариф</span>
                  <span>Стоимость</span>
                  <span>Старт</span>
                  <span>Окончание</span>
                  <span>Статус</span>
                </div>
                <?php foreach ($recentSubscriptions as $subscriptionRow): ?>
                  <?php $isActive = !empty($subscriptionRow['ends_at']) && strtotime((string) $subscriptionRow['ends_at']) >= time(); ?>
                  <div class="admin-data-row">
                    <span>
                      <?= $escape((string) ($subscriptionRow['user_name'] ?: 'Пользователь AKINO')) ?>
                      <small><?= $escape(format_phone((string) ($subscriptionRow['user_phone'] ?? ''))) ?></small>
                    </span>
                    <span><?= $escape((string) ($subscriptionRow['plan_name'] ?? 'AKINO')) ?></span>
                    <span><?= number_format((float) ($subscriptionRow['plan_price'] ?? 0), 0, '.', ' ') ?> ₽</span>
                    <span><?= $escape(admin_format_datetime($subscriptionRow['started_at'] ?? null)) ?></span>
                    <span><?= $escape(admin_format_datetime($subscriptionRow['ends_at'] ?? null)) ?></span>
                    <span>
                      <span class="admin-type-pill<?= $isActive ? ' is-gold' : '' ?>">
                        <?= $isActive ? 'Активна' : 'Завершена' ?>
                      </span>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php else: ?>
          <section class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>Настройки</h2>
                <p>Состояние панели управления, доступов и пользовательского сервиса.</p>
              </div>
            </div>

            <div class="admin-settings-grid">
              <article class="admin-settings-card">
                <h3>Вход в панель</h3>
                <p>Панель администратора доступна только после отдельной авторизации по логину и паролю.</p>
              </article>
              <article class="admin-settings-card">
                <h3>Администраторы</h3>
                <p>Админ-аккаунты отделены от обычных пользователей. Пароль текущего администратора можно изменить в разделе “Администраторы”.</p>
              </article>
              <article class="admin-settings-card">
                <h3>Пользователи</h3>
                <p>Аккаунты витрины управляются отдельно: можно просматривать активность, подписку, избранное, историю и блокировать доступ.</p>
              </article>
              <article class="admin-settings-card">
                <h3>Контент и витрина</h3>
                <p>Карточки можно добавлять, редактировать, удалять и назначать в дорожки главной страницы прямо из раздела “Контент”.</p>
              </article>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    </main>
  </div>
  <script src="js/admin.js?v=20260614-1"></script>
</body>
</html>
