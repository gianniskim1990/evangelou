<?php

/**
 * STAGE-05C: the customer QR route on the SHIPPED wiring with the real configuration (customer flag
 * ON, EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1', the disposable Club DB with migrations 001-003,
 * the real WooCommerce / PMPro site). No test seam: the route registered by EVC_Customer_Plugin is
 * called as a browser would. CI only.
 */
final class CustomerQrCommerceTest extends EVC_WP_Test_Case {
    const QR = '/evangelou-club-customer/v1/me/qr';

    /** @var EVC_Club_Db */
    private $club;

    public function set_up() {
        parent::set_up();
        $this->assertTrue(defined('WC_VERSION') && defined('PMPRO_VERSION'), 'run with EVC_TEST_COMMERCE_PLUGINS');
        $this->club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
    }

    public function tear_down() {
        $this->club = null;
        parent::tear_down();
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'user_email' => 'cq.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    public function test_the_shipped_route_issues_keeps_and_replaces_the_members_own_qr(): void {
        $this->assertTrue(EVC_Customer_Feature::enabled());
        $this->assertTrue(EVC_Customer_Qr_Backend::provenance_recorded());
        $this->assertArrayHasKey(self::QR, rest_get_server()->get_routes());

        $user = $this->customer();
        $this->club->execute('INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)', array(EVC_Member_Id::mint(), $user, 'enabled', '2026-11-04 09:00:00.000000', '2026-11-04 09:00:00.000000'));
        $member = (int) $this->club->fetch_one('SELECT id FROM evc_members WHERE wp_user_id = ?', array($user))['id'];
        $this->login_as($user);

        $this->assertSame('none', $this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']['state']);
        $first = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertSame(200, $first->get_status(), wp_json_encode($first->get_data()));
        $token = $first->get_data()['qr']['token'];
        $this->assertTrue(EVC_Qr_Token::is_valid_format($token));
        $this->assertSame(array('state', 'issued_at'), array_keys($this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']));
        $this->assertEnvelope($this->rest('POST', self::QR, array(), $this->nonce()), 409, 'qr_exists');
        $new = $this->rest('POST', self::QR, array('replace' => true), $this->nonce())->get_data()['qr']['token'];
        $this->assertNotSame($token, $new);
        $rows = $this->club->fetch_all('SELECT token_hash, status FROM evc_qr_tokens WHERE member_id = ? ORDER BY id', array($member));
        $this->assertSame(array(array('token_hash' => EVC_Qr_Token::digest($token), 'status' => 'revoked'), array('token_hash' => EVC_Qr_Token::digest($new), 'status' => 'active')), $rows);

        $this->login_as($this->customer());
        $this->assertSame('not_member', $this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']['state']);
    }
}
