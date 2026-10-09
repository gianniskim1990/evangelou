<?php
use PHPUnit\Framework\TestCase;

final class IdentifiersAndConfigTest extends TestCase {
    const MEMBER = 'mem_0123456789abcdef0123456789abcdef';

    public function test_fingerprint_is_deterministic_sha256(): void {
        $a = EVC_Request_Fingerprint::compute(self::MEMBER, 'free_coffee', 'espresso', 7);
        $this->assertSame($a, EVC_Request_Fingerprint::compute(self::MEMBER, 'free_coffee', 'espresso', 7));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $a);
        $this->assertTrue(EVC_Request_Fingerprint::matches($a, $a));
    }

    public function test_fingerprint_binds_every_payload_field(): void {
        $base = EVC_Request_Fingerprint::compute(self::MEMBER, 'free_coffee', 'espresso', 7);
        $variants = array(
            'member' => EVC_Request_Fingerprint::compute('mem_ffffffffffffffffffffffffffffffff', 'free_coffee', 'espresso', 7),
            'benefit' => EVC_Request_Fingerprint::compute(self::MEMBER, 'other_benefit', 'espresso', 7),
            'coffee' => EVC_Request_Fingerprint::compute(self::MEMBER, 'free_coffee', 'cappuccino', 7),
            'staff' => EVC_Request_Fingerprint::compute(self::MEMBER, 'free_coffee', 'espresso', 8),
        );
        foreach ($variants as $field => $fp) {
            $this->assertFalse(EVC_Request_Fingerprint::matches($base, $fp), "fingerprint must change with $field");
        }
    }

    public function test_fingerprint_has_no_time_input(): void {
        // A retry after Athens midnight must produce the same fingerprint, so
        // compute() must not accept or read any date/time.
        $params = (new ReflectionMethod('EVC_Request_Fingerprint', 'compute'))->getParameters();
        $names = array_map(function (ReflectionParameter $p) {
            return $p->getName();
        }, $params);
        $this->assertSame(array('member_public_id', 'benefit_type', 'coffee_code', 'staff_wp_user_id'), $names);
    }

    public function test_member_public_id_format(): void {
        $id = EVC_Member_Id::mint();
        $this->assertTrue(EVC_Member_Id::is_valid_format($id));
        $this->assertNotSame($id, EVC_Member_Id::mint());
        foreach (array('member-1', 'mem_ABC', 'mem_' . str_repeat('g', 32), self::MEMBER . "\n", 123, null) as $bad) {
            $this->assertFalse(EVC_Member_Id::is_valid_format($bad));
        }
    }

    public function test_db_config_never_exposes_password(): void {
        $config = new EVC_Db_Config('127.0.0.1', 3306, 'evc_club', 'evc_user', 's3cr3t-test-only');
        $this->assertStringNotContainsString('s3cr3t-test-only', print_r($config, true));
        $this->assertStringNotContainsString('s3cr3t-test-only', $config->dsn());
        ob_start();
        var_dump($config);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString('s3cr3t-test-only', $dump);
    }

    public function test_db_config_validates_input(): void {
        foreach (array(array('', 3306, 'db', 'u'), array('h', 0, 'db', 'u'), array('h', 3306, 'bad-name;drop', 'u'), array('h', 3306, 'db', '')) as $args) {
            try {
                new EVC_Db_Config($args[0], $args[1], $args[2], $args[3], 'p');
                $this->fail('Expected InvalidArgumentException for ' . json_encode($args));
            } catch (InvalidArgumentException $expected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_db_config_from_constants_fails_closed_when_undefined(): void {
        $this->assertNull(EVC_Db_Config::from_constants());
    }

    public function test_pdo_exception_mapping_strips_driver_messages(): void {
        $dup = EVC_Club_Db::map_exception(EVC_Faulty_Pdo::fault('23000', 1062, "Duplicate entry 'secret-value' for key 'evc_redemptions.uq_redemptions_request'"));
        $this->assertInstanceOf(EVC_Db_Duplicate_Key_Exception::class, $dup);
        $this->assertSame('uq_redemptions_request', $dup->key_name());
        $this->assertStringNotContainsString('secret-value', $dup->getMessage());

        $maria = EVC_Club_Db::map_exception(EVC_Faulty_Pdo::fault('23000', 1062, "Duplicate entry 'x' for key 'uq_redemptions_one_per_day'"));
        $this->assertSame('uq_redemptions_one_per_day', $maria->key_name());

        $this->assertInstanceOf(EVC_Db_Retryable_Exception::class, EVC_Club_Db::map_exception(EVC_Faulty_Pdo::fault('40001', 1213, 'Deadlock')));
        $this->assertInstanceOf(EVC_Db_Retryable_Exception::class, EVC_Club_Db::map_exception(EVC_Faulty_Pdo::fault('HY000', 1205, 'Lock wait')));

        $generic = EVC_Club_Db::map_exception(EVC_Faulty_Pdo::fault('42S02', 1146, "Table 'customer_db.secret' doesn't exist"));
        $this->assertSame('Club database operation failed.', $generic->getMessage());
        $this->assertSame(1146, $generic->driver_code());
        $this->assertNull($generic->getPrevious());
    }

    public function test_migration_statement_splitter(): void {
        $sql = "-- comment; with semicolon\r\nCREATE TABLE a (id INT);\r\n\r\n  -- another\nCREATE TABLE b (\n  id INT\n);\n";
        $this->assertSame(array('CREATE TABLE a (id INT)', "CREATE TABLE b (\n  id INT\n)"), EVC_Migrator::split_statements($sql));
    }

    public function test_migration_checksum_ignores_line_endings(): void {
        $dir = sys_get_temp_dir() . '/evc_ck_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/lf.sql', "CREATE TABLE a (id INT);\n");
        file_put_contents($dir . '/crlf.sql', "CREATE TABLE a (id INT);\r\n");
        $this->assertSame(EVC_Migrator::checksum_file($dir . '/lf.sql'), EVC_Migrator::checksum_file($dir . '/crlf.sql'));
        unlink($dir . '/lf.sql');
        unlink($dir . '/crlf.sql');
        rmdir($dir);
    }

    public function test_result_objects_keep_success_and_failure_apart(): void {
        $this->expectException(InvalidArgumentException::class);
        EVC_Redemption_Result::success(EVC_Redemption_Result::ALREADY_REDEEMED, array());
    }
}
