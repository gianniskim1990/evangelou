<?php
/**
 * STAGE-08 (D18): the administrator's one screen for the shared till account, with EVC_CLUB_ADMIN_TOOLS_ENABLED on.
 * Every action: nonce, capability, the action itself, and an audit entry without secrets.
 */
final class StaffAccountAdminTest extends EVC_WP_Test_Case {
    /** @var int */
    private $clock = 1_900_000_000;
    /** @var int */
    private $admin;

    public function set_up() {
        parent::set_up();
        EVC_Staff_Role::register();
        EVC_Admin_Tools_Plugin::boot(array('now' => function () {
            return $this->clock;
        }));
        $this->admin = (int) self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($this->admin);
    }

    private function parts(): array {
        $parts = EVC_Admin_Tools_Plugin::parts();
        $this->assertIsArray($parts);
        return $parts;
    }

    private function service(): EVC_Staff_Account_Service {
        return $this->parts()['service'];
    }

    private function staff_ids(): array {
        return array_map('intval', get_users(array('role' => EVC_Staff_Role::ROLE, 'fields' => 'ID')));
    }

    private function session(int $user_id): void {
        WP_Session_Tokens::get_instance($user_id)->create(time() + 3600);
    }

    /** A POST with the nonce of ITS OWN action and account (as the page's form carries), unless one is given. */
    private function post(array $fields, ?string $nonce = null): array {
        $own = EVC_Staff_Account_Page::nonce_action((string) ($fields['evc_staff_action'] ?? ''), (int) ($fields['evc_staff_user'] ?? 0));
        return $this->parts()['page']->process($fields + array('_wpnonce' => $nonce ?? wp_create_nonce($own)));
    }

    public function test_the_flag_wires_the_menu_and_the_login_hook(): void {
        $this->assertTrue(EVC_Admin_Tools_Feature::enabled());
        $this->assertNotFalse(has_action('admin_menu', array($this->parts()['page'], 'add_menu')));
        $this->assertNotFalse(has_action('admin_menu', array($this->parts()['readiness_page'], 'add_menu')));
        $this->assertNotFalse(has_action('wp_login', array($this->service(), 'record_login')));
    }

    public function test_the_shared_account_is_created_once_with_the_staff_role_only(): void {
        $r = $this->post(array('evc_staff_action' => 'create', 'evc_staff_login' => ''));
        $this->assertSame('ok', $r['code']);
        $this->assertTrue(EVC_Staff_Account_Service::strong($r['password']));
        $this->assertSame(EVC_Staff_Account_Service::PASSWORD_LENGTH, strlen($r['password']));
        foreach (array('Ab1', 'abcdefghijklmnopqrstuvwx', 'ABCDEFGHIJKLMNOPQRSTUVW1', 'abcdefghijklmnopqrstuvW', '1234567890123456789012aB') as $i => $candidate) {
            $this->assertSame($i === 4, EVC_Staff_Account_Service::strong($candidate), $candidate);
        }
        $user = get_userdata($r['user_id']);
        $this->assertSame(EVC_Staff_Account_Service::DEFAULT_LOGIN, $user->user_login);
        $this->assertSame(array(EVC_Staff_Role::ROLE), array_values($user->roles), 'nothing else is granted');
        $this->assertTrue(EVC_Staff_Role::is_restricted_staff_account($user));
        $this->assertSame('', $user->user_email, 'no personal data');
        $this->assertTrue(wp_check_password($r['password'], $user->user_pass, $user->ID));
        $this->assertFalse(EVC_Staff_Auth::is_disabled($user->ID));
        // Never a second one.
        $again = $this->post(array('evc_staff_action' => 'create', 'evc_staff_login' => 'another-till'));
        $this->assertSame(array('code' => 'exists', 'action' => 'create'), $again);
        $this->assertSame(array((int) $user->ID), $this->staff_ids());
        // Audited without secrets.
        $audit = EVC_Staff_Account_Service::audit_entries(10);
        $this->assertSame(array('create', 'create'), array_column($audit, 'action'));
        $this->assertSame(array('exists', 'ok'), array_column($audit, 'outcome'));
        $this->assertSame(array($this->admin, $this->admin), array_column($audit, 'actor'));
        $this->assertSame(array(0, (int) $user->ID), array_column($audit, 'target'));
        $this->assertSame(array($this->clock, $this->clock), array_column($audit, 'at'));
        $stored = (string) wp_json_encode(get_option(EVC_Staff_Account_Service::AUDIT_OPTION));
        $this->assertStringNotContainsString($r['password'], $stored);
        $this->assertStringNotContainsString(EVC_Staff_Account_Service::DEFAULT_LOGIN, $stored);
        // The password is shown in the answer to the POST, once; never on a later page.
        $page = $this->parts()['page'];
        $this->assertStringContainsString($r['password'], $page->html($r));
        $this->assertStringNotContainsString($r['password'], $page->html(null));
        $this->assertStringNotContainsString($r['password'], (string) wp_json_encode(wp_load_alloptions()));
    }

    public function test_a_login_name_must_be_valid_and_free(): void {
        foreach (array('ab', 'with space', 'someone@example.org', 'λάθος', 'Upper', '-dash') as $bad) {
            $this->assertSame('invalid_login', $this->service()->create($bad)['code'], $bad);
        }
        self::factory()->user->create(array('user_login' => 'taken-name', 'role' => 'subscriber'));
        $this->assertSame('login_taken', $this->service()->create('taken-name')['code']);
        $this->assertSame(array(), $this->staff_ids());
    }

    public function test_a_new_password_is_strong_shown_once_and_signs_the_tablet_out(): void {
        $created = $this->service()->create('till');
        $id = $created['user_id'];
        $this->session($id);
        $this->session($id);
        $r = $this->post(array('evc_staff_action' => 'password', 'evc_staff_user' => (string) $id));
        $this->assertSame('ok', $r['code']);
        $this->assertTrue(EVC_Staff_Account_Service::strong($r['password']));
        $this->assertNotSame($created['password'], $r['password']);
        $user = get_userdata($id);
        $this->assertTrue(wp_check_password($r['password'], $user->user_pass, $id));
        $this->assertFalse(wp_check_password($created['password'], $user->user_pass, $id), 'the old password no longer works');
        $this->assertSame(array(), WP_Session_Tokens::get_instance($id)->get_all(), 'signed out everywhere');
        $this->assertSame(array('password', 'ok', $id), array(EVC_Staff_Account_Service::audit_entries(1)[0]['action'], EVC_Staff_Account_Service::audit_entries(1)[0]['outcome'], EVC_Staff_Account_Service::audit_entries(1)[0]['target']));
    }

    public function test_disable_enable_and_sign_out_everywhere(): void {
        $id = $this->service()->create('till')['user_id'];
        $this->session($id);
        $this->assertSame('ok', $this->post(array('evc_staff_action' => 'disable', 'evc_staff_user' => (string) $id))['code']);
        $this->assertTrue(EVC_Staff_Auth::is_disabled($id));
        $this->assertSame(array(), WP_Session_Tokens::get_instance($id)->get_all());
        $this->assertTrue($this->service()->accounts()[0]['disabled']);
        $this->assertSame('ok', $this->post(array('evc_staff_action' => 'enable', 'evc_staff_user' => (string) $id))['code']);
        $this->assertFalse(EVC_Staff_Auth::is_disabled($id));
        $this->session($id);
        $this->assertSame(1, $this->service()->accounts()[0]['sessions']);
        $r = $this->post(array('evc_staff_action' => 'revoke'));
        $this->assertSame(array('code' => 'ok', 'count' => 1, 'action' => 'revoke'), $r);
        $this->assertSame(0, $this->service()->accounts()[0]['sessions']);
        $this->assertSame(array('revoke', 'enable', 'disable', 'create'), array_column(EVC_Staff_Account_Service::audit_entries(4), 'action'));
    }

    public function test_only_staff_accounts_can_be_targeted(): void {
        $editor = (int) self::factory()->user->create(array('role' => 'editor'));
        foreach (array('password', 'disable', 'enable') as $action) {
            $this->assertSame('not_staff', $this->post(array('evc_staff_action' => $action, 'evc_staff_user' => (string) $editor))['code'], $action);
            $this->assertSame('not_staff', $this->post(array('evc_staff_action' => $action, 'evc_staff_user' => (string) $this->admin))['code'], $action);
        }
        $this->assertFalse(EVC_Staff_Auth::is_disabled($editor));
        $this->assertSame('', get_user_meta($this->admin, EVC_Staff_Auth::DISABLED_META, true));
        $this->assertSame('failed', $this->post(array('evc_staff_action' => 'delete', 'evc_staff_user' => '1'))['code'], 'no other action exists');
    }

    public function test_nobody_but_a_club_administrator_may_act(): void {
        $id = $this->service()->create('till')['user_id'];
        $no_club_cap = (int) self::factory()->user->create(array('role' => 'administrator'));
        get_userdata($no_club_cap)->add_cap(EVC_Staff_Role::CAP_MANAGE, false);
        foreach (array(0, (int) self::factory()->user->create(array('role' => 'editor')), (int) self::factory()->user->create(array('role' => 'shop_manager')), $id, $no_club_cap) as $who) {
            wp_set_current_user($who);
            $this->assertFalse(EVC_Staff_Account_Service::may_manage(wp_get_current_user()), (string) $who);
            foreach (array(array('evc_staff_action' => 'create'), array('evc_staff_action' => 'password', 'evc_staff_user' => (string) $id), array('evc_staff_action' => 'disable', 'evc_staff_user' => (string) $id), array('evc_staff_action' => 'revoke')) as $post) {
                $this->assertSame('forbidden', $this->post($post)['code'], $post['evc_staff_action'] . ' as ' . $who);
            }
        }
        $this->assertFalse(EVC_Staff_Auth::is_disabled($id));
        $this->assertSame(array($id), $this->staff_ids());
        $this->assertSame(array('forbidden'), array_values(array_unique(array_column(EVC_Staff_Account_Service::audit_entries(20), 'outcome'))), 'every refusal by the service is audited');
        wp_set_current_user(0);
        $this->expectException(WPDieException::class);
        $this->parts()['page']->render();
    }

    public function test_a_missing_or_wrong_nonce_changes_nothing(): void {
        $id = $this->service()->create('till')['user_id'];
        $before = count(EVC_Staff_Account_Service::audit_entries(100));
        foreach (array('', 'deadbeef00', wp_create_nonce('another_action'), wp_create_nonce(EVC_Staff_Account_Page::NONCE_ACTION)) as $nonce) {
            $this->assertSame('bad_nonce', $this->post(array('evc_staff_action' => 'disable', 'evc_staff_user' => (string) $id), $nonce)['code']);
        }
        $this->assertSame('bad_nonce', $this->parts()['page']->process(array('evc_staff_action' => 'revoke'))['code'], 'no nonce field at all');
        $this->assertFalse(EVC_Staff_Auth::is_disabled($id));
        $this->assertSame($before, count(EVC_Staff_Account_Service::audit_entries(100)), 'the action never ran (and a bad nonce is not audited)');
    }

    public function test_a_nonce_is_bound_to_its_action_and_its_account(): void {
        $id = $this->service()->create('till')['user_id'];
        $other = (int) self::factory()->user->create(array('role' => EVC_Staff_Role::ROLE));
        $before = count(EVC_Staff_Account_Service::audit_entries(100));
        // The nonce of the «disable» form cannot be replayed as another action ...
        $disable = wp_create_nonce(EVC_Staff_Account_Page::nonce_action('disable', $id));
        foreach (array('password', 'enable', 'revoke', 'create') as $action) {
            $this->assertSame('bad_nonce', $this->post(array('evc_staff_action' => $action, 'evc_staff_user' => (string) $id), $disable)['code'], $action);
        }
        // ... nor against another account, nor by the old unbound nonce.
        $this->assertSame('bad_nonce', $this->post(array('evc_staff_action' => 'disable', 'evc_staff_user' => (string) $other), $disable)['code']);
        $this->assertSame('bad_nonce', $this->post(array('evc_staff_action' => 'disable', 'evc_staff_user' => (string) $id), wp_create_nonce(EVC_Staff_Account_Page::NONCE_ACTION))['code']);
        $this->assertSame($before, count(EVC_Staff_Account_Service::audit_entries(100)));
        $this->assertFalse(EVC_Staff_Auth::is_disabled($id));
        $this->assertFalse(EVC_Staff_Auth::is_disabled($other));
        // The nonce printed in the page's own «Νέος κωδικός» form works for that action and that account only.
        $html = $this->parts()['page']->html(null);
        $this->assertSame(1, preg_match('/name="_wpnonce" value="([^"]+)"[^<]*<input[^>]*><input type="hidden" name="evc_staff_action" value="password"><input type="hidden" name="evc_staff_user" value="' . $id . '">/', $html, $m), 'the password form of the first account');
        $form_nonce = $m[1];
        $this->assertSame('bad_nonce', $this->post(array('evc_staff_action' => 'disable', 'evc_staff_user' => (string) $id), $form_nonce)['code']);
        $this->assertSame('bad_nonce', $this->post(array('evc_staff_action' => 'password', 'evc_staff_user' => (string) $other), $form_nonce)['code']);
        $this->assertSame('ok', $this->post(array('evc_staff_action' => 'password', 'evc_staff_user' => (string) $id), $form_nonce)['code']);
    }

    public function test_the_answer_with_a_password_is_sent_no_store(): void {
        $sent = array();
        EVC_Admin_Tools_Plugin::boot(array('now' => function () {
            return $this->clock;
        }, 'header' => function (string $line) use (&$sent): void {
            $sent[] = $line;
        }));
        $id = $this->service()->create('till')['user_id'];
        $server = $_SERVER;
        $post = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = array('evc_staff_action' => 'disable', 'evc_staff_user' => (string) $id, '_wpnonce' => wp_create_nonce(EVC_Staff_Account_Page::nonce_action('disable', $id)));
            $this->parts()['page']->handle_load();
            $this->assertSame(array(), $sent, 'no password in this answer');
            $_POST = array('evc_staff_action' => 'password', 'evc_staff_user' => (string) $id, '_wpnonce' => wp_create_nonce(EVC_Staff_Account_Page::nonce_action('password', $id)));
            $this->parts()['page']->handle_load();
        } finally {
            $_SERVER = $server;
            $_POST = $post;
        }
        $this->assertSame(array(EVC_Staff_Account_Page::NO_STORE_HEADER), $sent);
        $this->assertStringContainsString('no-store', EVC_Staff_Account_Page::NO_STORE_HEADER);
        ob_start();
        $this->parts()['page']->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Νέος κωδικός (εμφανίζεται ΜΟΝΟ τώρα', $html, 'the password answer is rendered once');
    }

    public function test_an_account_with_more_than_the_staff_role_gets_no_password_and_is_not_enabled(): void {
        $created = $this->service()->create('till');
        $id = $created['user_id'];
        get_userdata($id)->add_cap('edit_posts');
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account(get_userdata($id)));
        $this->session($id);
        $r = $this->post(array('evc_staff_action' => 'password', 'evc_staff_user' => (string) $id));
        $this->assertSame(array('code' => 'not_restricted', 'action' => 'password'), $r, 'no password handed out');
        $this->assertTrue(wp_check_password($created['password'], get_userdata($id)->user_pass, $id), 'the password is unchanged');
        $this->assertCount(1, WP_Session_Tokens::get_instance($id)->get_all(), 'nothing else ran');
        // Disabling it is allowed; enabling it again is not.
        $this->assertSame('ok', $this->post(array('evc_staff_action' => 'disable', 'evc_staff_user' => (string) $id))['code']);
        $this->assertSame('not_restricted', $this->post(array('evc_staff_action' => 'enable', 'evc_staff_user' => (string) $id))['code']);
        $this->assertTrue(EVC_Staff_Auth::is_disabled($id));
        $this->assertSame(array('not_restricted', 'ok', 'not_restricted'), array_column(EVC_Staff_Account_Service::audit_entries(3), 'outcome'));
        // The page offers neither action for it.
        $html = $this->parts()['page']->html(null);
        $this->assertStringNotContainsString('value="password"', $html);
        $this->assertStringNotContainsString('value="enable"', $html);
    }

    public function test_a_weakened_password_generator_fails_closed_after_ten_attempts(): void {
        $calls = 0;
        $weak = function () use (&$calls) {
            $calls++;
            return 'aaaaaaaaaaaaaaaaaaaaaaaa';
        };
        add_filter('random_password', $weak);
        try {
            $r = $this->service()->create('till');
        } catch (Exception $e) {
            // A create that went on without a password ends in a WordPress notice: report it as an assertion below.
            $r = array('code' => 'went on without a password: ' . get_class($e));
        } finally {
            remove_filter('random_password', $weak);
        }
        $this->assertSame(array('code' => 'failed'), $r);
        $this->assertSame(EVC_Staff_Account_Service::PASSWORD_ATTEMPTS, $calls, 'bounded: no endless loop');
        $this->assertSame(10, EVC_Staff_Account_Service::PASSWORD_ATTEMPTS);
        $this->assertSame(array(), $this->staff_ids(), 'no account without a strong password');
        $created = $this->service()->create('till');
        $id = $created['user_id'];
        $this->session($id);
        $calls = 0;
        add_filter('random_password', $weak);
        try {
            $r = $this->post(array('evc_staff_action' => 'password', 'evc_staff_user' => (string) $id));
        } finally {
            remove_filter('random_password', $weak);
        }
        $this->assertSame(array('code' => 'failed', 'action' => 'password'), $r);
        $this->assertSame(10, $calls);
        $this->assertTrue(wp_check_password($created['password'], get_userdata($id)->user_pass, $id), 'the old password still works');
        $this->assertCount(1, WP_Session_Tokens::get_instance($id)->get_all(), 'the tablet stays signed in');
        // A filter that weakens only the first tries: the retry still yields a strong password.
        $calls = 0;
        $flaky = function ($password) use (&$calls) {
            $calls++;
            return $calls <= 3 ? 'short' : $password;
        };
        add_filter('random_password', $flaky);
        try {
            $r = $this->service()->set_password($id);
        } finally {
            remove_filter('random_password', $flaky);
        }
        $this->assertSame('ok', $r['code']);
        $this->assertSame(4, $calls);
        $this->assertTrue(EVC_Staff_Account_Service::strong($r['password']));
    }

    public function test_more_than_one_shared_account_is_a_warning_and_nothing_is_deleted(): void {
        $a = (int) self::factory()->user->create(array('role' => EVC_Staff_Role::ROLE));
        $b = (int) self::factory()->user->create(array('role' => EVC_Staff_Role::ROLE));
        $html = $this->parts()['page']->html(null);
        $this->assertStringContainsString('υπάρχουν 2 λογαριασμοί', $html);
        $this->assertSame('exists', $this->service()->create('till')['code']);
        $this->assertSame(array($a, $b), $this->staff_ids(), 'never deleted automatically');
        $rows = $this->parts()['readiness']->checks();
        $this->assertSame('wrong', $rows[array_search('staff_account', array_column($rows, 'key'), true)]['status']);
    }

    public function test_an_account_given_more_than_the_staff_role_stays_disabled(): void {
        $grant = function ($allcaps, $caps, $args, $user) {
            if (in_array(EVC_Staff_Role::ROLE, (array) $user->roles, true)) {
                $allcaps['edit_posts'] = true;
            }
            return $allcaps;
        };
        add_filter('user_has_cap', $grant, 10, 4);
        try {
            $r = $this->service()->create('till');
        } finally {
            remove_filter('user_has_cap', $grant, 10);
        }
        $this->assertSame('not_restricted', $r['code']);
        $this->assertArrayNotHasKey('password', $r, 'no password for an account that is not the restricted one');
        $ids = $this->staff_ids();
        $this->assertCount(1, $ids);
        $this->assertTrue(EVC_Staff_Auth::is_disabled($ids[0]));
    }

    public function test_the_last_sign_in_time_is_recorded_without_any_address(): void {
        $id = $this->service()->create('till')['user_id'];
        $this->assertNull($this->service()->accounts()[0]['last_login']);
        do_action('wp_login', 'till', get_userdata($id));
        $this->assertSame($this->clock, $this->service()->accounts()[0]['last_login']);
        $this->assertSame(array(EVC_Staff_Account_Service::LAST_LOGIN_META => array((string) $this->clock)), array_intersect_key(get_user_meta($id), array(EVC_Staff_Account_Service::LAST_LOGIN_META => 1)));
        do_action('wp_login', 'admin', get_userdata($this->admin));
        $this->assertSame('', get_user_meta($this->admin, EVC_Staff_Account_Service::LAST_LOGIN_META, true), 'only staff accounts');
        // A newer open session counts as the last sign-in too.
        WP_Session_Tokens::get_instance($id)->create(time() + 3600);
        $this->assertGreaterThanOrEqual(time() - 5, $this->service()->accounts()[0]['last_login']);
        $html = $this->parts()['page']->html(null);
        $this->assertStringNotContainsString('127.0.0.1', $html);
        $this->assertStringNotContainsString((string) ($_SERVER['REMOTE_ADDR'] ?? 'no-address'), $html);
    }

    public function test_the_audit_keeps_the_newest_hundred(): void {
        for ($i = 0; $i < 105; $i++) {
            $this->clock++;
            $this->service()->revoke_all();
        }
        $all = get_option(EVC_Staff_Account_Service::AUDIT_OPTION);
        $this->assertCount(100, $all);
        $this->assertSame($this->clock, EVC_Staff_Account_Service::audit_entries(1)[0]['at']);
        $autoload = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", EVC_Staff_Account_Service::AUDIT_OPTION));
        $this->assertContains($autoload, array('no', 'off'), 'never autoloaded');
    }
}
