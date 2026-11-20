<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-08 round 10 (LOW-1 of the round-9 review): the pure reader of SHOW GRANTS lines behind the readiness row
 * «Καταγραφή προετοιμασίας καφέ στο ταμείο (μετάβαση 004)». "ok" only on an explicit grant that covers the table;
 * "missing" only when the whole list was understood; everything else "unknown" (a check by hand).
 */
final class DbGrantsTest extends TestCase {
    const DB = 'evc_club_staging';
    const TABLE = 'evc_redemption_preparations';

    /** @param string[] $lines */
    private static function read(array $lines, string $db = self::DB): array {
        return EVC_Db_Grants::table_privileges($lines, $db, self::TABLE, array('INSERT', 'SELECT'));
    }

    private function assertState(string $state, array $lines, string $message, ?array $missing = null, string $db = self::DB): void {
        $r = self::read($lines, $db);
        $this->assertSame($state, $r['state'], $message);
        if ($missing !== null) {
            $this->assertSame($missing, $r['missing'], $message . ' (missing)');
        }
    }

    public function test_the_till_needs_insert_and_select_on_the_table_of_004(): void {
        $this->assertSame('evc_redemption_preparations', EVC_Db_Grants::PREPARATIONS_TABLE);
        $this->assertSame(array('INSERT', 'SELECT'), EVC_Db_Grants::PREPARATIONS_PRIVILEGES);
    }

    public function test_explicit_grants_on_a_covering_scope_read_ok(): void {
        $usage = 'GRANT USAGE ON *.* TO `evc`@`%`';
        $cases = array(
            'MySQL 8 table grant' => array($usage, 'GRANT SELECT, INSERT ON `evc_club_staging`.`evc_redemption_preparations` TO `evc`@`%`'),
            'table grant with TABLE keyword' => array($usage, 'GRANT INSERT, SELECT ON TABLE `evc_club_staging`.`evc_redemption_preparations` TO `evc`@`%`'),
            'database grant' => array($usage, 'GRANT SELECT, INSERT, UPDATE ON `evc_club_staging`.* TO `evc`@`%`'),
            'ALL PRIVILEGES on the database (MariaDB, with a password hash)' => array("GRANT USAGE ON *.* TO `evc`@`localhost` IDENTIFIED BY PASSWORD '*0123456789ABCDEF'", 'GRANT ALL PRIVILEGES ON `evc_club_staging`.* TO `evc`@`localhost`'),
            'ALL on the database' => array('GRANT ALL ON `evc_club_staging`.* TO `evc`@`%`'),
            'global (MySQL 8 root lists every privilege)' => array('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON *.* TO `root`@`%` WITH GRANT OPTION', 'GRANT APPLICATION_PASSWORD_ADMIN,AUDIT_ABORT_EXEMPT,BACKUP_ADMIN ON *.* TO `root`@`%` WITH GRANT OPTION', 'GRANT PROXY ON ``@`` TO `root`@`%` WITH GRANT OPTION'),
            'MariaDB root' => array("GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` IDENTIFIED VIA mysql_native_password USING 'invalid' OR unix_socket WITH GRANT OPTION", "GRANT PROXY ON ''@'%' TO 'root'@'localhost' WITH GRANT OPTION"),
            'split across the database and the table' => array('GRANT SELECT ON `evc_club_staging`.* TO `evc`@`%`', 'GRANT INSERT ON `evc_club_staging`.`evc_redemption_preparations` TO `evc`@`%`'),
            'escaped underscore (cPanel style)' => array('GRANT SELECT, INSERT ON `evc\\_club\\_staging`.* TO `evc`@`%`'),
            'db pattern with %' => array('GRANT SELECT, INSERT ON `evc\\_club%`.* TO `evc`@`%`'),
            'old quoting' => array("GRANT SELECT, INSERT ON 'evc_club_staging'.'evc_redemption_preparations' TO 'evc'@'%'"),
            'unquoted names' => array('GRANT SELECT, INSERT ON evc_club_staging.evc_redemption_preparations TO evc@localhost'),
            'a role line does not matter once the grant is explicit' => array('GRANT `evc_role`@`%` TO `evc`@`%`', 'GRANT SELECT, INSERT ON `evc_club_staging`.* TO `evc`@`%`'),
        );
        foreach ($cases as $label => $lines) {
            $this->assertState('ok', $lines, $label, array());
        }
    }

    public function test_a_fully_understood_list_without_the_grant_reads_missing_with_the_names(): void {
        $usage = 'GRANT USAGE ON *.* TO `evc`@`%`';
        $this->assertState('missing', array($usage, 'GRANT SELECT ON `evc_club_staging`.* TO `evc`@`%`'), 'select only', array('INSERT'));
        $this->assertState('missing', array($usage, 'GRANT INSERT ON `evc_club_staging`.`evc_redemption_preparations` TO `evc`@`%`'), 'insert only', array('SELECT'));
        $this->assertState('missing', array($usage), 'usage only', array('INSERT', 'SELECT'));
        $this->assertState('missing', array($usage, 'GRANT SELECT, INSERT ON `evc_club_staging`.`evc_redemptions` TO `evc`@`%`'), 'another table', array('INSERT', 'SELECT'));
        $this->assertState('missing', array($usage, 'GRANT SELECT, INSERT ON `other_db`.* TO `evc`@`%`'), 'another database', array('INSERT', 'SELECT'));
        $this->assertState('missing', array($usage, 'GRANT SELECT, INSERT ON `evc_club_staging_old`.* TO `evc`@`%`'), 'a longer database name is not a pattern match', array('INSERT', 'SELECT'));
        $this->assertState('missing', array($usage, 'GRANT SELECT, INSERT ON `evc\\_club\\_stagingX`.* TO `evc`@`%`'), 'escaped wildcards are literal', array('INSERT', 'SELECT'));
        $this->assertState('missing', array($usage, 'GRANT EXECUTE ON PROCEDURE `evc_club_staging`.`p` TO `evc`@`%`'), 'routine grants do not count', array('INSERT', 'SELECT'));
        $this->assertState('missing', array($usage, 'GRANT SELECT, INSERT ON `evc_club_staging`.`evc_redemption_preparations_old` TO `evc`@`%`'), 'a table with a longer name', array('INSERT', 'SELECT'));
    }

    public function test_anything_that_could_change_the_answer_reads_unknown(): void {
        $usage = 'GRANT USAGE ON *.* TO `evc`@`%`';
        $cases = array(
            'a MySQL role' => array($usage, 'GRANT `evc_role`@`%` TO `evc`@`%`'),
            'a MariaDB role' => array($usage, 'GRANT `evc_role` TO `evc`@`%`'),
            'a MariaDB default role' => array($usage, 'SET DEFAULT ROLE `evc_role` FOR `evc`@`%`'),
            'a partial revoke' => array('GRANT SELECT, INSERT ON *.* TO `evc`@`%`', 'REVOKE INSERT ON `evc_club_staging`.* FROM `evc`@`%`'),
            'column privileges on the table' => array($usage, 'GRANT SELECT (`redemption_id`), INSERT (`redemption_id`, `note_text`) ON `evc_club_staging`.`evc_redemption_preparations` TO `evc`@`%`'),
            'a database pattern matching only without letter case' => array($usage, 'GRANT SELECT, INSERT ON `EVC_CLUB_STAGING`.* TO `evc`@`%`'),
            'a table matching only without letter case' => array($usage, 'GRANT SELECT, INSERT ON `evc_club_staging`.`EVC_REDEMPTION_PREPARATIONS` TO `evc`@`%`'),
            'a line not understood' => array($usage, 'GRANT SELECT, INSERT ON something strange'),
            'unbalanced brackets' => array($usage, 'GRANT SELECT (`a` ON `evc_club_staging`.* TO `evc`@`%`'),
            'a row that is not text' => array($usage, null),
        );
        foreach ($cases as $label => $lines) {
            $this->assertState('unknown', $lines, $label);
        }
        $this->assertState('unknown', array(), 'no line at all');
        $this->assertState('unknown', array('GRANT ALL ON *.* TO `evc`@`%`'), 'no current database', null, '');
    }

    /**
     * Round 11 (C3): a database-level grant reads its name as a pattern, where \_ is a plain underscore; a
     * table-level grant names its database literally (MariaDB 10.11, checked against the server: no wildcard, and a
     * backslash is part of the name).
     */
    public function test_round11_escaped_underscores_and_literal_database_names(): void {
        $usage = 'GRANT USAGE ON *.* TO `evc`@`%`';
        $db_level = 'GRANT SELECT, INSERT ON `evc\\_club`.* TO `evc`@`%`';
        $this->assertState('ok', array($usage, $db_level), 'db level: \\_ is the underscore itself', array(), 'evc_club');
        $this->assertState('missing', array($usage, $db_level), 'db level: an escaped underscore is not a wildcard', null, 'evcXclub');
        $this->assertState('missing', array($usage, $db_level), 'db level: a database really named with the backslash is not covered', null, 'evc\\_club');
        $this->assertState('ok', array($usage, 'GRANT SELECT, INSERT ON `evc_club`.* TO `evc`@`%`'), 'db level: an unescaped underscore is a wildcard', array(), 'evcXclub');
        $table = 'GRANT SELECT, INSERT ON `evc\\_club`.`evc_redemption_preparations` TO `evc`@`%`';
        $this->assertState('missing', array($usage, $table), 'table level: the database name is literal, \\_ included', null, 'evc_club');
        $this->assertState('ok', array($usage, $table), 'table level: the database really named «evc\\_club»', array(), 'evc\\_club');
        $this->assertState('missing', array($usage, 'GRANT SELECT, INSERT ON `evc_club`.`evc_redemption_preparations` TO `evc`@`%`'), 'table level: no wildcard in the database name', null, 'evcXclub');
        $this->assertState('missing', array($usage, 'GRANT SELECT, INSERT ON `evc%`.`evc_redemption_preparations` TO `evc`@`%`'), 'table level: no % wildcard either', null, 'evc_club');
        $this->assertState('missing', array($usage, 'GRANT SELECT, INSERT ON `evc_club`.`evc\\_redemption\\_preparations` TO `evc`@`%`'), 'the table name is literal', null, 'evc_club');
        $this->assertState('unknown', array($usage, 'GRANT SELECT, INSERT ON `EVC_CLUB`.`evc_redemption_preparations` TO `evc`@`%`'), 'table level: letter case only = cannot tell', null, 'evc_club');
    }

    public function test_the_answer_carries_no_part_of_a_line(): void {
        $line = "GRANT USAGE ON *.* TO `secret_user`@`10.0.0.7` IDENTIFIED BY PASSWORD '*SECRETHASH'";
        $out = json_encode(array(self::read(array($line)), self::read(array($line, 'GRANT `r` TO `secret_user`@`10.0.0.7`'))));
        foreach (array('secret_user', '10.0.0.7', 'SECRETHASH', self::DB) as $needle) {
            $this->assertStringNotContainsString($needle, (string) $out);
        }
    }
}
