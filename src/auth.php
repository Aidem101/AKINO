<?php

declare(strict_types=1);

const AKINO_AUTH_CODE_REQUEST_LIMIT = 3;
const AKINO_AUTH_CODE_REQUEST_WINDOW_MINUTES = 10;
const AKINO_AUTH_CODE_ATTEMPT_LIMIT = 5;
const AKINO_AUTH_CODE_ATTEMPT_WINDOW_SECONDS = 600;

final class AuthCodeException extends RuntimeException
{
}

function assert_auth_code_request_allowed(string $phone): void
{
    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM auth_codes
         WHERE phone = :phone
           AND created_at >= DATE_SUB(NOW(), INTERVAL ' . AKINO_AUTH_CODE_REQUEST_WINDOW_MINUTES . ' MINUTE)'
    );
    $statement->execute(['phone' => $phone]);

    if ((int) $statement->fetchColumn() >= AKINO_AUTH_CODE_REQUEST_LIMIT) {
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

    $db = db();
    $badCode = false;
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

        if (!password_verify($code, $request['code_hash'])) {
            $badCode = true;
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

        return $request;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ($badCode) {
            register_auth_code_failed_attempt($requestId);
        }

        throw $exception;
    }
}

function login_user(array $user): void
{
    if (user_is_blocked($user)) {
        throw new RuntimeException('Аккаунт временно заблокирован. Обратитесь в поддержку AKINO.');
    }

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = (int) $user['id'];

    $statement = db()->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id');
    $statement->execute(['id' => (int) $user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];

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
