<?php
declare(strict_types=1);

namespace ProjectTracker;

use RuntimeException;

/** An error that is reported to the browser with an HTTP status and a readable message. */
final class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $extra additional fields added to the JSON error body */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
