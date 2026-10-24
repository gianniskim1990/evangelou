<?php
defined('ABSPATH') || exit;

/**
 * STAGE-03B.2: per-member mutual exclusion for BACS intent writes, using
 * MySQL/MariaDB named locks (GET_LOCK) on WordPress's own connection.
 *
 * What it guarantees (verified in tests against the real server):
 *  - at most one database SESSION holds a member's lock; GET_LOCK waits at
 *    most the given timeout (bounded, 0-10 s) and then reports "busy";
 *  - the lock belongs to the session: it is released by RELEASE_LOCK, or
 *    automatically when the connection ends (PHP crash, request killed);
 *  - ownership is CHECKED, not assumed: is_held() requires
 *    IS_USED_LOCK(name) = CONNECTION_ID() and the same connection id as at
 *    acquisition, so a silent wpdb reconnect (which drops the lock) is seen.
 * What it does NOT guarantee (documented, by design):
 *  - it is ADVISORY: only code that takes it is excluded. Order metadata
 *    written by anything else (an admin, another plugin) is not prevented;
 *    the intent store detects it instead (signature, duplicate rows,
 *    compare-and-set on the exact stored bytes) and fails closed;
 *  - it is per database server: a replicated / proxied setup that sends
 *    queries to different servers would need re-verification;
 *  - it is not durable: durability is the order metadata itself.
 * The name is scoped to this site (database + table prefix) so two sites on
 * one server never share a lock.
 */
final class EVC_Member_Lock {
    const MAX_WAIT_SECONDS = 10;

    /** @var wpdb */
    private $db;
    /** @var string */
    private $name;
    /** @var int */
    private $wp_user_id;
    /** @var string */
    private $connection_id;
    /** @var bool */
    private $released = false;

    private function __construct(wpdb $db, string $name, int $wp_user_id, string $connection_id) {
        $this->db = $db;
        $this->name = $name;
        $this->wp_user_id = $wp_user_id;
        $this->connection_id = $connection_id;
    }

    public static function name_for(wpdb $db, int $wp_user_id): string {
        return 'evc_bacs:' . substr(hash('sha256', DB_NAME . '|' . $db->prefix), 0, 16) . ':' . $wp_user_id;
    }

    /** The held lock, or null when another session holds it past the timeout (or on error). */
    public static function acquire(wpdb $db, int $wp_user_id, int $wait_seconds): ?self {
        if ($wp_user_id < 1 || $wait_seconds < 0 || $wait_seconds > self::MAX_WAIT_SECONDS) {
            throw new InvalidArgumentException('Invalid member lock request.');
        }
        $name = self::name_for($db, $wp_user_id);
        $db->last_error = '';
        $got = $db->get_var($db->prepare('SELECT GET_LOCK(%s, %d)', $name, $wait_seconds));
        if ($db->last_error !== '' || (string) $got !== '1') {
            return null;
        }
        $connection = (string) $db->get_var('SELECT CONNECTION_ID()');
        if ($db->last_error !== '' || $connection === '') {
            return null;
        }
        return new self($db, $name, $wp_user_id, $connection);
    }

    public function wp_user_id(): int {
        return $this->wp_user_id;
    }

    /** True only while THIS session (the one that acquired it) still holds the lock. */
    public function is_held(): bool {
        if ($this->released) {
            return false;
        }
        $this->db->last_error = '';
        $row = $this->db->get_row($this->db->prepare('SELECT IS_USED_LOCK(%s) AS owner, CONNECTION_ID() AS me', $this->name), ARRAY_A);
        return $this->db->last_error === '' && is_array($row)
            && $row['owner'] !== null && (string) $row['owner'] === (string) $row['me'] && (string) $row['me'] === $this->connection_id;
    }

    /** Releases the lock if this session still holds it. */
    public function release(): bool {
        if ($this->released) {
            return false;
        }
        $this->released = true;
        $this->db->last_error = '';
        $result = $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $this->name));
        return $this->db->last_error === '' && (string) $result === '1';
    }
}
