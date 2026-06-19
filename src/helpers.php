<?php

declare(strict_types=1);

const AKINO_DEFAULT_AVATAR_PATH = 'img/avatars/default-neutral.svg';
const AKINO_LEGACY_DEFAULT_AVATAR_PATH = 'img/people/image_2025-11-10_00-02-43.png';

function akino_default_avatar_path(): string
{
    return AKINO_DEFAULT_AVATAR_PATH;
}

function akino_avatar_display_path(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '' || $path === AKINO_LEGACY_DEFAULT_AVATAR_PATH) {
        return AKINO_DEFAULT_AVATAR_PATH;
    }

    return $path;
}

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

function akino_app_environment(): string
{
    $environment = strtolower(trim((string) getenv('AKINO_APP_ENV')));

    return $environment !== '' ? $environment : 'production';
}

function akino_is_production(): bool
{
    return akino_app_environment() === 'production';
}

function akino_trust_proxy_headers(): bool
{
    return akino_env_flag('AKINO_TRUST_PROXY') || getenv('VERCEL') !== false;
}

function akino_demo_auth_enabled(): bool
{
    return akino_env_flag('AKINO_DEMO_AUTH');
}

function akino_runtime_bootstrap_enabled(): bool
{
    return akino_env_flag('AKINO_RUNTIME_BOOTSTRAP');
}

function akino_configure_runtime_security(): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header_remove('X-Powered-By');
    }

    ini_set('expose_php', '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', request_is_secure() ? '1' : '0');

    if (akino_is_production()) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
    }
}

function akino_csp_nonce(): string
{
    static $nonce;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(18));
    }

    return $nonce;
}

function akino_csp_origins(string $environmentName): array
{
    $origins = [];
    $values = preg_split('/[\s,]+/', trim((string) getenv($environmentName))) ?: [];

    foreach ($values as $value) {
        $origin = origin_from_url($value);

        if ($origin !== '' && str_starts_with($origin, 'https://')) {
            $origins[$origin] = true;
        }
    }

    return array_keys($origins);
}

function akino_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('AKINOSESSID');
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => request_is_secure(),
        'cookie_samesite' => 'Lax',
        'cookie_path' => '/',
        'use_only_cookies' => true,
        'use_strict_mode' => true,
    ]);
}

function akino_send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('X-Permitted-Cross-Domain-Policies: none');

    $imageSources = array_merge(["'self'", 'data:'], akino_csp_origins('AKINO_CSP_IMAGE_ORIGINS'));
    $mediaSources = array_merge(
        ["'self'", 'https://interactive-examples.mdn.mozilla.net'],
        akino_csp_origins('AKINO_CSP_MEDIA_ORIGINS')
    );
    $directives = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "frame-src 'none'",
        "form-action 'self'",
        "script-src 'self' 'nonce-" . akino_csp_nonce() . "'",
        "script-src-attr 'none'",
        "style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
        "style-src-attr 'none'",
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
        'img-src ' . implode(' ', $imageSources),
        "connect-src 'self'",
        'media-src ' . implode(' ', $mediaSources),
        "manifest-src 'self'",
        "worker-src 'self'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $directives));

    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');

    if (preg_match('~/(?:api/|Cabinet\.php|Admin(?:_Login)?\.php|Watch\.php|logout\.php|admin_logout\.php)$~i', $path)) {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
    }

    if (akino_is_production() && request_is_secure()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
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
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength > 1024 * 1024) {
        json_response([
            'ok' => false,
            'message' => 'Request body is too large.',
        ], 413);
    }

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
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        http_response_code(500);
        $json = '{"ok":false,"message":"Response encoding failed."}';
    }

    echo $json;
    exit;
}

function request_origin(): string
{
    $configuredOrigin = origin_from_url(trim((string) getenv('AKINO_APP_ORIGIN')));

    if ($configuredOrigin !== '') {
        return $configuredOrigin;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));

    if ($host === '' || preg_match('/[\x00-\x20\/\\\\]/', $host)) {
        return '';
    }

    $forwardedProto = akino_trust_proxy_headers()
        ? strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''))
        : '';
    $scheme = in_array($forwardedProto, ['http', 'https'], true)
        ? $forwardedProto
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    return origin_from_url($scheme . '://' . $host);
}

function request_is_secure(): bool
{
    $forwardedProto = akino_trust_proxy_headers()
        ? strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''))
        : '';

    if ($forwardedProto !== '') {
        return $forwardedProto === 'https';
    }

    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function origin_from_url(string $url): string
{
    $parts = parse_url($url);

    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower((string) $parts['scheme']);

    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    $portNumber = isset($parts['port']) ? (int) $parts['port'] : null;
    $port = $portNumber !== null
        && !(($scheme === 'http' && $portNumber === 80) || ($scheme === 'https' && $portNumber === 443))
            ? ':' . $portNumber
            : '';

    return $scheme . '://' . strtolower((string) $parts['host']) . $port;
}

function request_client_ip(): string
{
    if (akino_trust_proxy_headers()) {
        $forwardedFor = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $candidate = trim((string) ($forwardedFor[0] ?? ''));

        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }

    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false ? $remoteAddress : 'unknown';
}

function akino_csrf_token(): string
{
    if (empty($_SESSION['akino_csrf']) || !is_string($_SESSION['akino_csrf'])) {
        $_SESSION['akino_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['akino_csrf'];
}

function akino_rotate_csrf_token(): string
{
    unset($_SESSION['akino_csrf']);

    return akino_csrf_token();
}

function akino_verify_csrf_token(?string $token): bool
{
    $sessionToken = (string) ($_SESSION['akino_csrf'] ?? '');

    return $sessionToken !== ''
        && is_string($token)
        && strlen($token) === strlen($sessionToken)
        && hash_equals($sessionToken, $token);
}

function require_csrf_token(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);

    if (!akino_verify_csrf_token(is_string($token) ? $token : null)) {
        if (function_exists('security_event_log')) {
            security_event_log('csrf_rejected', 'warning', 'anonymous');
        }

        json_response([
            'ok' => false,
            'message' => 'Сессия запроса устарела. Обновите страницу и попробуйте снова.',
        ], 403);
    }
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
        $actualOrigin = origin_from_url($originHeader);
    } elseif ($refererHeader !== '') {
        $actualOrigin = origin_from_url($refererHeader);
    }

    if ($actualOrigin !== '' && !hash_equals($expectedOrigin, $actualOrigin)) {
        if (function_exists('security_event_log')) {
            security_event_log('origin_rejected', 'critical', 'anonymous', null, null, [
                'actual_origin' => $actualOrigin,
            ]);
        }

        json_response([
            'ok' => false,
            'message' => 'Запрос отклонён из-за источника.',
        ], 403);
    }

    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    if ($actualOrigin === '' && $requestedWith !== 'xmlhttprequest') {
        if (function_exists('security_event_log')) {
            security_event_log('origin_rejected', 'warning', 'anonymous', null, null, [
                'reason' => 'missing_origin',
            ]);
        }

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
    require_csrf_token();
}

function security_rate_limit_table_available(): bool
{
    static $available;

    if ($available !== null) {
        return $available;
    }

    try {
        if (akino_runtime_bootstrap_enabled()) {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS security_rate_limits (
                    bucket VARCHAR(80) NOT NULL,
                    identity_hash CHAR(64) NOT NULL,
                    attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    window_started_at DATETIME NOT NULL,
                    blocked_until DATETIME DEFAULT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (bucket, identity_hash),
                    KEY security_rate_limits_updated_index (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $available = akino_table_exists('security_rate_limits');
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}

function security_rate_limit_identity(string $value): string
{
    $secret = trim((string) getenv('AKINO_AUTH_SECRET'));

    return $secret !== ''
        ? hash_hmac('sha256', $value, $secret)
        : hash('sha256', $value);
}

function security_rate_limit_exceeded(
    string $bucket,
    string $identity,
    int $limit,
    int $windowSeconds
): bool {
    $bucket = substr(preg_replace('/[^a-z0-9_.-]+/i', '', $bucket) ?? '', 0, 80);
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);

    if ($identity === 'unknown') {
        return false;
    }

    if ($bucket === '') {
        return true;
    }

    if (security_rate_limit_table_available()) {
        $statement = db()->prepare(
            'SELECT attempts, window_started_at, blocked_until
             FROM security_rate_limits
             WHERE bucket = :bucket AND identity_hash = :identity_hash
             LIMIT 1'
        );
        $statement->execute([
            'bucket' => $bucket,
            'identity_hash' => security_rate_limit_identity($identity),
        ]);
        $row = $statement->fetch();

        if (!$row) {
            return false;
        }

        $now = new DateTimeImmutable();
        $blockedUntil = !empty($row['blocked_until'])
            ? new DateTimeImmutable((string) $row['blocked_until'])
            : null;
        $windowStartedAt = new DateTimeImmutable((string) $row['window_started_at']);

        return ($blockedUntil !== null && $blockedUntil > $now)
            || ($windowStartedAt->getTimestamp() >= time() - $windowSeconds
                && (int) $row['attempts'] >= $limit);
    }

    $key = 'akino_rate_' . hash('sha256', $bucket . '|' . $identity);
    $state = $_SESSION[$key] ?? null;

    if (!is_array($state) || (int) ($state['startedAt'] ?? 0) < time() - $windowSeconds) {
        return false;
    }

    return (int) ($state['blockedUntil'] ?? 0) > time()
        || (int) ($state['attempts'] ?? 0) >= $limit;
}

function security_rate_limit_record_failure(
    string $bucket,
    string $identity,
    int $limit,
    int $windowSeconds,
    int $blockSeconds
): void {
    $bucket = substr(preg_replace('/[^a-z0-9_.-]+/i', '', $bucket) ?? '', 0, 80);
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);
    $blockSeconds = max(1, $blockSeconds);

    if ($identity === 'unknown') {
        return;
    }

    if ($bucket === '') {
        return;
    }

    if (security_rate_limit_table_available()) {
        $windowSecondsSql = (string) $windowSeconds;
        $blockSecondsSql = (string) $blockSeconds;
        $limitSql = (string) $limit;
        $statement = db()->prepare(
            'INSERT INTO security_rate_limits (
                bucket,
                identity_hash,
                attempts,
                window_started_at,
                blocked_until,
                updated_at
             ) VALUES (
                :bucket,
                :identity_hash,
                1,
                NOW(),
                NULL,
                NOW()
             )
             ON DUPLICATE KEY UPDATE
                blocked_until = CASE
                    WHEN blocked_until IS NOT NULL AND blocked_until > NOW() THEN blocked_until
                    WHEN window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSecondsSql . ' SECOND) THEN NULL
                    WHEN attempts + 1 >= ' . $limitSql . ' THEN DATE_ADD(NOW(), INTERVAL ' . $blockSecondsSql . ' SECOND)
                    ELSE NULL
                END,
                attempts = CASE
                    WHEN window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSecondsSql . ' SECOND) THEN 1
                    ELSE attempts + 1
                END,
                window_started_at = CASE
                    WHEN window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSecondsSql . ' SECOND) THEN NOW()
                    ELSE window_started_at
                END,
                updated_at = NOW()'
        );
        $statement->execute([
            'bucket' => $bucket,
            'identity_hash' => security_rate_limit_identity($identity),
        ]);

        return;
    }

    $key = 'akino_rate_' . hash('sha256', $bucket . '|' . $identity);
    $state = $_SESSION[$key] ?? null;

    if (!is_array($state) || (int) ($state['startedAt'] ?? 0) < time() - $windowSeconds) {
        $state = [
            'attempts' => 0,
            'startedAt' => time(),
            'blockedUntil' => 0,
        ];
    }

    $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;

    if ($state['attempts'] >= $limit) {
        $state['blockedUntil'] = time() + $blockSeconds;
    }

    $_SESSION[$key] = $state;
}

function security_rate_limit_clear(string $bucket, string $identity): void
{
    $bucket = substr(preg_replace('/[^a-z0-9_.-]+/i', '', $bucket) ?? '', 0, 80);

    if ($bucket === '' || $identity === 'unknown') {
        return;
    }

    if (security_rate_limit_table_available()) {
        $statement = db()->prepare(
            'DELETE FROM security_rate_limits
             WHERE bucket = :bucket AND identity_hash = :identity_hash'
        );
        $statement->execute([
            'bucket' => $bucket,
            'identity_hash' => security_rate_limit_identity($identity),
        ]);

        return;
    }

    unset($_SESSION['akino_rate_' . hash('sha256', $bucket . '|' . $identity)]);
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
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date instanceof DateTimeImmutable
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $date->format($format) === $value
        ) {
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

function akino_css_string_escape(string $value): string
{
    return str_replace(
        ["\\", "\"", "\r", "\n", "\f"],
        ["\\\\", "\\\"", "\\D ", "\\A ", "\\C "],
        $value
    );
}

function user_is_blocked(array $user): bool
{
    return !empty($user['is_blocked']);
}

function current_user_id(): ?int
{
    $userId = $_SESSION['user_id'] ?? null;

    if (!is_numeric($userId)) {
        $userId = akino_auth_cookie_user_id();
    }

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

function akino_auth_cookie_name(): string
{
    return 'akino_auth';
}

function akino_auth_cookie_secret(): string
{
    $secret = (string) getenv('AKINO_AUTH_SECRET');

    if (
        strlen($secret) >= 32
        && !in_array(strtolower($secret), [
            'replace-with-at-least-32-random-characters',
            'change-me',
        ], true)
    ) {
        return $secret;
    }

    return '';
}

function akino_auth_cookie_signature(int $userId, int $expiresAt): string
{
    $secret = akino_auth_cookie_secret();

    return $secret !== ''
        ? hash_hmac('sha256', 'v1|' . $userId . '|' . $expiresAt, $secret)
        : '';
}

function akino_set_auth_cookie(int $userId): void
{
    if (akino_auth_cookie_secret() === '') {
        akino_clear_auth_cookie();

        return;
    }

    $expiresAt = time() + 60 * 60 * 24 * 30;
    $signature = akino_auth_cookie_signature($userId, $expiresAt);
    $value = 'v1|' . $userId . '|' . $expiresAt . '|' . $signature;

    setcookie(akino_auth_cookie_name(), $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => request_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE[akino_auth_cookie_name()] = $value;
}

function akino_clear_auth_cookie(): void
{
    setcookie(akino_auth_cookie_name(), '', [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => request_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    unset($_COOKIE[akino_auth_cookie_name()]);
}

function akino_auth_cookie_user_id(): ?int
{
    if (akino_auth_cookie_secret() === '') {
        akino_clear_auth_cookie();

        return null;
    }

    $value = (string) ($_COOKIE[akino_auth_cookie_name()] ?? '');
    $parts = explode('|', $value);

    if (
        count($parts) !== 4
        || $parts[0] !== 'v1'
        || !ctype_digit($parts[1])
        || !ctype_digit($parts[2])
    ) {
        return null;
    }

    $userId = (int) $parts[1];
    $expiresAt = (int) $parts[2];
    $signature = (string) $parts[3];

    if ($userId < 1 || $expiresAt < time()) {
        akino_clear_auth_cookie();

        return null;
    }

    if (!hash_equals(akino_auth_cookie_signature($userId, $expiresAt), $signature)) {
        akino_clear_auth_cookie();

        return null;
    }

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = $userId;

    return $userId;
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
        'avatar_path' => akino_default_avatar_path(),
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

function update_user_avatar(int $userId, string $avatarPath): array
{
    $statement = db()->prepare(
        'UPDATE users
         SET avatar_path = :avatar_path,
             updated_at = NOW()
         WHERE id = :id'
    );

    $statement->execute([
        'id' => $userId,
        'avatar_path' => $avatarPath,
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
        'avatar' => akino_avatar_display_path($user['avatar_path'] ?? null),
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
