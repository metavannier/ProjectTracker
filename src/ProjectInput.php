<?php
declare(strict_types=1);

namespace ProjectTracker;

use InvalidArgumentException;

/**
 * Validation and normalisation of project data coming from the browser,
 * from an imported JSON file, or from the legacy JSON storage.
 */
final class ProjectInput
{
    public const MAX_TITLE = 500;
    public const MAX_NAME = 255;
    public const MAX_INSTITUTE = 255;
    public const MAX_CATEGORY = 100;
    public const MAX_PEOPLE = 200;

    /** Fields of the legacy JSON format (and of the API). */
    public const LEGACY_FIELDS = [
        'id', 'title', 'researchers', 'engineers', 'institute', 'submissionDate', 'startDate',
        'endDate', 'totalHours', 'lastUpdate', 'category', 'status', 'order',
    ];

    /** The descriptive fields that the "Add project" form and the "Edit" dialog change. */
    public const DETAIL_FIELDS = ['title', 'researchers', 'engineers', 'institute', 'category'];

    /**
     * Validates the descriptive fields sent by the "Add New Project" form or the
     * "Edit project" dialog. Same rules as the interface: a title, at least one
     * researcher and an institute are required.
     *
     * @param array<string, mixed> $data
     * @return array{title: string, researchers: list<string>, engineers: list<string>, institute: string, category: string}
     */
    public static function details(array $data): array
    {
        $title = self::text($data['title'] ?? null, 'Project title', self::MAX_TITLE);
        $researchers = self::names($data['researchers'] ?? null, 'Researchers');
        $engineers = self::names($data['engineers'] ?? [], 'Engineers');
        $institute = self::text($data['institute'] ?? null, 'Institute', self::MAX_INSTITUTE);
        $category = self::text($data['category'] ?? null, 'Category', self::MAX_CATEGORY);

        if ($title === '' || $researchers === [] || $institute === '') {
            throw new ApiException(400, 'Please fill in the title, at least one researcher and the institute');
        }
        if ($category === '') {
            throw new ApiException(400, 'Please choose a category');
        }
        return compact('title', 'researchers', 'engineers', 'institute', 'category');
    }

    /**
     * Normalises a project in the legacy JSON format (import / migration).
     * Values are kept exactly as they are; only their format is checked.
     *
     * @param mixed $p one element of the "projects" array
     * @param list<string> $errors receives one message per problem found
     * @param list<string> $warnings receives non-blocking remarks
     * @return array<string, mixed>|null the normalised record, or null when invalid
     */
    public static function legacy(mixed $p, string $label, array &$errors, array &$warnings = []): ?array
    {
        if (!is_array($p) || array_is_list($p) && $p !== []) {
            $errors[] = "$label: is not a project object";
            return null;
        }
        $problems = [];
        $field = static function (callable $fn, string $name) use (&$problems) {
            try {
                return $fn();
            } catch (InvalidArgumentException | ApiException $e) {
                $problems[] = "$name: " . $e->getMessage();
                return null;
            }
        };

        $id = null;
        if (array_key_exists('id', $p) && $p['id'] !== null && $p['id'] !== '') {
            if (is_int($p['id']) && $p['id'] > 0) {
                $id = $p['id'];
            } elseif (is_string($p['id']) && ctype_digit($p['id']) && (int) $p['id'] > 0) {
                $id = (int) $p['id'];
            } else {
                $problems[] = 'id: must be a positive whole number';
            }
        }

        $title = $field(fn () => self::text($p['title'] ?? null, 'title', self::MAX_TITLE, trim: false), 'title');
        if (is_string($title) && trim($title) === '') {
            $problems[] = 'title: is empty';
        }
        $researchers = $field(fn () => self::names($p['researchers'] ?? [], 'researchers', trim: false), 'researchers');
        $engineers = $field(fn () => self::names($p['engineers'] ?? [], 'engineers', trim: false), 'engineers');
        $institute = $field(fn () => self::text($p['institute'] ?? '', 'institute', self::MAX_INSTITUTE, trim: false), 'institute');
        $category = $field(fn () => self::text($p['category'] ?? '', 'category', self::MAX_CATEGORY, trim: false), 'category');

        $status = $p['status'] ?? 'active';
        if (!in_array($status, ['active', 'finished'], true)) {
            $problems[] = 'status: must be "active" or "finished"';
        }

        $submission = $field(fn () => Format::parseDate($p['submissionDate'] ?? null), 'submissionDate');
        $start = $field(fn () => Format::parseDate($p['startDate'] ?? null), 'startDate');
        $end = $field(fn () => Format::parseDate($p['endDate'] ?? null), 'endDate');
        $stamp = $field(fn () => Format::parseStamp($p['lastUpdate'] ?? null), 'lastUpdate') ?? [null, null];

        $hours = $p['totalHours'] ?? '0:00:00';
        $seconds = Format::parseDuration(is_string($hours) ? $hours : null);
        if ($seconds === null) {
            $problems[] = 'totalHours: "' . (is_scalar($hours) ? $hours : gettype($hours)) . '" is not a duration (expected H:MM:SS)';
        }

        $order = null;
        if (array_key_exists('order', $p) && $p['order'] !== null) {
            if (is_int($p['order']) || (is_string($p['order']) && preg_match('/^-?\d+$/', $p['order']))) {
                $order = (int) $p['order'];
            } elseif (is_float($p['order']) && floor($p['order']) === $p['order']) {
                $order = (int) $p['order'];
            } else {
                $problems[] = 'order: must be a whole number';
            }
        }

        $unknown = array_diff(array_keys($p), self::LEGACY_FIELDS);
        if ($unknown !== []) {
            $warnings[] = "$label: unknown field(s) ignored: " . implode(', ', $unknown);
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $errors[] = "$label: $problem";
            }
            return null;
        }

        return [
            'id' => $id,
            'title' => $title,
            'researchers' => $researchers,
            'engineers' => $engineers,
            'institute' => $institute,
            'category' => $category,
            'status' => $status,
            'submission_date' => $submission,
            'start_date' => $start,
            'end_date' => $end,
            'total_seconds' => $seconds,
            'last_update_date' => $stamp[0],
            'last_update_time' => $stamp[1],
            'sort_order' => $order,
        ];
    }

    /** Human readable label for messages: "project #27 "Title"". */
    public static function label(mixed $p, int $index): string
    {
        $id = is_array($p) && isset($p['id']) && is_scalar($p['id']) ? '#' . $p['id'] : 'at position ' . ($index + 1);
        $title = is_array($p) && isset($p['title']) && is_string($p['title']) ? ' "' . mb_strimwidth($p['title'], 0, 60, '…') . '"' : '';
        return "project $id$title";
    }

    private static function text(mixed $value, string $name, int $max, bool $trim = true): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw new ApiException(400, "$name must be text");
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ApiException(400, "$name contains invalid characters");
        }
        if ($trim) {
            $value = trim($value);
        }
        if (mb_strlen($value) > $max) {
            throw new ApiException(400, "$name is too long (maximum $max characters)");
        }
        return $value;
    }

    /** @return list<string> */
    private static function names(mixed $value, string $name, bool $trim = true): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ApiException(400, "$name must be a list of names");
        }
        if (count($value) > self::MAX_PEOPLE) {
            throw new ApiException(400, "$name: too many names (maximum " . self::MAX_PEOPLE . ')');
        }
        $names = [];
        foreach ($value as $item) {
            $text = self::text($item, $name, self::MAX_NAME, $trim);
            if ($trim && $text === '') {
                continue;
            }
            $names[] = $text;
        }
        return $names;
    }
}
