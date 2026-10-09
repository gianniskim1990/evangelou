<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Gateway to the SEPARATE Evangelou Club database (PDO + pdo_mysql).
 *
 * - Every query is a native prepared statement (emulation off) with bound
 *   parameters; SQL text never contains caller-supplied values.
 * - Every PDOException is converted to an EVC_Db_Exception carrying only
 *   SQLSTATE + driver code, never the driver message.
 * - transactional() always rolls back on any failure before rethrowing, so
 *   callers can safely run fresh recovery reads afterwards.
 * - Session is pinned to UTC, strict SQL mode and READ COMMITTED, so each
 *   statement outside a transaction sees the latest committed data.
 *
 * Hosting support for pdo_mysql is NOT yet confirmed (deployment blocker).
 */
class EVC_Club_Db {
    const ER_DUP_ENTRY = 1062;
    const ER_LOCK_WAIT_TIMEOUT = 1205;
    const ER_LOCK_DEADLOCK = 1213;

    /** @var PDO */
    private $pdo;

    /** Wraps an existing PDO connection (dependency injection for tests). */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw self::map_exception($e);
        }
        $this->exec_raw("SET time_zone = '+00:00'");
        $this->exec_raw("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->exec_raw('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    /** Opens a new connection. Failure never reveals host, user or password. */
    public static function connect(EVC_Db_Config $config): self {
        if (!extension_loaded('pdo_mysql')) {
            throw new EVC_Db_Exception('Club database driver (pdo_mysql) is not available.');
        }
        try {
            $pdo = new PDO($config->dsn(), $config->user(), $config->password(), array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ));
        } catch (PDOException $e) {
            $code = is_numeric($e->getCode()) ? (int) $e->getCode() : null;
            throw new EVC_Db_Exception('Club database is unavailable.', null, $code);
        }
        return new self($pdo);
    }

    /** @return array<string,mixed>|null First row or null. */
    public function fetch_one(string $sql, array $params = array()): ?array {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function fetch_all(string $sql, array $params = array()): array {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Executes a statement and returns the affected row count. */
    public function execute(string $sql, array $params = array()): int {
        return $this->run($sql, $params)->rowCount();
    }

    /** Executes an INSERT and returns the new AUTO_INCREMENT id. */
    public function insert(string $sql, array $params = array()): int {
        $this->run($sql, $params);
        try {
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            throw self::map_exception($e);
        }
    }

    /** Executes trusted, parameter-free SQL (session setup and migrations only). */
    public function exec_raw(string $sql): void {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            throw self::map_exception($e);
        }
    }

    /**
     * Runs $work inside one transaction and commits. On ANY failure (including
     * a failed COMMIT) the transaction is rolled back before the exception is
     * rethrown. A COMMIT failure means the outcome is unknown to the caller;
     * idempotent callers recover by retrying with the same request key.
     *
     * @param callable $work function (EVC_Club_Db $db): mixed
     * @return mixed Whatever $work returns.
     */
    public function transactional(callable $work) {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Nested Club DB transactions are not supported.');
        }
        try {
            $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            throw self::map_exception($e);
        }
        try {
            $result = $work($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback_quietly();
            if ($e instanceof PDOException) {
                throw self::map_exception($e);
            }
            throw $e;
        }
    }

    public function in_transaction(): bool {
        return $this->pdo->inTransaction();
    }

    private function run(string $sql, array $params): PDOStatement {
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach (array_values($params) as $i => $value) {
                $stmt->bindValue($i + 1, $value, self::param_type($value));
            }
            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            throw self::map_exception($e);
        }
    }

    private function rollback_quietly(): void {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable $ignored) {
            // Connection already gone: the server discards the open transaction.
        }
    }

    private static function param_type($value): int {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        return PDO::PARAM_STR;
    }

    /** Converts a driver exception into a message-free EVC_Db_Exception. */
    public static function map_exception(PDOException $e): EVC_Db_Exception {
        $info = $e->errorInfo;
        $state = (is_array($info) && isset($info[0])) ? (string) $info[0] : null;
        $code = (is_array($info) && isset($info[1])) ? (int) $info[1] : null;
        if ($code === null && is_numeric($e->getCode())) {
            $code = (int) $e->getCode();
        }

        if ($code === self::ER_DUP_ENTRY) {
            $key = null;
            $text = (is_array($info) && isset($info[2])) ? (string) $info[2] : '';
            // MySQL 8: "for key 'table.key'"; MariaDB: "for key 'key'".
            if (preg_match("/for key '(?:[^'.]*\\.)?([A-Za-z0-9_]+)'/", $text, $m)) {
                $key = $m[1];
            }
            return new EVC_Db_Duplicate_Key_Exception($key, $state, $code);
        }
        if ($code === self::ER_LOCK_DEADLOCK || $code === self::ER_LOCK_WAIT_TIMEOUT) {
            return new EVC_Db_Retryable_Exception('Club database transaction conflict; retry.', $state, $code);
        }
        return new EVC_Db_Exception('Club database operation failed.', $state, $code);
    }
}
