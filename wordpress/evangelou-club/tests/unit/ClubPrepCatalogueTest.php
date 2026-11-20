<?php
use PHPUnit\Framework\TestCase;

/**
 * Round 9 (owner decision D24): the shipped preparation catalogue (EVC_Club_Prep_Catalogue_Authority), its
 * approval digest, the D3 invariants (the seven coffee codes and labels of the v1 list; «Άλλος καφές»),
 * the dose / milk / size rules, and the pure history pre-fill (retired options are never pre-filled).
 */
final class ClubPrepCatalogueTest extends TestCase {
    private static function catalogue(): EVC_Prep_Catalogue {
        return EVC_Prep_Catalogue::from_array(EVC_Club_Prep_Catalogue_Authority::document());
    }

    private static function group_ids(string $coffee): array {
        return array_keys(self::catalogue()->groups($coffee));
    }

    private static function options(string $coffee, string $group): array {
        return self::catalogue()->groups($coffee)[$group]['options'];
    }

    public function test_the_document_parses_is_approved_and_matches_its_pinned_digest(): void {
        $c = self::catalogue();
        $this->assertTrue($c->is_approved());
        $this->assertSame(2, $c->version());
        $this->assertSame(EVC_Club_Prep_Catalogue_Authority::CATALOGUE_VERSION, $c->version());
        $this->assertTrue($c->allows_free_text_note(), 'D24: one short staff note');
        $this->assertSame('evc-coffee-v2-2026-11-20', EVC_Club_Prep_Catalogue_Authority::VERSION_LABEL);
        $this->assertNotSame('evc-coffee-v1-2026-11-03', EVC_Club_Prep_Catalogue_Authority::VERSION_LABEL, 'a new version string');
        $authority = new EVC_Club_Prep_Catalogue_Authority();
        $this->assertSame(EVC_Prep_Catalogue_Digest::of($authority->active_catalogue_data()), $authority->approved_digest(2));
        $this->assertNull($authority->approved_digest(1));
        $this->assertNull($authority->approved_digest(3));
        $this->assertTrue(EVC_Club_Prep_Catalogue_Authority::is_authorised());
        $this->assertSame('undecided', EVC_Club_Prep_Catalogue_Authority::document()['pricing_policy'], 'D-PRICING stays open');
    }

    public function test_the_seven_coffees_are_exactly_the_d3_list_with_the_same_labels(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/staff/class-evc-redemption-catalogue-source.php');
        $this->assertSame(1, preg_match('/const ITEMS = array\((.*?)\);/s', $source, $m));
        preg_match_all("/'([a-z_]+)' => '([^']+)'/u", $m[1], $pairs, PREG_SET_ORDER);
        $v1 = array();
        foreach ($pairs as $p) {
            $v1[$p[1]] = $p[2];
        }
        $this->assertCount(7, $v1);
        $this->assertSame($v1, EVC_Club_Prep_Catalogue_Authority::coffee_labels());
        $this->assertSame('Άλλος καφές', EVC_Club_Prep_Catalogue_Authority::coffee_labels()['other_coffee']);
    }

    public function test_triple_dose_on_the_espresso_based_coffees_and_the_greek_coffee_only(): void {
        foreach (array('espresso', 'freddo_espresso', 'cappuccino', 'freddo_cappuccino', 'greek_coffee') as $coffee) {
            $this->assertSame(array('single' => 'Μονός', 'double' => 'Διπλός', 'triple' => 'Τριπλός'), self::options($coffee, 'shots'), $coffee);
            $this->assertTrue(self::catalogue()->groups($coffee)['shots']['required'], $coffee);
        }
        foreach (array('filter_coffee', 'other_coffee') as $coffee) {
            $this->assertNotContains('shots', self::group_ids($coffee), $coffee);
        }
    }

    public function test_the_greek_coffee_has_one_dose_and_no_size_so_no_word_means_two_things(): void {
        $this->assertSame(array('shots', 'sweetness', 'sweetener'), self::group_ids('greek_coffee'));
        $this->assertSame('Δόση', self::catalogue()->groups('greek_coffee')['shots']['label']);
        foreach (array('filter_coffee', 'freddo_espresso', 'cappuccino', 'freddo_cappuccino') as $coffee) {
            $this->assertSame(array('regular' => 'Κανονικό μέγεθος', 'large' => 'Μεγάλο μέγεθος'), self::options($coffee, 'size'), $coffee);
            $this->assertSame('Μέγεθος', self::catalogue()->groups($coffee)['size']['label']);
        }
        $this->assertNotContains('size', self::group_ids('espresso'));
    }

    public function test_milk_where_it_applies_with_lactose_free_and_plant(): void {
        $with_none = array('none' => 'Χωρίς γάλα', 'regular' => 'Κανονικό γάλα', 'lactose_free' => 'Γάλα χωρίς λακτόζη', 'plant' => 'Φυτικό γάλα');
        $this->assertSame($with_none, self::options('freddo_espresso', 'milk'));
        $this->assertSame($with_none, self::options('filter_coffee', 'milk'));
        unset($with_none['none']);
        $this->assertSame($with_none, self::options('cappuccino', 'milk'), 'a cappuccino always has milk');
        $this->assertSame($with_none, self::options('freddo_cappuccino', 'milk'));
        foreach (array('espresso', 'greek_coffee', 'other_coffee') as $coffee) {
            $this->assertNotContains('milk', self::group_ids($coffee), $coffee);
        }
    }

    public function test_sweetness_and_sweetener_white_brown_stevia_hidden_for_no_sugar(): void {
        foreach (array_keys(EVC_Club_Prep_Catalogue_Authority::coffee_labels()) as $coffee) {
            $groups = self::catalogue()->groups($coffee);
            $this->assertSame(array('none', 'little', 'medium', 'sweet', 'very_sweet'), array_keys($groups['sweetness']['options']), $coffee);
            $this->assertSame(array('white' => 'Λευκή ζάχαρη', 'brown' => 'Καστανή ζάχαρη', 'stevia' => 'Στέβια'), $groups['sweetener']['options'], $coffee);
            $this->assertSame(array('group' => 'sweetness', 'mode' => 'not_in', 'options' => array('none')), $groups['sweetener']['visible_when'], $coffee);
        }
    }

    public function test_other_coffee_is_the_minimal_catch_all_with_the_note(): void {
        $this->assertSame(array('sweetness', 'sweetener'), self::group_ids('other_coffee'));
        $this->assertTrue(self::catalogue()->coffee_allows_quick_note('other_coffee', 'takeaway'));
        $this->assertFalse(self::catalogue()->coffee_allows_quick_note('other_coffee', 'very_hot'));
    }

    public function test_the_service_refuses_an_edited_document_without_a_new_approval(): void {
        $store = new EVC_In_Memory_Prepared_Redemption_Store();
        $store->add_member('mem_0123456789abcdef0123456789abcdef', 501);
        $membership = new EVC_Mock_Membership_Adapter();
        $membership->set(501, EVC_Mock_Membership_Adapter::active_until('2026-12-20T00:00:00Z'));
        $body = array('request_id' => '11111111-1111-4111-8111-111111111111', 'catalogue_version' => 2, 'coffee_code' => 'espresso', 'preparation' => array(
            'modifiers' => array(array('group' => 'shots', 'option' => 'triple'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array(), 'note' => 'στο τραπέζι 4'));
        $edited = EVC_Club_Prep_Catalogue_Authority::document();
        $edited['coffees'][0]['label'] = 'Εσπρέσο';
        $fake = new EVC_Fake_Prep_Catalogue_Authority($edited);
        $fake->approvals[2] = EVC_Club_Prep_Catalogue_Authority::APPROVED_DIGESTS[2];
        $refused = (new EVC_Prepared_Redemption_Service($store, $fake, $membership, new EVC_Fixed_Clock('2026-11-20T08:00:00Z')))->redeem('mem_0123456789abcdef0123456789abcdef', 'free_coffee', new EVC_Staff_Actor(7), $body);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::CATALOGUE_NOT_AUTHORISED, $refused->kind());

        $ok = (new EVC_Prepared_Redemption_Service($store, new EVC_Club_Prep_Catalogue_Authority(), $membership, new EVC_Fixed_Clock('2026-11-20T08:00:00Z')))->redeem('mem_0123456789abcdef0123456789abcdef', 'free_coffee', new EVC_Staff_Actor(7), $body);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $ok->kind());
        $this->assertSame('στο τραπέζι 4', $ok->redemption()['preparation']['note']);
        $this->assertSame('Τριπλός', $ok->redemption()['preparation']['modifiers'][0]['label']);
    }

    // ---- pure pre-fill ---------------------------------------------------------------------------------------------

    public function test_prefill_keeps_what_the_catalogue_still_offers_and_never_invents(): void {
        $c = self::catalogue();
        $full = EVC_Coffee_History::prefill($c, 'cappuccino', array('size' => 'large', 'shots' => 'double', 'sweetness' => 'little', 'sweetener' => 'stevia', 'milk' => 'plant'), array('cinnamon'), 'αφρός');
        $this->assertFalse($full['partial']);
        $this->assertSame(array('catalogue_version' => 2, 'coffee_code' => 'cappuccino', 'modifiers' => array(
            array('group' => 'size', 'option' => 'large'), array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'little'),
            array('group' => 'sweetener', 'option' => 'stevia'), array('group' => 'milk', 'option' => 'plant'),
        ), 'quick_notes' => array('cinnamon'), 'note' => 'αφρός'), $full['prefill']);

        // A retired option, a hidden group, an inapplicable quick note, two exclusive notes: dropped, partial.
        $partial = EVC_Coffee_History::prefill($c, 'espresso', array('shots' => 'quadruple', 'sweetness' => 'none', 'sweetener' => 'white'), array('very_hot', 'lukewarm', 'cinnamon'), null);
        $this->assertTrue($partial['partial']);
        $this->assertSame(array(array('group' => 'sweetness', 'option' => 'none')), $partial['prefill']['modifiers']);
        $this->assertSame(array('very_hot'), $partial['prefill']['quick_notes']);

        $this->assertSame(array('prefill' => null, 'partial' => true), EVC_Coffee_History::prefill($c, 'latte', array(), array(), null), 'a coffee no longer offered');
        $this->assertSame(array('prefill' => null, 'partial' => false), EVC_Coffee_History::prefill(null, 'espresso', array(), array(), null), 'no catalogue: no pre-fill');

        $no_notes = EVC_Club_Prep_Catalogue_Authority::document();
        $no_notes['free_text_note'] = false;
        $dropped = EVC_Coffee_History::prefill(EVC_Prep_Catalogue::from_array($no_notes), 'espresso', array('shots' => 'single', 'sweetness' => 'none'), array(), 'σημείωση');
        $this->assertNull($dropped['prefill']['note']);
        $this->assertTrue($dropped['partial']);
    }

    public function test_the_customer_view_never_carries_the_note_and_the_staff_view_no_codes_of_staff(): void {
        $item = array('business_date' => '2026-11-20', 'coffee_code' => 'espresso', 'coffee_label' => 'Espresso', 'details' => array('Διπλός'), 'note' => 'ιδιωτικό', 'prefill' => null, 'prefill_partial' => false);
        $this->assertSame(array(array('business_date' => '2026-11-20', 'coffee' => 'Espresso', 'details' => array('Διπλός'))), EVC_Coffee_History::for_customer(array($item)));
        $this->assertSame(array(array('business_date' => '2026-11-20', 'coffee_label' => 'Espresso', 'details' => array('Διπλός'), 'note' => 'ιδιωτικό', 'prefill' => null, 'prefill_partial' => false)), EVC_Coffee_History::for_staff(array($item)));
    }
}
