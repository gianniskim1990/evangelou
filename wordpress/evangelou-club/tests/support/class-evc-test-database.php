<?php
use PHPUnit\Framework\Assert;

/**
 * Disposable test databases on a LOCAL MySQL/MariaDB server (CI container).
 *
 * Safety rails:
 *  - connection settings come only from EVC_TEST_DB_* environment variables;
 *  - the host must be loopback (127.0.0.1, localhost or ::1);
 *  - every database is named evc_test_<random> and dropped afterwards.
 *
 * When the variables are missing the test is SKIPPED with an explicit
 * message, unless EVC_REQUIRE_DB=1 (set in CI), in which case it FAILS.
 */
final class EVC_Test_Database {
    const NAME_PREFIX = 'evc_test_';
    const LOOPBACK_HOSTS = array('127.0.0.1', 'localhost', '::1');

    /** @var string */
    private $name;

    private function __construct(string $name) {
        $this->name = $name;
    }

    public static function is_configured(): bool {
        return getenv('EVC_TEST_DB_HOST') !== false && getenv('EVC_TEST_DB_USER') !== false;
    }

    /** Skips (or fails under EVC_REQUIRE_DB=1) when no test server is configured. */
    public static function require_server(): void {
        if (self::is_configured()) {
            return;
        }
        $message = 'Real MySQL/MariaDB test server not configured (EVC_TEST_DB_HOST/USER unset).';
        if (getenv('EVC_REQUIRE_DB') === '1') {
            Assert::fail($message . ' EVC_REQUIRE_DB=1 forbids skipping.');
        }
        Assert::markTestSkipped($message);
    }

    public static function host(): string {
        $host = (string) getenv('EVC_TEST_DB_HOST');
        if (!in_array($host, self::LOOPBACK_HOSTS, true)) {
            throw new RuntimeException('Refusing to use a non-loopback test database host.');
        }
        return $host;
    }

    public static function port(): int {
        $port = getenv('EVC_TEST_DB_PORT');
        return $port === false ? 3306 : (int) $port;
    }

    public static function user(): string {
        return (string) getenv('EVC_TEST_DB_USER');
    }

    public static function password(): string {
        $password = getenv('EVC_TEST_DB_PASSWORD');
        return $password === false ? '' : (string) $password;
    }

    public static function migrations_dir(): string {
        return dirname(__DIR__, 2) . '/database';
    }

    public static function admin_pdo(): PDO {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', self::host(), self::port()),
            self::user(),
            self::password(),
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5)
        );
    }

    public static function create(): self {
        self::require_server();
        $name = self::NAME_PREFIX . bin2hex(random_bytes(6));
        self::admin_pdo()->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        return new self($name);
    }

    public function name(): string {
        return $this->name;
    }

    public function config(): EVC_Db_Config {
        return new EVC_Db_Config(self::host(), self::port(), $this->name, self::user(), self::password());
    }

    public function connect(): EVC_Club_Db {
        return EVC_Club_Db::connect($this->config());
    }

    public function faulty_pdo(): EVC_Faulty_Pdo {
        $config = $this->config();
        return new EVC_Faulty_Pdo($config->dsn(), $config->user(), $config->password(), array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }

    public function drop(): void {
        if (strpos($this->name, self::NAME_PREFIX) !== 0 || !preg_match('/^[a-z0-9_]+$/D', $this->name)) {
            throw new RuntimeException('Refusing to drop a non-test database.');
        }
        self::admin_pdo()->exec('DROP DATABASE IF EXISTS `' . $this->name . '`');
    }
}
