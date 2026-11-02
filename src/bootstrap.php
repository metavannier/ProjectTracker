<?php
declare(strict_types=1);

/*
 * Common bootstrap for the web front controller and the command-line tools.
 * No external dependency is needed: a tiny PSR-4 style autoloader is enough.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'ProjectTracker\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

const PROJECT_TRACKER_ROOT = __DIR__ . '/..';

// Turn PHP warnings/notices into exceptions so nothing fails silently.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
