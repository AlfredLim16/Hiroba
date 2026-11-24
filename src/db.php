<?php
declare(strict_types=1);

/**
 * Hiroba Database Connection Handler and Transaction Utilities
 * Utilizes PDO with prepared statements, emulation disabled, and utf8mb4 collation.
 */

require_once __DIR__ . '/config.php';

function getDbConnection(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database Connection Error: ' . $e->getMessage());

        if (APP_DEBUG) {
            die('Database Connection Failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        http_response_code(500);
        die('The marketplace is currently undergoing maintenance. Please try again shortly.');
    }
}

/**
 * Execute a callback within an atomic database transaction.
 * Automatically rolls back on any thrown exception.
 *
 * @param callable(PDO): mixed $callback
 * @return mixed
 * @throws Throwable
 */
function dbTransaction(callable $callback): mixed {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Transaction Rolled Back: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Execute a prepared statement with bound parameters.
 *
 * @param string $sql
 * @param array<string, mixed> $params
 * @return PDOStatement
 */
function dbQuery(string $sql, array $params = []): PDOStatement {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
