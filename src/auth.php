<?php

declare(strict_types=1);

const AKINO_AUTH_CODE_REQUEST_LIMIT = 3;
const AKINO_AUTH_CODE_REQUEST_WINDOW_MINUTES = 10;
const AKINO_AUTH_CODE_ATTEMPT_LIMIT = 5;
const AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS = 600;
const AKINO_AUTH_IP_REQUEST_LIMIT = 10;
const AKINO_AUTH_IP_VERIFY_FAILURE_LIMIT = 20;

final class AuthCodeException extends RuntimeException
{
}

function auth_request_rate_identity(): string
{
    return request_client_ip();
}

function assert_auth_request_rate_allowed(): void
{
    if (security_rate_limit_exceeded(
        'auth_code_request',
        auth_request_rate_identity(),
        AKINO_AUTH_IP_REQUEST_LIMIT,
        AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS
    )) {
        security_event_log('auth_rate_blocked', 'warning', 'anonymous', null, null, [
            'flow' => 'request_code',
        ]);

        throw new AuthCodeException('Слишком много запросов кода. Попробуйте позже.');
    }
}

function register_auth_request(): void
{
    security_rate_limit_record_failure(
        'auth_code_request',
        auth_request_rate_identity(),
        AKINO_AUTH_IP_REQUEST_LIMIT,
        AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS,
        AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS
    );
}

function assert_auth_verify_rate_allowed(): void
{
    if (security_rate_limit_exceeded(
        'auth_code_verify',
        auth_request_rate_identity(),
        AKINO_AUTH_IP_VERIFY_FAILURE_LIMIT,
        AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS
    )) {
        security_event_log('auth_rate_blocked', 'warning', 'anonymous', null, null, [
            'flow' => 'verify_code',
        ]);

        throw new AuthCodeException('Слишком много неверных попыток. Запросите новый код позже.');
    }
}

function register_auth_verify_failure(): void
{
    security_rate_limit_record_failure(
        'auth_code_verify',
        auth_request_rate_identity(),
        AKINO_AUTH_IP_VERIFY_FAILURE_LIMIT,
        AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS,
        AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS
    );
}

function auth_code_attempt_counter_available(): bool
{
    static $available;

    if ($available === null) {
        try {
            if (akino_runtime_bootstrap_enabled() && !akino_column_exists('auth_codes', 'attempt_count')) {
                db()->exec(
                    'ALTER TABLE auth_codes
                     ADD COLUMN attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at'
                );
            }

            $available = akino_column_exists('auth_codes', 'attempt_count');
        } catch (Throwable) {
            $available = false;
        }
    }

    return $available;
}

function assert_auth_code_request_allowed(string $phone): void
{
    assert_auth_request_rate_allowed();

    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM auth_codes
         WHERE phone = :phone
           AND created_at >= DATE_SUB(NOW(), INTERVAL ' . AKINO_AUTH_CODE_REQUEST_WINDOW_MINUTES . ' MINUTE)'
    );
    $statement->execute(['phone' => $phone]);

    if ((int) $statement->fetchColumn() >= AKINO_AUTH_CODE_REQUEST_LIMIT) {
        security_event_log(
            'auth_rate_blocked',
            'warning',
            'user',
            null,
            security_phone_label($phone),
            ['flow' => 'phone_request_limit']
        );

        throw new AuthCodeException('Слишком много запросов кода. Попробуйте позже.');
    }
}

function prune_auth_code_attempts(): void
{
    if (empty($_SESSION['akino_auth_code_attempts']) || !is_array($_SESSION['akino_auth_code_attempts'])) {
        $_SESSION['akino_auth_code_attempts'] = [];

        return;
    }

    $threshold = time() - AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS;

    foreach ($_SESSION['akino_auth_code_attempts'] as $requestId => $attempt) {
        $firstAt = (int) ($attempt['firstAt'] ?? 0);

        if ($firstAt < $threshold) {
            unset($_SESSION['akino_auth_code_attempts'][$requestId]);
        }
    }
}

function assert_auth_code_attempt_allowed(int $requestId): void
{
    prune_auth_code_attempts();

    $attempt = $_SESSION['akino_auth_code_attempts'][(string) $requestId] ?? null;

    if (is_array($attempt) && (int) ($attempt['count'] ?? 0) >= AKINO_AUTH_CODE_ATTEMPT_LIMIT) {
        throw new AuthCodeException('Слишком много неверных попыток. Запросите новый код.');
    }
}

function register_auth_code_failed_attempt(int $requestId): void
{
    prune_auth_code_attempts();

    $key = (string) $requestId;
    $attempt = $_SESSION['akino_auth_code_attempts'][$key] ?? [
        'count' => 0,
        'firstAt' => time(),
    ];

    $attempt['count'] = (int) ($attempt['count'] ?? 0) + 1;
    $attempt['firstAt'] = (int) ($attempt['firstAt'] ?? time());
    $_SESSION['akino_auth_code_attempts'][$key] = $attempt;

    if ($attempt['count'] >= AKINO_AUTH_CODE_ATTEMPT_LIMIT) {
        $statement = db()->prepare(
            'UPDATE auth_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
        );
        $statement->execute(['id' => $requestId]);
    }
}

function clear_auth_code_failed_attempts(int $requestId): void
{
    unset($_SESSION['akino_auth_code_attempts'][(string) $requestId]);
}

function create_auth_code_request(string $phone, string $intent): array
{
    assert_auth_code_request_allowed($phone);

    $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = (new DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');
    $db = db();

    $db->beginTransaction();

    try {
        $invalidate = $db->prepare(
            'UPDATE auth_codes
             SET used_at = NOW()
             WHERE phone = :phone
               AND used_at IS NULL'
        );
        $invalidate->execute(['phone' => $phone]);

        $statement = $db->prepare(
            'INSERT INTO auth_codes (phone, code_hash, intent, expires_at, created_at)
             VALUES (:phone, :code_hash, :intent, :expires_at, NOW())'
        );

        $statement->execute([
            'phone' => $phone,
            'code_hash' => $hash,
            'intent' => $intent,
            'expires_at' => $expiresAt,
        ]);

        $requestId = (int) $db->lastInsertId();
        $db->commit();
        register_auth_request();
        security_event_log(
            'auth_code_requested',
            'info',
            'user',
            null,
            security_phone_label($phone),
            ['intent' => $intent]
        );
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $exception;
    }

    return [
        'id' => $requestId,
        'code' => $code,
        'expiresAt' => $expiresAt,
    ];
}

function verify_auth_code_request(int $requestId, string $code): array
{
    assert_auth_code_attempt_allowed($requestId);
    assert_auth_verify_rate_allowed();

    $db = db();
    $db->beginTransaction();

    try {
        $statement = $db->prepare(
            'SELECT * FROM auth_codes WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $requestId]);
        $request = $statement->fetch();

        if (!$request) {
            throw new AuthCodeException('Код подтверждения не найден.');
        }

        if ($request['used_at'] !== null) {
            throw new AuthCodeException('Этот код уже был использован.');
        }

        if ((new DateTimeImmutable($request['expires_at'])) < new DateTimeImmutable()) {
            throw new AuthCodeException('Срок действия кода истёк. Запросите новый код.');
        }

        if (
            auth_code_attempt_counter_available()
            && (int) ($request['attempt_count'] ?? 0) >= AKINO_AUTH_CODE_ATTEMPT_LIMIT
        ) {
            throw new AuthCodeException('Слишком много неверных попыток. Запросите новый код.');
        }

        if (!password_verify($code, $request['code_hash'])) {
            if (auth_code_attempt_counter_available()) {
                $failedAttempt = $db->prepare(
                    'UPDATE auth_codes
                     SET used_at = CASE
                             WHEN attempt_count + 1 >= :attempt_limit THEN NOW()
                             ELSE used_at
                         END,
                         attempt_count = attempt_count + 1
                     WHERE id = :id AND used_at IS NULL'
                );
                $failedAttempt->execute([
                    'attempt_limit' => AKINO_AUTH_CODE_ATTEMPT_LIMIT,
                    'id' => $requestId,
                ]);
            } else {
                $failedAttempt = $db->prepare(
                    'UPDATE auth_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
                );
                $failedAttempt->execute(['id' => $requestId]);
            }

            $db->commit();
            register_auth_code_failed_attempt($requestId);
            register_auth_verify_failure();
            security_event_log(
                'auth_code_failed',
                'warning',
                'user',
                null,
                security_phone_label((string) ($request['phone'] ?? '')),
                [
                    'request_id' => $requestId,
                    'attempt_count' => (int) ($request['attempt_count'] ?? 0) + 1,
                ]
            );

            throw new AuthCodeException('Неверный код подтверждения.');
        }

        $update = $db->prepare(
            'UPDATE auth_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
        );
        $update->execute(['id' => $requestId]);

        if ($update->rowCount() !== 1) {
            throw new AuthCodeException('Этот код уже был использован.');
        }

        $db->commit();
        clear_auth_code_failed_attempts($requestId);
        security_rate_limit_clear('auth_code_verify', auth_request_rate_identity());
        security_event_log(
            'auth_code_verified',
            'info',
            'user',
            null,
            security_phone_label((string) ($request['phone'] ?? '')),
            ['intent' => (string) ($request['intent'] ?? 'login')]
        );

        return $request;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $exception;
    }
}

function login_user(array $user): void
{
    if (user_is_blocked($user)) {
        security_event_log(
            'user_login_blocked',
            'warning',
            'user',
            (int) ($user['id'] ?? 0),
            security_phone_label((string) ($user['phone'] ?? ''))
        );

        throw new RuntimeException('Аккаунт временно заблокирован. Обратитесь в поддержку AKINO.');
    }

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    akino_rotate_csrf_token();
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['auth_verified_at'] = time();
    akino_set_auth_cookie((int) $user['id']);

    $statement = db()->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id');
    $statement->execute(['id' => (int) $user['id']]);
    security_event_log(
        'user_login_success',
        'info',
        'user',
        (int) $user['id'],
        security_phone_label((string) ($user['phone'] ?? ''))
    );
}

function logout_user(): void
{
    $_SESSION = [];
    akino_clear_auth_cookie();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}
