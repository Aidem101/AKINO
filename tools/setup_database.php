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

    return new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
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

try {
    $config = setup_config();

    if (!$skipSchema) {
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema file not found: ' . $schemaPath);
        }

        $pdo = setup_mysql_pdo($config, false);
        $sql = file_get_contents($schemaPath);

        if ($sql === false) {
            throw new RuntimeException('Could not read schema file: ' . $schemaPath);
        }

        foreach (setup_sql_statements($sql) as $statement) {
            $pdo->exec($statement);
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
