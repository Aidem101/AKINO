<?php

declare(strict_types=1);

function ensure_security_center_support(): bool
{
    static $available;

    if ($available !== null) {
        return $available;
    }

    try {
        if (akino_runtime_bootstrap_enabled()) {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS security_events (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    event_type VARCHAR(80) NOT NULL,
                    severity VARCHAR(16) NOT NULL DEFAULT "info",
                    actor_type VARCHAR(24) NOT NULL DEFAULT "system",
                    actor_id BIGINT UNSIGNED DEFAULT NULL,
                    actor_label VARCHAR(120) DEFAULT NULL,
                    ip_hash CHAR(64) NOT NULL,
                    ip_masked VARCHAR(64) NOT NULL,
                    request_path VARCHAR(190) DEFAULT NULL,
                    details_json TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY security_events_type_created_index (event_type, created_at),
                    KEY security_events_severity_created_index (severity, created_at),
                    KEY security_events_ip_created_index (ip_hash, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            db()->exec(
                'CREATE TABLE IF NOT EXISTS security_backups (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    filename VARCHAR(190) NOT NULL,
                    checksum CHAR(64) NOT NULL,
                    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT "created",
                    created_by BIGINT UNSIGNED DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    verified_at DATETIME DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY security_backups_filename_unique (filename),
                    KEY security_backups_created_index (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            db()->exec(
                'CREATE TABLE IF NOT EXISTS security_file_integrity (
                    path VARCHAR(255) NOT NULL,
                    checksum CHAR(64) NOT NULL,
                    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT "clean",
                    recorded_by BIGINT UNSIGNED DEFAULT NULL,
                    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (path),
                    KEY security_file_integrity_status_index (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $available = akino_schema_ready([
            'security_events',
            'security_backups',
            'security_file_integrity',
        ]);
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}

function security_mask_ip(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $parts = explode('.', $ip);

        return $parts[0] . '.' . $parts[1] . '.x.x';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $packed = inet_pton($ip);

        if ($packed !== false) {
            $normalized = inet_ntop($packed);
            $parts = explode(':', (string) $normalized);

            return implode(':', array_slice($parts, 0, 3)) . ':…';
        }
    }

    return 'unknown';
}

function security_sanitize_details(array $details): array
{
    $sanitized = [];

    foreach ($details as $key => $value) {
        $normalizedKey = strtolower((string) $key);

        if (preg_match('/password|secret|token|cookie|authorization|code_hash|code$/', $normalizedKey)) {
            $sanitized[$key] = '[redacted]';
            continue;
        }

        if (is_array($value)) {
            $sanitized[$key] = security_sanitize_details($value);
        } elseif (is_scalar($value) || $value === null) {
            $sanitized[$key] = is_string($value)
                ? mb_substr($value, 0, 500, 'UTF-8')
                : $value;
        }
    }

    return $sanitized;
}

function security_event_log(
    string $eventType,
    string $severity = 'info',
    string $actorType = 'system',
    ?int $actorId = null,
    ?string $actorLabel = null,
    array $details = []
): void {
    if (!ensure_security_center_support()) {
        return;
    }

    $eventType = substr(preg_replace('/[^a-z0-9_.-]+/i', '_', $eventType) ?? '', 0, 80);
    $severity = in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info';
    $actorType = substr(preg_replace('/[^a-z0-9_.-]+/i', '_', $actorType) ?? 'system', 0, 24);

    if ($eventType === '') {
        return;
    }

    $ip = request_client_ip();
    $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $details = security_sanitize_details($details);
    $detailsJson = $details
        ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    try {
        db()->prepare(
            'INSERT INTO security_events (
                event_type,
                severity,
                actor_type,
                actor_id,
                actor_label,
                ip_hash,
                ip_masked,
                request_path,
                details_json,
                created_at
            ) VALUES (
                :event_type,
                :severity,
                :actor_type,
                :actor_id,
                :actor_label,
                :ip_hash,
                :ip_masked,
                :request_path,
                :details_json,
                NOW()
            )'
        )->execute([
            'event_type' => $eventType,
            'severity' => $severity,
            'actor_type' => $actorType,
            'actor_id' => $actorId && $actorId > 0 ? $actorId : null,
            'actor_label' => $actorLabel !== null
                ? mb_substr(trim($actorLabel), 0, 120, 'UTF-8')
                : null,
            'ip_hash' => security_rate_limit_identity($ip),
            'ip_masked' => security_mask_ip($ip),
            'request_path' => $requestPath !== ''
                ? mb_substr($requestPath, 0, 190, 'UTF-8')
                : null,
            'details_json' => $detailsJson,
        ]);
    } catch (Throwable $exception) {
        akino_log_exception($exception);
    }
}

function security_phone_label(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    return $digits !== '' ? 'Телефон ••••' . substr($digits, -4) : 'Телефон скрыт';
}

function security_event_label(string $eventType): string
{
    $labels = [
        'admin_login_success' => 'Успешный вход администратора',
        'admin_login_failed' => 'Неудачный вход администратора',
        'admin_login_blocked' => 'Вход администратора заблокирован',
        'admin_logout' => 'Выход администратора',
        'user_login_success' => 'Успешный вход пользователя',
        'user_login_blocked' => 'Вход заблокированного пользователя',
        'auth_code_requested' => 'Запрошен код подтверждения',
        'auth_code_failed' => 'Неверный код подтверждения',
        'auth_code_verified' => 'Код подтверждения принят',
        'auth_rate_blocked' => 'Перебор кодов заблокирован',
        'csrf_rejected' => 'Отклонён запрос без CSRF-токена',
        'origin_rejected' => 'Отклонён запрос с другого источника',
        'admin_action' => 'Действие администратора',
        'content_created' => 'Контент добавлен',
        'content_updated' => 'Контент изменён',
        'content_deleted' => 'Контент удалён',
        'episode_created' => 'Серия добавлена',
        'episode_updated' => 'Серия изменена',
        'episode_deleted' => 'Серия удалена',
        'admin_created' => 'Создан администратор',
        'admin_role_changed' => 'Изменена роль администратора',
        'admin_password_changed' => 'Изменён пароль администратора',
        'backup_created' => 'Создана резервная копия',
        'backup_verified' => 'Резервная копия проверена',
        'backup_failed' => 'Проверка копии не пройдена',
        'integrity_baseline_created' => 'Сохранён эталон файлов',
        'integrity_scan_clean' => 'Целостность файлов подтверждена',
        'integrity_scan_changed' => 'Обнаружены изменения файлов',
        'permission_denied' => 'Недостаточно прав',
    ];

    return $labels[$eventType] ?? str_replace('_', ' ', $eventType);
}

function fetch_security_events(int $limit = 40): array
{
    if (!ensure_security_center_support() || $limit <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT *
         FROM security_events
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', min($limit, 200), PDO::PARAM_INT);
    $statement->execute();

    return array_map(
        static function (array $event): array {
            $details = json_decode((string) ($event['details_json'] ?? ''), true);
            $event['details'] = is_array($details) ? $details : [];
            $event['label'] = security_event_label((string) ($event['event_type'] ?? ''));

            return $event;
        },
        $statement->fetchAll()
    );
}

function fetch_security_dashboard(): array
{
    $empty = [
        'events24h' => 0,
        'warnings7d' => 0,
        'blockedNow' => 0,
        'changedFiles' => 0,
        'trend' => [],
        'topSources' => [],
        'events' => [],
    ];

    if (!ensure_security_center_support()) {
        return $empty;
    }

    $stats = db()->query(
        'SELECT
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS events_24h,
            SUM(CASE WHEN severity IN ("warning", "critical") AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS warnings_7d
         FROM security_events'
    )->fetch() ?: [];

    $trendRows = db()->query(
        'SELECT DATE(created_at) AS event_date, COUNT(*) AS event_count
         FROM security_events
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(created_at)
         ORDER BY event_date ASC'
    )->fetchAll();
    $trendByDate = [];

    foreach ($trendRows as $row) {
        $trendByDate[(string) $row['event_date']] = (int) $row['event_count'];
    }

    $trend = [];

    for ($offset = 6; $offset >= 0; $offset--) {
        $date = (new DateTimeImmutable('-' . $offset . ' days'))->format('Y-m-d');
        $trend[] = [
            'date' => $date,
            'label' => (new DateTimeImmutable($date))->format('d.m'),
            'count' => (int) ($trendByDate[$date] ?? 0),
        ];
    }

    $topSources = db()->query(
        'SELECT ip_masked, COUNT(*) AS event_count
         FROM security_events
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND severity IN ("warning", "critical")
           AND ip_masked <> "unknown"
         GROUP BY ip_hash, ip_masked
         ORDER BY event_count DESC
         LIMIT 5'
    )->fetchAll();

    $blockedNow = akino_table_exists('security_rate_limits')
        ? (int) db()->query(
            'SELECT COUNT(*)
             FROM security_rate_limits
             WHERE blocked_until IS NOT NULL AND blocked_until > NOW()'
        )->fetchColumn()
        : 0;
    $changedFiles = (int) db()->query(
        'SELECT COUNT(*) FROM security_file_integrity WHERE status IN ("changed", "missing")'
    )->fetchColumn();

    return [
        'events24h' => (int) ($stats['events_24h'] ?? 0),
        'warnings7d' => (int) ($stats['warnings_7d'] ?? 0),
        'blockedNow' => $blockedNow,
        'changedFiles' => $changedFiles,
        'trend' => $trend,
        'topSources' => $topSources,
        'events' => fetch_security_events(50),
    ];
}

function security_backup_secret(): string
{
    $secret = trim((string) getenv('AKINO_BACKUP_SECRET'));

    if (strlen($secret) >= 32) {
        return $secret;
    }

    if (akino_is_production()) {
        return '';
    }

    return akino_auth_cookie_secret();
}

function security_backup_directory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
}

function security_backup_tables(): array
{
    return [
        'users',
        'auth_codes',
        'subscription_plans',
        'user_subscriptions',
        'movies',
        'seasons',
        'episodes',
        'movie_favorites',
        'watch_history',
        'watch_progress',
        'admin_accounts',
        'admin_user_action_logs',
        'security_rate_limits',
        'security_events',
        'security_file_integrity',
    ];
}

function create_encrypted_database_backup(?int $adminId = null): array
{
    if (!ensure_security_center_support()) {
        throw new RuntimeException('Центр безопасности недоступен.');
    }

    if (!extension_loaded('openssl')) {
        throw new RuntimeException('Для шифрования резервных копий требуется расширение OpenSSL.');
    }

    $secret = security_backup_secret();

    if (strlen($secret) < 32) {
        throw new RuntimeException('Укажите AKINO_BACKUP_SECRET длиной не менее 32 символов.');
    }

    $payload = [
        'format' => 'akino-encrypted-backup',
        'version' => 1,
        'createdAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        'tables' => [],
    ];

    foreach (security_backup_tables() as $table) {
        if (akino_table_exists($table)) {
            $payload['tables'][$table] = db()->query('SELECT * FROM `' . $table . '`')->fetchAll();
        }
    }

    $plainText = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $key = hash('sha256', $secret, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipherText === false) {
        throw new RuntimeException('Не удалось зашифровать резервную копию.');
    }

    $envelope = json_encode([
        'format' => 'akino-backup-envelope',
        'version' => 1,
        'cipher' => 'aes-256-gcm',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($cipherText),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $directory = security_backup_directory();

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог резервных копий.');
    }

    $filename = 'akino-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.backup';
    $path = $directory . DIRECTORY_SEPARATOR . $filename;

    if (file_put_contents($path, $envelope, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать резервную копию.');
    }

    @chmod($path, 0600);
    $checksum = hash_file('sha256', $path);
    $size = filesize($path);

    if ($checksum === false || $size === false) {
        @unlink($path);
        throw new RuntimeException('Не удалось вычислить контрольную сумму резервной копии.');
    }

    db()->prepare(
        'INSERT INTO security_backups (
            filename,
            checksum,
            size_bytes,
            status,
            created_by,
            created_at
        ) VALUES (
            :filename,
            :checksum,
            :size_bytes,
            "created",
            :created_by,
            NOW()
        )'
    )->execute([
        'filename' => $filename,
        'checksum' => $checksum,
        'size_bytes' => $size,
        'created_by' => $adminId && $adminId > 0 ? $adminId : null,
    ]);

    $backupId = (int) db()->lastInsertId();
    security_event_log('backup_created', 'info', 'admin', $adminId, null, [
        'backup_id' => $backupId,
        'size_bytes' => $size,
        'checksum_prefix' => substr($checksum, 0, 12),
    ]);

    return fetch_security_backup($backupId) ?? [];
}

function fetch_security_backup(int $backupId): ?array
{
    if (!ensure_security_center_support() || $backupId <= 0) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT backup.*, admin.display_name AS admin_name
         FROM security_backups backup
         LEFT JOIN admin_accounts admin ON admin.id = backup.created_by
         WHERE backup.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $backupId]);
    $backup = $statement->fetch();

    return $backup ?: null;
}

function fetch_security_backups(int $limit = 20): array
{
    if (!ensure_security_center_support() || $limit <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT backup.*, admin.display_name AS admin_name
         FROM security_backups backup
         LEFT JOIN admin_accounts admin ON admin.id = backup.created_by
         ORDER BY backup.created_at DESC, backup.id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', min($limit, 100), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function verify_encrypted_database_backup(int $backupId, ?int $adminId = null): bool
{
    $backup = fetch_security_backup($backupId);

    if (!$backup) {
        throw new RuntimeException('Резервная копия не найдена.');
    }

    $filename = basename((string) $backup['filename']);
    $path = security_backup_directory() . DIRECTORY_SEPARATOR . $filename;
    $verified = false;

    try {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Файл резервной копии недоступен.');
        }

        $checksum = hash_file('sha256', $path);

        if ($checksum === false || !hash_equals((string) $backup['checksum'], $checksum)) {
            throw new RuntimeException('Контрольная сумма резервной копии не совпадает.');
        }

        $envelope = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $secret = security_backup_secret();
        $iv = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['tag'] ?? ''), true);
        $cipherText = base64_decode((string) ($envelope['data'] ?? ''), true);

        if (strlen($secret) < 32 || $iv === false || $tag === false || $cipherText === false) {
            throw new RuntimeException('Структура резервной копии повреждена.');
        }

        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            hash('sha256', $secret, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Не удалось расшифровать резервную копию.');
        }

        $payload = json_decode($plainText, true, 512, JSON_THROW_ON_ERROR);

        if (($payload['format'] ?? '') !== 'akino-encrypted-backup' || !is_array($payload['tables'] ?? null)) {
            throw new RuntimeException('Формат резервной копии не распознан.');
        }

        $verified = true;
    } catch (Throwable $exception) {
        akino_log_exception($exception);
    }

    db()->prepare(
        'UPDATE security_backups
         SET status = :status,
             verified_at = NOW()
         WHERE id = :id'
    )->execute([
        'id' => $backupId,
        'status' => $verified ? 'verified' : 'failed',
    ]);

    security_event_log(
        $verified ? 'backup_verified' : 'backup_failed',
        $verified ? 'info' : 'critical',
        'admin',
        $adminId,
        null,
        ['backup_id' => $backupId]
    );

    return $verified;
}

function security_integrity_file_list(): array
{
    $root = dirname(__DIR__);
    $directories = ['src', 'public', 'config'];
    $allowedExtensions = ['php', 'js', 'css', 'json'];
    $files = [];

    foreach ($directories as $directory) {
        $absoluteDirectory = $root . DIRECTORY_SEPARATOR . $directory;

        if (!is_dir($absoluteDirectory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (!in_array($extension, $allowedExtensions, true)) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($root) + 1));
            $files[$relativePath] = $absolutePath;
        }
    }

    foreach (['database/akino.sql', 'public/.htaccess', 'vercel.json'] as $relativePath) {
        $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (is_file($absolutePath)) {
            $files[$relativePath] = $absolutePath;
        }
    }

    ksort($files);

    return $files;
}

function record_file_integrity_baseline(?int $adminId = null): array
{
    if (!ensure_security_center_support()) {
        throw new RuntimeException('Контроль целостности недоступен.');
    }

    $files = security_integrity_file_list();
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $pdo->exec('DELETE FROM security_file_integrity');
        $statement = $pdo->prepare(
            'INSERT INTO security_file_integrity (
                path,
                checksum,
                size_bytes,
                status,
                recorded_by,
                recorded_at,
                checked_at
            ) VALUES (
                :path,
                :checksum,
                :size_bytes,
                "clean",
                :recorded_by,
                NOW(),
                NOW()
            )'
        );

        foreach ($files as $relativePath => $absolutePath) {
            $checksum = hash_file('sha256', $absolutePath);
            $size = filesize($absolutePath);

            if ($checksum === false || $size === false) {
                throw new RuntimeException('Не удалось проверить файл: ' . $relativePath);
            }

            $statement->execute([
                'path' => $relativePath,
                'checksum' => $checksum,
                'size_bytes' => $size,
                'recorded_by' => $adminId && $adminId > 0 ? $adminId : null,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    security_event_log('integrity_baseline_created', 'info', 'admin', $adminId, null, [
        'files_count' => count($files),
    ]);

    return ['total' => count($files), 'clean' => count($files), 'changed' => 0, 'missing' => 0];
}

function scan_file_integrity(?int $adminId = null): array
{
    if (!ensure_security_center_support()) {
        throw new RuntimeException('Контроль целостности недоступен.');
    }

    $rows = db()->query(
        'SELECT * FROM security_file_integrity ORDER BY path ASC'
    )->fetchAll();

    if (!$rows) {
        throw new RuntimeException('Сначала сохраните эталонное состояние файлов.');
    }

    $root = dirname(__DIR__);
    $counts = ['total' => count($rows), 'clean' => 0, 'changed' => 0, 'missing' => 0];
    $update = db()->prepare(
        'UPDATE security_file_integrity
         SET status = :status,
             checked_at = NOW()
         WHERE path = :path'
    );

    foreach ($rows as $row) {
        $relativePath = (string) $row['path'];
        $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $status = 'clean';

        if (!is_file($absolutePath)) {
            $status = 'missing';
        } else {
            $checksum = hash_file('sha256', $absolutePath);

            if ($checksum === false || !hash_equals((string) $row['checksum'], $checksum)) {
                $status = 'changed';
            }
        }

        $counts[$status]++;
        $update->execute(['path' => $relativePath, 'status' => $status]);
    }

    $hasChanges = $counts['changed'] > 0 || $counts['missing'] > 0;
    security_event_log(
        $hasChanges ? 'integrity_scan_changed' : 'integrity_scan_clean',
        $hasChanges ? 'critical' : 'info',
        'admin',
        $adminId,
        null,
        $counts
    );

    return $counts;
}

function fetch_file_integrity_status(int $limit = 40): array
{
    if (!ensure_security_center_support() || $limit <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT *
         FROM security_file_integrity
         ORDER BY FIELD(status, "changed", "missing", "clean"), path ASC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', min($limit, 200), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}
