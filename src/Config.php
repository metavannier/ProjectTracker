<?php
declare(strict_types=1);

namespace ProjectTracker;

use RuntimeException;

/**
 * Application configuration, read from environment variables
 * (password.env and database.env in the docker-compose setup).
 */
final class Config
{
    private function __construct(
        public readonly string $password,
        public readonly string $sessionSecret,
        public readonly int $sessionLifetime,
        public readonly string $cookieSecure,
        public readonly string $timezone,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
    ) {
    }

    /**
     * @param bool $web true for the web application (login password and session
     *                  secret are mandatory), false for command-line tools.
     */
    public static function fromEnvironment(bool $web = true): self
    {
        $password = self::env('PASSWORD');
        $secret = self::env('SESSION_SECRET');
        if ($web) {
            if ($password === '') {
                throw new RuntimeException('PASSWORD is not set (see password.env). Server aborted.');
            }
            if ($secret === '') {
                throw new RuntimeException('SESSION_SECRET is not set (see password.env). Server aborted.');
            }
        }

        $hours = (float) (self::env('SESSION_LIFETIME_HOURS') ?: '8');
        if ($hours <= 0) {
            $hours = 8;
        }

        $cookieSecure = strtolower(self::env('SESSION_COOKIE_SECURE') ?: 'auto');
        if (!in_array($cookieSecure, ['auto', 'true', 'false'], true)) {
            $cookieSecure = 'auto';
        }

        $timezone = self::env('APP_TIMEZONE') ?: 'Europe/Paris';
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
            throw new RuntimeException("APP_TIMEZONE '$timezone' is not a valid time zone.");
        }

        $dbName = self::env('DB_NAME') ?: self::env('MARIADB_DATABASE');
        $dbUser = self::env('DB_USER') ?: self::env('MARIADB_USER');
        $dbPassword = self::env('DB_PASSWORD') ?: self::env('MARIADB_PASSWORD');
        if ($dbName === '' || $dbUser === '' || $dbPassword === '') {
            throw new RuntimeException(
                'Database settings are missing: set MARIADB_DATABASE, MARIADB_USER and MARIADB_PASSWORD (see database.env).'
            );
        }

        return new self(
            password: $password,
            sessionSecret: $secret,
            sessionLifetime: (int) round($hours * 3600),
            cookieSecure: $cookieSecure,
            timezone: $timezone,
            dbHost: self::env('DB_HOST') ?: 'db',
            dbPort: (int) (self::env('DB_PORT') ?: '3306'),
            dbName: $dbName,
            dbUser: $dbUser,
            dbPassword: $dbPassword,
        );
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        }
        return trim((string) $value);
    }
}
