<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-04A review (item 5): a failed connection never lets a PHP warning (which may name the host)
 * escape EVC_Club_Db::connect(); only the message-free EVC_Db_Exception leaves it, and the caller's
 * error handler is restored afterwards. Some PHP versions (7.4) warn on an unresolvable host; on
 * others the recorder simply stays empty. CI runs this on PHP 7.4, 8.2 and 8.4.
 */
final class ClubDbConnectWarningTest extends TestCase {
    public function test_a_failed_connection_raises_no_warning_and_reveals_nothing(): void {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql missing');
        }
        $host = 'evc-no-such-host-' . bin2hex(random_bytes(3)) . '.invalid';
        $warnings = array();
        set_error_handler(function (int $no, string $text) use (&$warnings) {
            $warnings[] = $text;
            return true;
        });
        try {
            EVC_Club_Db::connect(new EVC_Db_Config($host, 3306, 'evc_x', 'evc-secret-user', 'evc-secret-password'));
            $this->fail('connected to an unresolvable host');
        } catch (EVC_Db_Exception $e) {
            $this->assertSame('Club database is unavailable.', $e->getMessage());
            foreach (array($host, 'evc-secret-user', 'evc-secret-password') as $secret) {
                $this->assertStringNotContainsString($secret, (string) $e);
            }
        } finally {
            // Our recorder must still be the active handler (connect restored its own).
            trigger_error('probe', E_USER_NOTICE);
            restore_error_handler();
        }
        $this->assertSame(array('probe'), $warnings);
    }
}
