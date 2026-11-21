<?php

/**
 * Feature ENABLED: the protected /club-admin/ shell (EVC_Staff_Shell),
 * its asset manifest handling and rewrite registration, in real WordPress.
 * Shell decisions are exercised through EVC_Staff_Shell::respond(), which
 * serve() emits verbatim (real HTTP is covered by tests/http in CI).
 */
final class StaffShellTest extends EVC_WP_Test_Case {
    /** @var string */
    private $asset_dir;
    const ASSET_URL = 'https://example.org/wp-content/plugins/evangelou-club/staff-app';

    public function set_up() {
        parent::set_up();
        $this->asset_dir = sys_get_temp_dir() . '/evc-staff-assets-' . wp_generate_password(8, false, false);
        $this->write_assets(array(
            'staff.html' => array('file' => 'assets/staff-Ab12Cd34.js', 'isEntry' => true, 'css' => array('assets/staff-Ef56Gh78.css')),
        ), array('assets/staff-Ab12Cd34.js', 'assets/staff-Ef56Gh78.css'));
    }

    public function tear_down() {
        $this->remove_dir($this->asset_dir);
        parent::tear_down();
    }

    private function write_assets(array $manifest, array $files, ?string $raw_manifest = null): void {
        $this->remove_dir($this->asset_dir);
        mkdir($this->asset_dir . '/assets', 0777, true);
        foreach ($files as $file) {
            file_put_contents($this->asset_dir . '/' . $file, '/* test asset */');
        }
        file_put_contents($this->asset_dir . '/manifest.json', $raw_manifest ?? wp_json_encode($manifest));
    }

    private function remove_dir(?string $dir): void {
        if (!$dir || !is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    private function assets(): EVC_Staff_Assets {
        return new EVC_Staff_Assets($this->asset_dir, self::ASSET_URL);
    }

    private function shell(): array {
        return EVC_Staff_Shell::respond(wp_get_current_user(), $this->assets());
    }

    private static function config_of(array $response): ?array {
        if (!preg_match('#<script type="application/json" id="evc-staff-config">(.*?)</script>#s', $response['body'], $m)) {
            return null;
        }
        return json_decode($m[1], true);
    }

    private function assertNoStoreHeaders(array $response): void {
        $h = $response['headers'];
        $this->assertStringContainsString('no-store', $h['Cache-Control']);
        $this->assertStringContainsString('private', $h['Cache-Control']);
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertSame('no-referrer', $h['Referrer-Policy']);
        $this->assertSame('DENY', $h['X-Frame-Options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $h['Content-Security-Policy']);
    }

    private function assertNoBootstrap(array $response): void {
        $this->assertNull(self::config_of($response), 'no bootstrap config');
        $this->assertStringNotContainsString('evc-staff-config', $response['body']);
        $this->assertStringNotContainsString('type="module"', $response['body']);
        if (get_current_user_id() > 0) {
            $this->assertStringNotContainsString(wp_create_nonce('wp_rest'), $response['body'], 'no REST nonce');
        }
        $this->assertNoStoreHeaders($response);
    }

    // ---- authorization ---------------------------------------------------

    public function test_anonymous_visitor_is_sent_to_login_without_any_shell(): void {
        $r = $this->shell();
        $this->assertSame(302, $r['status']);
        $this->assertStringContainsString('wp-login.php', $r['location']);
        $this->assertStringContainsString(rawurlencode(EVC_Staff_Shell::shell_url()), $r['location']);
        $this->assertSame('', $r['body']);
        $this->assertNoBootstrap($r);
    }

    public function test_restricted_staff_gets_the_protected_shell_with_bootstrap(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $r = $this->shell();

        $this->assertSame(200, $r['status']);
        $this->assertNoStoreHeaders($r);
        $this->assertSame(EVC_Rest_Security::SHELL_CSP, $r['headers']['Content-Security-Policy']);
        $this->assertStringContainsString("script-src 'self'", $r['headers']['Content-Security-Policy']);
        $this->assertStringNotContainsString('unsafe-inline', $r['headers']['Content-Security-Policy']);
        $this->assertStringNotContainsString('unsafe-eval', $r['headers']['Content-Security-Policy']);
        $this->assertSame('noindex, nofollow', $r['headers']['X-Robots-Tag']);

        $this->assertStringContainsString('<script type="module" src="' . self::ASSET_URL . '/assets/staff-Ab12Cd34.js"></script>', $r['body']);
        $this->assertStringContainsString('<link rel="stylesheet" href="' . self::ASSET_URL . '/assets/staff-Ef56Gh78.css">', $r['body']);
        $this->assertSame(2, substr_count($r['body'], '<script'), 'only the module script and the inert JSON block');
        $this->assertStringNotContainsString('<script>', $r['body'], 'no executable inline script');

        $config = self::config_of($r);
        $this->assertIsArray($config);
        $this->assertSame(untrailingslashit(rest_url('evangelou-club/v1')), $config['restBase']);
        $this->assertNotFalse(wp_verify_nonce($config['nonce'], 'wp_rest'), 'valid wp_rest nonce for this session');
        $this->assertStringContainsString('reauth=1', $config['loginUrl']);
        $this->assertSame(EVC_Staff_Shell::shell_url(), $config['shellUrl']);
        $this->assertSame(300, $config['idleLockSeconds']);
        $this->assertSame(EVC_Staff_Session::session_ref($token), $config['sessionRef']);
        $this->assertSame(array('qrLookup' => false, 'phoneLookup' => false, 'coffeeRedemption' => false, 'history' => false), $config['features']);
        $this->assertStringNotContainsString($token, $r['body'], 'raw session token never exposed');
        foreach (array('user_login', 'user_email', 'DB_PASSWORD', 'logged_in') as $secretish) {
            $this->assertArrayNotHasKey($secretish, $config);
        }
        $this->assertStringNotContainsString('_wpnonce', $r['body'], 'no nonce in any URL');
        // STAGE-08 round 11 (D26): the member page's home-screen app never reaches the staff app, with either permalink form.
        foreach (array('', '/%postname%/') as $structure) {
            update_option('permalink_structure', $structure);
            $body = $this->shell()['body'];
            foreach (array('rel="manifest"', 'apple-touch-icon', 'apple-mobile-web-app', 'serviceWorker', 'sw.js', 'manifest.webmanifest', 'theme-color') as $needle) {
                $this->assertStringNotContainsString($needle, $body, $needle);
            }
        }
    }

    public function test_page_load_counts_as_staff_activity(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $this->shell();
        $this->assertGreaterThanOrEqual(time() - 2, (int) get_user_meta($staff, EVC_Staff_Session::activity_key($token), true));
    }

    public function test_accounts_without_the_exclusive_club_role_are_denied(): void {
        $variants = array(
            'administrator' => $this->create_user_with_role('administrator'),
            'editor' => $this->create_user_with_role('editor'),
            'subscriber' => $this->create_user_with_role('subscriber'),
        );
        $multi = $this->create_staff_user();
        (new WP_User($multi))->add_role('administrator');
        $variants['staff + administrator'] = $multi;
        $direct = $this->create_staff_user();
        (new WP_User($direct))->add_cap('manage_options');
        $variants['staff + direct cap'] = $direct;

        foreach ($variants as $label => $id) {
            clean_user_cache($id);
            $this->login_as($id);
            $r = $this->shell();
            $this->assertSame(403, $r['status'], $label);
            $this->assertNoBootstrap($r);
        }
    }

    public function test_disabled_shared_account_is_denied(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        update_user_meta($staff, EVC_Staff_Auth::DISABLED_META, '1');
        $r = $this->shell();
        $this->assertSame(403, $r['status']);
        $this->assertNoBootstrap($r);
    }

    public function test_idle_and_expired_sessions_are_destroyed_and_sent_to_reauth(): void {
        $staff = $this->create_staff_user();
        $manager = WP_Session_Tokens::get_instance($staff);

        $token = $this->login_as($staff);
        $session = $manager->get($token);
        $session['login'] = time() - 3600;
        $session['expiration'] = $session['login'] + EVC_Staff_Session::ABSOLUTE_SECONDS;
        $manager->update($token, $session);
        update_user_meta($staff, EVC_Staff_Session::activity_key($token), (string) (time() - 1801));
        $r = $this->shell();
        $this->assertSame(302, $r['status'], 'idle');
        $this->assertStringContainsString('reauth=1', $r['location']);
        $this->assertNoBootstrap($r);
        $this->assertNull($manager->get($token));

        $token = $this->login_as($staff);
        $session = $manager->get($token);
        $session['login'] = time() - 43200;
        $manager->update($token, $session);
        $r = $this->shell();
        $this->assertSame(302, $r['status'], 'expired');
        $this->assertStringContainsString('reauth=1', $r['location']);
        $this->assertNull($manager->get($token));
    }

    // ---- assets ------------------------------------------------------------

    public function test_missing_or_inconsistent_build_fails_closed_with_503(): void {
        $this->login_as($this->create_staff_user());
        $cases = array(
            'no manifest' => function () {
                unlink($this->asset_dir . '/manifest.json');
            },
            'corrupt manifest' => function () {
                $this->write_assets(array(), array('assets/staff-Ab12Cd34.js'), '{not json');
            },
            'entry missing' => function () {
                $this->write_assets(array('other.html' => array('file' => 'assets/staff-Ab12Cd34.js', 'isEntry' => true)), array('assets/staff-Ab12Cd34.js'));
            },
            'hashed js missing (stale manifest)' => function () {
                $this->write_assets(array('staff.html' => array('file' => 'assets/staff-Ab12Cd34.js', 'isEntry' => true)), array());
            },
            'css missing' => function () {
                $this->write_assets(array('staff.html' => array('file' => 'assets/staff-Ab12Cd34.js', 'isEntry' => true, 'css' => array('assets/gone.css'))), array('assets/staff-Ab12Cd34.js'));
            },
            'path traversal' => function () {
                $this->write_assets(array('staff.html' => array('file' => 'assets/../../evil.js', 'isEntry' => true)), array('assets/staff-Ab12Cd34.js'));
            },
            'non-asset file type inside assets/' => function () {
                $this->write_assets(array('staff.html' => array('file' => 'assets/evil.php', 'isEntry' => true)), array('assets/evil.php'));
            },
        );
        foreach ($cases as $label => $break) {
            $this->set_up_assets_ok();
            $break();
            $r = $this->shell();
            $this->assertSame(503, $r['status'], $label);
            $this->assertNoBootstrap($r);
        }
    }

    private function set_up_assets_ok(): void {
        $this->write_assets(array(
            'staff.html' => array('file' => 'assets/staff-Ab12Cd34.js', 'isEntry' => true, 'css' => array('assets/staff-Ef56Gh78.css')),
        ), array('assets/staff-Ab12Cd34.js', 'assets/staff-Ef56Gh78.css'));
    }

    public function test_bootstrap_json_cannot_break_out_of_its_script_element(): void {
        $hostile = array('x' => '</script><script>alert(1)</script>', 'y' => "'\"&<>", 'z' => '<!--');
        $encoded = EVC_Staff_Shell::encode_config($hostile);
        foreach (array('<', '>', '&', "'", '"x"') as $raw) {
            if ($raw === '"x"') {
                continue;
            }
            $this->assertStringNotContainsString($raw, $encoded, "raw $raw must be escaped");
        }
        $this->assertSame($hostile, json_decode($encoded, true), 'still valid JSON for the app');
    }

    // ---- routing -----------------------------------------------------------

    public function test_rewrite_rule_and_query_var_route_club_admin(): void {
        $this->set_permalink_structure('/%postname%/');
        delete_option(EVC_Staff_Shell::REWRITE_STATE_OPTION);
        EVC_Staff_Shell::sync_rewrite();
        $this->assertArrayHasKey('^club-admin/?$', (array) get_option('rewrite_rules'));
        $this->assertContains(EVC_Staff_Shell::QUERY_VAR, apply_filters('query_vars', array()));
        $this->go_to(home_url('/club-admin/'));
        $this->assertSame('1', (string) get_query_var(EVC_Staff_Shell::QUERY_VAR));
    }

    /** STAGE-08 round 4: staging ran with plain permalinks; /club-admin/ never reached WordPress there (Apache 404). */
    public function test_the_shell_url_follows_the_permalink_setting(): void {
        $this->set_permalink_structure('');
        $plain = EVC_Staff_Shell::shell_url();
        $this->assertSame(add_query_arg(EVC_Staff_Shell::QUERY_VAR, '1', home_url('/')), $plain);
        $this->assertStringNotContainsString('club-admin', $plain, 'plain permalinks: no pretty path');
        $this->go_to($plain);
        $this->assertSame('1', (string) get_query_var(EVC_Staff_Shell::QUERY_VAR), 'the plain URL reaches the shell');
        $r = $this->shell();
        $this->assertStringContainsString(rawurlencode($plain), $r['location'], 'login returns to the plain URL');
        $staff = get_userdata($this->create_staff_user());
        $this->assertSame($plain, EVC_Staff_Admin_Guard::redirect_target($staff, false), 'wp-admin sends the till to the plain URL');
        $this->assertSame($plain, apply_filters('login_redirect', admin_url(), '', $staff), 'so does the login redirect');
        $token = $this->login_as($staff->ID);
        $config = self::config_of($this->shell());
        $this->assertIsArray($config);
        $this->assertSame($plain, $config['shellUrl']);
        $this->assertStringContainsString(rawurlencode($plain), $config['loginUrl']);
        $this->assertStringContainsString('rest_route=', $config['restBase'], 'plain REST base for the app');
        // Round 5: the app accepts a plain base only exactly at homePath or its index.php.
        $this->assertSame('/', $config['homePath'], 'the test site sits at the root (fixed value, not recomputed)');
        $this->assertContains((string) wp_parse_url($config['restBase'], PHP_URL_PATH), array($config['homePath'], $config['homePath'] . 'index.php'), 'the plain base sits at the home path');
        unset($token);

        $this->set_permalink_structure('/%postname%/');
        $this->assertSame(home_url('/club-admin/'), EVC_Staff_Shell::shell_url());
        $this->assertSame(home_url('/club-admin/'), apply_filters('login_redirect', admin_url(), '', $staff));
    }

    /** STAGE-08 round 6: a subfolder install sends its own home path; fixed expected values, not recomputed. */
    public function test_the_home_path_of_a_subfolder_install_is_sent_as_a_fixed_value(): void {
        $this->set_permalink_structure('');
        $sub = static function () {
            return 'http://example.org/prometheus';
        };
        add_filter('pre_option_home', $sub);
        try {
            $this->assertSame('/prometheus/', EVC_Rest_Security::home_path());
            $staff = get_userdata($this->create_staff_user());
            $token = $this->login_as($staff->ID);
            $config = self::config_of($this->shell());
            $this->assertIsArray($config);
            $this->assertSame('/prometheus/', $config['homePath']);
            $this->assertSame('/prometheus/index.php', wp_parse_url($config['restBase'], PHP_URL_PATH), 'the plain base sits at that home path');
            unset($token);
        } finally {
            remove_filter('pre_option_home', $sub);
        }
        $greek = static function () {
            return 'http://example.org/καφές';
        };
        add_filter('pre_option_home', $greek);
        try {
            $this->assertSame('/%CE%BA%CE%B1%CF%86%CE%AD%CF%82/', EVC_Rest_Security::home_path(), 'non-ASCII: the form URL.pathname uses');
        } finally {
            remove_filter('pre_option_home', $greek);
        }
        $spaced = static function () {
            return 'http://example.org/my shop/caf%C3%A9';
        };
        add_filter('pre_option_home', $spaced);
        try {
            $this->assertSame('/my%20shop/caf%C3%A9/', EVC_Rest_Security::home_path(), 'already-encoded segments are not encoded twice');
        } finally {
            remove_filter('pre_option_home', $spaced);
        }
        // Round 7: the browser's URL.pathname keeps every «%» as it is: an encoded slash, lower-case hex and a
        // bare «%» are never decoded or re-encoded (fixed values, checked against new URL(…).pathname).
        foreach (array(
            'http://example.org/a%2Fb' => '/a%2Fb/',
            'http://example.org/a%2fb' => '/a%2fb/',
            'http://example.org/%ce%ba%ce%b1' => '/%ce%ba%ce%b1/',
            'http://example.org/100%' => '/100%/',
            'http://example.org/50%off/%zz' => '/50%off/%zz/',
            'http://example.org/x^|[]y/{a}`b' => '/x^|[]y/%7Ba%7D%60b/',
            // Round 8 (NIT-3): a backslash is a «/» for the browser (new URL('http://h/a\\b').pathname === '/a/b').
            'http://example.org/a\\b' => '/a/b/',
            'http://example.org/shop\\' => '/shop//', // home_url('/') = «…/shop\\/»: URL.pathname «/shop//», as the REST base
        ) as $home => $expected) {
            $filter = static function () use ($home) {
                return $home;
            };
            add_filter('pre_option_home', $filter);
            try {
                $this->assertSame($expected, EVC_Rest_Security::home_path(), $home);
            } finally {
                remove_filter('pre_option_home', $filter);
            }
        }
        $this->assertSame('/', EVC_Rest_Security::home_path());
    }

    public function test_rewrite_rules_are_flushed_only_when_the_state_changes(): void {
        $this->set_permalink_structure('/%postname%/');
        $flushes = 0;
        $count = function () use (&$flushes) {
            $flushes++;
        };
        add_action('generate_rewrite_rules', $count);
        update_option(EVC_Staff_Shell::REWRITE_STATE_OPTION, EVC_Staff_Shell::REWRITE_STATE_ON);
        EVC_Staff_Shell::sync_rewrite();
        EVC_Staff_Shell::sync_rewrite();
        $this->assertSame(0, $flushes, 'no flush on ordinary requests');
        update_option(EVC_Staff_Shell::REWRITE_STATE_OPTION, EVC_Staff_Shell::REWRITE_STATE_OFF);
        EVC_Staff_Shell::sync_rewrite();
        EVC_Staff_Shell::sync_rewrite();
        $this->assertSame(1, $flushes, 'exactly one flush when the desired state changed');
        remove_action('generate_rewrite_rules', $count);
    }

    public function test_an_existing_club_admin_page_is_never_taken_over(): void {
        $this->set_permalink_structure('/%postname%/');
        self::factory()->post->create(array('post_type' => 'page', 'post_name' => 'club-admin', 'post_status' => 'publish', 'post_title' => 'Existing page'));
        $this->assertTrue(EVC_Staff_Shell::has_path_conflict());
        $this->assertSame(EVC_Staff_Shell::REWRITE_STATE_OFF, EVC_Staff_Shell::desired_rewrite_state());
        // Simulate a fresh request: the plugin's own init earlier in this PHPUnit
        // process already registered the rule in memory.
        global $wp_rewrite;
        unset($wp_rewrite->extra_rules_top['^club-admin/?$']);
        update_option(EVC_Staff_Shell::REWRITE_STATE_OPTION, EVC_Staff_Shell::REWRITE_STATE_ON);
        EVC_Staff_Shell::sync_rewrite();
        $this->assertArrayNotHasKey('^club-admin/?$', (array) get_option('rewrite_rules'));
        $this->assertSame(EVC_Staff_Shell::REWRITE_STATE_OFF, get_option(EVC_Staff_Shell::REWRITE_STATE_OPTION));
    }

    public function test_ordinary_requests_are_untouched_by_the_shell_hook(): void {
        $this->go_to(home_url('/'));
        EVC_Staff_Shell::serve(); // must return (no output, no exit) when not the staff route
        $this->assertSame('', (string) get_query_var(EVC_Staff_Shell::QUERY_VAR));
        $this->assertSame(0, has_action('template_redirect', array('EVC_Staff_Shell', 'serve')));
    }
}
