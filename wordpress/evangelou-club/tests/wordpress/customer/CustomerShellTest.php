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
        $this->assertSame(array('restBase', 'nonce', 'loginUrl', 'logoutUrl', 'appVersion'), array_keys($config));
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
}
