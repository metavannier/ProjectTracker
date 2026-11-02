<?php
declare(strict_types=1);

namespace ProjectTracker;

use InvalidArgumentException;

/**
 * Conversions between the text formats used by the user interface (and by the
 * legacy JSON files) and the typed values stored in the database.
 *
 *   totalHours     "201:00:00"              <-> total_seconds 723600
 *   dates          "22/09/2025"             <-> DATE '2025-09-22'
 *   lastUpdate     "08/10/2026\r\n17:10:14"  <-> DATE '2026-10-08' + TIME '17:10:14'
 *                  "19/06/2026"             <-> DATE '2026-06-19' + TIME NULL
 *
 * The output formats are exactly those produced by the previous version of the
 * application, so the interface displays the same text as before.
 */
final class Format
{
    /**
     * Parses a "H:MM:SS" duration into seconds.
     *
     * Lenient mode (legacy data) accepts any number of digits per part and adds
     * them up exactly like the previous front-end did; strict mode only accepts
     * what the "Edit time manually" prompt accepts (MM and SS between 00 and 59).
     */
    public static function parseDuration(mixed $value, bool $strict = false): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $pattern = $strict
            ? '/^(\d{1,9}):([0-5]\d):([0-5]\d)$/'
            : '/^\s*(\d{1,9}):(\d{1,4}):(\d{1,4})\s*$/';
        if (!preg_match($pattern, $value, $m)) {
            return null;
        }
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }

    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Parses "DD/MM/YYYY" (or ISO "YYYY-MM-DD") into "YYYY-MM-DD".
     * Empty values give null. Invalid values throw.
     */
    public static function parseDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('a date must be text (DD/MM/YYYY)');
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $v, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            throw new InvalidArgumentException("\"$value\" is not a date (expected DD/MM/YYYY)");
        }
        if ($year < 1000 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException("\"$value\" is not a valid date");
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function formatDate(?string $ymd): string
    {
        if ($ymd === null || $ymd === '') {
            return '';
        }
        [$year, $month, $day] = explode('-', substr($ymd, 0, 10));
        return "$day/$month/$year";
    }

    /**
     * Parses a "Last update" value: a date, optionally followed by a time
     * ("08/10/2026\r\n17:10:14", "19/06/2026", "2026-10-08 17:10").
     *
     * @return array{0: ?string, 1: ?string} [date "YYYY-MM-DD", time "HH:MM:SS" or null]
     */
    public static function parseStamp(mixed $value): array
    {
        if ($value === null) {
            return [null, null];
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('"last update" must be text (DD/MM/YYYY HH:MM:SS)');
        }
        $v = trim($value);
        if ($v === '') {
            return [null, null];
        }
        $re = '#^(\d{1,2}/\d{1,2}/\d{4}|\d{4}-\d{2}-\d{2})(?:(?:\s+|,\s*|T)(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#';
        if (!preg_match($re, $v, $m)) {
            throw new InvalidArgumentException("\"$value\" is not a valid \"last update\" value (expected DD/MM/YYYY HH:MM:SS)");
        }
        $date = self::parseDate($m[1]);
        $time = null;
        if (isset($m[2]) && $m[2] !== '') {
            [$h, $i, $s] = [(int) $m[2], (int) $m[3], (int) ($m[4] ?? 0)];
            if ($h > 23 || $i > 59 || $s > 59) {
                throw new InvalidArgumentException("\"$value\" contains an invalid time");
            }
            $time = sprintf('%02d:%02d:%02d', $h, $i, $s);
        }
        return [$date, $time];
    }

    public static function formatStamp(?string $date, ?string $time): string
    {
        $formatted = self::formatDate($date);
        if ($formatted === '' || $time === null || $time === '') {
            return $formatted;
        }
        // Same separator as the previous front-end: date, CR LF, time
        return $formatted . "\r\n" . substr($time, 0, 8);
    }

    /** Current date and time in the application's time zone. */
    public static function now(string $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($timezone));
    }
}
