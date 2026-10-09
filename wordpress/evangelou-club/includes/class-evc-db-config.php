<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Connection settings for the SEPARATE Club database. Never hardcoded: in
 * WordPress they are expected to come from server-side constants in
 * wp-config.php (see from_constants()); tests read environment variables.
 * The password is never exposed through __toString/var_dump/print_r.
 */
final class EVC_Db_Config {
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $database;
    /** @var string */
    private $user;
    /** @var string */
    private $password;

    public function __construct(string $host, int $port, string $database, string $user, string $password) {
        if ($host === '' || $user === '') {
            throw new InvalidArgumentException('Club DB host and user are required.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Club DB port is out of range.');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)) {
            throw new InvalidArgumentException('Club DB name must be 1-64 chars of [A-Za-z0-9_].');
        }
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->user = $user;
        $this->password = $password;
    }

    /**
     * Reads EVC_CLUB_DB_HOST / _PORT / _NAME / _USER / _PASSWORD constants.
     * Returns null when they are not all defined, so callers fail closed.
     */
    public static function from_constants(): ?self {
        foreach (array('EVC_CLUB_DB_HOST', 'EVC_CLUB_DB_NAME', 'EVC_CLUB_DB_USER', 'EVC_CLUB_DB_PASSWORD') as $name) {
            if (!defined($name)) {
                return null;
            }
        }
        $port = defined('EVC_CLUB_DB_PORT') ? (int) constant('EVC_CLUB_DB_PORT') : 3306;
        return new self(
            (string) constant('EVC_CLUB_DB_HOST'),
            $port,
            (string) constant('EVC_CLUB_DB_NAME'),
            (string) constant('EVC_CLUB_DB_USER'),
            (string) constant('EVC_CLUB_DB_PASSWORD')
        );
    }

    public function dsn(): string {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database);
    }

    public function user(): string {
        return $this->user;
    }

    public function password(): string {
        return $this->password;
    }

    public function database(): string {
        return $this->database;
    }

    public function __debugInfo() {
        return array(
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'user' => $this->user,
            'password' => '[redacted]',
        );
    }
}
