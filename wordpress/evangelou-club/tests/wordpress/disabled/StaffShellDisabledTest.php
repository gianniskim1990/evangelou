<?php

/** Feature NOT enabled (default): no /club-admin/ shell, rule, query var or session routes. */
final class StaffShellDisabledTest extends EVC_WP_Test_Case {
    public function test_shell_answers_404_without_bootstrap_even_for_valid_staff(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $dir = sys_get_temp_dir() . '/evc-off-' . wp_generate_password(6, false, false);
        mkdir($dir . '/assets', 0777, true);
        file_put_contents($dir . '/assets/staff-Ab12Cd34.js', '1');
        file_put_contents($dir . '/manifest.json', wp_json_encode(array('staff.html' => array('file' => 'assets/staff-Ab12Cd34.js', 'isEntry' => true))));

        $r = EVC_Staff_Shell::respond(wp_get_current_user(), new EVC_Staff_Assets($dir, 'https://example.org/x'));

        $this->assertSame(404, $r['status']);
        $this->assertStringNotContainsString('evc-staff-config', $r['body']);
        $this->assertStringNotContainsString(wp_create_nonce('wp_rest'), $r['body']);
        $this->assertStringNotContainsString('type="module"', $r['body']);
        $this->assertStringContainsString('no-store', $r['headers']['Cache-Control']);
        unlink($dir . '/assets/staff-Ab12Cd34.js');
        unlink($dir . '/manifest.json');
        rmdir($dir . '/assets');
        rmdir($dir);
    }

    public function test_no_rewrite_rule_or_query_var_while_disabled(): void {
        $this->set_permalink_structure('/%postname%/');
        update_option(EVC_Staff_Shell::REWRITE_STATE_OPTION, EVC_Staff_Shell::REWRITE_STATE_ON); // as if it had been on
        EVC_Staff_Shell::sync_rewrite();
        $this->assertSame(EVC_Staff_Shell::REWRITE_STATE_OFF, get_option(EVC_Staff_Shell::REWRITE_STATE_OPTION));
        $this->assertArrayNotHasKey('^club-admin/?$', (array) get_option('rewrite_rules'), 'rule removed when switched off');
        $this->assertNotContains(EVC_Staff_Shell::QUERY_VAR, apply_filters('query_vars', array()));
    }

    public function test_first_activation_with_the_flag_off_does_not_flush_rewrite_rules(): void {
        delete_option(EVC_Staff_Shell::REWRITE_STATE_OPTION);
        $flushes = 0;
        $count = function () use (&$flushes) {
            $flushes++;
        };
        add_action('generate_rewrite_rules', $count);
        EVC_Staff_Shell::sync_rewrite();
        remove_action('generate_rewrite_rules', $count);
        $this->assertSame(0, $flushes);
        $this->assertSame(EVC_Staff_Shell::REWRITE_STATE_OFF, get_option(EVC_Staff_Shell::REWRITE_STATE_OPTION));
    }

    public function test_session_routes_do_not_exist(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayNotHasKey('/evangelou-club/v1/session', $routes);
        $this->assertArrayNotHasKey('/evangelou-club/v1/session/end', $routes);
        $this->login_as($this->create_staff_user());
        $this->assertWPError(EVC_Staff_Auth::authorize_session_request(new WP_REST_Request('GET', '/evangelou-club/v1/session')));
    }
}
