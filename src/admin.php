<?php

declare(strict_types=1);

const AKINO_ADMIN_LOGIN_ATTEMPT_LIMIT = 8;
const AKINO_ADMIN_LOGIN_WINDOW_SECONDS = 900;
const AKINO_ADMIN_LOGIN_BLOCK_SECONDS = 1800;

function ensure_admin_support(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    $GLOBALS['akino_admin_available'] = true;

    try {
        ensure_movie_library();

        if (movie_fallback_mode()) {
            $GLOBALS['akino_admin_available'] = false;
            $bootstrapped = true;

            return;
        }

        ensure_playback_library();

        if (!akino_runtime_bootstrap_enabled()) {
            $schemaReady = akino_schema_ready([
                'users',
                'movies',
                'seasons',
                'episodes',
                'watch_progress',
                'admin_accounts',
                'admin_user_action_logs',
                'security_events',
                'security_backups',
                'security_file_integrity',
            ])
                && admin_column_exists('users', 'is_admin')
                && admin_column_exists('users', 'is_blocked')
                && admin_column_exists('users', 'blocked_at')
                && admin_column_exists('movies', 'director')
                && admin_column_exists('movies', 'media_path')
                && admin_column_exists('admin_accounts', 'role');

            if (!$schemaReady) {
                $GLOBALS['akino_admin_available'] = false;
            }

            $bootstrapped = true;

            return;
        }

        if (!admin_column_exists('users', 'is_admin')) {
            db()->exec('ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER avatar_path');
        }

        if (!admin_column_exists('users', 'is_blocked')) {
            db()->exec('ALTER TABLE users ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin');
        }

        if (!admin_column_exists('users', 'blocked_at')) {
            db()->exec('ALTER TABLE users ADD COLUMN blocked_at DATETIME DEFAULT NULL AFTER is_blocked');
        }

        if (!admin_column_exists('movies', 'director')) {
            db()->exec('ALTER TABLE movies ADD COLUMN director VARCHAR(160) DEFAULT NULL AFTER country');
        }

        if (!admin_column_exists('movies', 'media_path')) {
            db()->exec('ALTER TABLE movies ADD COLUMN media_path VARCHAR(255) DEFAULT NULL AFTER hero_path');
        }

        db()->exec(
            'CREATE TABLE IF NOT EXISTS admin_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                login VARCHAR(60) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                avatar_path VARCHAR(255) NOT NULL DEFAULT "img/avatars/default-neutral.svg",
                role VARCHAR(24) NOT NULL DEFAULT "owner",
                last_login_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY admin_accounts_login_unique (login)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!admin_column_exists('admin_accounts', 'role')) {
            db()->exec('ALTER TABLE admin_accounts ADD COLUMN role VARCHAR(24) NOT NULL DEFAULT "owner" AFTER avatar_path');
        }

        db()->exec(
            'CREATE TABLE IF NOT EXISTS admin_user_action_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_account_id BIGINT UNSIGNED DEFAULT NULL,
                user_id BIGINT UNSIGNED DEFAULT NULL,
                action_type VARCHAR(80) NOT NULL,
                action_summary VARCHAR(255) NOT NULL,
                details_json TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY admin_user_action_logs_user_created_index (user_id, created_at),
                KEY admin_user_action_logs_admin_created_index (admin_account_id, created_at),
                CONSTRAINT admin_user_action_logs_admin_account_id_foreign
                    FOREIGN KEY (admin_account_id) REFERENCES admin_accounts (id) ON DELETE SET NULL,
                CONSTRAINT admin_user_action_logs_user_id_foreign
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!ensure_security_center_support()) {
            throw new RuntimeException('Security center schema is unavailable.');
        }

        ensure_admin_default_account();
    } catch (Throwable) {
        $GLOBALS['akino_admin_available'] = false;
    }

    $bootstrapped = true;
}

function admin_support_available(): bool
{
    ensure_admin_support();

    return (bool) ($GLOBALS['akino_admin_available'] ?? false);
}

function admin_column_exists(string $table, string $column): bool
{
    $statement = db()->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (bool) $statement->fetch();
}

function admin_config(): array
{
    static $config;

    if ($config === null) {
        $config = require __DIR__ . '/../config/admin.php';
        $config['login'] = admin_normalize_login((string) ($config['login'] ?? 'akino_admin'));
        $config['password'] = (string) ($config['password'] ?? '');
        $config['display_name'] = trim((string) ($config['display_name'] ?? 'Администратор AKINO'));
        $config['avatar_path'] = akino_avatar_display_path($config['avatar_path'] ?? null);

        if ($config['login'] === '') {
            $config['login'] = 'akino_admin';
        }

    }

    return $config;
}

function admin_normalize_login(string $login): string
{
    $login = mb_strtolower(trim($login), 'UTF-8');
    $login = preg_replace('/[^a-z0-9_.-]+/u', '', $login) ?? '';

    return trim($login);
}

function admin_role_definitions(): array
{
    return [
        'owner' => [
            'label' => 'Администратор',
            'description' => 'Полный доступ, роли, безопасность и резервные копии.',
        ],
        'editor' => [
            'label' => 'Редактор',
            'description' => 'Управление фильмами, сериалами и сериями.',
        ],
        'moderator' => [
            'label' => 'Модератор',
            'description' => 'Работа с пользователями и подписками.',
        ],
        'auditor' => [
            'label' => 'Аудитор',
            'description' => 'Просмотр журнала безопасности без изменения данных.',
        ],
    ];
}

function admin_normalize_role(string $role): string
{
    $role = strtolower(trim($role));

    return isset(admin_role_definitions()[$role]) ? $role : 'auditor';
}

function admin_role_label(string $role): string
{
    $role = admin_normalize_role($role);

    return (string) admin_role_definitions()[$role]['label'];
}

function admin_role_permissions(string $role): array
{
    $permissions = [
        'owner' => ['*'],
        'editor' => ['account.self', 'content.manage', 'episodes.manage'],
        'moderator' => ['account.self', 'users.manage', 'subscriptions.view'],
        'auditor' => ['account.self', 'security.view'],
    ];

    return $permissions[admin_normalize_role($role)] ?? [];
}

function admin_can(?array $account, string $permission): bool
{
    if (!$account) {
        return false;
    }

    $permissions = admin_role_permissions((string) ($account['role'] ?? 'auditor'));

    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function admin_tab_permission(string $tab): string
{
    return match ($tab) {
        'content' => 'content.manage',
        'episodes' => 'episodes.manage',
        'users' => 'users.manage',
        'subscriptions' => 'subscriptions.view',
        'security', 'settings' => 'security.view',
        default => 'account.self',
    };
}

function admin_action_permission(string $action): string
{
    return match ($action) {
        'save_movie', 'delete_movie' => 'content.manage',
        'save_episode', 'delete_episode' => 'episodes.manage',
        'save_user_profile', 'toggle_user_block', 'extend_user_subscription', 'delete_user' => 'users.manage',
        'create_admin', 'change_admin_role' => 'admins.manage',
        'create_backup', 'verify_backup', 'record_integrity_baseline', 'scan_integrity' => 'security.manage',
        default => 'account.self',
    };
}

function admin_first_allowed_tab(array $account): string
{
    foreach (['content', 'episodes', 'users', 'subscriptions', 'security', 'admins', 'settings'] as $tab) {
        if (admin_can($account, admin_tab_permission($tab))) {
            return $tab;
        }
    }

    return 'admins';
}

function ensure_admin_default_account(): void
{
    $config = admin_config();
    $statement = db()->prepare(
        'SELECT *
         FROM admin_accounts
         WHERE login = :login
         LIMIT 1'
    );
    $statement->execute(['login' => $config['login']]);
    $account = $statement->fetch() ?: null;

    if (!$account) {
        if (
            strlen($config['password']) < 12
            || in_array(strtolower($config['password']), [
                'change-me',
                'password',
                'admin123',
                'replace-with-a-strong-password',
            ], true)
        ) {
            throw new RuntimeException('AKINO_ADMIN_PASSWORD must contain at least 12 characters and must not use a default value.');
        }

        $insert = db()->prepare(
            'INSERT INTO admin_accounts (
                login,
                password_hash,
                display_name,
                avatar_path,
                role,
                created_at,
                updated_at
            ) VALUES (
                :login,
                :password_hash,
                :display_name,
                :avatar_path,
                "owner",
                NOW(),
                NOW()
            )'
        );
        $insert->execute([
            'login' => $config['login'],
            'password_hash' => password_hash($config['password'], PASSWORD_DEFAULT),
            'display_name' => $config['display_name'],
            'avatar_path' => $config['avatar_path'],
        ]);
    }
}

function find_admin_account_by_id(int $accountId): ?array
{
    ensure_admin_support();

    if (!admin_support_available() || $accountId <= 0) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT *
         FROM admin_accounts
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $accountId]);
    $account = $statement->fetch();

    return $account ?: null;
}

function find_admin_account_by_login(string $login): ?array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        return null;
    }

    $login = admin_normalize_login($login);

    if ($login === '') {
        return null;
    }

    $statement = db()->prepare(
        'SELECT *
         FROM admin_accounts
         WHERE login = :login
         LIMIT 1'
    );
    $statement->execute(['login' => $login]);
    $account = $statement->fetch();

    return $account ?: null;
}

function admin_current_account(): ?array
{
    $accountId = $_SESSION['admin_account_id'] ?? null;

    if (!is_numeric($accountId)) {
        return null;
    }

    $account = find_admin_account_by_id((int) $accountId);

    if (!$account) {
        unset($_SESSION['admin_account_id']);

        return null;
    }

    return $account;
}

function admin_login_rate_identity(): string
{
    return request_client_ip();
}

function admin_login_rate_limited(): bool
{
    return security_rate_limit_exceeded(
        'admin_login',
        admin_login_rate_identity(),
        AKINO_ADMIN_LOGIN_ATTEMPT_LIMIT,
        AKINO_ADMIN_LOGIN_WINDOW_SECONDS
    );
}

function admin_login_attempt(string $login, string $password): bool
{
    ensure_admin_support();

    if (!admin_support_available() || admin_login_rate_limited()) {
        security_event_log('admin_login_blocked', 'warning', 'admin', null, admin_normalize_login($login));

        return false;
    }

    if (mb_strlen(trim($login), 'UTF-8') > 60 || strlen($password) > 128) {
        security_rate_limit_record_failure(
            'admin_login',
            admin_login_rate_identity(),
            AKINO_ADMIN_LOGIN_ATTEMPT_LIMIT,
            AKINO_ADMIN_LOGIN_WINDOW_SECONDS,
            AKINO_ADMIN_LOGIN_BLOCK_SECONDS
        );
        security_event_log('admin_login_failed', 'warning', 'admin', null, admin_normalize_login($login), [
            'reason' => 'invalid_input',
        ]);

        return false;
    }

    $account = find_admin_account_by_login($login);
    $passwordHash = $account
        ? (string) ($account['password_hash'] ?? '')
        : password_hash('akino-invalid-admin-password', PASSWORD_DEFAULT);
    $passwordValid = password_verify($password, $passwordHash);

    if (!$account || !$passwordValid) {
        security_rate_limit_record_failure(
            'admin_login',
            admin_login_rate_identity(),
            AKINO_ADMIN_LOGIN_ATTEMPT_LIMIT,
            AKINO_ADMIN_LOGIN_WINDOW_SECONDS,
            AKINO_ADMIN_LOGIN_BLOCK_SECONDS
        );
        security_event_log('admin_login_failed', 'warning', 'admin', null, admin_normalize_login($login));
        usleep(random_int(100000, 250000));

        return false;
    }

    security_rate_limit_clear('admin_login', admin_login_rate_identity());
    session_regenerate_id(true);
    akino_rotate_csrf_token();
    unset($_SESSION['akino_admin_csrf']);
    $_SESSION['admin_account_id'] = (int) $account['id'];

    db()->prepare(
        'UPDATE admin_accounts
         SET last_login_at = NOW(),
             updated_at = NOW()
         WHERE id = :id'
    )->execute([
        'id' => (int) $account['id'],
    ]);
    security_event_log(
        'admin_login_success',
        'info',
        'admin',
        (int) $account['id'],
        (string) ($account['login'] ?? '')
    );

    return true;
}

function admin_logout(): void
{
    $account = admin_current_account();

    if ($account) {
        security_event_log(
            'admin_logout',
            'info',
            'admin',
            (int) ($account['id'] ?? 0),
            (string) ($account['login'] ?? '')
        );
    }

    unset(
        $_SESSION['admin_account_id'],
        $_SESSION['akino_admin_csrf'],
        $_SESSION['akino_admin_flash']
    );

    session_regenerate_id(true);
    akino_rotate_csrf_token();
}

function fetch_admin_accounts(int $limit = 20): array
{
    ensure_admin_support();

    if (!admin_support_available() || $limit <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT *
         FROM admin_accounts
         ORDER BY updated_at DESC, id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function admin_login_in_use(string $login, ?int $ignoreId = null): bool
{
    $normalizedLogin = admin_normalize_login($login);

    if ($normalizedLogin === '') {
        return false;
    }

    $sql = 'SELECT id FROM admin_accounts WHERE login = :login';

    if ($ignoreId !== null && $ignoreId > 0) {
        $sql .= ' AND id <> :id';
    }

    $sql .= ' LIMIT 1';
    $statement = db()->prepare($sql);
    $statement->bindValue(':login', $normalizedLogin);

    if ($ignoreId !== null && $ignoreId > 0) {
        $statement->bindValue(':id', $ignoreId, PDO::PARAM_INT);
    }

    $statement->execute();

    return (bool) $statement->fetchColumn();
}

function admin_validate_new_account(array $input): array
{
    $errors = [];
    $login = admin_normalize_login((string) ($input['login'] ?? ''));
    $displayName = trim((string) ($input['display_name'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirm = (string) ($input['password_confirm'] ?? '');
    $role = strtolower(trim((string) ($input['role'] ?? '')));

    if ($login === '') {
        $errors[] = 'Укажите логин администратора латиницей.';
    } elseif (strlen($login) < 3) {
        $errors[] = 'Логин администратора должен быть не короче 3 символов.';
    } elseif (strlen($login) > 60) {
        $errors[] = 'Логин администратора не должен быть длиннее 60 символов.';
    } elseif (admin_login_in_use($login)) {
        $errors[] = 'Администратор с таким логином уже существует.';
    }

    if ($displayName === '') {
        $errors[] = 'Укажите отображаемое имя администратора.';
    } elseif (mb_strlen($displayName, 'UTF-8') > 120) {
        $errors[] = 'Имя администратора не должно быть длиннее 120 символов.';
    }

    if (strlen($password) < 12) {
        $errors[] = 'Пароль администратора должен быть не короче 12 символов.';
    } elseif (strlen($password) > 128) {
        $errors[] = 'Пароль администратора не должен быть длиннее 128 символов.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Подтверждение пароля не совпадает.';
    }

    if (!isset(admin_role_definitions()[$role])) {
        $errors[] = 'Выберите допустимую роль администратора.';
    }

    $avatarPath = trim((string) ($input['avatar_path'] ?? ''));

    if ($avatarPath !== '') {
        $avatarError = admin_movie_image_reference_error($avatarPath);

        if ($avatarError !== null) {
            $errors[] = $avatarError;
        }
    }

    return $errors;
}

function create_admin_account(array $input): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        throw new RuntimeException('Создание администратора сейчас недоступно.');
    }

    $login = admin_normalize_login((string) ($input['login'] ?? ''));
    $displayName = trim((string) ($input['display_name'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $role = admin_normalize_role((string) ($input['role'] ?? 'auditor'));
    $avatarPath = trim((string) ($input['avatar_path'] ?? ''));

    if ($avatarPath === '') {
        $avatarPath = akino_default_avatar_path();
    }

    $statement = db()->prepare(
        'INSERT INTO admin_accounts (
            login,
            password_hash,
            display_name,
            avatar_path,
            role,
            created_at,
            updated_at
        ) VALUES (
            :login,
            :password_hash,
            :display_name,
            :avatar_path,
            :role,
            NOW(),
            NOW()
        )'
    );
    $statement->execute([
        'login' => $login,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name' => $displayName,
        'avatar_path' => $avatarPath,
        'role' => $role,
    ]);

    $account = find_admin_account_by_id((int) db()->lastInsertId()) ?? [];
    security_event_log('admin_created', 'info', 'admin', (int) ($_SESSION['admin_account_id'] ?? 0), null, [
        'created_admin_id' => (int) ($account['id'] ?? 0),
        'created_login' => (string) ($account['login'] ?? ''),
        'role' => $role,
    ]);

    return $account;
}

function admin_change_account_role(int $accountId, string $role, int $actorId): array
{
    $account = find_admin_account_by_id($accountId);
    $role = strtolower(trim($role));

    if (!$account) {
        return ['Администратор для изменения роли не найден.'];
    }

    if (!isset(admin_role_definitions()[$role])) {
        return ['Выбрана недопустимая роль администратора.'];
    }

    if ($accountId === $actorId && (string) ($account['role'] ?? '') === 'owner' && $role !== 'owner') {
        return ['Нельзя понизить собственную роль администратора.'];
    }

    if ((string) ($account['role'] ?? '') === 'owner' && $role !== 'owner') {
        $ownerCount = (int) db()->query(
            'SELECT COUNT(*) FROM admin_accounts WHERE role = "owner"'
        )->fetchColumn();

        if ($ownerCount <= 1) {
            return ['В системе должен остаться хотя бы один администратор с полным доступом.'];
        }
    }

    db()->prepare(
        'UPDATE admin_accounts
         SET role = :role,
             updated_at = NOW()
         WHERE id = :id'
    )->execute([
        'id' => $accountId,
        'role' => $role,
    ]);

    security_event_log('admin_role_changed', 'warning', 'admin', $actorId, null, [
        'target_admin_id' => $accountId,
        'target_login' => (string) ($account['login'] ?? ''),
        'previous_role' => (string) ($account['role'] ?? ''),
        'new_role' => $role,
    ]);

    return [];
}

function admin_change_password(int $accountId, string $currentPassword, string $newPassword, string $newPasswordConfirm): array
{
    $errors = [];
    $account = find_admin_account_by_id($accountId);

    if (!$account) {
        $errors[] = 'Текущий администратор не найден.';

        return $errors;
    }

    if (
        strlen($currentPassword) > 128
        || !password_verify($currentPassword, (string) ($account['password_hash'] ?? ''))
    ) {
        $errors[] = 'Текущий пароль указан неверно.';
    }

    if (strlen($newPassword) < 12) {
        $errors[] = 'Новый пароль должен быть не короче 12 символов.';
    } elseif (strlen($newPassword) > 128) {
        $errors[] = 'Новый пароль не должен быть длиннее 128 символов.';
    }

    if ($newPassword !== $newPasswordConfirm) {
        $errors[] = 'Подтверждение нового пароля не совпадает.';
    }

    if (!$errors) {
        db()->prepare(
            'UPDATE admin_accounts
             SET password_hash = :password_hash,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $accountId,
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);
        security_event_log(
            'admin_password_changed',
            'warning',
            'admin',
            $accountId,
            (string) ($account['login'] ?? '')
        );
    }

    return $errors;
}

function require_admin_panel_user(): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        throw new RuntimeException('Панель администратора недоступна без активного подключения к базе данных.');
    }

    $account = admin_current_account();

    if (!$account) {
        header('Location: Admin_Login.php');
        exit;
    }

    return $account;
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['akino_admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function admin_pull_flash(): ?array
{
    $flash = $_SESSION['akino_admin_flash'] ?? null;
    unset($_SESSION['akino_admin_flash']);

    return is_array($flash) ? $flash : null;
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['akino_admin_csrf'])) {
        $_SESSION['akino_admin_csrf'] = bin2hex(random_bytes(24));
    }

    return (string) $_SESSION['akino_admin_csrf'];
}

function admin_verify_csrf_token(?string $token): bool
{
    $sessionToken = (string) ($_SESSION['akino_admin_csrf'] ?? '');

    return $sessionToken !== '' && is_string($token) && hash_equals($sessionToken, $token);
}

function admin_canonical_genre(string $genre): string
{
    $genre = trim($genre);
    $aliases = [
        'хоррор' => 'Ужасы',
        'приключение' => 'Приключения',
    ];
    $key = mb_strtolower($genre, 'UTF-8');

    return $aliases[$key] ?? $genre;
}

function admin_genre_options(): array
{
    $options = [
        'Боевик',
        'Комедия',
        'Драма',
        'Триллер',
        'Ужасы',
        'Фантастика',
        'Романтика',
        'Детектив',
        'Приключения',
        'Семейный',
        'Криминал',
        'Анимация',
        'Фэнтези',
        'Мистика',
        'Аниме',
    ];

    foreach (fetch_catalog_filter_options('movie')['genres'] as $genre) {
        foreach (admin_split_genres((string) $genre) as $genrePart) {
            $options[] = admin_canonical_genre($genrePart);
        }
    }

    foreach (fetch_catalog_filter_options('series')['genres'] as $genre) {
        foreach (admin_split_genres((string) $genre) as $genrePart) {
            $options[] = admin_canonical_genre($genrePart);
        }
    }

    $options = array_values(array_unique(array_filter(array_map('trim', $options))));
    sort($options, SORT_NATURAL | SORT_FLAG_CASE);

    return $options;
}

function admin_movie_form_defaults(): array
{
    return [
        'id' => 0,
        'title' => '',
        'contentType' => 'movie',
        'posterUrl' => '',
        'releaseYear' => (int) date('Y'),
        'director' => '',
        'rating' => '7.0',
        'mediaPath' => '',
        'durationMinutes' => '',
        'description' => '',
        'genres' => [],
        'country' => '',
        'ageRating' => '16+',
        'homeSections' => [],
    ];
}

function admin_home_section_definitions(): array
{
    return [
        'slider' => [
            'label' => 'Главный film-track',
            'hint' => 'Большая верхняя дорожка с широкими карточками.',
        ],
        'recommended' => [
            'label' => 'Рекомендуем',
            'hint' => 'Первый блок мини-карточек под слайдером.',
        ],
        'new' => [
            'label' => 'Новое',
            'hint' => 'Подборка свежих релизов на главной странице.',
        ],
        'editors_choice' => [
            'label' => 'Выбор редакции',
            'hint' => 'Большие постеры в редакционном блоке.',
        ],
        'for_you' => [
            'label' => 'Для вас',
            'hint' => 'Нижняя персональная дорожка на главной.',
        ],
    ];
}

function admin_normalize_home_sections_input($value): array
{
    if (is_string($value)) {
        $value = [$value];
    }

    if (!is_array($value)) {
        return [];
    }

    $allowed = array_fill_keys(array_keys(admin_home_section_definitions()), true);
    $sections = [];

    foreach ($value as $sectionKey) {
        $sectionKey = trim((string) $sectionKey);

        if ($sectionKey !== '' && isset($allowed[$sectionKey])) {
            $sections[$sectionKey] = true;
        }
    }

    return array_keys($sections);
}

function admin_home_sections_from_movie(array $movie): array
{
    $sections = [];

    foreach (admin_home_section_definitions() as $sectionKey => $_meta) {
        $column = home_section_column($sectionKey);

        if (($movie[$column] ?? null) !== null) {
            $sections[] = $sectionKey;
        }
    }

    return $sections;
}

function admin_split_genres(string $value): array
{
    return movie_split_genres($value);
}

function admin_duration_minutes_from_text(?string $value): int
{
    $value = trim((string) $value);

    if ($value === '') {
        return 0;
    }

    $hours = 0;
    $minutes = 0;

    if (preg_match('/(\d+)\s*ч/u', $value, $matches)) {
        $hours = (int) $matches[1];
    }

    if (preg_match('/(\d+)\s*мин/u', $value, $matches)) {
        $minutes = (int) $matches[1];
    }

    if ($hours === 0 && $minutes === 0 && preg_match('/(\d+)/', $value, $matches)) {
        $minutes = (int) $matches[1];
    }

    return max(0, ($hours * 60) + $minutes);
}

function admin_duration_text_from_minutes(int $minutes, string $contentType): ?string
{
    $minutes = max(0, $minutes);

    if ($minutes === 0) {
        return $contentType === 'series' ? '1 сезон' : null;
    }

    if ($contentType === 'series') {
        return $minutes . ' мин / серия';
    }

    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    if ($hours > 0) {
        return $rest > 0 ? sprintf('%d ч %02d мин', $hours, $rest) : sprintf('%d ч', $hours);
    }

    return $minutes . ' мин';
}

function admin_movie_form_from_movie(array $movie): array
{
    return [
        'id' => (int) ($movie['id'] ?? 0),
        'title' => (string) ($movie['title'] ?? ''),
        'contentType' => (string) ($movie['content_type'] ?? 'movie'),
        'posterUrl' => (string) ($movie['poster_path'] ?? ''),
        'releaseYear' => (int) ($movie['release_year'] ?? date('Y')),
        'director' => (string) ($movie['director'] ?? ''),
        'rating' => (string) number_format((float) ($movie['rating'] ?? 0), 1, '.', ''),
        'mediaPath' => (string) ($movie['media_path'] ?? ''),
        'durationMinutes' => (string) admin_duration_minutes_from_text((string) ($movie['duration_text'] ?? '')),
        'description' => (string) ($movie['description'] ?? ''),
        'genres' => admin_normalize_genres_input(admin_split_genres((string) ($movie['genre'] ?? ''))),
        'country' => (string) ($movie['country'] ?? ''),
        'ageRating' => (string) ($movie['age_rating'] ?? '16+'),
        'homeSections' => admin_home_sections_from_movie($movie),
    ];
}

function admin_normalize_genres_input($value): array
{
    if (is_string($value)) {
        $value = [$value];
    }

    if (!is_array($value)) {
        return [];
    }

    $genres = [];

    foreach ($value as $genreValue) {
        foreach (movie_split_genres((string) $genreValue) as $genre) {
            $genre = admin_canonical_genre($genre);
            $key = mb_strtolower($genre, 'UTF-8');

            if (!isset($genres[$key])) {
                $genres[$key] = $genre;
            }
        }
    }

    return array_values($genres);
}

function admin_movie_form_from_input(array $input): array
{
    $defaults = admin_movie_form_defaults();
    $contentType = (string) ($input['content_type'] ?? $defaults['contentType']);

    return [
        'id' => max(0, (int) ($input['movie_id'] ?? 0)),
        'title' => trim((string) ($input['title'] ?? '')),
        'contentType' => in_array($contentType, ['movie', 'series'], true) ? $contentType : $defaults['contentType'],
        'posterUrl' => trim((string) ($input['poster_url'] ?? '')),
        'releaseYear' => (int) ($input['release_year'] ?? 0),
        'director' => trim((string) ($input['director'] ?? '')),
        'rating' => trim((string) ($input['rating'] ?? '')),
        'mediaPath' => trim((string) ($input['media_path'] ?? '')),
        'durationMinutes' => trim((string) ($input['duration_minutes'] ?? '')),
        'description' => trim((string) ($input['description'] ?? '')),
        'genres' => admin_normalize_genres_input($input['genres'] ?? []),
        'country' => trim((string) ($input['country'] ?? '')),
        'ageRating' => trim((string) ($input['age_rating'] ?? '16+')),
        'homeSections' => admin_normalize_home_sections_input($input['home_sections'] ?? []),
    ];
}

function admin_movie_image_reference_error(string $reference): ?string
{
    if (strlen($reference) > 255 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
        return 'Ссылка на изображение слишком длинная или содержит недопустимые символы.';
    }

    if (preg_match('~^https?://~i', $reference)) {
        if (filter_var($reference, FILTER_VALIDATE_URL) === false) {
            return 'Укажите корректный URL изображения.';
        }

        if (akino_is_production() && str_starts_with(strtolower($reference), 'http://')) {
            return 'В рабочем окружении внешние изображения должны использовать HTTPS.';
        }

        return null;
    }

    $relativePath = ltrim(str_replace('\\', '/', $reference), '/');

    if ($relativePath === '' || str_contains($relativePath, '../')) {
        return 'Укажите корректный локальный путь к изображению.';
    }

    $publicRoot = realpath(__DIR__ . '/../public');
    $imagePath = realpath(__DIR__ . '/../public/' . $relativePath);

    if (
        $publicRoot === false
        || $imagePath === false
        || !is_file($imagePath)
        || !str_starts_with(strtolower($imagePath), strtolower($publicRoot . DIRECTORY_SEPARATOR))
        || @getimagesize($imagePath) === false
    ) {
        return 'Локальный файл изображения не найден или имеет неподдерживаемый формат.';
    }

    return null;
}

function admin_media_reference_error(string $reference): ?string
{
    if ($reference === '') {
        return null;
    }

    if (strlen($reference) > 255 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
        return 'Ссылка на медиафайл слишком длинная или содержит недопустимые символы.';
    }

    if (preg_match('~^https?://~i', $reference)) {
        if (filter_var($reference, FILTER_VALIDATE_URL) === false) {
            return 'Укажите корректный URL медиафайла.';
        }

        if (akino_is_production() && str_starts_with(strtolower($reference), 'http://')) {
            return 'В рабочем окружении внешние медиафайлы должны использовать HTTPS.';
        }

        return null;
    }

    $relativePath = ltrim(str_replace('\\', '/', $reference), '/');

    if ($relativePath === '' || str_contains($relativePath, '../')) {
        return 'Укажите корректный локальный путь к медиафайлу.';
    }

    $publicRoot = realpath(__DIR__ . '/../public');
    $mediaPath = realpath(__DIR__ . '/../public/' . $relativePath);

    if (
        $publicRoot === false
        || $mediaPath === false
        || !is_file($mediaPath)
        || !str_starts_with(strtolower($mediaPath), strtolower($publicRoot . DIRECTORY_SEPARATOR))
    ) {
        return 'Локальный медиафайл не найден.';
    }

    return null;
}

function admin_validate_movie_form(array $form, array $genreOptions): array
{
    $errors = [];
    $currentYear = (int) date('Y') + 2;
    $allowedGenres = array_fill_keys($genreOptions, true);
    $allowedHomeSections = array_fill_keys(array_keys(admin_home_section_definitions()), true);
    $rating = is_numeric($form['rating']) ? (float) $form['rating'] : null;
    $duration = $form['durationMinutes'] !== '' && is_numeric($form['durationMinutes'])
        ? (int) $form['durationMinutes']
        : 0;

    if ((int) ($form['id'] ?? 0) > 0 && fetch_admin_movie_by_id((int) $form['id']) === null) {
        $errors[] = 'Карточка для редактирования не найдена. Обновите список контента.';
    }

    if ($form['title'] === '') {
        $errors[] = 'Укажите название контента.';
    } elseif (mb_strlen($form['title'], 'UTF-8') > 160) {
        $errors[] = 'Название контента не должно быть длиннее 160 символов.';
    }

    if (!in_array($form['contentType'], ['movie', 'series'], true)) {
        $errors[] = 'Выберите корректный тип контента.';
    }

    if ($form['posterUrl'] === '') {
        $errors[] = 'Укажите URL постера или локальный путь к изображению.';
    } else {
        $imageError = admin_movie_image_reference_error($form['posterUrl']);

        if ($imageError !== null) {
            $errors[] = $imageError;
        }
    }

    if ($form['releaseYear'] < 1900 || $form['releaseYear'] > $currentYear) {
        $errors[] = 'Укажите корректный год выпуска.';
    }

    if ($rating === null || $rating < 0 || $rating > 10) {
        $errors[] = 'Рейтинг должен быть в диапазоне от 0 до 10.';
    }

    if (mb_strlen($form['director'], 'UTF-8') > 160) {
        $errors[] = 'Имя режиссёра не должно быть длиннее 160 символов.';
    }

    $mediaError = admin_media_reference_error($form['mediaPath']);

    if ($mediaError !== null) {
        $errors[] = $mediaError;
    }

    if ($duration < 0) {
        $errors[] = 'Длительность не может быть отрицательной.';
    }

    if ($form['description'] === '') {
        $errors[] = 'Добавьте описание контента.';
    } elseif (mb_strlen($form['description'], 'UTF-8') > 10000) {
        $errors[] = 'Описание контента не должно быть длиннее 10000 символов.';
    }

    if (mb_strlen($form['country'], 'UTF-8') > 120) {
        $errors[] = 'Название страны не должно быть длиннее 120 символов.';
    }

    if (mb_strlen($form['ageRating'], 'UTF-8') > 20) {
        $errors[] = 'Возрастной рейтинг не должен быть длиннее 20 символов.';
    }

    if (!$form['genres']) {
        $errors[] = 'Выберите хотя бы один жанр.';
    } else {
        foreach ($form['genres'] as $genre) {
            if (!isset($allowedGenres[$genre])) {
                $errors[] = 'Один из выбранных жанров не поддерживается.';
                break;
            }
        }
    }

    foreach ($form['homeSections'] as $sectionKey) {
        if (!isset($allowedHomeSections[$sectionKey])) {
            $errors[] = 'Одна из выбранных секций главной больше не поддерживается.';
            break;
        }
    }

    return $errors;
}

function admin_slugify(string $value): string
{
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'cz', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shh', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'akino-content';
}

function admin_slug_exists(string $slug, ?int $ignoreId = null): bool
{
    $sql = 'SELECT id FROM movies WHERE slug = :slug';

    if ($ignoreId !== null && $ignoreId > 0) {
        $sql .= ' AND id <> :id';
    }

    $sql .= ' LIMIT 1';
    $statement = db()->prepare($sql);
    $statement->bindValue(':slug', $slug);

    if ($ignoreId !== null && $ignoreId > 0) {
        $statement->bindValue(':id', $ignoreId, PDO::PARAM_INT);
    }

    $statement->execute();

    return (bool) $statement->fetchColumn();
}

function admin_unique_slug(string $title, ?int $ignoreId = null): string
{
    $base = admin_slugify($title);
    $slug = $base;
    $suffix = 2;

    while (admin_slug_exists($slug, $ignoreId)) {
        $slug = $base . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}

function admin_next_catalog_order(): int
{
    return (int) db()->query('SELECT COALESCE(MAX(catalog_order), 0) + 10 FROM movies')->fetchColumn();
}

function admin_next_section_order(string $column): int
{
    $allowed = ['slider_order', 'recommended_order', 'new_order', 'editors_choice_order', 'for_you_order'];

    if (!in_array($column, $allowed, true)) {
        throw new InvalidArgumentException('Недопустимое поле сортировки.');
    }

    return (int) db()->query(sprintf('SELECT COALESCE(MAX(%s), 0) + 1 FROM movies WHERE %s IS NOT NULL', $column, $column))->fetchColumn();
}

function fetch_admin_movie_by_id(int $movieId): ?array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        return null;
    }

    return find_movie_by_id($movieId);
}

function save_admin_movie(array $form, ?int $movieId = null): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        throw new RuntimeException('Сохранение контента сейчас недоступно.');
    }

    $existingMovie = $movieId !== null && $movieId > 0 ? fetch_admin_movie_by_id($movieId) : null;

    if ($movieId !== null && $movieId > 0 && $existingMovie === null) {
        throw new RuntimeException('Карточка для редактирования не найдена.');
    }

    $slug = admin_unique_slug($form['title'], $existingMovie ? (int) $existingMovie['id'] : null);
    $durationMinutes = $form['durationMinutes'] !== '' ? max(0, (int) $form['durationMinutes']) : 0;
    $durationText = admin_duration_text_from_minutes($durationMinutes, $form['contentType']);
    $rating = round((float) $form['rating'], 1);
    $genre = movie_join_genres($form['genres']);
    $sectionOrders = [];

    foreach (admin_home_section_definitions() as $sectionKey => $_meta) {
        $column = home_section_column($sectionKey);
        $selected = in_array($sectionKey, $form['homeSections'], true);
        $currentOrder = $existingMovie[$column] ?? null;

        if (!$selected) {
            $sectionOrders[$column] = null;
            continue;
        }

        if ($currentOrder !== null) {
            $sectionOrders[$column] = (int) $currentOrder;
            continue;
        }

        $sectionOrders[$column] = admin_next_section_order($column);
    }

    $payload = [
        'slug' => $slug,
        'title' => $form['title'],
        'content_type' => $form['contentType'],
        'release_year' => (int) $form['releaseYear'],
        'rating' => $rating,
        'genre' => $genre,
        'country' => $form['country'] !== '' ? $form['country'] : null,
        'director' => $form['director'] !== '' ? $form['director'] : null,
        'duration_text' => $durationText,
        'age_rating' => $form['ageRating'] !== '' ? $form['ageRating'] : null,
        'description' => $form['description'],
        'poster_path' => $form['posterUrl'],
        'card_path' => $form['posterUrl'],
        'hero_path' => $form['posterUrl'],
        'media_path' => $form['mediaPath'] !== '' ? $form['mediaPath'] : null,
    ] + $sectionOrders;

    if ($existingMovie) {
        $statement = db()->prepare(
            'UPDATE movies
             SET slug = :slug,
                 title = :title,
                 content_type = :content_type,
                 release_year = :release_year,
                 rating = :rating,
                 genre = :genre,
                 country = :country,
                 director = :director,
                 duration_text = :duration_text,
                 age_rating = :age_rating,
                 description = :description,
                 poster_path = :poster_path,
                 card_path = :card_path,
                 hero_path = :hero_path,
                 media_path = :media_path,
                 slider_order = :slider_order,
                 recommended_order = :recommended_order,
                 new_order = :new_order,
                 editors_choice_order = :editors_choice_order,
                 for_you_order = :for_you_order,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $payload['id'] = (int) $existingMovie['id'];
        $statement->execute($payload);

        return fetch_admin_movie_by_id((int) $existingMovie['id']) ?? [];
    }

    $statement = db()->prepare(
        'INSERT INTO movies (
            slug,
            title,
            content_type,
            release_year,
            rating,
            genre,
            country,
            director,
            duration_text,
            age_rating,
            description,
            poster_path,
            card_path,
            hero_path,
            media_path,
            slider_order,
            recommended_order,
            new_order,
            editors_choice_order,
            for_you_order,
            catalog_order,
            created_at,
            updated_at
        ) VALUES (
            :slug,
            :title,
            :content_type,
            :release_year,
            :rating,
            :genre,
            :country,
            :director,
            :duration_text,
            :age_rating,
            :description,
            :poster_path,
            :card_path,
            :hero_path,
            :media_path,
            :slider_order,
            :recommended_order,
            :new_order,
            :editors_choice_order,
            :for_you_order,
            :catalog_order,
            NOW(),
            NOW()
        )'
    );
    $payload['catalog_order'] = admin_next_catalog_order();
    $statement->execute($payload);

    return fetch_admin_movie_by_id((int) db()->lastInsertId()) ?? [];
}

function delete_admin_movie(int $movieId): bool
{
    ensure_admin_support();

    if (!admin_support_available() || $movieId <= 0) {
        return false;
    }

    $pdo = db();
    $startedTransaction = !$pdo->inTransaction();

    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $cleanupTables = [
            'movie_favorites',
            'watch_history',
            'watch_progress',
        ];

        foreach ($cleanupTables as $table) {
            $statement = $pdo->prepare(sprintf('DELETE FROM %s WHERE movie_id = :id', $table));
            $statement->execute(['id' => $movieId]);
        }

        $statement = $pdo->prepare('DELETE FROM movies WHERE id = :id');
        $statement->execute(['id' => $movieId]);
        $deleted = $statement->rowCount() > 0;

        if ($startedTransaction) {
            $pdo->commit();
        }

        return $deleted;
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function fetch_admin_content_list(int $limit = 24): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT *
         FROM movies
         ORDER BY updated_at DESC, id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function fetch_admin_series_options(): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        return [];
    }

    $statement = db()->query(
        'SELECT id, title, card_path, hero_path
         FROM movies
         WHERE content_type = "series"
         ORDER BY title ASC, id ASC'
    );

    return $statement->fetchAll();
}

function admin_episode_form_defaults(): array
{
    return [
        'id' => 0,
        'seriesId' => 0,
        'seasonNumber' => 1,
        'seasonTitle' => '',
        'seasonDescription' => '',
        'episodeNumber' => 1,
        'title' => '',
        'description' => '',
        'durationMinutes' => '',
        'videoPath' => '',
        'previewPath' => '',
        'sortOrder' => '',
    ];
}

function admin_episode_form_from_input(array $input): array
{
    $defaults = admin_episode_form_defaults();

    return [
        'id' => max(0, (int) ($input['episode_id'] ?? $defaults['id'])),
        'seriesId' => max(0, (int) ($input['series_id'] ?? $defaults['seriesId'])),
        'seasonNumber' => max(1, (int) ($input['season_number'] ?? $defaults['seasonNumber'])),
        'seasonTitle' => trim((string) ($input['season_title'] ?? $defaults['seasonTitle'])),
        'seasonDescription' => trim((string) ($input['season_description'] ?? $defaults['seasonDescription'])),
        'episodeNumber' => max(1, (int) ($input['episode_number'] ?? $defaults['episodeNumber'])),
        'title' => trim((string) ($input['episode_title'] ?? $defaults['title'])),
        'description' => trim((string) ($input['episode_description'] ?? $defaults['description'])),
        'durationMinutes' => trim((string) ($input['duration_minutes'] ?? $defaults['durationMinutes'])),
        'videoPath' => trim((string) ($input['video_path'] ?? $defaults['videoPath'])),
        'previewPath' => trim((string) ($input['preview_path'] ?? $defaults['previewPath'])),
        'sortOrder' => trim((string) ($input['sort_order'] ?? $defaults['sortOrder'])),
    ];
}

function admin_episode_form_from_episode(array $episode): array
{
    $durationSeconds = (int) ($episode['duration_seconds'] ?? 0);

    return [
        'id' => (int) ($episode['id'] ?? 0),
        'seriesId' => (int) ($episode['series_id'] ?? 0),
        'seasonNumber' => (int) ($episode['season_number'] ?? 1),
        'seasonTitle' => (string) ($episode['season_title'] ?? ''),
        'seasonDescription' => (string) ($episode['season_description'] ?? ''),
        'episodeNumber' => (int) ($episode['episode_number'] ?? 1),
        'title' => (string) ($episode['title'] ?? ''),
        'description' => (string) ($episode['description'] ?? ''),
        'durationMinutes' => $durationSeconds > 0 ? (string) max(1, (int) ceil($durationSeconds / 60)) : '',
        'videoPath' => (string) ($episode['video_path'] ?? ''),
        'previewPath' => (string) ($episode['preview_path'] ?? ''),
        'sortOrder' => (string) ($episode['sort_order'] ?? ''),
    ];
}

function admin_fetch_episode_by_id(int $episodeId): ?array
{
    ensure_admin_support();

    if (!admin_support_available() || $episodeId <= 0) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT
            e.*,
            s.series_id,
            s.season_number,
            s.title AS season_title,
            s.description AS season_description,
            m.title AS series_title
         FROM episodes e
         INNER JOIN seasons s ON s.id = e.season_id
         INNER JOIN movies m ON m.id = s.series_id
         WHERE e.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $episodeId]);
    $episode = $statement->fetch();

    return $episode ?: null;
}

function fetch_admin_episode_list(int $limit = 50): array
{
    ensure_admin_support();

    if (!admin_support_available() || $limit <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT
            e.*,
            s.series_id,
            s.season_number,
            s.title AS season_title,
            m.title AS series_title,
            m.content_type AS series_content_type,
            m.card_path AS series_card_path
         FROM episodes e
         INNER JOIN seasons s ON s.id = e.season_id
         INNER JOIN movies m ON m.id = s.series_id
         ORDER BY m.title ASC, s.season_number ASC, e.episode_number ASC, e.id ASC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function admin_episode_number_in_use(
    int $seriesId,
    int $seasonNumber,
    int $episodeNumber,
    ?int $ignoreEpisodeId = null
): bool {
    if ($seriesId <= 0 || $seasonNumber <= 0 || $episodeNumber <= 0) {
        return false;
    }

    $sql = 'SELECT e.id
            FROM episodes e
            INNER JOIN seasons s ON s.id = e.season_id
            WHERE s.series_id = :series_id
              AND s.season_number = :season_number
              AND e.episode_number = :episode_number';

    if ($ignoreEpisodeId !== null && $ignoreEpisodeId > 0) {
        $sql .= ' AND e.id <> :ignore_episode_id';
    }

    $sql .= ' LIMIT 1';
    $statement = db()->prepare($sql);
    $statement->bindValue(':series_id', $seriesId, PDO::PARAM_INT);
    $statement->bindValue(':season_number', $seasonNumber, PDO::PARAM_INT);
    $statement->bindValue(':episode_number', $episodeNumber, PDO::PARAM_INT);

    if ($ignoreEpisodeId !== null && $ignoreEpisodeId > 0) {
        $statement->bindValue(':ignore_episode_id', $ignoreEpisodeId, PDO::PARAM_INT);
    }

    $statement->execute();

    return (bool) $statement->fetchColumn();
}

function admin_validate_episode_form(array $form): array
{
    $errors = [];
    $episodeId = (int) ($form['id'] ?? 0);
    $seriesId = (int) ($form['seriesId'] ?? 0);
    $series = $seriesId > 0 ? find_movie_by_id($seriesId) : null;
    $seasonNumber = (int) ($form['seasonNumber'] ?? 0);
    $episodeNumber = (int) ($form['episodeNumber'] ?? 0);
    $duration = trim((string) ($form['durationMinutes'] ?? ''));
    $sortOrder = trim((string) ($form['sortOrder'] ?? ''));

    if ($episodeId > 0 && admin_fetch_episode_by_id($episodeId) === null) {
        $errors[] = 'Серия для редактирования не найдена. Обновите библиотеку серий.';
    }

    if (!$series || ($series['content_type'] ?? '') !== 'series') {
        $errors[] = 'Выберите сериал для серии.';
    }

    if ($seasonNumber < 1) {
        $errors[] = 'Номер сезона должен быть больше нуля.';
    }

    if (trim((string) ($form['seasonTitle'] ?? '')) === '') {
        $errors[] = 'Укажите название сезона.';
    } elseif (mb_strlen((string) $form['seasonTitle'], 'UTF-8') > 160) {
        $errors[] = 'Название сезона не должно быть длиннее 160 символов.';
    }

    if ($episodeNumber < 1) {
        $errors[] = 'Номер серии должен быть больше нуля.';
    }

    if (trim((string) ($form['title'] ?? '')) === '') {
        $errors[] = 'Укажите название серии.';
    } elseif (mb_strlen((string) $form['title'], 'UTF-8') > 160) {
        $errors[] = 'Название серии не должно быть длиннее 160 символов.';
    }

    if (trim((string) ($form['videoPath'] ?? '')) === '') {
        $errors[] = 'Укажите URL файла или потока серии.';
    } else {
        $videoError = admin_media_reference_error((string) $form['videoPath']);

        if ($videoError !== null) {
            $errors[] = $videoError;
        }
    }

    if (trim((string) ($form['previewPath'] ?? '')) !== '') {
        $previewError = admin_movie_image_reference_error((string) $form['previewPath']);

        if ($previewError !== null) {
            $errors[] = $previewError;
        }
    }

    if (mb_strlen((string) ($form['seasonDescription'] ?? ''), 'UTF-8') > 10000) {
        $errors[] = 'Описание сезона не должно быть длиннее 10000 символов.';
    }

    if (mb_strlen((string) ($form['description'] ?? ''), 'UTF-8') > 10000) {
        $errors[] = 'Описание серии не должно быть длиннее 10000 символов.';
    }

    if ($duration !== '' && (!is_numeric($duration) || (int) $duration < 0)) {
        $errors[] = 'Длительность серии должна быть числом минут.';
    }

    if ($sortOrder !== '' && (!is_numeric($sortOrder) || (int) $sortOrder < 0)) {
        $errors[] = 'Порядок сортировки должен быть положительным числом.';
    }

    if (
        $series
        && ($series['content_type'] ?? '') === 'series'
        && $seasonNumber > 0
        && $episodeNumber > 0
        && admin_episode_number_in_use($seriesId, $seasonNumber, $episodeNumber, $episodeId ?: null)
    ) {
        $errors[] = 'В этом сезоне уже существует серия с таким номером.';
    }

    return $errors;
}

function admin_save_episode(array $form, ?int $episodeId = null): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        throw new RuntimeException('Управление сериями недоступно без подключённой базы данных.');
    }

    $seriesId = (int) $form['seriesId'];
    $series = find_movie_by_id($seriesId);

    if (!$series || ($series['content_type'] ?? '') !== 'series') {
        throw new RuntimeException('Сериал для серии не найден.');
    }

    $seasonNumber = max(1, (int) $form['seasonNumber']);
    $episodeNumber = max(1, (int) $form['episodeNumber']);
    $existingEpisode = null;

    if ($episodeId !== null && $episodeId > 0) {
        $existingEpisode = admin_fetch_episode_by_id($episodeId);

        if (!$existingEpisode) {
            throw new RuntimeException('Серия для редактирования не найдена.');
        }
    }

    if (admin_episode_number_in_use($seriesId, $seasonNumber, $episodeNumber, $episodeId)) {
        throw new RuntimeException('В этом сезоне уже существует серия с таким номером.');
    }

    $durationMinutes = trim((string) ($form['durationMinutes'] ?? ''));
    $durationSeconds = $durationMinutes !== '' ? max(0, (int) $durationMinutes) * 60 : 0;
    $previewPath = trim((string) ($form['previewPath'] ?? ''));

    if ($previewPath === '') {
        $previewPath = (string) ($series['card_path'] ?: $series['poster_path']);
    }

    $sortOrder = trim((string) ($form['sortOrder'] ?? ''));
    $sortOrderValue = $sortOrder !== '' ? max(0, (int) $sortOrder) : $episodeNumber * 10;
    $db = db();
    $db->beginTransaction();

    try {
        $seasonStatement = $db->prepare(
            'SELECT *
             FROM seasons
             WHERE series_id = :series_id AND season_number = :season_number
             LIMIT 1
             FOR UPDATE'
        );
        $seasonStatement->execute([
            'series_id' => $seriesId,
            'season_number' => $seasonNumber,
        ]);
        $season = $seasonStatement->fetch() ?: null;

        if ($season) {
            $updateSeason = $db->prepare(
                'UPDATE seasons
                 SET title = :title,
                     description = :description,
                     poster_path = :poster_path,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $updateSeason->execute([
                'id' => (int) $season['id'],
                'title' => (string) $form['seasonTitle'],
                'description' => (string) ($form['seasonDescription'] ?? ''),
                'poster_path' => $previewPath,
            ]);
            $seasonId = (int) $season['id'];
        } else {
            $insertSeason = $db->prepare(
                'INSERT INTO seasons (
                    series_id,
                    season_number,
                    title,
                    description,
                    poster_path,
                    sort_order,
                    created_at,
                    updated_at
                ) VALUES (
                    :series_id,
                    :season_number,
                    :title,
                    :description,
                    :poster_path,
                    :sort_order,
                    NOW(),
                    NOW()
                )'
            );
            $insertSeason->execute([
                'series_id' => $seriesId,
                'season_number' => $seasonNumber,
                'title' => (string) $form['seasonTitle'],
                'description' => (string) ($form['seasonDescription'] ?? ''),
                'poster_path' => $previewPath,
                'sort_order' => $seasonNumber * 10,
            ]);
            $seasonId = (int) $db->lastInsertId();
        }

        if ($episodeId !== null && $episodeId > 0) {
            $updateEpisode = $db->prepare(
                'UPDATE episodes
                 SET season_id = :season_id,
                     episode_number = :episode_number,
                     title = :title,
                     description = :description,
                     duration_seconds = :duration_seconds,
                     video_path = :video_path,
                     preview_path = :preview_path,
                     sort_order = :sort_order,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $updateEpisode->execute([
                'id' => $episodeId,
                'season_id' => $seasonId,
                'episode_number' => $episodeNumber,
                'title' => (string) $form['title'],
                'description' => (string) ($form['description'] ?? ''),
                'duration_seconds' => $durationSeconds,
                'video_path' => (string) $form['videoPath'],
                'preview_path' => $previewPath,
                'sort_order' => $sortOrderValue,
            ]);
            $savedEpisodeId = $episodeId;
        } else {
            $insertEpisode = $db->prepare(
                'INSERT INTO episodes (
                    season_id,
                    episode_number,
                    title,
                    description,
                    duration_seconds,
                    video_path,
                    preview_path,
                    sort_order,
                    created_at,
                    updated_at
                ) VALUES (
                    :season_id,
                    :episode_number,
                    :title,
                    :description,
                    :duration_seconds,
                    :video_path,
                    :preview_path,
                    :sort_order,
                    NOW(),
                    NOW()
                )'
            );
            $insertEpisode->execute([
                'season_id' => $seasonId,
                'episode_number' => $episodeNumber,
                'title' => (string) $form['title'],
                'description' => (string) ($form['description'] ?? ''),
                'duration_seconds' => $durationSeconds,
                'video_path' => (string) $form['videoPath'],
                'preview_path' => $previewPath,
                'sort_order' => $sortOrderValue,
            ]);
            $savedEpisodeId = (int) $db->lastInsertId();
        }

        $db->commit();

        return admin_fetch_episode_by_id($savedEpisodeId) ?? [];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $exception;
    }
}

function admin_delete_episode(int $episodeId): void
{
    ensure_admin_support();

    if (!admin_support_available() || $episodeId <= 0) {
        return;
    }

    $statement = db()->prepare('DELETE FROM episodes WHERE id = :id');
    $statement->execute(['id' => $episodeId]);
}

function admin_format_datetime(?string $value): string
{
    if (!$value) {
        return 'Не указано';
    }

    return (new DateTimeImmutable($value))->format('d.m.Y H:i');
}

function admin_format_date(?string $value): string
{
    if (!$value) {
        return 'Не указано';
    }

    return (new DateTimeImmutable($value))->format('d.m.Y');
}

function admin_log_user_action(?int $userId, string $actionType, string $summary, array $details = []): void
{
    ensure_admin_support();

    if (!admin_support_available()) {
        return;
    }

    $adminAccount = admin_current_account();
    $detailsJson = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    db()->prepare(
        'INSERT INTO admin_user_action_logs (
            admin_account_id,
            user_id,
            action_type,
            action_summary,
            details_json,
            created_at
        ) VALUES (
            :admin_account_id,
            :user_id,
            :action_type,
            :action_summary,
            :details_json,
            NOW()
        )'
    )->execute([
        'admin_account_id' => $adminAccount ? (int) ($adminAccount['id'] ?? 0) : null,
        'user_id' => $userId && $userId > 0 ? $userId : null,
        'action_type' => $actionType,
        'action_summary' => $summary,
        'details_json' => $detailsJson,
    ]);

    security_event_log(
        'admin_action',
        'info',
        'admin',
        $adminAccount ? (int) ($adminAccount['id'] ?? 0) : null,
        $adminAccount ? (string) ($adminAccount['login'] ?? '') : null,
        [
            'action_type' => $actionType,
            'summary' => $summary,
            'user_id' => $userId,
        ]
    );
}

function fetch_admin_user_action_logs(int $userId, int $limit = 12): array
{
    ensure_admin_support();

    if (!admin_support_available() || $userId <= 0 || $limit <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT
            log.*,
            admin.display_name AS admin_display_name,
            admin.login AS admin_login
         FROM admin_user_action_logs log
         LEFT JOIN admin_accounts admin ON admin.id = log.admin_account_id
         WHERE log.user_id = :user_id
         ORDER BY log.created_at DESC, log.id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return array_map(
        static function (array $row): array {
            $details = [];

            if (!empty($row['details_json'])) {
                $decoded = json_decode((string) $row['details_json'], true);
                $details = is_array($decoded) ? $decoded : [];
            }

            return [
                'id' => (int) ($row['id'] ?? 0),
                'actionType' => (string) ($row['action_type'] ?? ''),
                'summary' => (string) ($row['action_summary'] ?? ''),
                'details' => $details,
                'createdAt' => (string) ($row['created_at'] ?? ''),
                'createdAtDisplay' => admin_format_datetime($row['created_at'] ?? null),
                'adminName' => (string) (($row['admin_display_name'] ?? '') !== '' ? $row['admin_display_name'] : 'Системное действие'),
                'adminLogin' => (string) ($row['admin_login'] ?? ''),
            ];
        },
        $statement->fetchAll()
    );
}

function admin_hydrate_user_directory_row(array $row): array
{
    $row['avatar_display'] = akino_avatar_display_path($row['avatar_path'] ?? null);
    $row['name_display'] = (string) ($row['name'] ?: 'Пользователь AKINO');
    $row['phone_display'] = format_phone((string) ($row['phone'] ?? ''));
    $row['email_display'] = (string) ($row['email'] ?: 'Не указан');
    $row['has_active_subscription'] = (bool) ($row['has_active_subscription'] ?? false);
    $row['is_blocked'] = !empty($row['is_blocked']);
    $row['favorites_count'] = (int) ($row['favorites_count'] ?? 0);
    $row['history_count'] = (int) ($row['history_count'] ?? 0);
    $row['continue_count'] = (int) ($row['continue_count'] ?? 0);

    return $row;
}

function admin_email_in_use(string $email, ?int $ignoreUserId = null): bool
{
    $email = trim($email);

    if ($email === '') {
        return false;
    }

    $sql = 'SELECT id FROM users WHERE email = :email';

    if ($ignoreUserId !== null && $ignoreUserId > 0) {
        $sql .= ' AND id <> :id';
    }

    $sql .= ' LIMIT 1';
    $statement = db()->prepare($sql);
    $statement->bindValue(':email', $email);

    if ($ignoreUserId !== null && $ignoreUserId > 0) {
        $statement->bindValue(':id', $ignoreUserId, PDO::PARAM_INT);
    }

    $statement->execute();

    return (bool) $statement->fetchColumn();
}

function admin_user_profile_form_from_user(array $user): array
{
    return [
        'id' => (int) ($user['id'] ?? 0),
        'name' => trim((string) ($user['name'] ?? '')),
        'phone' => trim((string) ($user['phone'] ?? '')),
        'phoneInput' => trim((string) ($user['phone'] ?? '')),
        'email' => trim((string) ($user['email'] ?? '')),
        'gender' => trim((string) ($user['gender'] ?? '')),
        'birthDate' => trim((string) ($user['birth_date'] ?? '')),
        'birthDateInput' => trim((string) ($user['birth_date'] ?? '')),
        'avatarPath' => trim((string) ($user['avatar_path'] ?? '')),
    ];
}

function admin_user_profile_form_from_input(array $input): array
{
    $phoneInput = trim((string) ($input['phone'] ?? ''));
    $birthDateInput = trim((string) ($input['birth_date'] ?? ''));

    return [
        'id' => max(0, (int) ($input['user_id'] ?? 0)),
        'name' => trim((string) ($input['name'] ?? '')),
        'phone' => normalize_phone($phoneInput),
        'phoneInput' => $phoneInput,
        'email' => trim((string) ($input['email'] ?? '')),
        'gender' => trim((string) ($input['gender'] ?? '')),
        'birthDate' => parse_birth_date($birthDateInput),
        'birthDateInput' => $birthDateInput,
        'avatarPath' => trim((string) ($input['avatar_path'] ?? '')),
    ];
}

function admin_validate_user_profile_form(array $form): array
{
    $errors = [];
    $userId = (int) ($form['id'] ?? 0);
    $name = trim((string) ($form['name'] ?? ''));
    $phone = $form['phone'] ?? null;
    $email = trim((string) ($form['email'] ?? ''));
    $birthDateInput = trim((string) ($form['birthDateInput'] ?? ''));
    $birthDate = $form['birthDate'] ?? null;

    if ($userId <= 0 || admin_fetch_user_directory_row_by_id($userId) === null) {
        $errors[] = 'Пользователь для редактирования не найден.';
    }

    if ($name === '') {
        $errors[] = 'Укажите имя пользователя.';
    } elseif (mb_strlen($name, 'UTF-8') > 120) {
        $errors[] = 'Имя пользователя не должно быть длиннее 120 символов.';
    }

    if (!is_string($phone) || $phone === '') {
        $errors[] = 'Введите корректный номер телефона.';
    } else {
        $existingPhoneUser = find_user_by_phone($phone);

        if ($existingPhoneUser && (int) ($existingPhoneUser['id'] ?? 0) !== $userId) {
            $errors[] = 'Этот телефон уже привязан к другому аккаунту.';
        }
    }

    if (mb_strlen($email, 'UTF-8') > 190) {
        $errors[] = 'E-mail не должен быть длиннее 190 символов.';
    } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Введите корректный e-mail.';
    } elseif ($email !== '' && admin_email_in_use($email, $userId)) {
        $errors[] = 'Этот e-mail уже используется другим пользователем.';
    }

    if ($birthDateInput !== '' && $birthDate === null) {
        $errors[] = 'Дата рождения должна быть в формате ДД.ММ.ГГГГ или YYYY-MM-DD.';
    }

    if (mb_strlen((string) ($form['gender'] ?? ''), 'UTF-8') > 40) {
        $errors[] = 'Поле пола не должно быть длиннее 40 символов.';
    }

    $avatarPath = trim((string) ($form['avatarPath'] ?? ''));

    if ($avatarPath !== '') {
        $avatarError = admin_movie_image_reference_error($avatarPath);

        if ($avatarError !== null) {
            $errors[] = $avatarError;
        }
    }

    return $errors;
}

function admin_update_user_profile(array $form): ?array
{
    $userId = (int) ($form['id'] ?? 0);
    $user = admin_fetch_user_directory_row_by_id($userId);

    if (!$user) {
        return null;
    }

    $avatarPath = trim((string) ($form['avatarPath'] ?? ''));

    if ($avatarPath === '') {
        $avatarPath = akino_default_avatar_path();
    }

    db()->prepare(
        'UPDATE users
         SET name = :name,
             phone = :phone,
             email = :email,
             gender = :gender,
             birth_date = :birth_date,
             avatar_path = :avatar_path,
             updated_at = NOW()
         WHERE id = :id
           AND COALESCE(is_admin, 0) = 0'
    )->execute([
        'id' => $userId,
        'name' => trim((string) ($form['name'] ?? '')),
        'phone' => (string) ($form['phone'] ?? ''),
        'email' => trim((string) ($form['email'] ?? '')) ?: null,
        'gender' => trim((string) ($form['gender'] ?? '')) ?: null,
        'birth_date' => $form['birthDate'] ?: null,
        'avatar_path' => $avatarPath,
    ]);

    $updatedUser = fetch_admin_user_detail($userId);

    if ($updatedUser) {
        $fieldLabels = [
            'name' => 'Имя',
            'phone' => 'Телефон',
            'email' => 'E-mail',
            'gender' => 'Пол',
            'birth_date' => 'Дата рождения',
            'avatar_path' => 'Аватар',
        ];
        $changes = [];

        foreach ($fieldLabels as $field => $label) {
            $before = trim((string) ($user[$field] ?? ''));
            $after = trim((string) ($updatedUser[$field] ?? ''));

            if ($before !== $after) {
                $changes[] = [
                    'field' => $field,
                    'label' => $label,
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        admin_log_user_action(
            $userId,
            'profile_updated',
            'Профиль пользователя обновлён',
            [
                'user_name' => (string) ($updatedUser['name_display'] ?? ''),
                'changes' => $changes,
            ]
        );
    }

    return $updatedUser;
}

function admin_fetch_user_directory_row_by_id(int $userId): ?array
{
    ensure_admin_support();

    if (!admin_support_available() || $userId <= 0) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT
            u.*,
            us.started_at AS subscription_started_at,
            us.ends_at AS subscription_ends_at,
            sp.name AS subscription_plan_name,
            sp.price AS subscription_plan_price,
            CASE
                WHEN us.ends_at IS NOT NULL AND us.ends_at >= NOW() THEN 1
                ELSE 0
            END AS has_active_subscription,
            (
                SELECT COUNT(*)
                FROM movie_favorites mf
                WHERE mf.user_id = u.id
            ) AS favorites_count,
            (
                SELECT COUNT(*)
                FROM watch_history wh
                WHERE wh.user_id = u.id
            ) AS history_count,
            (
                SELECT COUNT(*)
                FROM watch_progress wp
                WHERE wp.user_id = u.id
                  AND wp.is_completed = 0
            ) AS continue_count,
            (
                SELECT MAX(wp.updated_at)
                FROM watch_progress wp
                WHERE wp.user_id = u.id
            ) AS playback_activity_at
         FROM users u
         LEFT JOIN user_subscriptions us
           ON us.id = (
                SELECT us_latest.id
                FROM user_subscriptions us_latest
                WHERE us_latest.user_id = u.id
                ORDER BY us_latest.ends_at DESC, us_latest.id DESC
                LIMIT 1
            )
         LEFT JOIN subscription_plans sp ON sp.id = us.plan_id
         WHERE u.id = :user_id
           AND COALESCE(u.is_admin, 0) = 0
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return $row ? admin_hydrate_user_directory_row($row) : null;
}

function fetch_admin_user_detail(int $userId): ?array
{
    $user = admin_fetch_user_directory_row_by_id($userId);

    if (!$user) {
        return null;
    }

    $user['subscriptionPayload'] = get_user_subscription_payload($userId);
    $user['favorites'] = fetch_user_favorites_payload($userId, 8);
    $user['history'] = fetch_user_watch_history_payload($userId, 8);
    $user['continueWatching'] = fetch_continue_watching_payload($userId, 6);
    $user['actionLogs'] = fetch_admin_user_action_logs($userId, 12);

    return $user;
}

function admin_set_user_block_state(int $userId, bool $isBlocked): ?array
{
    $user = admin_fetch_user_directory_row_by_id($userId);

    if (!$user) {
        return null;
    }

    db()->prepare(
        'UPDATE users
         SET is_blocked = :is_blocked,
             blocked_at = :blocked_at,
             updated_at = NOW()
         WHERE id = :id
           AND COALESCE(is_admin, 0) = 0'
    )->execute([
        'id' => $userId,
        'is_blocked' => $isBlocked ? 1 : 0,
        'blocked_at' => $isBlocked ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
    ]);

    if ($isBlocked && (int) ($user['id'] ?? 0) > 0 && isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $user['id']) {
        unset($_SESSION['user_id']);
    }

    $updatedUser = admin_fetch_user_directory_row_by_id($userId);

    if ($updatedUser) {
        admin_log_user_action(
            $userId,
            $isBlocked ? 'user_blocked' : 'user_unblocked',
            $isBlocked ? 'Аккаунт пользователя заблокирован' : 'Аккаунт пользователя разблокирован',
            [
                'user_name' => (string) ($updatedUser['name_display'] ?? ''),
                'phone' => (string) ($updatedUser['phone'] ?? ''),
            ]
        );
    }

    return $updatedUser;
}

function admin_delete_user_account(int $userId): ?array
{
    $user = admin_fetch_user_directory_row_by_id($userId);

    if (!$user) {
        return null;
    }

    admin_log_user_action(
        $userId,
        'user_deleted',
        'Пользователь удалён из системы',
        [
            'user_name' => (string) ($user['name_display'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ]
    );

    db()->prepare(
        'DELETE FROM users
         WHERE id = :id
           AND COALESCE(is_admin, 0) = 0'
    )->execute([
        'id' => $userId,
    ]);

    if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $userId) {
        unset($_SESSION['user_id']);
    }

    return $user;
}

function admin_extend_user_subscription(int $userId): ?array
{
    $user = admin_fetch_user_directory_row_by_id($userId);

    if (!$user) {
        return null;
    }

    $previousEndsAt = $user['subscription_ends_at'] ?? null;
    $subscriptionPayload = activate_subscription($userId);
    $updatedUser = admin_fetch_user_directory_row_by_id($userId);

    if ($updatedUser) {
        admin_log_user_action(
            $userId,
            'subscription_extended',
            'Подписка пользователя продлена',
            [
                'user_name' => (string) ($updatedUser['name_display'] ?? ''),
                'previous_ends_at' => $previousEndsAt,
                'new_ends_at' => $subscriptionPayload['endsAt'] ?? null,
                'plan_name' => $subscriptionPayload['name'] ?? 'AKINO',
            ]
        );
    }

    return $updatedUser;
}

function admin_user_filter_defaults(): array
{
    return [
        'q' => '',
        'subscription' => 'all',
        'sort' => 'newest',
    ];
}

function admin_normalize_user_filters(array $input): array
{
    $defaults = admin_user_filter_defaults();
    $filters = [
        'q' => trim((string) ($input['q'] ?? $defaults['q'])),
        'subscription' => (string) ($input['subscription'] ?? $defaults['subscription']),
        'sort' => (string) ($input['sort'] ?? $defaults['sort']),
    ];

    if (mb_strlen($filters['q'], 'UTF-8') > 100) {
        $filters['q'] = mb_substr($filters['q'], 0, 100, 'UTF-8');
    }

    if (!in_array($filters['subscription'], ['all', 'active', 'inactive'], true)) {
        $filters['subscription'] = $defaults['subscription'];
    }

    if (!in_array($filters['sort'], ['newest', 'last_login', 'subscription_end', 'name'], true)) {
        $filters['sort'] = $defaults['sort'];
    }

    return $filters;
}

function admin_build_user_directory_where(array $filters, array &$params): string
{
    $filters = admin_normalize_user_filters($filters);
    $conditions = ['COALESCE(u.is_admin, 0) = 0'];
    $search = $filters['q'];

    if ($search !== '') {
        $phoneDigits = preg_replace('/\D+/', '', $search) ?? '';
        $searchConditions = [
            'u.name LIKE :search_name',
            'u.email LIKE :search_email',
            'u.phone LIKE :search_phone',
        ];
        $searchPattern = '%' . $search . '%';
        $params['search_name'] = $searchPattern;
        $params['search_email'] = $searchPattern;
        $params['search_phone'] = $searchPattern;

        if ($phoneDigits !== '') {
            $searchConditions[] = 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(u.phone, " ", ""), "-", ""), "(", ""), ")", ""), "+", "") LIKE :phone_search';
            $params['phone_search'] = '%' . $phoneDigits . '%';
        }

        $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
    }

    if ($filters['subscription'] === 'active') {
        $conditions[] = 'EXISTS (
            SELECT 1
            FROM user_subscriptions us_filter
            WHERE us_filter.user_id = u.id
              AND us_filter.ends_at >= NOW()
        )';
    } elseif ($filters['subscription'] === 'inactive') {
        $conditions[] = 'NOT EXISTS (
            SELECT 1
            FROM user_subscriptions us_filter
            WHERE us_filter.user_id = u.id
              AND us_filter.ends_at >= NOW()
        )';
    }

    return ' WHERE ' . implode(' AND ', $conditions);
}

function admin_user_directory_order_by(array $filters): string
{
    $filters = admin_normalize_user_filters($filters);

    return match ($filters['sort']) {
        'last_login' => 'COALESCE(u.last_login_at, u.created_at) DESC, u.id DESC',
        'subscription_end' => 'CASE WHEN us.ends_at IS NULL THEN 1 ELSE 0 END ASC, us.ends_at DESC, u.id DESC',
        'name' => 'COALESCE(NULLIF(u.name, ""), u.phone) ASC, u.id DESC',
        default => 'u.created_at DESC, u.id DESC',
    };
}

function fetch_admin_user_overview(): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        return [
            'totalUsers' => 0,
            'activeSubscriptionCount' => 0,
            'inactiveSubscriptionCount' => 0,
            'recentLoginCount' => 0,
            'emailCount' => 0,
        ];
    }

    $baseStats = db()->query(
        'SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN email IS NOT NULL AND email <> "" THEN 1 ELSE 0 END) AS email_count,
            SUM(CASE WHEN last_login_at IS NOT NULL AND last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent_login_count
         FROM users
         WHERE COALESCE(is_admin, 0) = 0'
    )->fetch() ?: [];

    $activeSubscriptionCount = (int) db()->query(
        'SELECT COUNT(DISTINCT us.user_id)
         FROM user_subscriptions us
         INNER JOIN users u ON u.id = us.user_id
         WHERE COALESCE(u.is_admin, 0) = 0
           AND us.ends_at >= NOW()'
    )->fetchColumn();

    $totalUsers = (int) ($baseStats['total_users'] ?? 0);

    return [
        'totalUsers' => $totalUsers,
        'activeSubscriptionCount' => $activeSubscriptionCount,
        'inactiveSubscriptionCount' => max(0, $totalUsers - $activeSubscriptionCount),
        'recentLoginCount' => (int) ($baseStats['recent_login_count'] ?? 0),
        'emailCount' => (int) ($baseStats['email_count'] ?? 0),
    ];
}

function fetch_admin_user_directory(array $filters = [], int $limit = 24): array
{
    ensure_admin_support();

    if (!admin_support_available() || $limit <= 0) {
        return [
            'items' => [],
            'total' => 0,
            'limit' => max(0, $limit),
        ];
    }

    $filters = admin_normalize_user_filters($filters);
    $params = [];
    $whereSql = admin_build_user_directory_where($filters, $params);

    $countStatement = db()->prepare('SELECT COUNT(*) FROM users u' . $whereSql);
    foreach ($params as $key => $value) {
        $countStatement->bindValue(':' . $key, $value);
    }
    $countStatement->execute();
    $total = (int) $countStatement->fetchColumn();

    $statement = db()->prepare(
        'SELECT
            u.*,
            us.started_at AS subscription_started_at,
            us.ends_at AS subscription_ends_at,
            sp.name AS subscription_plan_name,
            sp.price AS subscription_plan_price,
            CASE
                WHEN us.ends_at IS NOT NULL AND us.ends_at >= NOW() THEN 1
                ELSE 0
            END AS has_active_subscription,
            (
                SELECT COUNT(*)
                FROM movie_favorites mf
                WHERE mf.user_id = u.id
            ) AS favorites_count,
            (
                SELECT COUNT(*)
                FROM watch_history wh
                WHERE wh.user_id = u.id
            ) AS history_count,
            (
                SELECT COUNT(*)
                FROM watch_progress wp
                WHERE wp.user_id = u.id
                  AND wp.is_completed = 0
            ) AS continue_count,
            (
                SELECT MAX(wp.updated_at)
                FROM watch_progress wp
                WHERE wp.user_id = u.id
            ) AS playback_activity_at
         FROM users u
         LEFT JOIN user_subscriptions us
           ON us.id = (
                SELECT us_latest.id
                FROM user_subscriptions us_latest
                WHERE us_latest.user_id = u.id
                ORDER BY us_latest.ends_at DESC, us_latest.id DESC
                LIMIT 1
            )
         LEFT JOIN subscription_plans sp ON sp.id = us.plan_id' . $whereSql . '
         ORDER BY ' . admin_user_directory_order_by($filters) . '
         LIMIT :limit'
    );

    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value);
    }
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    $items = $statement->fetchAll();

    $items = array_map('admin_hydrate_user_directory_row', $items);

    return [
        'items' => $items,
        'total' => $total,
        'limit' => $limit,
    ];
}

function fetch_admin_recent_users(int $limit = 8): array
{
    return fetch_admin_user_directory([], $limit)['items'];
}

function fetch_admin_recent_subscriptions(int $limit = 8): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT
            us.*,
            u.name AS user_name,
            u.phone AS user_phone,
            sp.name AS plan_name,
            sp.price AS plan_price
         FROM user_subscriptions us
         INNER JOIN users u ON u.id = us.user_id
         INNER JOIN subscription_plans sp ON sp.id = us.plan_id
         WHERE COALESCE(u.is_admin, 0) = 0
         ORDER BY us.updated_at DESC, us.id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function fetch_admin_dashboard_stats(): array
{
    ensure_admin_support();

    if (!admin_support_available()) {
        return [
            'contentCount' => 0,
            'movieCount' => 0,
            'seriesCount' => 0,
            'userCount' => 0,
            'adminCount' => 0,
            'activeSubscriptionCount' => 0,
        ];
    }

    return [
        'contentCount' => (int) db()->query('SELECT COUNT(*) FROM movies')->fetchColumn(),
        'movieCount' => (int) db()->query('SELECT COUNT(*) FROM movies WHERE content_type = "movie"')->fetchColumn(),
        'seriesCount' => (int) db()->query('SELECT COUNT(*) FROM movies WHERE content_type = "series"')->fetchColumn(),
        'userCount' => (int) db()->query('SELECT COUNT(*) FROM users WHERE COALESCE(is_admin, 0) = 0')->fetchColumn(),
        'adminCount' => (int) db()->query('SELECT COUNT(*) FROM admin_accounts')->fetchColumn(),
        'activeSubscriptionCount' => (int) db()->query(
            'SELECT COUNT(DISTINCT us.user_id)
             FROM user_subscriptions us
             INNER JOIN users u ON u.id = us.user_id
             WHERE COALESCE(u.is_admin, 0) = 0
               AND us.ends_at >= NOW()'
        )->fetchColumn(),
    ];
}
