<?php
declare(strict_types=1);

/*
 * Tests of bin/migrate-json.php. Runs INSIDE the test web container against
 * the EMPTY in-memory test database (see tests/run-tests.sh).
 * Leaves the database containing the fixture, for tests/api-test.php.
 */

require '/var/www/projecttracker/src/bootstrap.php';

use ProjectTracker\Config;
use ProjectTracker\Database;

const MIGRATE = 'php /var/www/projecttracker/bin/migrate-json.php';
const EXPORT = 'php /var/www/projecttracker/bin/export-json.php';
const FIXTURE = '/tests/fixtures/legacy-export.json';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

/** @return array{0: int, 1: string} exit code and output */
function run(string $command): array
{
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

$pdo = (new Database(Config::fromEnvironment(web: false)))->pdo();
$count = static fn () => (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
$fixture = json_decode((string) file_get_contents(FIXTURE), true);
check($count() === 0, 'test database starts empty');

// Invalid data: nothing written, every problem reported
$tmp = sys_get_temp_dir();
file_put_contents("$tmp/bad.json", json_encode(['projects' => [
    ['id' => 1, 'title' => 'ok', 'totalHours' => '1:00:00'],
    ['id' => 2, 'title' => 'bad date', 'startDate' => '31/02/2026'],
    ['id' => 3, 'title' => 'bad hours', 'totalHours' => 'two hours'],
    ['id' => 4, 'title' => 'bad status', 'status' => 'archived'],
]]));
[$code, $out] = run(MIGRATE . " $tmp/bad.json");
check($code === 1 && $count() === 0, 'invalid source is refused and nothing is written');
check(substr_count($out, 'ERROR') === 3, 'each invalid value is reported (' . substr_count($out, 'ERROR') . ' errors)');

// Dry run: nothing written
[$code, $out] = run(MIGRATE . ' --dry-run ' . FIXTURE);
check($code === 0 && str_contains($out, 'DRY RUN') && $count() === 0, 'dry run verifies and writes nothing');

// Real migration
[$code, $out] = run(MIGRATE . ' ' . FIXTURE);
check($code === 0 && str_contains($out, 'Migration completed successfully'), 'migration succeeds');
check($count() === count($fixture['projects']), 'all projects migrated');

// Round trip: exporting again gives exactly the original data
[$code, $out] = run(EXPORT);
$export = json_decode($out, true);
check($code === 0 && json_encode($export['projects']) === json_encode($fixture['projects']), 'export after migration is identical to the source');

// Legacy record kept verbatim in the history
$logged = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'migrate'")->fetchColumn();
check($logged === count($fixture['projects']), 'a verbatim copy of each legacy record is kept in activity_log');

// Second run refused
[$code] = run(MIGRATE . ' ' . FIXTURE);
check($code === 1 && $count() === count($fixture['projects']), 'running the migration twice is refused');

// Legacy "project" directory containing two files with the same id (left by the
// old duplicate-id bug): both projects are kept, the second one gets a new id.
@mkdir("$tmp/project");
array_map('unlink', glob("$tmp/project/*.json") ?: []);
foreach ($fixture['projects'] as $p) {
    file_put_contents("$tmp/project/{$p['id']}_x.json", json_encode($p));
}
$dup = $fixture['projects'][0];
$dup['title'] = 'Created at the same time on another computer';
file_put_contents("$tmp/project/{$dup['id']}_y.json", json_encode($dup));
[$code, $out] = run(MIGRATE . " --replace $tmp/project");
check($code === 0 && $count() === count($fixture['projects']) + 1, 'directory source: duplicate id kept as a new project');
check(str_contains($out, 'is used twice'), 'duplicate id is reported');
$newId = (int) $pdo->query("SELECT id FROM projects WHERE title = 'Created at the same time on another computer'")->fetchColumn();
check($newId > 30, "duplicate received a new free id (#$newId)");

// Restore the fixture for the API tests
[$code] = run(MIGRATE . ' --replace ' . FIXTURE);
check($code === 0 && $count() === count($fixture['projects']), '--replace restores the fixture');

if ($failures > 0) {
    echo "$failures migration test(s) FAILED" . PHP_EOL;
    exit(1);
}
echo 'Migration tests passed.' . PHP_EOL;
