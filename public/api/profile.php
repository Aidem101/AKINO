<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

function profile_avatar_upload_error_message(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой. Загрузите изображение до 2 МБ.',
        UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Попробуйте ещё раз.',
        UPLOAD_ERR_NO_FILE => 'Выберите изображение для аватарки.',
        default => 'Не удалось загрузить файл. Попробуйте другое изображение.',
    };
}

function handle_profile_avatar_upload(array $user): void
{
    $maxBytes = 2 * 1024 * 1024;
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength > $maxBytes + (512 * 1024)) {
        json_response([
            'ok' => false,
            'message' => 'Файл слишком большой. Загрузите изображение до 2 МБ.',
        ], 413);
    }

    $file = $_FILES['avatar'] ?? null;

    if (!is_array($file) || is_array($file['error'] ?? null)) {
        json_response([
            'ok' => false,
            'message' => 'Выберите изображение для аватарки.',
        ], 422);
    }

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK) {
        json_response([
            'ok' => false,
            'message' => profile_avatar_upload_error_message($errorCode),
        ], $errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE ? 413 : 422);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $fileSize = (int) ($file['size'] ?? 0);

    if ($tmpPath === '' || $fileSize <= 0) {
        json_response([
            'ok' => false,
            'message' => 'Выберите изображение для аватарки.',
        ], 422);
    }

    if ($fileSize > $maxBytes) {
        json_response([
            'ok' => false,
            'message' => 'Файл слишком большой. Загрузите изображение до 2 МБ.',
        ], 413);
    }

    $allowedMimeTypes = [
        'image/jpeg' => ['extension' => 'jpg', 'type' => IMAGETYPE_JPEG],
        'image/png' => ['extension' => 'png', 'type' => IMAGETYPE_PNG],
        'image/webp' => ['extension' => 'webp', 'type' => IMAGETYPE_WEBP],
    ];

    $mimeType = '';

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) ($finfo->file($tmpPath) ?: '');
    }

    $imageInfo = @getimagesize($tmpPath);
    $imageType = is_array($imageInfo) ? (int) ($imageInfo[2] ?? 0) : 0;
    $imageWidth = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
    $imageHeight = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;
    $detectedImage = $mimeType !== '' && isset($allowedMimeTypes[$mimeType])
        ? $allowedMimeTypes[$mimeType]
        : null;

    if ($detectedImage === null) {
        foreach ($allowedMimeTypes as $candidate) {
            if ($imageType === $candidate['type']) {
                $detectedImage = $candidate;
                break;
            }
        }
    }

    if (
        $detectedImage === null
        || $imageType !== $detectedImage['type']
        || $imageWidth < 1
        || $imageHeight < 1
    ) {
        json_response([
            'ok' => false,
            'message' => 'Загрузите аватарку в формате JPG, PNG или WEBP.',
        ], 422);
    }

    $publicRoot = dirname(__DIR__);
    $uploadDir = $publicRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        json_response([
            'ok' => false,
            'message' => 'Не удалось подготовить папку для аватарки.',
        ], 500);
    }

    $fileName = sprintf(
        'user-%d-%s.%s',
        (int) $user['id'],
        bin2hex(random_bytes(12)),
        $detectedImage['extension']
    );
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    $avatarPath = 'uploads/avatars/' . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        json_response([
            'ok' => false,
            'message' => 'Не удалось сохранить аватарку. Попробуйте ещё раз.',
        ], 500);
    }

    @chmod($targetPath, 0644);

    $previousAvatar = (string) ($user['avatar_path'] ?? '');

    try {
        $updatedUser = update_user_avatar((int) $user['id'], $avatarPath);
    } catch (Throwable $exception) {
        @unlink($targetPath);
        throw $exception;
    }

    if (str_starts_with($previousAvatar, 'uploads/avatars/')) {
        $uploadRoot = realpath($uploadDir);
        $previousPath = realpath($publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $previousAvatar));

        if (
            is_string($uploadRoot)
            && is_string($previousPath)
            && str_starts_with($previousPath, $uploadRoot . DIRECTORY_SEPARATOR)
            && is_file($previousPath)
        ) {
            @unlink($previousPath);
        }
    }

    json_response([
        'ok' => true,
        'message' => 'Аватарка обновлена.',
        'user' => build_account_user_payload($updatedUser),
    ]);
}

try {
    $user = require_auth_user();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response([
            'ok' => true,
            'user' => build_account_user_payload($user),
        ]);
    }

    require_post();

    if (($_POST['action'] ?? '') === 'avatar') {
        handle_profile_avatar_upload($user);
    }

    $input = request_input();
    $phone = normalize_phone((string) ($input['phone'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $gender = trim((string) ($input['gender'] ?? ''));
    $birthDate = parse_birth_date((string) ($input['birthDate'] ?? ''));

    if ($phone === null) {
        json_response([
            'ok' => false,
            'message' => 'Введите корректный номер телефона.',
        ], 422);
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_response([
            'ok' => false,
            'message' => 'Введите корректный e-mail.',
        ], 422);
    }

    if (mb_strlen($email, 'UTF-8') > 190 || mb_strlen($gender, 'UTF-8') > 40) {
        json_response([
            'ok' => false,
            'message' => 'Одно из полей профиля превышает допустимую длину.',
        ], 422);
    }

    if (($input['birthDate'] ?? '') !== '' && $birthDate === null) {
        json_response([
            'ok' => false,
            'message' => 'Введите дату рождения в формате ДД.ММ.ГГГГ.',
        ], 422);
    }

    if ($birthDate !== null && $birthDate > date('Y-m-d')) {
        json_response([
            'ok' => false,
            'message' => 'Дата рождения не может быть в будущем.',
        ], 422);
    }

    $existingUser = find_user_by_phone($phone);

    if ($existingUser && (int) $existingUser['id'] !== (int) $user['id']) {
        json_response([
            'ok' => false,
            'message' => 'Этот телефон уже привязан к другому аккаунту.',
        ], 422);
    }

    $updatedUser = update_user_profile((int) $user['id'], [
        'phone' => $phone,
        'email' => $email,
        'gender' => $gender,
        'birth_date' => $birthDate,
    ]);

    json_response([
        'ok' => true,
        'message' => 'Профиль сохранён.',
        'user' => build_account_user_payload($updatedUser),
    ]);
} catch (PDOException $exception) {
    akino_log_exception($exception);

    $message = $exception->getCode() === '23000'
        ? 'Такой e-mail уже используется.'
        : 'Не удалось сохранить профиль. Попробуйте позже.';

    json_response([
        'ok' => false,
        'message' => $message,
    ], 422);
} catch (Throwable $exception) {
    akino_log_exception($exception);

    json_response([
        'ok' => false,
        'message' => 'Не удалось обработать профиль. Попробуйте позже.',
    ], 500);
}
