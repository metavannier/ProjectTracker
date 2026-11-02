<?php
declare(strict_types=1);

/*
 * Waits until the database accepts connections, then creates / upgrades the
 * tables (database/schema.sql is idempotent). Run automatically by the
 * container entrypoint before Apache starts.
 *
 *   php bin/init-db.php [--wait=SECONDS] [--check-web-config]
 */

require __DIR__ . '/../src/bootstrap.php';

use ProjectTracker\Config;
use ProjectTracker\Database;

$options = getopt('', ['wait::', 'check-web-config']);
$wait = isset($options['wait']) ? max(0, (int) $options['wait']) : 90;

try {
    // Fail fast (like the previous version) when PASSWORD / SESSION_SECRET are missing
    $config = Config::fromEnvironment(web: isset($options['check-web-config']));
} catch (RuntimeException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$database = new Database($config);
$deadline = time() + $wait;
while (true) {
    try {
        $database->pdo();
        break;
    } catch (PDOException $e) {
        if (time() >= $deadline) {
            fwrite(STDERR, "ERROR: cannot connect to the database at {$config->dbHost}:{$config->dbPort}: {$e->getMessage()}" . PHP_EOL);
            exit(1);
        }
        fwrite(STDERR, "Waiting for the database at {$config->dbHost}:{$config->dbPort}..." . PHP_EOL);
        sleep(2);
    }
}

try {
    $database->applySchema();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: cannot create the database tables: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$count = (int) $database->pdo()->query('SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL')->fetchColumn();
echo "Database ready ({$config->dbName}): $count project(s)." . PHP_EOL;
if ($count === 0) {
    echo 'The database is empty. To import your existing data, see "Migrating the existing JSON data" in README.md.' . PHP_EOL;
}
