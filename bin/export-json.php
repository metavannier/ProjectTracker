<?php
declare(strict_types=1);

/*
 * Writes all projects in the same JSON format as the "Export Data as JSON"
 * button (useful for backups or to compare with the original JSON file).
 *
 *   php bin/export-json.php                 > backup.json
 *   php bin/export-json.php --output=/path/backup.json
 */

require __DIR__ . '/../src/bootstrap.php';

use ProjectTracker\Config;
use ProjectTracker\Database;
use ProjectTracker\ProjectRepository;

$options = getopt('', ['output:']);

try {
    $database = new Database(Config::fromEnvironment(web: false));
    $projects = $database->snapshot(static fn (PDO $pdo) => (new ProjectRepository($pdo))->all());
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$json = json_encode([
    'projects' => $projects,
    'exportedAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
    'source' => 'Project Time Tracker by Thomas Vannier',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (isset($options['output'])) {
    if (file_put_contents($options['output'], $json) === false) {
        fwrite(STDERR, "ERROR: cannot write {$options['output']}" . PHP_EOL);
        exit(1);
    }
    fwrite(STDERR, count($projects) . " project(s) exported to {$options['output']}" . PHP_EOL);
} else {
    echo $json;
}
