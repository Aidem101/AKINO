<?php

declare(strict_types=1);

function db_config(): array
{
    static $config;

    if ($config === null) {
        $config = require __DIR__ . '/../config/database.php';
    }

    return $config;
}

function db(): PDO
{
    static $pdo;
    static $failedConnection;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($failedConnection instanceof Throwable) {
        throw $failedConnection;
    }

    if ((string) getenv('AKINO_FORCE_DB_FALLBACK') === '1') {
        throw new RuntimeException('Database fallback forced by AKINO_FORCE_DB_FALLBACK.');
    }

    $config = db_config();
    $hosts = array_values(array_unique(array_filter(array_merge(
        [(string) ($config['host'] ?? '')],
        is_array($config['fallback_hosts'] ?? null) ? $config['fallback_hosts'] : []
    ))));
    $dsnList = [];
    $lastException = null;

    if (!empty($config['socket'])) {
        $dsnList[] = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            $config['socket'],
            $config['database'],
            $config['charset']
        );
    }

    foreach ($hosts as $host) {
        $dsnList[] = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            (int) $config['port'],
            $config['database'],
            $config['charset']
        );
    }

    foreach (array_values(array_unique($dsnList)) as $dsn) {
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => max(1, (int) ($config['timeout'] ?? 1)),
            ];
            $sslCa = trim((string) ($config['ssl_ca'] ?? ''));

            if ($sslCa !== '') {
                if (!is_file($sslCa) || !is_readable($sslCa)) {
                    throw new RuntimeException('Configured database CA file is not readable.');
                }

                if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                    $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $sslCa;
                }

                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] =
                        (bool) ($config['ssl_verify_server_cert'] ?? true);
                }
            }

            $pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $options
            );

            return $pdo;
        } catch (PDOException $exception) {
            $lastException = $exception;
        }
    }

    $failedConnection = $lastException ?? new RuntimeException('Unable to connect to the database.');

    throw $failedConnection;
}
