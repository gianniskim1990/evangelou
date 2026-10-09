<?php
/**
 * PDO subclass for fault injection against a REAL test database.
 * Faults raise genuine PDOExceptions carrying MySQL-style errorInfo.
 */
class EVC_Faulty_Pdo extends PDO {
    /** @var string|null Regex: prepare() of matching SQL fails with a generic error. */
    public $fail_prepare_matching;
    /** @var string|null Regex: prepare() of matching SQL fails with a deadlock. */
    public $deadlock_prepare_matching;
    /** @var int How many more deadlocks to inject (-1 = forever). */
    public $deadlocks_remaining = 0;
    /** @var bool Fail COMMIT without committing (server rolls back). */
    public $fail_commit_before = false;
    /** @var bool Commit for real, then report failure (lost response). */
    public $fail_commit_after = false;

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = array()) {
        if ($this->fail_prepare_matching !== null && preg_match($this->fail_prepare_matching, $query)) {
            throw self::fault('HY000', 2006, 'Injected fault: server has gone away');
        }
        if ($this->deadlock_prepare_matching !== null && $this->deadlocks_remaining !== 0
            && preg_match($this->deadlock_prepare_matching, $query)) {
            if ($this->deadlocks_remaining > 0) {
                $this->deadlocks_remaining--;
            }
            throw self::fault('40001', 1213, 'Injected fault: deadlock');
        }
        return parent::prepare($query, $options);
    }

    public function commit(): bool {
        if ($this->fail_commit_before) {
            throw self::fault('HY000', 2013, 'Injected fault: lost connection before commit');
        }
        $ok = parent::commit();
        if ($this->fail_commit_after) {
            throw self::fault('HY000', 2013, 'Injected fault: lost connection after commit');
        }
        return $ok;
    }

    public static function fault(string $state, int $code, string $message): PDOException {
        $e = new PDOException($message);
        $e->errorInfo = array($state, $code, $message);
        return $e;
    }
}
