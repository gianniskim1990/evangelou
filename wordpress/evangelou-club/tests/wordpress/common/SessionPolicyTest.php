<?php

final class SessionPolicyTest extends EVC_WP_Test_Case {
    const LOGIN = 1800000000;

    private static function session(array $overrides = array()): array {
        return array_merge(array(
            'login' => self::LOGIN,
            'expiration' => self::LOGIN + EVC_Staff_Session::ABSOLUTE_SECONDS,
            EVC_Staff_Session::POLICY_FLAG => EVC_Staff_Session::POLICY_VERSION,
        ), $overrides);
    }

    // ---- pure policy, exact boundaries ---------------------------------

    public function test_absolute_lifetime_boundary_is_exactly_12_hours(): void {
        $end = self::LOGIN + 12 * HOUR_IN_SECONDS;
        $this->assertSame('ok', EVC_Staff_Session::evaluate(self::session(), $end - 10, $end - 1));
        $this->assertSame('session_expired', EVC_Staff_Session::evaluate(self::session(), $end - 10, $end));
        $this->assertSame('session_expired', EVC_Staff_Session::evaluate(self::session(array('expiration' => self::LOGIN + 600)), self::LOGIN + 590, self::LOGIN + 600));
    }

    public function test_inactivity_boundary_is_exactly_30_minutes(): void {
        $last = self::LOGIN + 3600;
        $this->assertSame('ok', EVC_Staff_Session::evaluate(self::session(), $last, $last + 30 * MINUTE_IN_SECONDS - 1));
        $this->assertSame('session_idle', EVC_Staff_Session::evaluate(self::session(), $last, $last + 30 * MINUTE_IN_SECONDS));
        $this->assertSame('session_idle', EVC_Staff_Session::evaluate(self::session(), null, self::LOGIN + 1800), 'no activity yet: idle counts from login');
    }

    public function test_missing_or_malformed_session_metadata_fails_closed(): void {
        $now = self::LOGIN + 60;
        $this->assertSame('session_invalid', EVC_Staff_Session::evaluate(null, null, $now));
        $this->assertSame('session_invalid', EVC_Staff_Session::evaluate(array('login' => self::LOGIN, 'expiration' => self::LOGIN + 100), null, $now), 'no policy marker');
        $this->assertSame('session_invalid', EVC_Staff_Session::evaluate(self::session(array(EVC_Staff_Session::POLICY_FLAG => 2)), null, $now));
        $this->assertSame('session_invalid', EVC_Staff_Session::evaluate(self::session(array('login' => (string) self::LOGIN)), null, $now));
        $this->assertSame('session_invalid', EVC_Staff_Session::evaluate(self::session(), false, $now), 'malformed activity');
        $this->assertSame('session_invalid', EVC_Staff_Session::evaluate(self::session(), self::LOGIN - 1, $now), 'activity before login');
        $this->assertSame('session_invalid', EVC_Staff_Session::evaluate(self::session(), $now + 3600, $now), 'activity in the future');
    }

    // ---- WordPress integration -----------------------------------------

    /** Moves a real session's login time into the past (simulates elapsed time). */
    private function age_session(int $user_id, string $token, int $seconds): void {
        $manager = WP_Session_Tokens::get_instance($user_id);
        $session = $manager->get($token);
        $session['login'] = time() - $seconds;
        $session['expiration'] = $session['login'] + EVC_Staff_Session::ABSOLUTE_SECONDS;
        $manager->update($token, $session);
    }

    public function test_staff_cookie_lifetime_is_12_hours_even_with_remember_me(): void {
        $staff = $this->create_staff_user();
        $editor = $this->create_user_with_role('editor');
        $this->assertSame(43200, apply_filters('auth_cookie_expiration', 2 * DAY_IN_SECONDS, $staff, true));
        $this->assertSame(43200, apply_filters('auth_cookie_expiration', 2 * DAY_IN_SECONDS, $staff, false));
        $this->assertSame(14 * DAY_IN_SECONDS, apply_filters('auth_cookie_expiration', 14 * DAY_IN_SECONDS, $editor, true), 'other users unchanged');
    }

    public function test_new_staff_session_carries_policy_marker_and_12h_expiry(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $session = WP_Session_Tokens::get_instance($staff)->get($token);
        $this->assertSame(EVC_Staff_Session::POLICY_VERSION, $session[EVC_Staff_Session::POLICY_FLAG]);
        $this->assertEqualsWithDelta(43200, $session['expiration'] - $session['login'], 2);
        $editor = $this->create_user_with_role('editor');
        $other = WP_Session_Tokens::get_instance($editor)->get($this->login_as($editor));
        $this->assertArrayNotHasKey(EVC_Staff_Session::POLICY_FLAG, $other, 'non-staff sessions untouched');
    }

    public function test_valid_session_is_accepted_and_activity_is_throttled(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $this->age_session($staff, $token, 600); // activity values below must not predate login
        $key = EVC_Staff_Session::activity_key($token);
        $this->assertSame('ok', EVC_Staff_Session::verify_current($staff));
        $first = get_user_meta($staff, $key, true);
        $this->assertNotSame('', $first);
        update_user_meta($staff, $key, (string) (time() - 30));
        $this->assertSame('ok', EVC_Staff_Session::verify_current($staff));
        $this->assertSame((string) (time() - 30), get_user_meta($staff, $key, true), 'no rewrite within 60 s');
        update_user_meta($staff, $key, (string) (time() - 120));
        $this->assertSame('ok', EVC_Staff_Session::verify_current($staff));
        $this->assertGreaterThanOrEqual(time() - 2, (int) get_user_meta($staff, $key, true), 'refreshed after 60 s');
        $this->assertStringNotContainsString($token, $key, 'raw token never used as a key');
    }

    public function test_idle_session_is_destroyed_server_side(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $this->age_session($staff, $token, 3600);
        update_user_meta($staff, EVC_Staff_Session::activity_key($token), (string) (time() - 1801));
        $this->assertSame('session_idle', EVC_Staff_Session::verify_current($staff));
        $this->assertNull(WP_Session_Tokens::get_instance($staff)->get($token), 'session destroyed');
        $this->assertSame('', get_user_meta($staff, EVC_Staff_Session::activity_key($token), true));
        $this->assertSame('session_invalid', EVC_Staff_Session::verify_current($staff), 'cannot be revived');
    }

    public function test_session_past_absolute_lifetime_is_destroyed(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $manager = WP_Session_Tokens::get_instance($staff);
        $session = $manager->get($token);
        $session['login'] = time() - 43200;
        $session['expiration'] = time() + 3600;
        $manager->update($token, $session);
        update_user_meta($staff, EVC_Staff_Session::activity_key($token), (string) (time() - 5));
        $this->assertSame('session_expired', EVC_Staff_Session::verify_current($staff));
        $this->assertNull($manager->get($token));
    }

    public function test_session_created_without_policy_marker_is_rejected(): void {
        $staff = $this->create_staff_user();
        remove_filter('attach_session_information', array('EVC_Staff_Session', 'filter_attach_session'), 10);
        $token = $this->login_as($staff);
        add_filter('attach_session_information', array('EVC_Staff_Session', 'filter_attach_session'), 10, 2);
        $this->assertSame('session_invalid', EVC_Staff_Session::verify_current($staff));
        $this->assertNull(WP_Session_Tokens::get_instance($staff)->get($token));
    }

    public function test_malformed_activity_metadata_fails_closed(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        update_user_meta($staff, EVC_Staff_Session::activity_key($token), 'not-a-timestamp');
        $this->assertSame('session_invalid', EVC_Staff_Session::verify_current($staff));
    }

    public function test_two_tablet_sessions_are_independent(): void {
        $staff = $this->create_staff_user();
        $a = $this->login_as($staff);
        $b = $this->login_as($staff);
        $this->assertNotSame($a, $b);
        $this->assertCount(2, WP_Session_Tokens::get_instance($staff)->get_all());

        $this->use_session($staff, $a);
        $this->assertSame('ok', EVC_Staff_Session::verify_current($staff));
        $this->assertSame('', get_user_meta($staff, EVC_Staff_Session::activity_key($b), true), 'A never refreshes B');

        $this->age_session($staff, $b, 3600);
        update_user_meta($staff, EVC_Staff_Session::activity_key($b), (string) (time() - 1801));
        $this->use_session($staff, $b);
        $this->assertSame('session_idle', EVC_Staff_Session::verify_current($staff));
        $this->use_session($staff, $a);
        $this->assertSame('ok', EVC_Staff_Session::verify_current($staff), 'B expiring does not affect A');
    }

    public function test_logout_destroys_the_current_session_only(): void {
        $staff = $this->create_staff_user();
        $a = $this->login_as($staff);
        $b = $this->login_as($staff);
        $this->assertSame('ok', EVC_Staff_Session::verify_current($staff));
        wp_logout();
        $manager = WP_Session_Tokens::get_instance($staff);
        $this->assertNull($manager->get($b), 'logged-out session gone');
        $this->assertNotNull($manager->get($a), 'other tablet still signed in');
        $this->assertSame('', get_user_meta($staff, EVC_Staff_Session::activity_key($b), true));
    }

    public function test_emergency_revocation_requires_admin_and_kills_every_staff_session(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $this->login_as($staff);
        $this->assertCount(2, WP_Session_Tokens::get_instance($staff)->get_all());

        $denied = EVC_Staff_Session::revoke_all_staff_sessions();
        $this->assertWPError($denied, 'the shared staff account itself cannot revoke sessions');
        $this->assertCount(2, WP_Session_Tokens::get_instance($staff)->get_all());

        wp_set_current_user($this->create_user_with_role('editor'));
        $this->assertWPError(EVC_Staff_Session::revoke_all_staff_sessions(), 'editors cannot either');

        wp_set_current_user($this->create_user_with_role('administrator'));
        $this->assertSame(1, EVC_Staff_Session::revoke_all_staff_sessions());
        $this->assertCount(0, WP_Session_Tokens::get_instance($staff)->get_all());
    }

    public function test_disabling_the_shared_account_requires_admin_and_ends_sessions(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $this->assertWPError(EVC_Staff_Auth::set_disabled($staff, true));
        $this->assertFalse(EVC_Staff_Auth::is_disabled($staff));
        wp_set_current_user($this->create_user_with_role('administrator'));
        $this->assertTrue(EVC_Staff_Auth::set_disabled($staff, true));
        $this->assertTrue(EVC_Staff_Auth::is_disabled($staff));
        $this->assertCount(0, WP_Session_Tokens::get_instance($staff)->get_all());
    }

    public function test_password_rotation_invalidates_existing_auth_cookies(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $cookie = $_COOKIE[LOGGED_IN_COOKIE];
        $this->assertSame($staff, (int) wp_validate_auth_cookie($cookie, 'logged_in'));
        wp_set_password('A-new-rotated-Passphrase-2', $staff);
        clean_user_cache($staff);
        $this->assertFalse(wp_validate_auth_cookie($cookie, 'logged_in'), 'old cookie rejected after password change');
        $this->assertIsString($token);
    }

    public function test_session_ref_is_a_one_way_keyed_reference(): void {
        $staff = $this->create_staff_user();
        $a = $this->login_as($staff);
        $b = $this->login_as($staff);
        $ref = EVC_Staff_Session::session_ref($a);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ref);
        $this->assertSame($ref, EVC_Staff_Session::session_ref($a));
        $this->assertNotSame($ref, EVC_Staff_Session::session_ref($b));
        $this->assertStringNotContainsString(substr($a, 0, 16), $ref);
        $this->assertNotSame(substr(hash('sha256', $a), 0, 32), $ref, 'not the plain WordPress verifier');
    }
}
