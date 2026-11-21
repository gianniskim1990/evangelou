<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Owner decision D24 (2026-11-20, relayed by the coordinator): the shop's coffee PREPARATION catalogue,
 * the server-side source of truth for the till's step-by-step coffee choice (dose incl. triple, sugar,
 * sweetener, milk, size, quick notes, one short staff note).
 *
 * - D3 stays: any coffee counts. The SEVEN coffee codes are exactly the v1 list
 *   (EVC_Approved_Coffee_Catalogue_Source, evc-coffee-v1-2026-11-03) with the same labels; «Άλλος καφές»
 *   (other_coffee) is the catch-all with a minimal profile plus the note. The coffee type and its
 *   preparation never affect eligibility, and the preparation is never part of a uniqueness key.
 * - D-PRICING stays undecided (`pricing_policy: undecided`): no option carries a price.
 * - Base: the UI-02.R1 preview profiles (app/src/preview/coffeeConfig.ts) in the UI-03C catalogue format,
 *   with D24's additions: a TRIPLE dose for the espresso-based coffees and the Greek coffee; milk
 *   (none / regular / lactose-free / plant) where it applies; a cup SIZE (κανονικό / μεγάλο) for the
 *   filter coffee, the freddos and the cappuccinos. The Greek coffee has NO size group: its «Δόση»
 *   (μονός / διπλός / τριπλός) is what people call its size, so there is one meaning per word.
 *
 * Approval record: EVC_Prep_Catalogue_Authority wants, for a document that says "approved", a pinned
 * digest of exactly that document (EVC_Prep_Catalogue_Digest). The digest of D24's version is pinned in
 * APPROVED_DIGESTS below: any later edit of the document changes the digest and the server then refuses
 * every new prepared redemption (catalogue_not_authorised) until a reviewed change pins the new version.
 *
 * Constructed only by EVC_Club_Prepared_Redemption_Backend (packager rule set), and read-only everywhere.
 */
final class EVC_Club_Prep_Catalogue_Authority implements EVC_Prep_Catalogue_Authority {
    const CATALOGUE_VERSION = 2;
    /** Human-readable name of this version (the D3 v1 list was evc-coffee-v1-2026-11-03). */
    const VERSION_LABEL = 'evc-coffee-v2-2026-11-20';
    /** catalogue_version => EVC_Prep_Catalogue_Digest of the approved document (owner decision D24). */
    const APPROVED_DIGESTS = array(
        2 => '75c1343006d18b8027796f7666edc258564e65cfb19b39e132967b30978d0f81',
    );

    public function active_catalogue_data(): array {
        return self::document();
    }

    public function approved_digest(int $catalogue_version): ?string {
        return self::APPROVED_DIGESTS[$catalogue_version] ?? null;
    }

    /**
     * The shipped document parses, says "approved" and matches its pinned digest: exactly the check the
     * service makes before every new prepared redemption (read by the lookup reply's `prepared` and readiness).
     */
    public static function is_authorised(): bool {
        $catalogue = self::catalogue();
        $digest = self::APPROVED_DIGESTS[self::CATALOGUE_VERSION] ?? null;
        return $catalogue !== null && $catalogue->is_approved() && $catalogue->version() === self::CATALOGUE_VERSION
            && is_string($digest) && hash_equals($digest, EVC_Prep_Catalogue_Digest::of(self::document()));
    }

    /** The parsed catalogue, or null when the shipped document does not parse (never expected; tested). */
    public static function catalogue(): ?EVC_Prep_Catalogue {
        try {
            return EVC_Prep_Catalogue::from_array(self::document());
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    /** code => label of the seven coffees, in menu order (also the label of a v1 ledger row's coffee_code). */
    public static function coffee_labels(): array {
        $out = array();
        foreach (self::document()['coffees'] as $coffee) {
            $out[$coffee['code']] = $coffee['label'];
        }
        return $out;
    }

    private static function option(string $id, string $label): array {
        return array('id' => $id, 'label' => $label);
    }

    private static function group(string $id, string $label, bool $required, array $options, ?array $visible_when = null): array {
        $group = array('id' => $id, 'label' => $label, 'required' => $required, 'options' => $options);
        if ($visible_when !== null) {
            $group['visible_when'] = $visible_when;
        }
        return $group;
    }

    /** The approved D24 document (decoded JSON shape, EVC_Prep_Catalogue::SCHEMA). */
    public static function document(): array {
        $dose = function (bool $triple): array {
            $options = array(self::option('single', 'Μονός'), self::option('double', 'Διπλός'));
            if ($triple) {
                $options[] = self::option('triple', 'Τριπλός');
            }
            return self::group('shots', 'Δόση', true, $options);
        };
        $sweetness = self::group('sweetness', 'Ζάχαρη', true, array(
            self::option('none', 'Σκέτος'),
            self::option('little', 'Ολίγη'),
            self::option('medium', 'Μέτριος'),
            self::option('sweet', 'Γλυκός'),
            self::option('very_sweet', 'Πολύ γλυκός'),
        ));
        $sweetener = self::group('sweetener', 'Γλυκαντικό', true, array(
            self::option('white', 'Λευκή ζάχαρη'),
            self::option('brown', 'Καστανή ζάχαρη'),
            self::option('stevia', 'Στέβια'),
        ), array('group' => 'sweetness', 'not_in' => array('none')));
        $milk = function (bool $with_none): array {
            $options = $with_none ? array(self::option('none', 'Χωρίς γάλα')) : array();
            $options[] = self::option('regular', 'Κανονικό γάλα');
            $options[] = self::option('lactose_free', 'Γάλα χωρίς λακτόζη');
            $options[] = self::option('plant', 'Φυτικό γάλα');
            return self::group('milk', 'Γάλα', true, $options);
        };
        $size = self::group('size', 'Μέγεθος', true, array(
            self::option('regular', 'Κανονικό μέγεθος'),
            self::option('large', 'Μεγάλο μέγεθος'),
        ));
        $ice = self::group('ice', 'Πάγος', false, array(self::option('little', 'Λίγος πάγος'), self::option('extra', 'Πολύς πάγος')));
        $foam = self::group('foam', 'Αφρόγαλα', false, array(self::option('little', 'Λίγος αφρός'), self::option('extra', 'Πολύς αφρός')));
        $caffeine = self::group('caffeine', 'Καφεΐνη', false, array(self::option('decaf', 'Χωρίς καφεΐνη')));

        return array(
            'schema' => EVC_Prep_Catalogue::SCHEMA,
            'catalogue_version' => self::CATALOGUE_VERSION,
            'status' => EVC_Prep_Catalogue::STATUS_APPROVED,
            'pricing_policy' => EVC_Prep_Catalogue::PRICING_UNDECIDED,
            'free_text_note' => true,
            'quick_notes' => array(
                array('id' => 'takeaway', 'label' => 'Σε ποτήρι για έξω', 'applies_to' => 'any'),
                array('id' => 'very_hot', 'label' => 'Πολύ ζεστός', 'applies_to' => 'hot'),
                array('id' => 'lukewarm', 'label' => 'Χλιαρός', 'applies_to' => 'hot'),
                array('id' => 'ice_side', 'label' => 'Πάγος στο πλάι', 'applies_to' => 'cold'),
                array('id' => 'no_straw', 'label' => 'Χωρίς καλαμάκι', 'applies_to' => 'cold'),
                array('id' => 'cinnamon', 'label' => 'Κανέλα στον αφρό', 'applies_to' => 'foam'),
                array('id' => 'cocoa', 'label' => 'Κακάο στον αφρό', 'applies_to' => 'foam'),
            ),
            'exclusive_quick_notes' => array(array('very_hot', 'lukewarm')),
            'max_quick_notes' => 4,
            'coffees' => array(
                array('code' => 'espresso', 'label' => 'Espresso', 'temperature' => 'hot',
                    'groups' => array($dose(true), $sweetness, $sweetener, $caffeine),
                    'quick_notes' => array('takeaway', 'very_hot', 'lukewarm')),
                array('code' => 'freddo_espresso', 'label' => 'Freddo Espresso', 'temperature' => 'cold',
                    'groups' => array($size, $dose(true), $sweetness, $sweetener, $milk(true), $ice, $caffeine),
                    'quick_notes' => array('takeaway', 'ice_side', 'no_straw')),
                array('code' => 'cappuccino', 'label' => 'Cappuccino', 'temperature' => 'hot',
                    'groups' => array($size, $dose(true), $sweetness, $sweetener, $milk(false), $foam, $caffeine),
                    'quick_notes' => array('takeaway', 'very_hot', 'lukewarm', 'cinnamon', 'cocoa')),
                array('code' => 'freddo_cappuccino', 'label' => 'Freddo Cappuccino', 'temperature' => 'cold',
                    'groups' => array($size, $dose(true), $sweetness, $sweetener, $milk(false), $ice, $foam, $caffeine),
                    'quick_notes' => array('takeaway', 'ice_side', 'no_straw', 'cinnamon', 'cocoa')),
                array('code' => 'greek_coffee', 'label' => 'Ελληνικός', 'temperature' => 'hot',
                    'groups' => array($dose(true), $sweetness, $sweetener),
                    'quick_notes' => array('takeaway', 'very_hot', 'lukewarm')),
                array('code' => 'filter_coffee', 'label' => 'Φίλτρου', 'temperature' => 'hot',
                    'groups' => array($size, $sweetness, $sweetener, $milk(true), $caffeine),
                    'quick_notes' => array('takeaway', 'very_hot', 'lukewarm')),
                array('code' => 'other_coffee', 'label' => 'Άλλος καφές', 'temperature' => 'hot',
                    'groups' => array($sweetness, $sweetener),
                    'quick_notes' => array('takeaway')),
            ),
        );
    }
}
