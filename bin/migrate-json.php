<?php
declare(strict_types=1);

/*
 * One-time migration of the legacy JSON data into the SQL database.
 *
 *   php bin/migrate-json.php [--dry-run] [--replace] <source>
 *
 * <source> can be:
 *   - a file exported with "Export Data as JSON" (e.g. centuri-projects.json)
 *   - the legacy projects.json file (a plain array of projects)
 *   - the legacy "project" directory (one JSON file per project)
 *   - "-" to read an exported file from standard input
 *
 * Guarantees:
 *   - project ids, titles, people, institutes, categories, statuses, dates,
 *     recorded hours, "last update" values and display order are kept;
 *   - a verbatim copy of every legacy record is stored in activity_log;
 *   - everything happens in ONE transaction, and the result is read back and
 *     compared field by field with the source BEFORE committing: if anything
 *     differs, nothing is written;
 *   - --dry-run performs the whole migration and verification, then rolls back.
 */

require __DIR__ . '/../src/bootstrap.php';

use ProjectTracker\Config;
use ProjectTracker\Database;
use ProjectTracker\Format;
use ProjectTracker\ProjectInput;
use ProjectTracker\ProjectRepository;

const USAGE = <<<TXT
Usage: php bin/migrate-json.php [--dry-run] [--replace] <source>

  <source>    centuri-projects.json (export), projects.json, the "project" directory,
              or "-" to read the JSON from standard input
  --dry-run   check everything and show the report, but do not write anything
  --replace   delete the projects currently in the database before importing
              (only needed to run the migration a second time)

TXT;

final class MigrationFailed extends RuntimeException
{
}

function out(string $line = ''): void
{
    echo $line . PHP_EOL;
}

/**
 * @return array{items: list<array{label: string, data: mixed, file: string}>, meta: array<string, mixed>}
 */
function loadSource(string $source): array
{
    $decode = static function (string $json, string $name): mixed {
        // Remove a UTF-8 byte order mark if present
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);
        try {
            return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MigrationFailed("$name is not valid JSON: {$e->getMessage()}");
        }
    };

    if ($source === '-') {
        $data = $decode((string) stream_get_contents(STDIN), 'standard input');
        $name = 'stdin';
    } elseif (is_dir($source)) {
        $files = glob(rtrim($source, '/') . '/*.json') ?: [];
        natsort($files);
        if ($files === []) {
            throw new MigrationFailed("No .json file found in directory $source");
        }
        $items = [];
        foreach ($files as $file) {
            $items[] = ['label' => basename($file), 'data' => $decode((string) file_get_contents($file), basename($file)), 'file' => basename($file)];
        }
        return ['items' => $items, 'meta' => ['format' => 'directory with ' . count($files) . ' project file(s)']];
    } elseif (is_file($source)) {
        $data = $decode((string) file_get_contents($source), basename($source));
        $name = basename($source);
    } else {
        throw new MigrationFailed("Source not found: $source");
    }

    if (is_array($data) && array_is_list($data)) {
        $projects = $data;
        $meta = ['format' => 'plain array of projects (projects.json)'];
    } elseif (is_array($data) && isset($data['projects']) && is_array($data['projects'])) {
        $projects = $data['projects'];
        $meta = ['format' => 'export file', 'exportedAt' => $data['exportedAt'] ?? null, 'source' => $data['source'] ?? null];
    } else {
        throw new MigrationFailed("$name does not contain a projects array");
    }

    $items = [];
    foreach ($projects as $i => $p) {
        $items[] = ['label' => ProjectInput::label($p, $i), 'data' => $p, 'file' => $name];
    }
    return ['items' => $items, 'meta' => $meta];
}

/**
 * Compares one migrated project (as the application now returns it) with the
 * original legacy record. Returns [blocking differences, format-only notes].
 *
 * @param array<string, mixed> $migrated
 * @param array<string, mixed> $original
 * @return array{0: list<string>, 1: list<string>}
 */
function compareProject(array $migrated, array $original, bool $idKept): array
{
    $errors = [];
    $notes = [];

    if ($idKept && $migrated['id'] !== (int) $original['id']) {
        $errors[] = "id {$original['id']} became {$migrated['id']}";
    }
    foreach (['title', 'institute', 'category'] as $field) {
        $expected = (string) ($original[$field] ?? '');
        if ($migrated[$field] !== $expected) {
            $errors[] = "$field differs";
        }
    }
    foreach (['researchers', 'engineers'] as $field) {
        $expected = array_map('strval', $original[$field] ?? []);
        if ($migrated[$field] !== $expected) {
            $errors[] = "$field differ";
        }
    }
    if ($migrated['status'] !== ($original['status'] ?? 'active')) {
        $errors[] = 'status differs';
    }

    $sameText = static fn (string $field) => $migrated[$field] === (string) ($original[$field] ?? '');
    foreach (['submissionDate', 'startDate', 'endDate'] as $field) {
        if (!$sameText($field)) {
            if (Format::parseDate($migrated[$field]) === Format::parseDate($original[$field] ?? null)) {
                $notes[] = "$field \"{$original[$field]}\" is now written \"{$migrated[$field]}\"";
            } else {
                $errors[] = "$field differs";
            }
        }
    }
    if (!$sameText('totalHours')) {
        if (Format::parseDuration($migrated['totalHours']) === Format::parseDuration((string) ($original['totalHours'] ?? '0:00:00'))) {
            $notes[] = "totalHours \"" . ($original['totalHours'] ?? '') . "\" is now written \"{$migrated['totalHours']}\"";
        } else {
            $errors[] = 'totalHours differs';
        }
    }
    if (!$sameText('lastUpdate')) {
        if (Format::parseStamp($migrated['lastUpdate']) === Format::parseStamp($original['lastUpdate'] ?? null)) {
            $notes[] = 'lastUpdate ' . json_encode($original['lastUpdate']) . ' is now written ' . json_encode($migrated['lastUpdate']);
        } else {
            $errors[] = 'lastUpdate differs';
        }
    }
    $expectedOrder = isset($original['order']) ? (int) $original['order'] : null;
    if (($migrated['order'] ?? null) !== $expectedOrder) {
        $errors[] = 'order differs';
    }
    return [$errors, $notes];
}

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$replace = in_array('--replace', $args, true);
$positional = array_values(array_filter($args, static fn ($a) => $a === '-' || !str_starts_with($a, '--')));
$unknown = array_diff(array_filter($args, static fn ($a) => str_starts_with($a, '--')), ['--dry-run', '--replace']);
if (count($positional) !== 1 || $unknown !== []) {
    fwrite(STDERR, USAGE);
    exit(2);
}

try {
    $source = loadSource($positional[0]);
    out('Source: ' . $positional[0] . ' (' . $source['meta']['format'] . ')');
    if (!empty($source['meta']['exportedAt'])) {
        out('Exported at: ' . $source['meta']['exportedAt']);
    }
    out('Projects found: ' . count($source['items']));
    out();

    // 1. Validate every record ------------------------------------------------
    $errors = [];
    $warnings = [];
    $records = [];
    $seenIds = [];
    foreach ($source['items'] as $item) {
        $record = ProjectInput::legacy($item['data'], $item['label'], $errors, $warnings);
        if ($record === null) {
            continue;
        }
        $idKept = true;
        if ($record['id'] === null) {
            $warnings[] = "{$item['label']}: has no id — it will receive a new id";
            $idKept = false;
        } elseif (isset($seenIds[$record['id']])) {
            $warnings[] = "{$item['label']}: id #{$record['id']} is used twice (also by {$seenIds[$record['id']]}) — this one will receive a new id so that both are kept";
            $record['id'] = null;
            $idKept = false;
        } else {
            $seenIds[$record['id']] = $item['label'];
        }
        $records[] = ['record' => $record, 'item' => $item, 'idKept' => $idKept];
    }
    foreach ($warnings as $warning) {
        out("WARNING  $warning");
    }
    if ($errors !== []) {
        foreach ($errors as $error) {
            out("ERROR    $error");
        }
        throw new MigrationFailed(count($errors) . ' problem(s) found in the source data. Nothing was written. Fix the source file and run the migration again.');
    }
    if ($records === []) {
        throw new MigrationFailed('The source contains no project. Nothing to migrate.');
    }

    // Insert projects that keep their id first, so new ids never collide with them
    usort($records, static fn ($a, $b) => ($b['idKept'] <=> $a['idKept']));

    // 2. Database ---------------------------------------------------------------
    $database = new Database(Config::fromEnvironment(web: false));
    $database->applySchema();
    $pdo = $database->pdo();

    $existing = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($existing > 0 && !$replace) {
        throw new MigrationFailed(
            "The database already contains $existing project(s) (including deleted ones). Nothing was written.\n"
            . 'If you really want to replace them with the content of the source, run again with --replace.'
        );
    }

    $pdo->beginTransaction();
    try {
        $repo = new ProjectRepository($pdo);
        if ($existing > 0) {
            out("--replace: removing the $existing project(s) currently in the database.");
            $pdo->exec('DELETE FROM project_members');
            $pdo->exec('DELETE FROM projects');
            $pdo->prepare("INSERT INTO activity_log (action, details) VALUES ('migrate_replace', ?)")
                ->execute([json_encode(['removedProjects' => $existing])]);
        }

        $log = $pdo->prepare(
            "INSERT INTO activity_log (action, project_id, seconds_delta, total_seconds_after, details) VALUES ('migrate', ?, ?, ?, ?)"
        );
        foreach ($records as &$entry) {
            $entry['newId'] = $repo->insert($entry['record']);
            $total = (int) $entry['record']['total_seconds'];
            $log->execute([
                $entry['newId'],
                $total,
                $total,
                json_encode(['file' => $entry['item']['file'], 'source' => $entry['item']['data']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        unset($entry);

        // 3. Verify: read everything back through the application code ------------
        $migrated = [];
        foreach ($repo->all() as $project) {
            $migrated[$project['id']] = $project;
        }
        $problems = [];
        $notes = [];
        $sourceSeconds = 0;
        $dbSeconds = 0;
        foreach ($records as $entry) {
            $project = $migrated[$entry['newId']] ?? null;
            if ($project === null) {
                $problems[] = "{$entry['item']['label']}: missing after migration";
                continue;
            }
            [$diff, $remarks] = compareProject($project, $entry['item']['data'], $entry['idKept']);
            foreach ($diff as $d) {
                $problems[] = "{$entry['item']['label']}: $d";
            }
            foreach ($remarks as $r) {
                $notes[] = "{$entry['item']['label']}: $r";
            }
            $sourceSeconds += (int) $entry['record']['total_seconds'];
            $dbSeconds += Format::parseDuration($project['totalHours']);
        }
        if (count($migrated) !== count($records)) {
            $problems[] = 'the database contains ' . count($migrated) . ' project(s) but ' . count($records) . ' were imported';
        }
        if ($sourceSeconds !== $dbSeconds) {
            $problems[] = 'total recorded time differs: ' . Format::formatDuration($sourceSeconds) . ' in the source, ' . Format::formatDuration($dbSeconds) . ' in the database';
        }
        if ($problems !== []) {
            foreach ($problems as $problem) {
                out("ERROR    $problem");
            }
            throw new MigrationFailed('Verification failed. Nothing was written.');
        }

        // 4. Report ---------------------------------------------------------------------
        out(sprintf('%-5s %-55s %-9s %12s', 'ID', 'Title', 'Status', 'Total hours'));
        out(str_repeat('-', 84));
        foreach ($migrated as $project) {
            out(sprintf('%-5d %-55s %-9s %12s', $project['id'], mb_strimwidth($project['title'], 0, 55, '…'), $project['status'], $project['totalHours']));
        }
        out(str_repeat('-', 84));
        $active = count(array_filter($migrated, static fn ($p) => $p['status'] === 'active'));
        out(sprintf('%d project(s): %d active, %d finished. Total recorded time: %s', count($migrated), $active, count($migrated) - $active, Format::formatDuration($dbSeconds)));
        out();
        foreach ($notes as $note) {
            out("NOTE     $note (same value, different notation)");
        }
        out('Verification: every project was read back and matches the source (ids, titles, people, institutes,');
        out('categories, statuses, dates, recorded hours, last update and display order).');

        if ($dryRun) {
            $pdo->rollBack();
            out();
            out('DRY RUN: nothing was written. Run again without --dry-run to perform the migration.');
        } else {
            $pdo->commit();
            out();
            out('Migration completed successfully.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (MigrationFailed $e) {
    fwrite(STDERR, PHP_EOL . 'MIGRATION ABORTED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'MIGRATION ABORTED (nothing was written): ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
