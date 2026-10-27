<?php

/**
 * STAGE-04A with EVC_CLUB_MIGRATIONS_ENABLED = true (own PHPUnit process: wp-migrations).
 *
 * Every test runs against a disposable Club database (evc_test_<random>) on the loopback test
 * server, reached through a dedicated, randomly named MySQL user with a random password, so the
 * secret checks look for values that cannot appear by accident. The WordPress test database
 * (DB_NAME) is on the same server: the separation checks must tell them apart.
 */
final class ClubMigrationsTest extends EVC_WP_Test_Case {
    const REQUEST_URI = '/wp-admin/tools.php?page=evc-club-db';

    /** @var EVC_Test_Database */
    private $club;
    /** @var string */
    private $db_user;
    /** @var string */
    private $db_password;
    /** @var string[] */
    private $log = array();
    /** @var string[] */
    private $cli_out = array();
    /** @var int */
    private $connects = 0;
    /** @var string[] */
    private $temp_dirs = array();

    public function set_up() {
        parent::set_up();
        $this->assertTrue(EVC_Club_Migrations_Feature::enabled(), 'run with EVC_TEST_CLUB_MIGRATIONS_FLAG=enabled');
        $this->club = EVC_Test_Database::create();
        $this->db_user = 'evcmig_' . bin2hex(random_bytes(4));
        $this->db_password = 'Pw-' . bin2hex(random_bytes(10));
        $admin = EVC_Test_Database::admin_pdo();
        foreach (array('%', 'localhost', '127.0.0.1') as $host) {
            $admin->exec(sprintf("CREATE USER '%s'@'%s' IDENTIFIED BY '%s'", $this->db_user, $host, $this->db_password));
            $admin->exec(sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%s'", $this->club->name(), $this->db_user, $host));
        }
        $this->log = array();
        $this->cli_out = array();
        $this->connects = 0;
        $_SERVER['REQUEST_URI'] = self::REQUEST_URI;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = array();
        $_REQUEST = array();
    }

    public function tear_down() {
        $_POST = array();
        $_REQUEST = array();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $admin = EVC_Test_Database::admin_pdo();
        foreach (array('%', 'localhost', '127.0.0.1') as $host) {
            $admin->exec(sprintf("DROP USER IF EXISTS '%s'@'%s'", $this->db_user, $host));
        }
        $this->club->drop();
        foreach ($this->temp_dirs as $dir) {
            foreach (glob($dir . '/*') ?: array() as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        parent::tear_down();
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function config(?string $name = null, ?string $password = null, ?int $port = null): EVC_Db_Config {
        return new EVC_Db_Config(EVC_Test_Database::host(), $port ?? EVC_Test_Database::port(), $name ?? $this->club->name(), $this->db_user, $password ?? $this->db_password);
    }

    /** @return array{service:EVC_Club_Migrations_Service,page:EVC_Club_Migrations_Admin_Page,cli:EVC_Club_Migrations_Cli} */
    private function parts(?EVC_Db_Config $config = null, ?array $wordpress = null, ?callable $wordpress_server = null): array {
        $config = $config ?? $this->config();
        return EVC_Club_Migrations_Plugin::build(
            function () use ($config) {
                return $config;
            },
            function (EVC_Db_Config $db) {
                $this->connects++;
                return EVC_Club_Db::connect($db);
            },
            function (string $line) {
                $this->log[] = $line;
            },
            array(
                'line' => function (string $text) {
                    $this->cli_out[] = 'line: ' . $text;
                },
                'success' => function (string $text) {
                    $this->cli_out[] = 'success: ' . $text;
                },
                'error' => function (string $text) {
                    $this->cli_out[] = 'error: ' . $text;
                    throw new RuntimeException('cli-error');
                },
            ),
            $wordpress,
            $wordpress_server
        );
    }

    private function admin(): int {
        $id = $this->create_user_with_role('administrator');
        wp_set_current_user($id);
        return $id;
    }

    /** Renders the page for the current user; a POST when $post is given. */
    private function render(EVC_Club_Migrations_Admin_Page $page, ?array $post = null): string {
        if ($post !== null) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = wp_slash($post);
            $_REQUEST = $_POST;
        } else {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = array();
            $_REQUEST = array();
        }
        ob_start();
        try {
            $page->render();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function apply_post(?string $word = EVC_Club_Migrations_Service::CONFIRM_WORD, ?string $nonce = null): array {
        $post = array(EVC_Club_Migrations_Admin_Page::FIELD_ACTION => EVC_Club_Migrations_Admin_Page::ACTION_APPLY);
        if ($word !== null) {
            $post[EVC_Club_Migrations_Admin_Page::FIELD_CONFIRM] = $word;
        }
        $post['_wpnonce'] = $nonce ?? wp_create_nonce(EVC_Club_Migrations_Admin_Page::NONCE_ACTION);
        return $post;
    }

    /** @return string[] table names in the Club test database */
    private function tables(?string $schema = null): array {
        $stmt = EVC_Test_Database::admin_pdo()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
        $stmt->execute(array($schema ?? $this->club->name()));
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        sort($names, SORT_STRING);
        return $names;
    }

    private function club_pdo(): PDO {
        $pdo = EVC_Test_Database::admin_pdo();
        $pdo->exec('USE `' . $this->club->name() . '`');
        return $pdo;
    }

    private function audit_rows(): array {
        if (!in_array('evc_audit_events', $this->tables(), true)) {
            return array();
        }
        return $this->club_pdo()->query("SELECT event_type, outcome, staff_wp_user_id, details_json FROM evc_audit_events WHERE event_type = 'club_db_migration' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function recorded(): array {
        return $this->club_pdo()->query('SELECT version, name, checksum, applied_at_utc FROM evc_schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function migrate_only_001(): void {
        $dir = sys_get_temp_dir() . '/evc_mig_partial_' . bin2hex(random_bytes(5));
        mkdir($dir);
        copy(EVC_Test_Database::migrations_dir() . '/001_initial.sql', $dir . '/001_initial.sql');
        $this->temp_dirs[] = $dir;
        $this->assertSame(array(1), (new EVC_Migrator($this->club->connect(), $dir))->migrate());
    }

    private function migrate_all(): void {
        $this->assertSame(array(1, 2), (new EVC_Migrator($this->club->connect(), EVC_Test_Database::migrations_dir()))->migrate());
    }

    private const ALL_TABLES = array('evc_audit_events', 'evc_members', 'evc_pay_aliases', 'evc_pay_correction_heads', 'evc_pay_corrections', 'evc_pay_member_heads', 'evc_pay_movements', 'evc_pay_periods', 'evc_qr_tokens', 'evc_redemptions', 'evc_schema_migrations');

    /** No connection secret (user, password, host, full database name) in $text. */
    private function assert_no_secrets(string $text, string $where): void {
        foreach (array($this->db_user, $this->db_password, EVC_Test_Database::host(), $this->club->name(), (string) DB_HOST, (string) DB_PASSWORD) as $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $text, $where . ' leaks a connection value');
            }
        }
    }

    private function refusal_codes(string $html): array {
        preg_match_all('/data-refusal="([a-z_]+)"/', $html, $m);
        return $m[1];
    }

    // ---- boot, menu and access -----------------------------------------------------------------

    public function test_the_real_boot_registered_one_tools_page_for_administrators_only(): void {
        global $wp_filter, $submenu;
        $pages = array();
        foreach ($wp_filter['admin_menu']->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof EVC_Club_Migrations_Admin_Page) {
                    $pages[] = $callback['function'][0];
                }
            }
        }
        $this->assertCount(1, $pages, 'the real plugins_loaded boot must hook exactly one page');
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            $this->assertStringNotContainsString('migration', $route);
        }

        $this->admin();
        $pages[0]->add_menu();
        $entry = null;
        foreach ((array) ($submenu['tools.php'] ?? array()) as $item) {
            if (($item[2] ?? null) === EVC_Club_Migrations_Admin_Page::SLUG) {
                $entry = $item;
            }
        }
        $this->assertNotNull($entry);
        $this->assertSame('manage_options', $entry[1]);
        $this->assertSame('Evangelou Club — Βάση δεδομένων', $entry[0]);

        // The real wiring reads the EVC_CLUB_DB_* constants, which this site does not define.
        $html = $this->render($pages[0]);
        $this->assertSame(array(EVC_Club_Migrations_Service::CONFIG_MISSING), $this->refusal_codes($html));
        $this->assertStringContainsString('EVC_CLUB_DB_HOST', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_users_without_manage_options_or_club_staff_get_no_access(): void {
        add_role('shop_manager', 'Shop manager', array('read' => true, 'edit_posts' => true, 'manage_woocommerce' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true, 'list_users' => true, 'edit_users' => true));
        $staff_with_cap = $this->create_staff_user();
        get_userdata($staff_with_cap)->add_cap('manage_options');
        $users = array(
            'logged out' => 0,
            'subscriber' => $this->create_user_with_role('subscriber'),
            'editor' => $this->create_user_with_role('editor'),
            'shop manager' => $this->create_user_with_role('shop_manager'),
            'club staff' => $this->create_staff_user(),
            'club staff given manage_options' => $staff_with_cap,
        );
        $page = $this->parts()['page'];
        foreach ($users as $label => $id) {
            wp_set_current_user($id);
            foreach (array(null, $this->apply_post()) as $post) {
                try {
                    $this->render($page, $post);
                    $this->fail($label . ' reached the page');
                } catch (WPDieException $e) {
                    $this->assertStringContainsString('Δεν έχετε δικαίωμα', $e->getMessage(), $label);
                }
            }
        }
        $this->assertSame(0, $this->connects, 'a refused user never causes a Club DB connection');
        $this->assertSame(array(), $this->tables());
        remove_role('shop_manager');
    }

    public function test_club_staff_with_manage_options_is_refused(): void {
        $staff = $this->create_staff_user();
        get_userdata($staff)->add_cap('manage_options');
        wp_set_current_user($staff);
        $this->assertTrue(current_user_can('manage_options'));
        $this->assertFalse(EVC_Club_Migrations_Admin_Page::may_manage(wp_get_current_user()));
        $this->expectException(WPDieException::class);
        $this->render($this->parts()['page'], $this->apply_post());
    }

    // ---- status ----------------------------------------------------------------------------------

    public function test_status_on_a_fresh_database_is_read_only_and_lists_both_pending_migrations(): void {
        $this->admin();
        $parts = $this->parts();
        $html = $this->render($parts['page']);
        $this->assertSame(array(), $this->tables(), 'status must not create anything (not even the bookkeeping table)');
        $this->assertStringContainsString('001 — initial', $html);
        $this->assertStringContainsString('002 — payment_provenance', $html);
        $this->assertStringContainsString('Επιτυχής', $html);
        $this->assertSame(array(), $this->refusal_codes($html));
        $this->assertSame(7, substr_count($html, 'data-state="passed"'));
        $this->assertStringContainsString('name="_wpnonce"', $html);
        $this->assertStringContainsString(EVC_Club_Migrations_Service::CONFIRM_WORD, $html);
        $status = $parts['service']->status();
        $this->assertTrue($status['can_apply']);
        $this->assertSame(array(array('version' => 1, 'name' => 'initial'), array('version' => 2, 'name' => 'payment_provenance')), $status['pending']);
        $this->assertNotNull($status['server_version']);
        $this->assert_no_secrets($html, 'status page');
        $this->assertSame(array(), $this->log, 'status writes no log line');
    }

    // ---- request checks --------------------------------------------------------------------------

    public function test_missing_or_wrong_nonce_never_applies(): void {
        $this->admin();
        $page = $this->parts()['page'];
        $post = $this->apply_post();
        unset($post['_wpnonce']);
        foreach (array($post, $this->apply_post(EVC_Club_Migrations_Service::CONFIRM_WORD, wp_create_nonce('another_action')), $this->apply_post(EVC_Club_Migrations_Service::CONFIRM_WORD, 'abc123')) as $request) {
            try {
                $this->render($page, $request);
                $this->fail('a POST without the right nonce reached apply');
            } catch (WPDieException $e) {
                $this->assertSame(array(), $this->tables());
            }
        }
        $this->assertSame(0, $this->connects);
    }

    public function test_wrong_typed_word_never_applies(): void {
        $this->admin();
        $page = $this->parts()['page'];
        foreach (array(null, '', 'εφαρμογη', 'Εφαρμογη', 'ΕΦΑΡΜΟΓΉ', 'APPLY', 'ΕΦΑΡΜΟΓΗ ΤΩΡΑ', 'yes') as $word) {
            $html = $this->render($page, $this->apply_post($word));
            $this->assertStringContainsString('Η λέξη επιβεβαίωσης δεν είναι σωστή', $html, var_export($word, true));
            $this->assertSame(array(), $this->tables(), var_export($word, true));
        }
        $this->assertSame(array(), $this->log);
    }

    public function test_capability_is_checked_again_after_the_nonce(): void {
        $id = $this->admin();
        $page = $this->parts()['page'];
        // The account loses manage_options while the request is in flight (after the first check).
        $demote = function () use ($id) {
            get_userdata($id)->set_role('editor');
            wp_set_current_user(0);
            wp_set_current_user($id);
        };
        add_action('check_admin_referer', $demote);
        try {
            $this->render($page, $this->apply_post());
            $this->fail('a demoted account applied migrations');
        } catch (WPDieException $e) {
            $this->assertStringContainsString('Δεν έχετε δικαίωμα', $e->getMessage());
        } finally {
            remove_action('check_admin_referer', $demote);
        }
        $this->assertSame(array(), $this->tables());
    }

    // ---- apply -----------------------------------------------------------------------------------

    public function test_fresh_database_applies_001_then_002_with_checksums_and_an_audit_event(): void {
        $admin = $this->admin();
        $html = $this->render($this->parts()['page'], $this->apply_post('  ' . EVC_Club_Migrations_Service::CONFIRM_WORD . ' '));
        $this->assertStringContainsString('Εφαρμόστηκαν οι μεταβάσεις: 001, 002.', $html);
        $this->assertStringContainsString('καταγράφηκε στο ημερολόγιο ελέγχου', $html);
        $this->assertStringContainsString('Η βάση του Club είναι ενημερωμένη.', $html);
        $this->assertSame(self::ALL_TABLES, $this->tables());

        $rows = $this->recorded();
        $this->assertSame(array('1', '2'), array_map('strval', array_column($rows, 'version')));
        $this->assertSame(array('initial', 'payment_provenance'), array_column($rows, 'name'));
        $this->assertSame(EVC_Migrator::checksum_file(EVC_Test_Database::migrations_dir() . '/001_initial.sql'), $rows[0]['checksum']);
        $this->assertSame(EVC_Migrator::checksum_file(EVC_Test_Database::migrations_dir() . '/002_payment_provenance.sql'), $rows[1]['checksum']);
        $this->assertLessThanOrEqual($rows[1]['applied_at_utc'], $rows[0]['applied_at_utc']);

        $audit = $this->audit_rows();
        $this->assertCount(1, $audit);
        $this->assertSame('applied', $audit[0]['outcome']);
        $this->assertSame((string) $admin, (string) $audit[0]['staff_wp_user_id']);
        $this->assertSame(array('channel' => 'admin', 'versions' => '001, 002'), json_decode($audit[0]['details_json'], true));

        $this->assertSame(array(sprintf('Evangelou Club: Club DB migrations apply via admin by user #%d: result=applied versions=001,002 audit=written', $admin)), $this->log);
        $this->assert_no_secrets($html . implode("\n", $this->log) . $audit[0]['details_json'], 'apply');
    }

    public function test_a_second_apply_changes_nothing(): void {
        $admin = $this->admin();
        $page = $this->parts()['page'];
        $this->render($page, $this->apply_post());
        $before = $this->recorded();
        $html = $this->render($page, $this->apply_post());
        $this->assertStringContainsString('Δεν υπάρχει καμία εκκρεμής μετάβαση', $html);
        $this->assertSame($before, $this->recorded());
        $this->assertCount(1, $this->audit_rows(), 'nothing pending writes no audit row');
        $this->assertSame(self::ALL_TABLES, $this->tables());
        $this->assertSame(sprintf('Evangelou Club: Club DB migrations apply via admin by user #%d: result=nothing_pending versions=- audit=not_written', $admin), end($this->log));
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_partial_state_with_only_001_applies_002(): void {
        $this->migrate_only_001();
        $this->admin();
        $parts = $this->parts();
        $status = $parts['service']->status();
        $this->assertSame(array(), $status['refusals']);
        $this->assertSame(array(array('version' => 2, 'name' => 'payment_provenance')), $status['pending']);
        $html = $this->render($parts['page'], $this->apply_post());
        $this->assertStringContainsString('Εφαρμόστηκαν οι μεταβάσεις: 002.', $html);
        $this->assertSame(self::ALL_TABLES, $this->tables());
        $audit = $this->audit_rows();
        $this->assertCount(1, $audit);
        $this->assertSame('002', json_decode($audit[0]['details_json'], true)['versions']);
    }

    public function test_lock_held_by_another_connection_is_refused(): void {
        $holder = EVC_Test_Database::admin_pdo();
        $this->assertSame('1', (string) $holder->query("SELECT GET_LOCK(CONCAT('" . EVC_Migrator::LOCK_PREFIX . "', SHA1('" . $this->club->name() . "')), 0)")->fetchColumn());
        try {
            $this->admin();
            $started = microtime(true);
            $html = $this->render($this->parts()['page'], $this->apply_post());
            $this->assertLessThan(5.0, microtime(true) - $started, 'a held lock is refused at once');
            $this->assertStringContainsString('τρέχει ήδη μια άλλη εφαρμογή μεταβάσεων', $html);
            $this->assertSame(array(), $this->tables());
            $this->assertStringContainsString('result=refused versions=- reasons=lock_busy', end($this->log));
        } finally {
            $holder->query("SELECT RELEASE_LOCK(CONCAT('" . EVC_Migrator::LOCK_PREFIX . "', SHA1('" . $this->club->name() . "')))");
        }
    }

    // ---- separation checks -----------------------------------------------------------------------

    /** Applies through the page and returns [html, refusal codes of the apply notice]. */
    private function refused_apply(EVC_Club_Migrations_Admin_Page $page, string $expected_code): string {
        $html = $this->render($page, $this->apply_post());
        $this->assertStringContainsString('notice-error evc-club-db-result', $html);
        $this->assertStringContainsString(EVC_Club_Migrations_Service::message($expected_code), $html);
        $this->assertStringContainsString('reasons=', end($this->log));
        $this->assertStringContainsString($expected_code, end($this->log));
        $this->assertStringNotContainsString('<form', $html);
        $this->assert_no_secrets($html . implode("\n", $this->log), 'refusal');
        return $html;
    }

    public function test_the_wordpress_database_itself_is_refused_before_connecting(): void {
        $this->admin();
        $wp_tables_before = $this->tables(DB_NAME);
        foreach (array(DB_NAME, strtoupper(DB_NAME)) as $name) {
            $page = $this->parts($this->config($name))['page'];
            $html = $this->refused_apply($page, EVC_Club_Migrations_Service::SAME_DATABASE);
            $this->assertContains(EVC_Club_Migrations_Service::SAME_DATABASE, $this->refusal_codes($html));
        }
        $this->assertSame(0, $this->connects, 'the configured WordPress database is refused before any connection');
        $this->assertSame($wp_tables_before, $this->tables(DB_NAME));
        $this->assertNotContains('evc_schema_migrations', $this->tables(DB_NAME));
    }

    public function test_the_same_server_database_is_refused_even_under_another_configured_name(): void {
        global $wpdb;
        $this->admin();
        // WordPress's configuration is reported differently (as through a proxy / alias), but the
        // Club connection lands in the very same database: the server identity check refuses it.
        $root = new EVC_Db_Config(EVC_Test_Database::host(), EVC_Test_Database::port(), DB_NAME, EVC_Test_Database::user(), EVC_Test_Database::password());
        $parts = $this->parts($root, array('name' => 'some_alias', 'host' => 'db.internal:3306', 'prefix' => $wpdb->base_prefix));
        $wp_tables_before = $this->tables(DB_NAME);
        $status = $parts['service']->status();
        $this->assertContains(EVC_Club_Migrations_Service::SAME_DATABASE, $status['refusals']);
        $this->refused_apply($parts['page'], EVC_Club_Migrations_Service::SAME_DATABASE);
        $this->assertSame($wp_tables_before, $this->tables(DB_NAME));
    }

    public function test_unknown_wordpress_identity_is_refused(): void {
        $this->admin();
        $parts = $this->parts(null, null, function () {
            return null;
        });
        $this->refused_apply($parts['page'], EVC_Club_Migrations_Service::WORDPRESS_IDENTITY_UNKNOWN);
        $this->assertSame(array(), $this->tables());
    }

    public function test_wordpress_tables_are_refused(): void {
        $this->admin();
        foreach (array('wptests_options', 'wptests_wc_orders', 'wp_posts', 'users') as $table) {
            $this->club_pdo()->exec('CREATE TABLE `' . $table . '` (id INT PRIMARY KEY) ENGINE=InnoDB');
            $html = $this->refused_apply($this->parts()['page'], EVC_Club_Migrations_Service::WORDPRESS_TABLES_PRESENT);
            $this->assertContains(EVC_Club_Migrations_Service::WORDPRESS_TABLES_PRESENT, $this->refusal_codes($html), $table);
            $this->assertNotContains(EVC_Club_Migrations_Service::UNKNOWN_TABLES_PRESENT, $this->refusal_codes($html), $table);
            $this->assertSame(array($table), $this->tables(), 'nothing created next to ' . $table);
            $this->club_pdo()->exec('DROP TABLE `' . $table . '`');
        }
    }

    public function test_unknown_tables_are_refused(): void {
        $this->admin();
        $this->club_pdo()->exec('CREATE TABLE inventory (id INT PRIMARY KEY) ENGINE=InnoDB');
        $html = $this->refused_apply($this->parts()['page'], EVC_Club_Migrations_Service::UNKNOWN_TABLES_PRESENT);
        $this->assertSame(array(EVC_Club_Migrations_Service::UNKNOWN_TABLES_PRESENT), $this->refusal_codes($html));
        $this->assertSame(array('inventory'), $this->tables());
    }

    public function test_unrecorded_club_tables_are_refused(): void {
        $this->admin();
        $this->club_pdo()->exec('CREATE TABLE evc_members (id INT PRIMARY KEY) ENGINE=InnoDB');
        $html = $this->refused_apply($this->parts()['page'], EVC_Club_Migrations_Service::UNRECORDED_CLUB_TABLES);
        $this->assertSame(array(EVC_Club_Migrations_Service::UNRECORDED_CLUB_TABLES), $this->refusal_codes($html));
        $this->assertSame(array('evc_members'), $this->tables());
    }

    public function test_missing_club_tables_are_refused(): void {
        $this->migrate_all();
        $this->club_pdo()->exec('DROP TABLE evc_pay_corrections');
        $this->admin();
        $html = $this->refused_apply($this->parts()['page'], EVC_Club_Migrations_Service::MISSING_CLUB_TABLES);
        $this->assertSame(array(EVC_Club_Migrations_Service::MISSING_CLUB_TABLES), $this->refusal_codes($html));
    }

    public function test_checksum_drift_is_refused(): void {
        $this->migrate_all();
        $this->club_pdo()->exec("UPDATE evc_schema_migrations SET checksum = REPEAT('0', 64) WHERE version = 1");
        $this->admin();
        $parts = $this->parts();
        $status = $parts['service']->status();
        $this->assertSame(array(EVC_Club_Migrations_Service::DRIFT), $status['refusals']);
        $this->assertFalse($status['applied'][0]['checksum_ok']);
        $this->assertTrue($status['applied'][1]['checksum_ok']);
        $html = $this->refused_apply($parts['page'], EVC_Club_Migrations_Service::DRIFT);
        $this->assertStringContainsString('ΔΙΑΦΟΡΕΤΙΚΟ', $html);
        $this->assertSame(array(), $this->audit_rows());
    }

    public function test_out_of_order_history_is_refused(): void {
        $this->migrate_all();
        $this->club_pdo()->exec('DELETE FROM evc_schema_migrations WHERE version = 1');
        $this->admin();
        $status = $this->parts()['service']->status();
        $this->assertContains(EVC_Club_Migrations_Service::DRIFT, $status['refusals']);
        $this->assertSame(array(array('version' => 1, 'name' => 'initial')), $status['pending']);
        $this->refused_apply($this->parts()['page'], EVC_Club_Migrations_Service::DRIFT);
    }

    public function test_a_misshapen_bookkeeping_table_is_refused(): void {
        $this->club_pdo()->exec('CREATE TABLE evc_schema_migrations (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->admin();
        $html = $this->refused_apply($this->parts()['page'], EVC_Club_Migrations_Service::BOOKKEEPING_INVALID);
        $this->assertSame(array(EVC_Club_Migrations_Service::BOOKKEEPING_INVALID), $this->refusal_codes($html));
        $this->assertSame(array('evc_schema_migrations'), $this->tables());
    }

    // ---- configuration, connection and secrets -----------------------------------------------------

    public function test_connection_failures_are_refused_without_secrets(): void {
        $this->admin();
        foreach (array($this->config(null, 'wrong-' . $this->db_password), $this->config(null, null, 1)) as $config) {
            $parts = $this->parts($config);
            $html = $this->render($parts['page']);
            $this->assertSame(array(EVC_Club_Migrations_Service::CONNECTION_FAILED), $this->refusal_codes($html));
            $this->assertStringContainsString('Απέτυχε', $html);
            $this->assert_no_secrets($html, 'connection failure page');
            $html = $this->refused_apply($parts['page'], EVC_Club_Migrations_Service::CONNECTION_FAILED);
            $this->assertStringNotContainsString('wrong-', $html . implode("\n", $this->log));
        }
        $this->assertSame(array(), $this->tables());
    }

    public function test_missing_or_invalid_configuration_is_refused_without_connecting(): void {
        $this->admin();
        $missing = EVC_Club_Migrations_Plugin::build(function () {
            return null;
        }, function () {
            $this->connects++;
            throw new RuntimeException('never');
        }, function (string $line) {
            $this->log[] = $line;
        });
        $html = $this->render($missing['page']);
        $this->assertSame(array(EVC_Club_Migrations_Service::CONFIG_MISSING), $this->refusal_codes($html));
        $invalid = EVC_Club_Migrations_Plugin::build(function () {
            return new EVC_Db_Config('127.0.0.1', 3306, 'bad-name!', 'u', 'p');
        }, function () {
            $this->connects++;
            throw new RuntimeException('never');
        }, function (string $line) {
            $this->log[] = $line;
        });
        $html = $this->render($invalid['page'], $this->apply_post());
        $this->assertContains(EVC_Club_Migrations_Service::CONFIG_INVALID, $this->refusal_codes($html));
        $this->assertStringContainsString(EVC_Club_Migrations_Service::message(EVC_Club_Migrations_Service::CONFIG_INVALID), $html);
        $this->assertSame(0, $this->connects);
    }

    public function test_output_never_contains_connection_secrets(): void {
        $this->admin();
        $parts = $this->parts();
        $status = $parts['service']->status();
        $name = $this->club->name();
        $this->assertSame($name[0] . '•••' . substr($name, -1) . ' (' . strlen($name) . ')', $status['database_hint']);
        $all = $this->render($parts['page']) . $this->render($parts['page'], $this->apply_post()) . $this->render($parts['page']) . print_r($status, true);
        $parts['cli']->status();
        $all .= implode("\n", $this->cli_out) . implode("\n", $this->log) . print_r($parts['service']->status(), true);
        $this->assertStringContainsString('(κρυμμένο)', $all);
        $this->assert_no_secrets($all, 'page, report, CLI and log');
    }

    // ---- WP-CLI command (the WP_CLI runtime itself is not loaded in tests) -------------------------

    public function test_cli_status_and_apply(): void {
        $parts = $this->parts();
        $cli = $parts['cli'];
        $cli->status(array(), array());
        $out = implode("\n", $this->cli_out);
        $this->assertStringContainsString('Εκκρεμής 001 initial', $out);
        $this->assertStringContainsString('Εκκρεμής 002 payment_provenance', $out);
        $this->assertStringContainsString('wp evc-club migrations apply --yes', $out);
        $this->assertSame(array(), $this->tables());

        foreach (array(array(), array('yes' => false), array('yes' => 'no'), array('force' => true)) as $assoc) {
            $this->cli_out = array();
            try {
                $cli->apply(array(), $assoc);
                $this->fail('apply without --yes');
            } catch (RuntimeException $e) {
                $this->assertSame('cli-error', $e->getMessage());
                $this->assertStringContainsString('Χρειάζεται --yes', $this->cli_out[0]);
            }
            $this->assertSame(array(), $this->tables());
        }

        $this->cli_out = array();
        $cli->apply(array(), array('yes' => true));
        $this->assertSame(array('success: Η βάση του Club ενημερώθηκε. Εφαρμόστηκαν οι μεταβάσεις: 001, 002. Η ενέργεια καταγράφηκε στο ημερολόγιο ελέγχου του Club.'), $this->cli_out);
        $this->assertSame(self::ALL_TABLES, $this->tables());
        $audit = $this->audit_rows();
        $this->assertSame('cli', json_decode($audit[0]['details_json'], true)['channel']);
        $this->assertNull($audit[0]['staff_wp_user_id']);
        $this->assertStringContainsString('via cli by user #0: result=applied', end($this->log));

        $this->cli_out = array();
        $cli->apply(array(), array('yes' => true));
        $this->assertSame(array('line: Δεν υπάρχει καμία εκκρεμής μετάβαση. Δεν έγινε καμία αλλαγή.'), $this->cli_out);
        $this->cli_out = array();
        $cli->status(array(), array());
        $this->assertStringContainsString('success: Η βάση του Club είναι ενημερωμένη.', end($this->cli_out));
    }

    public function test_cli_refuses_with_the_same_checks(): void {
        $this->club_pdo()->exec('CREATE TABLE wptests_users (id INT PRIMARY KEY) ENGINE=InnoDB');
        $cli = $this->parts()['cli'];
        $cli->status(array(), array());
        $this->assertStringContainsString('ΑΡΝΗΣΗ [wordpress_tables_present]', implode("\n", $this->cli_out));
        $this->cli_out = array();
        try {
            $cli->apply(array(), array('yes' => true));
            $this->fail('the CLI applied despite a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(EVC_Club_Migrations_Service::message(EVC_Club_Migrations_Service::WORDPRESS_TABLES_PRESENT), $this->cli_out[0]);
        }
        $this->assertSame(array('wptests_users'), $this->tables());
        $this->assert_no_secrets(implode("\n", $this->cli_out) . implode("\n", $this->log), 'CLI');
    }
}
