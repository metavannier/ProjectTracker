<?php
declare(strict_types=1);

namespace ProjectTracker;

use InvalidArgumentException;
use PDO;

/**
 * All the changes the interface can make, each one as a small, targeted,
 * atomic database transaction.
 *
 * Why this fixes the lost/overwritten data of the JSON version:
 *  - the browser never sends back "the whole list" any more: each action only
 *    touches the project(s) it is about;
 *  - recorded time is ADDED on the server (total = total + n), so two
 *    engineers recording time at the same moment both get their hours;
 *  - a manual time correction or a details edit made from an out-of-date
 *    screen is detected and refused instead of silently overwriting newer data;
 *  - project ids are assigned by the database (no more duplicate ids);
 *  - every change carries a unique operation id: if the same change is sent
 *    twice (network retry, restore after an expired session, two open tabs)
 *    it is applied only once;
 *  - every change is written to activity_log (complete history).
 */
final class ProjectService
{
    /** Longest duration accepted for one timer session (sanity check). */
    private const MAX_TIMER_SECONDS = 366 * 24 * 3600;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly ?string $clientIp = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->db->snapshot(static fn (PDO $pdo) => (new ProjectRepository($pdo))->all());
    }

    // ------------------------------------------------------------------ create

    /** @param array<string, mixed> $data */
    public function create(array $data, ?string $opId): void
    {
        $details = ProjectInput::details($data);
        $now = Format::now($this->config->timezone);
        $today = $now->format('Y-m-d');

        $submission = $this->parse(fn () => Format::parseDate($data['submissionDate'] ?? null), 'submissionDate') ?? $today;
        $start = $this->parse(fn () => Format::parseDate($data['startDate'] ?? null), 'startDate') ?? $today;
        [$luDate, $luTime] = $this->parse(fn () => Format::parseStamp($data['lastUpdate'] ?? null), 'lastUpdate');
        if ($luDate === null) {
            [$luDate, $luTime] = [$today, $now->format('H:i:s')];
        }

        $record = $details + [
            'status' => 'active',
            'submission_date' => $submission,
            'start_date' => $start,
            'end_date' => null,
            'total_seconds' => 0,
            'last_update_date' => $luDate,
            'last_update_time' => $luTime,
            'sort_order' => null,
        ];

        $this->change($opId, 'create', function (PDO $pdo, ProjectRepository $repo) use ($record, $opId) {
            $id = $repo->insert($record);
            $this->log($pdo, $opId, 'create', $id, null, 0, ['project' => $repo->find($id)]);
        });
    }

    // -------------------------------------------------------- edit the details

    /**
     * Updates title / researchers / engineers / institute / category.
     *
     * When the browser sends the values it started from ("original"), the
     * change is merged field by field: fields this user did not touch keep any
     * newer value saved by someone else, and a field changed by both users is
     * reported as a conflict (HTTP 409) instead of being overwritten.
     *
     * @param array<string, mixed> $data
     */
    public function updateDetails(int $id, array $data, ?string $opId): void
    {
        $mine = ProjectInput::details($data);
        $original = $this->originalDetails($data['original'] ?? null);

        $this->change($opId, 'update', function (PDO $pdo, ProjectRepository $repo) use ($id, $mine, $original, $opId) {
            $current = $repo->find($id, lock: true) ?? throw $this->notFound();
            $before = array_intersect_key($current, array_flip(ProjectInput::DETAIL_FIELDS));

            $result = [];
            $conflicts = [];
            foreach (ProjectInput::DETAIL_FIELDS as $field) {
                if ($original === null) {
                    $result[$field] = $mine[$field];
                } elseif ($mine[$field] === $original[$field]) {
                    $result[$field] = $before[$field];            // not changed by this user
                } elseif ($before[$field] === $original[$field] || $before[$field] === $mine[$field]) {
                    $result[$field] = $mine[$field];              // changed by this user only
                } else {
                    $conflicts[] = $field;                        // changed by both: refuse
                }
            }
            if ($conflicts !== []) {
                throw new ApiException(
                    409,
                    'This project was modified by another user while you were editing it (' . implode(', ', $conflicts)
                    . '). The latest version is now displayed — please apply your changes again.',
                    ['conflicts' => $conflicts]
                );
            }
            if ($result === $before) {
                return;
            }

            $pdo->prepare('UPDATE projects SET title = ?, institute = ?, category = ? WHERE id = ?')
                ->execute([$result['title'], $result['institute'], $result['category'], $id]);
            if ($result['researchers'] !== $before['researchers'] || $result['engineers'] !== $before['engineers']) {
                $repo->replaceMembers($id, $result['researchers'], $result['engineers']);
            }
            $this->log($pdo, $opId, 'update', $id, null, null, ['before' => $before, 'after' => $result]);
        });
    }

    // -------------------------------------------------------------------- time

    /** Adds the time measured by the timer (atomic increment). */
    public function addTime(int $id, mixed $seconds, mixed $lastUpdate, ?string $opId): void
    {
        if (!is_int($seconds) || $seconds < 0 || $seconds > self::MAX_TIMER_SECONDS) {
            throw new ApiException(400, 'Invalid duration');
        }
        $stamp = $this->stamp($lastUpdate);

        $this->change($opId, 'add_time', function (PDO $pdo, ProjectRepository $repo) use ($id, $seconds, $stamp, $opId) {
            $row = $repo->row($id, lock: true) ?? throw $this->notFound();
            [$date, $time] = $this->latestStamp($row, $stamp);
            $pdo->prepare(
                'UPDATE projects SET total_seconds = total_seconds + ?, last_update_date = ?, last_update_time = ? WHERE id = ?'
            )->execute([$seconds, $date, $time, $id]);

            $total = (int) $row['total_seconds'] + $seconds;
            $this->log($pdo, $opId, 'add_time', $id, $seconds, $total, [
                'lastUpdate' => Format::formatStamp($stamp[0], $stamp[1]),
            ]);
        });
    }

    /**
     * "Edit time manually": replaces the total. $expected is the total that
     * was displayed to the user; if somebody recorded time in the meantime the
     * change is refused (HTTP 409) instead of erasing their hours.
     */
    public function setTime(int $id, mixed $totalHours, mixed $expected, mixed $lastUpdate, ?string $opId): void
    {
        $newTotal = Format::parseDuration($totalHours, strict: true);
        if ($newTotal === null) {
            throw new ApiException(400, 'Invalid time format. Use HH:MM:SS');
        }
        $expectedTotal = null;
        if ($expected !== null) {
            $expectedTotal = Format::parseDuration($expected) ?? throw new ApiException(400, 'Invalid expected time');
        }
        $stamp = $this->stamp($lastUpdate);

        $this->change($opId, 'set_time', function (PDO $pdo, ProjectRepository $repo) use ($id, $newTotal, $expectedTotal, $stamp, $opId) {
            $row = $repo->row($id, lock: true) ?? throw $this->notFound();
            $oldTotal = (int) $row['total_seconds'];
            if ($expectedTotal !== null && $expectedTotal !== $oldTotal) {
                throw new ApiException(
                    409,
                    'The recorded time of this project was changed by another user in the meantime (it is now '
                    . Format::formatDuration($oldTotal) . '). Please try again.'
                );
            }
            [$date, $time] = $this->latestStamp($row, $stamp);
            $pdo->prepare('UPDATE projects SET total_seconds = ?, last_update_date = ?, last_update_time = ? WHERE id = ?')
                ->execute([$newTotal, $date, $time, $id]);

            $this->log($pdo, $opId, 'set_time', $id, $newTotal - $oldTotal, $newTotal, [
                'before' => Format::formatDuration($oldTotal),
                'after' => Format::formatDuration($newTotal),
                'lastUpdate' => Format::formatStamp($stamp[0], $stamp[1]),
            ]);
        });
    }

    // ------------------------------------------------------------------ status

    /**
     * Marks projects as finished (status "finished") or reopens them (status
     * "active"). Projects that already have the requested status are left
     * untouched (their end date is preserved).
     *
     * @param list<int> $ids
     */
    public function setStatus(array $ids, string $status, mixed $endDate, ?string $opId, bool $single = false): void
    {
        $end = null;
        if ($status === 'finished') {
            $end = $this->parse(fn () => Format::parseDate($endDate), 'endDate')
                ?? Format::now($this->config->timezone)->format('Y-m-d');
        }
        $action = $status === 'finished' ? 'finish' : 'reopen';

        $this->change($opId, $action, function (PDO $pdo, ProjectRepository $repo) use ($ids, $status, $end, $opId, $single, $action) {
            $rows = $this->lockRows($pdo, $ids);
            if ($single && $rows === []) {
                throw $this->notFound();
            }
            $update = $pdo->prepare('UPDATE projects SET status = ?, end_date = ? WHERE id = ?');
            foreach ($rows as $row) {
                if ($row['status'] === $status) {
                    continue;
                }
                $update->execute([$status, $end, $row['id']]);
                $this->log($pdo, $opId, $action, (int) $row['id'], null, (int) $row['total_seconds'], [
                    'endDate' => Format::formatDate($end),
                ]);
            }
        });
    }

    // ------------------------------------------------------------------ delete

    /**
     * Deletes projects. This is a soft delete: the project disappears from the
     * application but stays in the database (see README, "Restoring a deleted
     * project"). Deleting a project that is already gone is not an error.
     *
     * @param list<int> $ids
     */
    public function delete(array $ids, ?string $opId): void
    {
        $this->change($opId, 'delete', function (PDO $pdo, ProjectRepository $repo) use ($ids, $opId) {
            $update = $pdo->prepare('UPDATE projects SET deleted_at = CURRENT_TIMESTAMP(3) WHERE id = ?');
            foreach ($this->lockRows($pdo, $ids) as $row) {
                $snapshot = $repo->find((int) $row['id']);
                $update->execute([$row['id']]);
                $this->log($pdo, $opId, 'delete', (int) $row['id'], null, (int) $row['total_seconds'], ['project' => $snapshot]);
            }
        });
    }

    // ------------------------------------------------------------- reordering

    /**
     * Drag & drop: moves project $id to the position of $targetId, using the
     * same rule as before (moving down = after the target, moving up = before
     * it), but computed on the CURRENT order stored in the database, so a
     * reorder from one computer never undoes changes made from another one.
     */
    public function move(int $id, mixed $targetId, ?string $opId): void
    {
        if (!is_int($targetId)) {
            throw new ApiException(400, 'Invalid target project');
        }
        $this->change($opId, 'move', function (PDO $pdo) use ($id, $targetId, $opId) {
            $rows = $pdo->query(
                'SELECT id, sort_order FROM projects WHERE deleted_at IS NULL ORDER BY '
                . ProjectRepository::DISPLAY_ORDER . ' FOR UPDATE'
            )->fetchAll();
            $order = array_map(static fn ($r) => (int) $r['id'], $rows);
            $current = array_column($rows, 'sort_order', 'id');

            $from = array_search($id, $order, true);
            $to = array_search($targetId, $order, true);
            if ($from === false || $to === false) {
                throw $this->notFound();
            }
            if ($from === $to) {
                return;
            }
            array_splice($order, $from, 1);
            $newTarget = array_search($targetId, $order, true);
            array_splice($order, $from < $to ? $newTarget + 1 : $newTarget, 0, [$id]);

            $update = $pdo->prepare('UPDATE projects SET sort_order = ? WHERE id = ?');
            foreach ($order as $position => $projectId) {
                if ($current[$projectId] === null || (int) $current[$projectId] !== $position) {
                    $update->execute([$position, $projectId]);
                }
            }
            $this->log($pdo, $opId, 'move', $id, null, null, [
                'target' => $targetId,
                'position' => $from < $to ? 'after' : 'before',
            ]);
        });
    }

    // ------------------------------------------------------------------ import

    /**
     * "Import Data": adds the projects of an exported JSON file to the current
     * list (new ids, all other values kept). Everything is imported, or
     * nothing if one project is invalid.
     */
    public function import(mixed $projects, ?string $opId): int
    {
        if (!is_array($projects) || !array_is_list($projects)) {
            throw new ApiException(400, 'Invalid JSON: no projects array found');
        }
        if (count($projects) > 10000) {
            throw new ApiException(400, 'Too many projects in one import (maximum 10000)');
        }
        $errors = [];
        $records = [];
        foreach ($projects as $index => $p) {
            $record = ProjectInput::legacy($p, ProjectInput::label($p, $index), $errors);
            if ($record !== null) {
                $record['id'] = null;
                $records[] = ['record' => $record, 'source' => $p];
            }
        }
        if ($errors !== []) {
            $shown = array_slice($errors, 0, 5);
            throw new ApiException(400, 'Nothing was imported. ' . implode(' — ', $shown)
                . (count($errors) > 5 ? ' — and ' . (count($errors) - 5) . ' more problem(s)' : ''));
        }

        $this->change($opId, 'import', function (PDO $pdo, ProjectRepository $repo) use ($records, $opId) {
            foreach ($records as $item) {
                $id = $repo->insert($item['record']);
                $total = (int) $item['record']['total_seconds'];
                $this->log($pdo, $opId, 'import', $id, $total, $total, ['source' => $item['source']]);
            }
        });
        return count($records);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Runs one change in a transaction, at most once per operation id.
     * Returns false when this operation id had already been applied.
     */
    private function change(?string $opId, string $action, callable $work): bool
    {
        return $this->db->transaction(function (PDO $pdo) use ($opId, $action, $work) {
            if ($opId !== null) {
                $stmt = $pdo->prepare('INSERT IGNORE INTO processed_operations (op_id, action) VALUES (?, ?)');
                $stmt->execute([$opId, $action]);
                if ($stmt->rowCount() === 0) {
                    return false;
                }
            }
            $work($pdo, new ProjectRepository($pdo));
            return true;
        });
    }

    /** @param array<string, mixed>|null $details */
    private function log(PDO $pdo, ?string $opId, string $action, ?int $projectId, ?int $delta, ?int $totalAfter, ?array $details): void
    {
        $pdo->prepare(
            'INSERT INTO activity_log (op_id, action, project_id, seconds_delta, total_seconds_after, details, client_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $opId,
            $action,
            $projectId,
            $delta,
            $totalAfter,
            $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $this->clientIp,
        ]);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function lockRows(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => is_int($id) && $id > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = $pdo->prepare(sprintf(
            'SELECT id, status, total_seconds FROM projects WHERE deleted_at IS NULL AND id IN (%s) ORDER BY id FOR UPDATE',
            implode(',', array_fill(0, count($ids), '?'))
        ));
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    /**
     * "Last update" sent by the browser (its local date and time, as before),
     * or the server's current time when missing.
     *
     * @return array{0: string, 1: ?string}
     */
    private function stamp(mixed $value): array
    {
        [$date, $time] = $this->parse(fn () => Format::parseStamp($value), 'lastUpdate');
        if ($date === null) {
            $now = Format::now($this->config->timezone);
            return [$now->format('Y-m-d'), $now->format('H:i:s')];
        }
        return [$date, $time];
    }

    /**
     * Never moves "Last update" backwards (e.g. when a change kept offline is
     * sent later, after someone else already updated the project).
     *
     * @param array<string, mixed> $row
     * @param array{0: string, 1: ?string} $stamp
     * @return array{0: string, 1: ?string}
     */
    private function latestStamp(array $row, array $stamp): array
    {
        $current = [$row['last_update_date'], $row['last_update_time']];
        if ($current[0] === null) {
            return $stamp;
        }
        $key = static fn (array $s) => $s[0] . ' ' . ($s[1] ?? '00:00:00');
        return strcmp($key($stamp), $key($current)) >= 0 ? $stamp : $current;
    }

    /** @return array<string, mixed>|null */
    private function originalDetails(mixed $original): ?array
    {
        if (!is_array($original)) {
            return null;
        }
        $clean = [];
        foreach (ProjectInput::DETAIL_FIELDS as $field) {
            $value = $original[$field] ?? null;
            if (in_array($field, ['researchers', 'engineers'], true)) {
                $clean[$field] = is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
            } else {
                $clean[$field] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $clean;
    }

    private function parse(callable $fn, string $field): mixed
    {
        try {
            return $fn();
        } catch (InvalidArgumentException $e) {
            throw new ApiException(400, "$field: " . $e->getMessage());
        }
    }

    private function notFound(): ApiException
    {
        return new ApiException(404, 'This project no longer exists (it may have been deleted by another user).');
    }
}
