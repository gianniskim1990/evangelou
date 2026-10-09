<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Club database failure. The message is always a fixed, generic string: the
 * driver's own message (which can contain SQL, values, host names or user
 * names) is deliberately NOT kept, so nothing sensitive can leak to a
 * response or log through this exception. Only the SQLSTATE and the numeric
 * driver error code are retained for diagnostics.
 */
class EVC_Db_Exception extends RuntimeException {
    /** @var string|null */
    private $sql_state;
    /** @var int|null */
    private $driver_code;

    public function __construct(string $generic_message, ?string $sql_state = null, ?int $driver_code = null) {
        parent::__construct($generic_message);
        $this->sql_state = $sql_state;
        $this->driver_code = $driver_code;
    }

    public function sql_state(): ?string {
        return $this->sql_state;
    }

    public function driver_code(): ?int {
        return $this->driver_code;
    }
}

/** A unique-key violation (MySQL/MariaDB error 1062). */
final class EVC_Db_Duplicate_Key_Exception extends EVC_Db_Exception {
    /** @var string|null */
    private $key_name;

    public function __construct(?string $key_name, ?string $sql_state, int $driver_code) {
        parent::__construct('Club database unique constraint violated.', $sql_state, $driver_code);
        $this->key_name = $key_name;
    }

    /** Unique key name without table prefix, e.g. "uq_redemptions_request"; null if not parseable. */
    public function key_name(): ?string {
        return $this->key_name;
    }
}

/** Deadlock (1213) or lock-wait timeout (1205): the whole transaction may be retried. */
final class EVC_Db_Retryable_Exception extends EVC_Db_Exception {
}

/** Migration bookkeeping/drift/apply failure. */
final class EVC_Migration_Exception extends RuntimeException {
}
