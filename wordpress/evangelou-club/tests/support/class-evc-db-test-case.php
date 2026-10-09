<?php
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a REAL, freshly migrated, disposable
 * MySQL/MariaDB database. Each test gets its own database, dropped afterwards.
 */
abstract class EVC_Db_Test_Case extends TestCase {
    const DEFAULT_NOW = '2026-10-09T10:00:00Z';
    const DEFAULT_EXPIRY = '2026-11-09T10:00:00Z';
    const STAFF_ID = 501;

    /** @var EVC_Test_Database|null */
    protected $database;
    /** @var EVC_Club_Db|null */
    protected $db;
    /** @var EVC_Mock_Membership_Adapter */
    protected $adapter;
    /** @var EVC_Fixed_Clock */
    protected $clock;
    /** @var array<int,array> Diagnostics passed to the service's error reporter. */
    protected $reported = array();

    protected function migrate_on_setup(): bool {
        return true;
    }

    protected function setUp(): void {
        parent::setUp();
        $this->database = EVC_Test_Database::create();
        $this->db = $this->database->connect();
        if ($this->migrate_on_setup()) {
            (new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir()))->migrate();
        }
        $this->adapter = new EVC_Mock_Membership_Adapter();
        $this->clock = new EVC_Fixed_Clock(self::DEFAULT_NOW);
        $this->reported = array();
    }

    protected function tearDown(): void {
        $this->db = null;
        if ($this->database !== null) {
            $this->database->drop();
            $this->database = null;
        }
        parent::tearDown();
    }

    public static function catalog(): EVC_Coffee_Catalog {
        return new EVC_Coffee_Catalog(require dirname(__DIR__) . '/fixtures/coffee-catalog.php');
    }

    public static function uuid4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /** Inserts a Club member directly (enrollment is a later task). Returns public id. */
    protected function create_member(int $wp_user_id, string $status = 'enabled', ?EVC_Club_Db $db = null): string {
        $public_id = EVC_Member_Id::mint();
        ($db ?: $this->db)->execute(
            'INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)',
            array($public_id, $wp_user_id, $status, '2026-09-01 00:00:00.000000', '2026-09-01 00:00:00.000000')
        );
        return $public_id;
    }

    /** Creates an enabled member with an active, paid membership. */
    protected function create_active_member(int $wp_user_id, string $expires = self::DEFAULT_EXPIRY): string {
        $this->adapter->set($wp_user_id, EVC_Mock_Membership_Adapter::active_until($expires));
        return $this->create_member($wp_user_id);
    }

    protected function service(?EVC_Club_Db $db = null, ?EVC_Audit_Log $audit = null): EVC_Redemption_Service {
        $reported = &$this->reported;
        return new EVC_Redemption_Service(
            new EVC_Redemption_Store($db ?: $this->db),
            $audit ?: new EVC_Db_Audit_Log(),
            $this->adapter,
            self::catalog(),
            $this->clock,
            function (array $diagnostic) use (&$reported) {
                $reported[] = $diagnostic;
            }
        );
    }

    protected function request(string $member_public_id, string $coffee = 'espresso', ?string $request_id = null, int $staff = self::STAFF_ID): EVC_Redemption_Request {
        return new EVC_Redemption_Request($member_public_id, 'free_coffee', $coffee, $request_id ?: self::uuid4(), $staff);
    }

    protected function count_rows(string $table, string $where = '1 = 1', array $params = array()): int {
        $row = $this->db->fetch_one('SELECT COUNT(*) AS n FROM ' . $table . ' WHERE ' . $where, $params);
        return (int) $row['n'];
    }
}
