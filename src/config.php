<?php
declare(strict_types=1);

/**
 * Hiroba Application Configuration and Environment Loader
 * Strictly Vanilla PHP without external package managers.
 */

function loadEnvFile(string $filePath): void {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Strip enclosing quotes if present
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }

            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Determine root directory path
$baseDir = dirname(__DIR__);

// Load local environment overrides first, fallback to standard .env
if (file_exists($baseDir . '/.env.local')) {
    loadEnvFile($baseDir . '/.env.local');
} elseif (file_exists($baseDir . '/.env')) {
    loadEnvFile($baseDir . '/.env');
}

// Application environment constants
define('APP_NAME', (string)($_ENV['APP_NAME'] ?? 'Hiroba'));
define('APP_ENV', (string)($_ENV['APP_ENV'] ?? 'local'));
define('APP_DEBUG', filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('APP_URL', rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost/hiroba/public'), '/'));

// Database configuration
define('DB_HOST', (string)($_ENV['DB_HOST'] ?? '127.0.0.1'));
define('DB_PORT', (int)($_ENV['DB_PORT'] ?? 3306));
define('DB_NAME', (string)($_ENV['DB_NAME'] ?? 'hiroba'));
define('DB_USER', (string)($_ENV['DB_USER'] ?? 'root'));
define('DB_PASS', (string)($_ENV['DB_PASS'] ?? ''));

// Session and security parameters
define('SESSION_LIFETIME', (int)($_ENV['SESSION_LIFETIME'] ?? 1800));
define('CSRF_EXPIRY', (int)($_ENV['CSRF_EXPIRY'] ?? 3600));
define('COOKIE_SECURE', filter_var($_ENV['COOKIE_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN));

// File upload boundaries
define('BASE_PATH', $baseDir);
define('PUBLIC_PATH', $baseDir . '/public');
define('UPLOAD_PATH', $baseDir . '/public/uploads');
define('MAX_UPLOAD_SIZE', 2 * 1024 * 1024);

// Error reporting settings based on debug flag
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

// Ensure default timezone is set consistently
date_default_timezone_set('Asia/Manila');
