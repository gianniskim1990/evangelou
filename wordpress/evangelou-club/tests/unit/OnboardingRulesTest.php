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
