<?php

/**
 * STAGE-01, customer flag ENABLED: the protected /club-member/ page
 * (EVC_Customer_Shell) in real WordPress, through respond() which serve()
 * emits verbatim. Native WordPress login only; the wp_rest nonce reaches
 * nothing but a signed-in customer's 200 page.
 */
final class CustomerShellTest extends EVC_WP_Test_Case {
    /** @var string */
    private $asset_dir;
    const ASSET_URL = 'https://example.org/wp-content/plugins/evangelou-club/customer-app';

    public function set_up() {
        parent::set_up();
        $this->asset_dir = sys_get_temp_dir() . '/evc-customer-assets-' . wp_generate_password(8, false, false);
        mkdir($this->asset_dir . '/assets', 0777, true);
        foreach (array('assets/customer-Ab12Cd34.js', 'assets/customer-Ef56Gh78.css') as $file) {
            file_put_contents($this->asset_dir . '/' . $file, '/* test asset */');
        }
        file_put_contents($this->asset_dir . '/manifest.json', wp_json_encode(array(
            'customer.html' => array('file' => 'assets/customer-Ab12Cd34.js', 'isEntry' => true, 'css' => array('assets/customer-Ef56Gh78.css')),
        )));
    }

    public function tear_down() {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->asset_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->asset_dir);
        parent::tear_down();
    }

    private function assets(): EVC_Staff_Assets {
        return new EVC_Staff_Assets($this->asset_dir, self::ASSET_URL, EVC_Customer_Shell::ASSET_ENTRY);
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'subscriber', 'user_pass' => self::PASSWORD));
    }

    private function config_of(string $body): array {
        $this->assertSame(1, preg_match('#<script type="application/json" id="evc-customer-config">(.*?)</script>#s', $body, $m));
        return json_decode($m[1], true);
    }

    public function test_signed_out_visitors_go_to_the_native_wordpress_login(): void {
        wp_set_current_user(0);
        $r = EVC_Customer_Shell::respond(wp_get_current_user(), $this->assets());
        $this->assertSame(302, $r['status']);
        $this->assertStringContainsString('wp-login.php', $r['location']);
        $this->assertStringContainsString(rawurlencode(EVC_Customer_Shell::shell_url()), $r['location']);
        $this->assertSame('', $r['body']);
    }

    public function test_staff_and_privileged_accounts_get_403_without_bootstrap(): void {
        foreach (array($this->create_staff_user(), $this->create_user_with_role('administrator'), $this->create_user_with_role('editor')) as $id) {
            $this->login_as($id);
            $r = EVC_Customer_Shell::respond(wp_get_current_user(), $this->assets());
            $this->assertSame(403, $r['status']);
            $this->assertStringNotContainsString('evc-customer-config', $r['body']);
        }
    }

    public function test_missing_assets_are_503_without_bootstrap(): void {
        $this->login_as($this->customer());
        unlink($this->asset_dir . '/assets/customer-Ab12Cd34.js');
        $r = EVC_Customer_Shell::respond(wp_get_current_user(), $this->assets());
        $this->assertSame(503, $r['status']);
        $this->assertStringNotContainsString('nonce', $r['body']);
    }

    public function test_a_customer_gets_the_page_with_the_customer_rest_base_and_a_nonce(): void {
        $id = $this->customer();
        $this->login_as($id);
        $r = EVC_Customer_Shell::respond(wp_get_current_user(), $this->assets());
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString(self::ASSET_URL . '/assets/customer-Ab12Cd34.js', $r['body']);
        $config = $this->config_of($r['body']);
        // Round 10: no joinUrl while the in-store join flow (EVC_CLUB_ONBOARDING_ENABLED) is off: nothing new is shown.
        $this->assertFalse(EVC_Onboarding_Feature::enabled());
        $this->assertSame(array('restBase', 'homePath', 'nonce', 'loginUrl', 'logoutUrl', 'appVersion'), array_keys($config));
        $this->assertSame(wp_parse_url(home_url('/'), PHP_URL_PATH), $config['homePath'], 'round 5: the home path for a plain REST base');
        $this->assertSame(1, wp_verify_nonce($config['nonce'], 'wp_rest'));
        $this->assertStringContainsString('evangelou-club-customer/v1', $config['restBase']);
        $this->assertStringContainsString('action=logout', $config['logoutUrl']);
        $user = get_userdata($id);
        foreach (array($user->user_login, $user->user_email) as $needle) {
            $this->assertStringNotContainsString($needle, $r['body'], 'no personal data in the page');
        }
        $this->assertSame(EVC_Rest_Security::SHELL_CSP, $r['headers']['Content-Security-Policy']);
        $this->assertSame('noindex, nofollow', $r['headers']['X-Robots-Tag']);
        $this->assertStringContainsString('no-store', $r['headers']['Cache-Control']);
    }

    public function test_the_page_url_follows_the_permalink_setting(): void {
        update_option('permalink_structure', '');
        $this->assertStringContainsString('evc_club_member=1', EVC_Customer_Shell::shell_url(), 'plain permalinks (current staging)');
        update_option('permalink_structure', '/%postname%/');
        $this->assertStringEndsWith('/club-member/', EVC_Customer_Shell::shell_url());
    }

    public function test_query_var_and_rewrite_exist_only_with_the_flag(): void {
        $this->assertContains(EVC_Customer_Shell::QUERY_VAR, apply_filters('query_vars', array()));
        $this->assertSame(EVC_Customer_Shell::REWRITE_STATE_ON, EVC_Customer_Shell::desired_rewrite_state());
    }

    // ---- STAGE-08 round 11 (owner decision D26 part 1): home-screen app -------------------------------------------

    private function worker_file(string $body = "/* worker */\n"): string {
        $file = $this->asset_dir . '/sw.js';
        file_put_contents($file, $body);
        return $file;
    }

    private function home_path(): string {
        return EVC_Rest_Security::home_path();
    }

    public function test_round11_the_page_links_the_manifest_and_the_ios_tags_with_both_permalink_forms(): void {
        $this->login_as($this->customer());
        foreach (array('' => 'evc_club_member=manifest', '/%postname%/' => '/club-member/manifest.webmanifest') as $structure => $manifest) {
            update_option('permalink_structure', $structure);
            $body = EVC_Customer_Shell::respond(wp_get_current_user(), $this->assets())['body'];
            $this->assertSame(1, preg_match('#<link rel="manifest" href="([^"]+)">#', $body, $m), $structure);
            $this->assertStringContainsString($manifest, html_entity_decode($m[1]), $structure);
            $this->assertStringContainsString('<meta name="theme-color" content="#1e1713">', $body);
            $this->assertStringContainsString('<link rel="apple-touch-icon" href="' . EVC_Customer_Pwa::icon_base() . '/apple-touch-icon.png">', $body);
            $this->assertStringContainsString('<meta name="apple-mobile-web-app-capable" content="yes">', $body);
            $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Ευαγγέλου Club">', $body);
            $this->assertLessThan(strpos($body, '<script type="module"'), strpos($body, 'rel="manifest"'), 'in the head, before the app');
        }
    }

    public function test_round11_the_service_worker_is_offered_only_with_pretty_permalinks_and_only_for_the_member_path(): void {
        $this->login_as($this->customer());
        update_option('permalink_structure', '');
        $plain = $this->config_of(EVC_Customer_Shell::respond(wp_get_current_user(), $this->assets())['body']);
        $this->assertArrayNotHasKey('serviceWorkerUrl', $plain, 'plain permalinks: the scope would be the whole site');
        $this->assertArrayNotHasKey('serviceWorkerScope', $plain);
        update_option('permalink_structure', '/%postname%/');
        $pretty = $this->config_of(EVC_Customer_Shell::respond(wp_get_current_user(), $this->assets())['body']);
        $this->assertSame(home_url('/club-member/sw.js'), $pretty['serviceWorkerUrl']);
        $this->assertSame($this->home_path() . 'club-member/', $pretty['serviceWorkerScope']);
        $this->assertSame(array('restBase', 'homePath', 'nonce', 'loginUrl', 'logoutUrl', 'serviceWorkerUrl', 'serviceWorkerScope', 'appVersion'), array_keys($pretty));
    }

    public function test_round11_the_manifest_is_public_and_points_at_the_member_page(): void {
        wp_set_current_user(0);
        foreach (array('/%postname%/', '') as $structure) {
            update_option('permalink_structure', $structure);
            $r = EVC_Customer_Shell::respond_pwa(EVC_Customer_Pwa::MANIFEST, '/', $this->assets(), self::ASSET_URL, $this->worker_file());
            $this->assertSame(200, $r['status'], 'a browser fetches the manifest without cookies');
            $this->assertSame('application/manifest+json; charset=utf-8', $r['headers']['Content-Type']);
            $this->assertSame('nosniff', $r['headers']['X-Content-Type-Options']);
            $m = json_decode($r['body'], true);
            $this->assertSame('Ευαγγέλου Club', $m['name']);
            $this->assertSame('Club', $m['short_name']);
            $this->assertSame('standalone', $m['display']);
            $this->assertSame('el', $m['lang']);
            $this->assertSame('#1e1713', $m['theme_color']);
            $this->assertSame('#1e1713', $m['background_color']);
            $this->assertSame(EVC_Customer_Shell::shell_url(), $m['start_url']);
            $this->assertSame($m['start_url'], $m['id']);
            if ($structure === '') {
                $this->assertStringContainsString('evc_club_member=1', $m['start_url']);
                $this->assertSame($this->home_path(), $m['scope'], 'plain: the member page path is the home path');
            } else {
                $this->assertSame(home_url('/club-member/'), $m['start_url']);
                $this->assertSame($this->home_path() . 'club-member/', $m['scope'], 'pretty: only the member page');
            }
            $start_path = wp_parse_url($m['start_url'], PHP_URL_PATH);
            $this->assertStringStartsWith($m['scope'], $start_path, 'the start page is inside the scope');
            $icons = array_map(function ($i) {
                return $i['sizes'] . ' ' . $i['purpose'] . ' ' . $i['type'] . ' ' . basename($i['src']);
            }, $m['icons']);
            $this->assertSame(array('192x192 any image/png icon-192.png', '512x512 any image/png icon-512.png', '512x512 maskable image/png icon-maskable-512.png'), $icons);
            foreach ($m['icons'] as $icon) {
                $this->assertSame(wp_parse_url(home_url('/'), PHP_URL_HOST), wp_parse_url($icon['src'], PHP_URL_HOST), 'same origin');
            }
            $this->assertStringNotContainsString('nonce', $r['body']);
            $this->assertStringNotContainsString('club-admin', $r['body'], 'nothing of the staff app');
        }
    }

    public function test_round11_the_worker_is_served_only_at_its_pretty_path_with_its_configuration(): void {
        wp_set_current_user(0);
        update_option('permalink_structure', '/%postname%/');
        $path = $this->home_path() . 'club-member/sw.js';
        $r = EVC_Customer_Shell::respond_pwa(EVC_Customer_Pwa::WORKER, $path, $this->assets(), self::ASSET_URL, $this->worker_file());
        $this->assertSame(200, $r['status']);
        $this->assertSame('text/javascript; charset=utf-8', $r['headers']['Content-Type']);
        $this->assertStringContainsString('no-store', $r['headers']['Cache-Control']);
        $this->assertArrayNotHasKey('Service-Worker-Allowed', $r['headers'], 'the script lives under its scope: no wider scope is allowed');
        $this->assertSame(1, preg_match('#^self\.__EVC_SW__ = (\{.*\});\n/\* worker \*/\n$#s', $r['body'], $m));
        $cfg = json_decode($m[1], true);
        $this->assertSame($this->home_path() . 'club-member/', $cfg['scope']);
        $this->assertSame(self::ASSET_URL . '/assets/', $cfg['assetBase']);
        $this->assertSame(array(self::ASSET_URL . '/assets/customer-Ab12Cd34.js', self::ASSET_URL . '/assets/customer-Ef56Gh78.css'), $cfg['precache']);
        $this->assertSame(array(self::ASSET_URL . '/assets/customer-Ab12Cd34.js'), $cfg['offline']['scripts']);
        $this->assertContains($this->home_path() . 'wp-json/', $cfg['restPrefixes']);
        $this->assertMatchesRegularExpression('/^' . preg_quote(EVC_CLUB_VERSION, '/') . '-[0-9a-f]{16}$/', $cfg['version']);
        $this->assertStringNotContainsString('nonce', $r['body']);
        // A new build = a new version (a new cache; the old one is deleted when the new worker activates).
        $other = EVC_Customer_Shell::respond_pwa(EVC_Customer_Pwa::WORKER, $path, $this->assets(), self::ASSET_URL, $this->worker_file("/* worker 2 */\n"));
        preg_match('#^self\.__EVC_SW__ = (\{.*\});#', $other['body'], $m2);
        $this->assertNotSame($cfg['version'], json_decode($m2[1], true)['version']);

        foreach (array('/', '/sw.js', '/club-member/', $this->home_path() . 'club-member/sw.js/x', $this->home_path() . 'club-admin/sw.js') as $wrong) {
            if ($wrong === $path) {
                continue;
            }
            $this->assertSame(404, EVC_Customer_Shell::respond_pwa(EVC_Customer_Pwa::WORKER, $wrong, $this->assets(), self::ASSET_URL, $this->worker_file())['status'], $wrong);
        }
        unlink($this->asset_dir . '/sw.js');
        $this->assertSame(404, EVC_Customer_Shell::respond_pwa(EVC_Customer_Pwa::WORKER, $path, $this->assets(), self::ASSET_URL, $this->asset_dir . '/sw.js')['status'], 'no build');
        update_option('permalink_structure', '');
        $this->assertSame(404, EVC_Customer_Shell::respond_pwa(EVC_Customer_Pwa::WORKER, '/', $this->assets(), self::ASSET_URL, $this->worker_file())['status'], 'plain permalinks: no worker at all');
        $this->assertSame(404, EVC_Customer_Shell::respond_pwa(EVC_Customer_Pwa::WORKER, $path, $this->assets(), self::ASSET_URL, $this->worker_file())['status'], 'plain permalinks: not even at the pretty path');
        $this->assertSame(404, EVC_Customer_Shell::respond_pwa('x', '/', $this->assets(), self::ASSET_URL, $this->worker_file())['status']);
    }

    public function test_round11_the_rewrite_rules_cover_the_manifest_and_the_worker(): void {
        update_option('permalink_structure', '/%postname%/');
        delete_option(EVC_Customer_Shell::REWRITE_STATE_OPTION);
        EVC_Customer_Shell::sync_rewrite();
        global $wp_rewrite;
        $rules = $wp_rewrite->extra_rules_top;
        $this->assertSame('index.php?evc_club_member=manifest', $rules['^club-member/manifest\.webmanifest$'] ?? null);
        $this->assertSame('index.php?evc_club_member=sw', $rules['^club-member/sw\.js$'] ?? null);
        $this->assertSame('on-v2', get_option(EVC_Customer_Shell::REWRITE_STATE_OPTION));
    }
}
