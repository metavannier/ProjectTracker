<?php
declare(strict_types=1);

namespace ProjectTracker;

use PDO;
use PDOException;
use Throwable;

/**
 * Thin wrapper around PDO: one connection per request, strict SQL mode,
 * UTC session time zone, and transactions that are retried automatically
 * when the database reports a deadlock or a lock wait timeout.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config->dbHost,
                $this->config->dbPort,
                $this->config->dbName
            );
            $this->pdo = new PDO($dsn, $this->config->dbUser, $this->config->dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
                // UPDATE returns the number of matched rows, not only the changed ones
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    "SET time_zone = '+00:00', "
                    . "sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ]);
        }
        return $this->pdo;
    }

    /**
     * Runs $work inside a transaction. Everything is committed together or
     * nothing is. Deadlocks / lock timeouts are retried (up to $attempts times).
     *
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public function transaction(callable $work, int $attempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $pdo = $this->pdo();
            $pdo->beginTransaction();
            try {
                $result = $work($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($attempt < $attempts && self::isTransientLockError($e)) {
                    usleep(random_int(20_000, 150_000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Runs read-only queries on one consistent snapshot of the database. */
    public function snapshot(callable $work): mixed
    {
        $pdo = $this->pdo();
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $result = $work($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function isTransientLockError(Throwable $e): bool
    {
        // 1213 = deadlock, 1205 = lock wait timeout
        return $e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
    }

    public static function isConnectionError(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        // 2002 = cannot connect, 2006 = server has gone away, 2013 = lost connection,
        // 1040 = too many connections, 1045 = access denied, 1049 = unknown database
        return in_array($code, [1040, 1045, 1049, 2002, 2006, 2013], true)
            || str_contains($e->getMessage(), 'SQLSTATE[HY000] [2002]');
    }

    /** Applies database/schema.sql (idempotent). */
    public function applySchema(): void
    {
        $sql = file_get_contents(PROJECT_TRACKER_ROOT . '/database/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Cannot read database/schema.sql');
        }
        // Remove comment lines, then run each statement separately.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) {
            $this->pdo()->exec($statement);
        }
    }
}
