<?php

declare(strict_types=1);

function akino_env_flag(string $name, bool $default = false): bool
{
    $value = getenv($name);

    if ($value === false) {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));

    if ($normalized === '') {
        return $default;
    }

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function akino_demo_auth_enabled(): bool
{
    return akino_env_flag('AKINO_DEMO_AUTH');
}

function akino_runtime_bootstrap_enabled(): bool
{
    return akino_env_flag('AKINO_RUNTIME_BOOTSTRAP');
}

function akino_table_exists(string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    $statement = db()->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
         LIMIT 1'
    );
    $statement->execute(['table_name' => $table]);

    return (bool) $statement->fetchColumn();
}

function akino_column_exists(string $table, string $column): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

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

    return (bool) $statement->fetchColumn();
}

function akino_schema_ready(array $tables): bool
{
    foreach ($tables as $table) {
        if (!is_string($table) || !akino_table_exists($table)) {
            return false;
        }
    }

    return true;
}

function akino_log_exception(Throwable $exception): void
{
    error_log(sprintf(
        '[AKINO] %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
}

function request_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $payload = json_decode((string) file_get_contents('php://input'), true);

        return is_array($payload) ? $payload : [];
    }

    return $_POST;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_origin(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));

    if ($host === '') {
        return '';
    }

    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $scheme = in_array($forwardedProto, ['http', 'https'], true)
        ? $forwardedProto
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    return strtolower($scheme . '://' . $host);
}

function origin_from_url(string $url): string
{
    $parts = parse_url($url);

    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

    return strtolower((string) $parts['scheme'] . '://' . (string) $parts['host'] . $port);
}

function require_same_origin_post(): void
{
    $expectedOrigin = request_origin();

    if ($expectedOrigin === '') {
        return;
    }

    $actualOrigin = '';
    $originHeader = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $refererHeader = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

    if ($originHeader !== '') {
        $actualOrigin = strtolower(rtrim($originHeader, '/'));
    } elseif ($refererHeader !== '') {
        $actualOrigin = origin_from_url($refererHeader);
    }

    if ($actualOrigin !== '' && !hash_equals($expectedOrigin, $actualOrigin)) {
        json_response([
            'ok' => false,
            'message' => 'Запрос отклонён из-за источника.',
        ], 403);
    }

    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    if ($actualOrigin === '' && $requestedWith !== 'xmlhttprequest') {
        json_response([
            'ok' => false,
            'message' => 'Запрос отклонён из-за отсутствия проверки источника.',
        ], 403);
    }
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response([
            'ok' => false,
            'message' => 'Method not allowed.',
        ], 405);
    }

    require_same_origin_post();
}

function normalize_phone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if (strlen($digits) === 10) {
        $digits = '7' . $digits;
    } elseif (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }

    if (strlen($digits) !== 11 || $digits[0] !== '7') {
        return null;
    }

    return '+' . $digits;
}

function format_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if (strlen($digits) === 11 && $digits[0] === '7') {
        return sprintf(
            '+7 (%s) %s-%s-%s',
            substr($digits, 1, 3),
            substr($digits, 4, 3),
            substr($digits, 7, 2),
            substr($digits, 9, 2)
        );
    }

    return $phone;
}

function parse_birth_date(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $formats = ['d.m.Y', 'Y-m-d'];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);

        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function format_birth_date(?string $value): string
{
    if (!$value) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return $date instanceof DateTimeImmutable ? $date->format('d.m.Y') : '';
}

function user_is_blocked(array $user): bool
{
    return !empty($user['is_blocked']);
}

function current_user_id(): ?int
{
    $userId = $_SESSION['user_id'] ?? null;

    if (!is_numeric($userId)) {
        return null;
    }

    $user = find_user_by_id((int) $userId);

    if (!$user || user_is_blocked($user)) {
        unset($_SESSION['user_id']);

        return null;
    }

    return (int) $user['id'];
}

function user_exists_by_phone(string $phone): bool
{
    $statement = db()->prepare('SELECT id FROM users WHERE phone = :phone LIMIT 1');
    $statement->execute(['phone' => $phone]);

    return (bool) $statement->fetchColumn();
}

function find_user_by_id(int $userId): ?array
{
    $statement = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    return $user ?: null;
}

function find_user_by_phone(string $phone): ?array
{
    $statement = db()->prepare('SELECT * FROM users WHERE phone = :phone LIMIT 1');
    $statement->execute(['phone' => $phone]);
    $user = $statement->fetch();

    return $user ?: null;
}

function create_user(string $phone): array
{
    $statement = db()->prepare(
        'INSERT INTO users (phone, name, avatar_path, created_at, updated_at)
         VALUES (:phone, :name, :avatar_path, NOW(), NOW())'
    );

    $statement->execute([
        'phone' => $phone,
        'name' => 'Пользователь ' . substr(preg_replace('/\D+/', '', $phone) ?? '', -4),
        'avatar_path' => 'img/people/image_2025-11-10_00-02-43.png',
    ]);

    return find_user_by_id((int) db()->lastInsertId()) ?? [];
}

function update_user_profile(int $userId, array $data): array
{
    $statement = db()->prepare(
        'UPDATE users
         SET phone = :phone,
             email = :email,
             gender = :gender,
             birth_date = :birth_date,
             updated_at = NOW()
         WHERE id = :id'
    );

    $statement->execute([
        'id' => $userId,
        'phone' => $data['phone'],
        'email' => $data['email'] ?: null,
        'gender' => $data['gender'] ?: null,
        'birth_date' => $data['birth_date'],
    ]);

    return find_user_by_id($userId) ?? [];
}

function build_user_payload(array $user): array
{
    $subscription = get_user_subscription_payload((int) $user['id']);

    return [
        'id' => (int) $user['id'],
        'name' => $user['name'] ?: 'Пользователь AKINO',
        'phone' => $user['phone'],
        'phoneDisplay' => format_phone($user['phone']),
        'email' => $user['email'] ?? '',
        'gender' => $user['gender'] ?? '',
        'birthDate' => $user['birth_date'] ?? '',
        'birthDateDisplay' => format_birth_date($user['birth_date'] ?? null),
        'avatar' => $user['avatar_path'] ?: 'img/people/image_2025-11-10_00-02-43.png',
        'isAdmin' => !empty($user['is_admin']),
        'isBlocked' => user_is_blocked($user),
        'blockedAt' => $user['blocked_at'] ?? null,
        'subscription' => $subscription,
    ];
}

function current_user_payload(): ?array
{
    $userId = current_user_id();

    if ($userId === null) {
        return null;
    }

    $user = find_user_by_id($userId);

    if (!$user) {
        unset($_SESSION['user_id']);

        return null;
    }

    if (user_is_blocked($user)) {
        unset($_SESSION['user_id']);

        return null;
    }

    return build_user_payload($user);
}

function require_auth_user(): array
{
    $userId = $_SESSION['user_id'] ?? null;

    if (!is_numeric($userId)) {
        json_response([
            'ok' => false,
            'message' => 'Требуется авторизация.',
        ], 401);
    }

    $user = find_user_by_id((int) $userId);

    if (!$user) {
        unset($_SESSION['user_id']);
        json_response([
            'ok' => false,
            'message' => 'Требуется авторизация.',
        ], 401);
    }

    if (user_is_blocked($user)) {
        unset($_SESSION['user_id']);
        json_response([
            'ok' => false,
            'message' => 'Аккаунт временно заблокирован. Обратитесь в поддержку AKINO.',
        ], 403);
    }

    return $user;
}
