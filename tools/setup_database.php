<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This setup script must be run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);

require_once $root . '/src/env.php';

$args = array_slice($argv, 1);
$skipSchema = in_array('--skip-schema', $args, true);
$skipSeed = in_array('--skip-seed', $args, true);
$schemaPath = $root . '/database/akino.sql';

function setup_config(): array
{
    return require dirname(__DIR__) . '/config/database.php';
}

function setup_mysql_pdo(array $config, bool $withDatabase): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s%s',
        $config['host'],
        (int) $config['port'],
        $config['charset'],
        $withDatabase ? ';dbname=' . $config['database'] : ''
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
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

    return new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        $options
    );
}

function setup_sql_statements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $statements = [];

    foreach ($parts as $part) {
        $statement = trim($part);

        if ($statement !== '') {
            $statements[] = $statement;
        }
    }

    return $statements;
}

function setup_column_exists(PDO $pdo, string $database, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = :database_name
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $statement->execute([
        'database_name' => $database,
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (bool) $statement->fetchColumn();
}

try {
    $config = setup_config();

    if (!$skipSchema) {
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema file not found: ' . $schemaPath);
        }

        $pdo = setup_mysql_pdo($config, false);
        $databaseName = (string) ($config['database'] ?? '');

        if ($databaseName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $databaseName)) {
            throw new RuntimeException('Invalid database name.');
        }

        $quotedDatabase = '`' . str_replace('`', '``', $databaseName) . '`';
        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $quotedDatabase
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $pdo->exec('USE ' . $quotedDatabase);
        $sql = file_get_contents($schemaPath);

        if ($sql === false) {
            throw new RuntimeException('Could not read schema file: ' . $schemaPath);
        }

        foreach (setup_sql_statements($sql) as $statement) {
            if (preg_match('/^(?:CREATE\s+DATABASE|USE)\b/i', $statement)) {
                continue;
            }

            $pdo->exec($statement);
        }

        $databasePdo = setup_mysql_pdo($config, true);

        if (!setup_column_exists($databasePdo, $config['database'], 'auth_codes', 'attempt_count')) {
            $databasePdo->exec(
                'ALTER TABLE auth_codes
                 ADD COLUMN attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at'
            );
        }

        if (!setup_column_exists($databasePdo, $config['database'], 'admin_accounts', 'role')) {
            $databasePdo->exec(
                'ALTER TABLE admin_accounts
                 ADD COLUMN role VARCHAR(24) NOT NULL DEFAULT "owner" AFTER avatar_path'
            );
        }

        echo "Schema applied from database/akino.sql\n";
    }

    if (!$skipSeed) {
        putenv('AKINO_RUNTIME_BOOTSTRAP=1');
        $_ENV['AKINO_RUNTIME_BOOTSTRAP'] = '1';
        $_SERVER['AKINO_RUNTIME_BOOTSTRAP'] = '1';

        require_once $root . '/src/bootstrap.php';

        ensure_movie_library();
        ensure_playback_library();
        ensure_admin_support();

        if (!admin_support_available()) {
            throw new RuntimeException('Admin schema was not initialized. Check database permissions and AKINO_ADMIN_PASSWORD.');
        }

        echo "Seed data and default admin bootstrap completed\n";
    }

    echo "Done\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[setup failed] ' . $exception->getMessage() . "\n");
    exit(1);
}
