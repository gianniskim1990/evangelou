<?php
use PHPUnit\Framework\TestCase;

/** STAGE-07: the pure onboarding rules (the same as the approved CUSTOMER-01 form, plus the password policy). */
final class OnboardingRulesTest extends TestCase {
    private static function valid(array $override = array()): array {
        return array_merge(array('name' => 'Μαρία Παπαδοπούλου', 'phone' => '694 123 4567', 'email' => ' Maria.P@Example.gr ', 'password' => 'kafes-sto-limani-7'), $override);
    }

    public function test_a_valid_registration_has_no_errors(): void {
        $this->assertSame(array(), EVC_Onboarding_Rules::validate(self::valid()));
        $this->assertSame('maria.p@example.gr', EVC_Onboarding_Rules::normalise_email(' Maria.P@Example.gr '));
    }

    public function test_field_errors_are_codes(): void {
        $cases = array(
            'no name' => array(array('name' => '  '), 'name', EVC_Onboarding_Rules::NAME_MISSING),
            'one word' => array(array('name' => 'Μαρία'), 'name', EVC_Onboarding_Rules::NAME_ONE_WORD),
            'digits in the name' => array(array('name' => 'Μαρία 007'), 'name', EVC_Onboarding_Rules::NAME_CHARS),
            'markup in the name' => array(array('name' => 'Μαρία <b>Π</b>'), 'name', EVC_Onboarding_Rules::NAME_CHARS),
            'long name' => array(array('name' => 'Α ' . str_repeat('β', 79)), 'name', EVC_Onboarding_Rules::NAME_LONG),
            'name not a string' => array(array('name' => array('x')), 'name', EVC_Onboarding_Rules::NAME_MISSING),
            'no phone' => array(array('phone' => ''), 'phone', EVC_Onboarding_Rules::PHONE_MISSING),
            'landline' => array(array('phone' => '2510 123456'), 'phone', EVC_Onboarding_Rules::PHONE_INVALID),
            'foreign mobile' => array(array('phone' => '+44 7700 900123'), 'phone', EVC_Onboarding_Rules::PHONE_INVALID),
            'short mobile' => array(array('phone' => '69412345'), 'phone', EVC_Onboarding_Rules::PHONE_INVALID),
            'no e-mail (required)' => array(array('email' => ' '), 'email', EVC_Onboarding_Rules::EMAIL_MISSING),
            'bad e-mail' => array(array('email' => 'maria@example'), 'email', EVC_Onboarding_Rules::EMAIL_INVALID),
            'e-mail with a space' => array(array('email' => 'ma ria@example.gr'), 'email', EVC_Onboarding_Rules::EMAIL_INVALID),
            'short password' => array(array('password' => 'short1'), 'password', EVC_Onboarding_Rules::PASSWORD_SHORT),
            'nine characters' => array(array('password' => '123456789'), 'password', EVC_Onboarding_Rules::PASSWORD_SHORT),
            'long password' => array(array('password' => str_repeat('ab', 65)), 'password', EVC_Onboarding_Rules::PASSWORD_LONG),
            'common password' => array(array('password' => 'Password123'), 'password', EVC_Onboarding_Rules::PASSWORD_WEAK),
            'one repeated character' => array(array('password' => 'aaaaaaaaaaaa'), 'password', EVC_Onboarding_Rules::PASSWORD_WEAK),
            'the e-mail name' => array(array('password' => 'maria.p2026!'), 'password', EVC_Onboarding_Rules::PASSWORD_WEAK),
            'the mobile number' => array(array('password' => 'x-41234567-x'), 'password', EVC_Onboarding_Rules::PASSWORD_WEAK),
            'password not a string' => array(array('password' => 1234567890123), 'password', EVC_Onboarding_Rules::PASSWORD_SHORT),
        );
        foreach ($cases as $label => list($override, $field, $code)) {
            $errors = EVC_Onboarding_Rules::validate(self::valid($override));
            $this->assertSame(array($field => $code), $errors, $label);
        }
        $this->assertNull(EVC_Onboarding_Rules::password_problem('ten chars!', '', ''), 'exactly ten characters');
        $this->assertNull(EVC_Onboarding_Rules::password_problem(str_repeat('ab', 64), '', ''), 'exactly 128 characters');
        $this->assertNull(EVC_Onboarding_Rules::password_problem('μυστικό-123', 'mar@x.gr', ''), 'a three-letter e-mail name is not checked');
    }

    public function test_the_greek_mobile_has_one_canonical_form(): void {
        foreach (array('6941234567', '694 123 4567', '+30 694 123 4567', '0030 694-123-4567', '(694) 123.4567') as $raw) {
            $this->assertSame('+306941234567', EVC_Onboarding_Rules::canonical_greek_mobile($raw), $raw);
        }
        foreach (array('', '2510123456', '+3069412345', '+30694123456789', 'abc', '+306941234567x') as $raw) {
            $this->assertNull(EVC_Onboarding_Rules::canonical_greek_mobile($raw), $raw);
        }
    }

    public function test_the_name_split(): void {
        $this->assertSame(array('Μαρία', 'Παπαδοπούλου'), EVC_Onboarding_Rules::split_name('  Μαρία   Παπαδοπούλου '));
        $this->assertSame(array('Άννα', 'Μαρία Κ.'), EVC_Onboarding_Rules::split_name('Άννα Μαρία Κ.'));
        $this->assertSame(array('Άννα', ''), EVC_Onboarding_Rules::split_name('Άννα'));
    }

    public function test_the_client_key_is_an_hmac_never_the_address(): void {
        $a = EVC_Onboarding_Rules::client_key('203.0.113.10', 'secret-1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $a);
        $this->assertStringNotContainsString('203', $a);
        $this->assertNotSame($a, EVC_Onboarding_Rules::client_key('203.0.113.11', 'secret-1'));
        $this->assertNotSame($a, EVC_Onboarding_Rules::client_key('203.0.113.10', 'secret-2'));
        $this->assertSame('', EVC_Onboarding_Rules::client_key('', 'secret-1'));
        $this->assertSame('', EVC_Onboarding_Rules::client_key('203.0.113.10', ''));
    }

    public function test_ipv6_is_keyed_by_its_64_and_mapped_ipv4_by_its_address(): void {
        $k = EVC_Onboarding_Rules::client_key('2001:db8:1:2::1', 's');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $k);
        $this->assertSame($k, EVC_Onboarding_Rules::client_key('2001:db8:1:2:ffff:abcd:1234:9999', 's'), 'one /64 is one client');
        $this->assertSame($k, EVC_Onboarding_Rules::client_key(' 2001:0db8:0001:0002:0000:0000:0000:0007 ', 's'), 'any spelling');
        $this->assertNotSame($k, EVC_Onboarding_Rules::client_key('2001:db8:1:3::1', 's'), 'the next /64 is another client');
        $this->assertNotSame($k, EVC_Onboarding_Rules::client_key('2001:db8:1:2::1', 't'));
        $v4 = EVC_Onboarding_Rules::client_key('192.0.2.7', 's');
        $this->assertSame($v4, EVC_Onboarding_Rules::client_key('::ffff:192.0.2.7', 's'), 'IPv4-mapped IPv6 = the IPv4 address');
        $this->assertNotSame($v4, EVC_Onboarding_Rules::client_key('192.0.2.8', 's'), 'IPv4 is per address');
        $this->assertNotSame($v4, EVC_Onboarding_Rules::client_key('::c000:207', 's'), 'not mapped: an IPv6 /64');
        foreach (array('not-an-ip', '2001:db8::1/64', '999.1.1.1', '192.0.2.7 ') as $bad) {
            $this->assertSame($bad === '192.0.2.7 ' ? $v4 : '', EVC_Onboarding_Rules::client_key($bad, 's'), $bad);
        }
    }

    public function test_the_ipv6_48_key(): void {
        $n = EVC_Onboarding_Rules::network_key('2001:db8:aa:1::1', 's');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $n);
        $this->assertSame($n, EVC_Onboarding_Rules::network_key('2001:db8:aa:ffff:1:2:3:4', 's'), 'one /48');
        $this->assertNotSame($n, EVC_Onboarding_Rules::network_key('2001:db8:ab::1', 's'), 'another /48');
        $this->assertNotSame($n, EVC_Onboarding_Rules::client_key('2001:db8:aa:1::1', 's'), 'never the /64 key');
        $this->assertNotSame($n, EVC_Onboarding_Rules::network_key('2001:db8:aa:1::1', 't'));
        foreach (array('192.0.2.7', '::ffff:192.0.2.7', '', 'not-an-ip') as $none) {
            $this->assertSame('', EVC_Onboarding_Rules::network_key($none, 's'), $none);
        }
        $this->assertSame('', EVC_Onboarding_Rules::network_key('2001:db8::1', ''));
    }

    public function test_the_secret_is_derived_and_domain_separated(): void {
        $d = EVC_Onboarding_Rules::derive_secret('salt-1');
        $this->assertSame(hash_hmac('sha256', 'evangelou-club/join-rate-limit-key/v1', 'salt-1'), $d);
        $this->assertNotSame('salt-1', $d);
        $this->assertNotSame($d, EVC_Onboarding_Rules::derive_secret('salt-2'));
        $this->assertSame('', EVC_Onboarding_Rules::derive_secret(''));
    }

    public function test_a_configured_cap(): void {
        $this->assertSame(3, EVC_Onboarding_Rules::cap(null, 3));
        $this->assertSame(1, EVC_Onboarding_Rules::cap(1, 3));
        $this->assertSame(1000, EVC_Onboarding_Rules::cap(1000, 3));
        foreach (array(0, -1, 1001, '5', 5.0, true, array(5)) as $bad) {
            $this->assertSame(3, EVC_Onboarding_Rules::cap($bad, 3), var_export($bad, true));
        }
    }

    public function test_the_stored_counter(): void {
        $this->assertSame(array(4, 1700000000), EVC_Onboarding_Rules::parse_counter('4|1700000000'));
        $this->assertSame(array(1, 0), EVC_Onboarding_Rules::parse_counter('1|0'));
        foreach (array(null, '', '4', '4|', '|1', 'a|1', "4|1\n", '4|1|2', '1234567890|1', '1|1234567890123', 4) as $bad) {
            $this->assertNull(EVC_Onboarding_Rules::parse_counter($bad), var_export($bad, true));
        }
    }

    public function test_the_failure_window(): void {
        $state = null;
        for ($i = 1; $i <= 9; $i++) {
            $state = EVC_Onboarding_Rules::add_failure($state, 1000 + $i, 300);
            $this->assertFalse(EVC_Onboarding_Rules::blocked($state, 1000 + $i, 300, 10), "after $i");
        }
        $state = EVC_Onboarding_Rules::add_failure($state, 1100, 300);
        $this->assertSame(array(10, 1001), $state);
        $this->assertTrue(EVC_Onboarding_Rules::blocked($state, 1100, 300, 10), 'the 10th failure blocks');
        $this->assertTrue(EVC_Onboarding_Rules::blocked($state, 1300, 300, 10), 'until the window ends');
        $this->assertFalse(EVC_Onboarding_Rules::blocked($state, 1301, 300, 10), 'window over');
        $this->assertSame(array(1, 1301), EVC_Onboarding_Rules::add_failure($state, 1301, 300), 'a new window');
        $this->assertFalse(EVC_Onboarding_Rules::blocked(null, 1000, 300, 10));
    }
}
