<?php
defined('ABSPATH') || exit;
/**
 * STAGE-05A: where the staff redemption backend gets the coffee allowlist from.
 *
 * Returning null means "no approved catalogue": the backend is unavailable (503).
 */
interface EVC_Redemption_Catalogue_Source {
    public function catalogue(): ?EVC_Coffee_Catalog;
}

/**
 * The owner-approved v1 coffee list (owner decision D3, 2026-11-03 12:25 Athens, verbatim
 * «όλοι οι καφέδες ότι και να είναι»): the free daily coffee covers ANY coffee the shop serves; the
 * coffee type never affects eligibility. The codes only record WHICH coffee was made; «Άλλος καφές»
 * (other_coffee) is the catch-all, so no coffee the shop serves is ever refused. An unknown code is
 * still rejected (invalid_coffee) because the ledger stores only these codes.
 *
 * The shop has no online coffee menu. Adding or renaming an entry is one small reviewed change of
 * ITEMS + VERSION here (the packager allows EVC_Coffee_Catalog to be built only in this file, in
 * catalogue(); docs/staff-redemption.md). The list is never read from wp-config, an option or the
 * database.
 */
final class EVC_Approved_Coffee_Catalogue_Source implements EVC_Redemption_Catalogue_Source {
    const VERSION = 'evc-coffee-v1-2026-11-03';

    const ITEMS = array(
        'espresso' => 'Espresso',
        'freddo_espresso' => 'Freddo Espresso',
        'cappuccino' => 'Cappuccino',
        'freddo_cappuccino' => 'Freddo Cappuccino',
        'greek_coffee' => 'Ελληνικός',
        'filter_coffee' => 'Φίλτρου',
        'other_coffee' => 'Άλλος καφές',
    );

    public function catalogue(): ?EVC_Coffee_Catalog {
        return new EVC_Coffee_Catalog(self::ITEMS);
    }
}
