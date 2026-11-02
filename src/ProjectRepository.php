<?php
declare(strict_types=1);

namespace ProjectTracker;

use PDO;

/**
 * Reads and writes project rows. Converts between database rows and the JSON
 * format used by the interface (which is identical to the legacy JSON format).
 */
final class ProjectRepository
{
    private const COLUMNS = 'id, title, institute, category, status, submission_date, start_date, end_date,
        total_seconds, last_update_date, last_update_time, sort_order';

    /** Display order: the old "order" field, or the id when a project was never reordered. */
    public const DISPLAY_ORDER = 'COALESCE(sort_order, id), id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> all (non deleted) projects, in display order */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM projects WHERE deleted_at IS NULL ORDER BY ' . self::DISPLAY_ORDER
        )->fetchAll();

        $members = [];
        $stmt = $this->pdo->query(
            'SELECT m.project_id, m.role, m.name
               FROM project_members m
               JOIN projects p ON p.id = m.project_id AND p.deleted_at IS NULL
              ORDER BY m.project_id, m.role, m.position'
        );
        foreach ($stmt as $m) {
            $members[(int) $m['project_id']][$m['role']][] = $m['name'];
        }

        return array_map(
            static fn (array $row) => self::toApi($row, $members[(int) $row['id']] ?? []),
            $rows
        );
    }

    /**
     * Returns one project in the API format, or null if it does not exist (or
     * was deleted). With $lock the row is locked until the end of the transaction.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, bool $lock = false): ?array
    {
        $row = $this->row($id, $lock);
        return $row === null ? null : self::toApi($row, $this->members($id));
    }

    /** @return array<string, mixed>|null raw database row */
    public function row(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM projects WHERE id = ? AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array{researcher?: list<string>, engineer?: list<string>} */
    public function members(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT role, name FROM project_members WHERE project_id = ? ORDER BY role, position'
        );
        $stmt->execute([$id]);
        $members = [];
        foreach ($stmt as $m) {
            $members[$m['role']][] = $m['name'];
        }
        return $members;
    }

    /**
     * Inserts a project. $p uses the keys produced by ProjectInput::legacy().
     * When $p['id'] is set (migration) that id is kept; otherwise the database
     * assigns the next free id (no more duplicate ids between two computers).
     *
     * @param array<string, mixed> $p
     */
    public function insert(array $p): int
    {
        $columns = ['title', 'institute', 'category', 'status', 'submission_date', 'start_date', 'end_date',
            'total_seconds', 'last_update_date', 'last_update_time', 'sort_order'];
        $values = array_map(static fn ($c) => $p[$c] ?? null, $columns);
        $values[array_search('total_seconds', $columns, true)] = (int) ($p['total_seconds'] ?? 0);
        $values[array_search('status', $columns, true)] = $p['status'] ?? 'active';

        if (!empty($p['id'])) {
            array_unshift($columns, 'id');
            array_unshift($values, (int) $p['id']);
        }

        $sql = sprintf(
            'INSERT INTO projects (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->pdo->prepare($sql)->execute($values);
        $id = !empty($p['id']) ? (int) $p['id'] : (int) $this->pdo->lastInsertId();

        $this->replaceMembers($id, $p['researchers'] ?? [], $p['engineers'] ?? []);
        return $id;
    }

    /**
     * @param list<string> $researchers
     * @param list<string> $engineers
     */
    public function replaceMembers(int $id, array $researchers, array $engineers): void
    {
        $this->pdo->prepare('DELETE FROM project_members WHERE project_id = ?')->execute([$id]);
        $insert = $this->pdo->prepare(
            'INSERT INTO project_members (project_id, role, position, name) VALUES (?, ?, ?, ?)'
        );
        foreach (['researcher' => $researchers, 'engineer' => $engineers] as $role => $names) {
            foreach (array_values($names) as $position => $name) {
                $insert->execute([$id, $role, $position, $name]);
            }
        }
    }

    /**
     * Converts a database row into the JSON format used by the interface.
     * Same field names, order and text formats as the legacy JSON files.
     *
     * @param array<string, mixed> $row
     * @param array{researcher?: list<string>, engineer?: list<string>} $members
     * @return array<string, mixed>
     */
    public static function toApi(array $row, array $members): array
    {
        $project = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'researchers' => $members['researcher'] ?? [],
            'engineers' => $members['engineer'] ?? [],
            'institute' => (string) $row['institute'],
            'submissionDate' => Format::formatDate($row['submission_date']),
            'startDate' => Format::formatDate($row['start_date']),
            'endDate' => Format::formatDate($row['end_date']),
            'totalHours' => Format::formatDuration((int) $row['total_seconds']),
            'lastUpdate' => Format::formatStamp($row['last_update_date'], $row['last_update_time']),
            'category' => (string) $row['category'],
            'status' => (string) $row['status'],
        ];
        if ($row['sort_order'] !== null) {
            $project['order'] = (int) $row['sort_order'];
        }
        return $project;
    }
}
