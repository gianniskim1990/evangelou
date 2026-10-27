<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/mutation/lib-mutation-evidence.php';

/**
 * STAGE-03B.1: the mutation runner can tell a meaningful kill (the named test
 * failed an ASSERTION) from a broken run (error, test missing, no log).
 * JUnit samples are in PHPUnit 9's own format.
 */
final class MutationEvidenceTest extends TestCase {
    const KILLER = 'CommerceMembershipSourceTest::test_configured_constants_select_the_real_source';

    private static function junit(string $case_body): string {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<testsuites><testsuite name="wp-commerce" tests="2" assertions="3" errors="0" failures="1" skipped="0" time="1.0">'
            . '<testsuite name="CommerceMembershipSourceTest" file="tests/wordpress/commerce/CommerceMembershipSourceTest.php" tests="2">'
            . '<testcase name="test_no_club_order_is_none" class="CommerceMembershipSourceTest" classname="CommerceMembershipSourceTest" file="x.php" line="1" assertions="1" time="0.1"/>'
            . '<testcase name="test_configured_constants_select_the_real_source" class="CommerceMembershipSourceTest" classname="CommerceMembershipSourceTest" file="x.php" line="105" assertions="1" time="0.1">'
            . $case_body
            . '</testcase></testsuite></testsuite></testsuites>';
    }

    public function test_assertion_failure_of_the_named_killer_is_a_meaningful_kill(): void {
        $xml = self::junit('<failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that EVC_Unavailable_Membership_Adapter Object () is an instance of class "EVC_Pmpro_Membership_Adapter".</failure>');
        $this->assertSame('assertion', evc_mutation_killer_verdict($xml, array(self::KILLER))['verdict']);
    }

    public function test_an_error_in_the_named_killer_is_not_a_meaningful_kill(): void {
        $xml = self::junit('<error type="EVC_Membership_Source_Exception">EVC_Membership_Source_Exception: Membership source is not available.' . "\n" . 'at x.php:1</error>');
        $v = evc_mutation_killer_verdict($xml, array(self::KILLER));
        $this->assertSame('error', $v['verdict']);
        $this->assertStringContainsString('Membership source is not available.', $v['detail']);
        $this->assertStringNotContainsString('at x.php', $v['detail']);
    }

    public function test_a_passing_killer_means_the_mutant_survived_that_test(): void {
        $this->assertSame('passed', evc_mutation_killer_verdict(self::junit(''), array(self::KILLER))['verdict']);
    }

    public function test_a_killer_that_did_not_run_is_a_broken_setup(): void {
        $this->assertSame('missing', evc_mutation_killer_verdict(self::junit(''), array('CommerceMembershipSourceTest::test_renamed'))['verdict']);
        $this->assertSame('missing', evc_mutation_killer_verdict(self::junit(''), array('OtherTest::test_configured_constants_select_the_real_source'))['verdict']);
    }

    public function test_a_missing_or_corrupt_log_is_a_broken_setup(): void {
        $this->assertSame('unreadable', evc_mutation_killer_verdict('', array(self::KILLER))['verdict']);
        $this->assertSame('unreadable', evc_mutation_killer_verdict('PHP Fatal error: ...', array(self::KILLER))['verdict']);
    }

    public function test_every_named_killer_must_fail_an_assertion(): void {
        $xml = self::junit('<failure type="PHPUnit\Framework\ExpectationFailedException">x</failure>');
        $this->assertSame('passed', evc_mutation_killer_verdict($xml, array(self::KILLER, 'CommerceMembershipSourceTest::test_no_club_order_is_none'))['verdict']);
    }

    public function test_killer_names_are_validated(): void {
        foreach (array(array(), array('no_class_separator'), array('::m'), array('C::')) as $killers) {
            try {
                evc_mutation_killer_verdict(self::junit(''), $killers);
                $this->fail('accepted ' . json_encode($killers));
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_killer_filter_selects_exactly_the_named_tests(): void {
        $f = evc_mutation_killer_filter(array('WcCompletionTest::test_a', 'BacsIssuerTest::test_b', 'WcCompletionTest::test_a'));
        $this->assertSame('/^(?:WcCompletionTest::test_a|BacsIssuerTest::test_b)(?: with data set .*)?$/', $f);
        $this->assertSame(1, preg_match($f, 'WcCompletionTest::test_a'));
        $this->assertSame(1, preg_match($f, 'BacsIssuerTest::test_b with data set #0'));
        $this->assertSame(0, preg_match($f, 'WcCompletionTest::test_ab'), 'never a prefix match');
        $this->assertSame(0, preg_match($f, 'XWcCompletionTest::test_a'));
        foreach (array(array(), array('no_separator'), array('C::m)|.*'), array('C::')) as $killers) {
            try {
                evc_mutation_killer_filter($killers);
                $this->fail('accepted ' . json_encode($killers));
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
