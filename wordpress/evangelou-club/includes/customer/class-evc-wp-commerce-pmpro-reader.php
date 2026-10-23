<?php
defined('ABSPATH') || exit;
/**
 * STAGE-02: the concrete, READ-ONLY EVC_Pmpro_Reader for the verified staging
 * setup: WooCommerce 11.2.1 + Paid Memberships Pro 3.8.9 + PMPro WooCommerce
 * Add On 1.11, one simple €20 product mapped to one membership level, bank
 * transfer (BACS) only, manual monthly renewal.
 *
 * It reports FACTS, never decisions (EVC_Pmpro_Entitlement_Mapper decides):
 *  - the user's PMPro rows (pmpro_memberships_users) with the stored
 *    SITE-LOCAL start/end strings untouched;
 *  - Club WooCommerce orders of that user as PENDING or FAILED facts only:
 *      pending, on-hold (bank transfer awaiting payment),
 *      processing (not completed: PMPro WooCommerce 1.11 has not issued
 *      the membership yet)                                 -> pending
 *      failed, cancelled, refunded                         -> failed
 *      completed                                           -> NOT a fact:
 *        the add-on has issued the PMPro row, but a workflow state is never
 *        evidence that money arrived (Task 1D-I), so it can never pay;
 *      checkout-draft, trash                               -> ignored
 *      anything else (a status added by another plugin)    -> conflict;
 *  - CONFIRMED facts only from EVC_Club_Payment_Provenance (the Club's own
 *    record of which money funded which Athens period). Production has none
 *    yet (EVC_Unrecorded_Club_Payment_Provenance), so nobody is "active".
 *
 * Environment and configuration are VERIFIED on every read; any difference
 * is a source conflict, which the mapper turns into "indeterminate" (shown to
 * the customer as "unverified", never as paid): exact plugin versions, the
 * product (simple, published, sold individually, €20, EUR store, no
 * autocomplete), the add-on mapping (this product -> this level, and no other
 * product granting the level), the level (1 month, no recurring billing), and
 * every Club order (customer's own, BACS, EUR, exactly one Club item of
 * quantity 1). The site timezone is reported as is; the mapper requires
 * "Europe/Athens".
 *
 * Read-only: SELECTs and getters only, no write, no hook, no cache warm-up
 * on the shop's tables. Identifiers leave this class only as HMAC refs; no
 * name, e-mail, address, amount or note is ever copied. A database error
 * THROWS (the adapter turns it into "unavailable", never into a state).
 */
final class EVC_Wp_Commerce_Pmpro_Reader implements EVC_Pmpro_Reader {
    const PENDING_STATUSES = array('pending', 'on-hold', 'processing');
    const FAILED_STATUSES = array('failed', 'cancelled', 'refunded');
    const NOT_EVIDENCE_STATUSES = array('completed');
    /** Not placed (checkout draft) or deleted (trash): never a purchase. */
    const IGNORED_STATUSES = array('checkout-draft', 'trash');

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var EVC_Club_Payment_Provenance */
    private $provenance;

    public function __construct(EVC_Commerce_Source_Config $config, EVC_Club_Payment_Provenance $provenance) {
        $this->config = $config;
        $this->provenance = $provenance;
    }

    public function snapshot(int $wp_user_id): EVC_Pmpro_Snapshot {
        $timezone = (string) get_option('timezone_string', '');
        if (!$this->plugins_loaded()) {
            return new EVC_Pmpro_Snapshot(EVC_Pmpro_Snapshot::CONTRACT_VERSION, false, null, $timezone, true, array(), array());
        }
        $pmpro_version = (string) PMPRO_VERSION;
        if ($wp_user_id < 1 || get_userdata($wp_user_id) === false) {
            return new EVC_Pmpro_Snapshot(EVC_Pmpro_Snapshot::CONTRACT_VERSION, true, $pmpro_version, $timezone, false, array(), array());
        }
        $conflicts = $this->environment_conflicts();
        $rows = $this->membership_rows($wp_user_id);
        $confirmed = $this->provenance->confirmed_payments($wp_user_id);
        $settled = array();
        foreach ($confirmed as $fact) {
            if (!$fact instanceof EVC_Pmpro_Payment_Fact || !$fact->was_confirmed()) {
                throw new UnexpectedValueException('Provenance returned a non-confirmed fact.');
            }
            $settled[$fact->payment_ref] = true;
        }
        $payments = $confirmed;
        foreach ($this->club_orders($wp_user_id) as $order) {
            $ref = $this->config->ref('wc_order', (string) $order->get_id());
            $problem = $this->order_problem($order, $wp_user_id);
            if ($problem !== null) {
                $conflicts[] = $problem;
                continue;
            }
            if (isset($settled[$ref])) {
                continue; // the Club's own record of this order is authoritative
            }
            $status = (string) $order->get_status();
            if (in_array($status, self::NOT_EVIDENCE_STATUSES, true)) {
                continue;
            }
            $fact_status = in_array($status, self::PENDING_STATUSES, true) ? EVC_Pmpro_Payment_Fact::STATUS_PENDING : EVC_Pmpro_Payment_Fact::STATUS_FAILED;
            $payments[] = new EVC_Pmpro_Payment_Fact($ref, null, EVC_Payment_Evidence::KIND_WC_ORDER, $this->config->level_id, null, $fact_status, EVC_Pmpro_Payment_Fact::ENV_LIVE, null, null, null);
        }
        return new EVC_Pmpro_Snapshot(EVC_Pmpro_Snapshot::CONTRACT_VERSION, true, $pmpro_version, $timezone, true, $rows, $payments, array_values(array_unique($conflicts)));
    }

    private function plugins_loaded(): bool {
        global $wpdb;
        return defined('PMPRO_VERSION') && function_exists('pmpro_getLevel') && isset($wpdb->pmpro_memberships_users)
            && defined('WC_VERSION') && function_exists('wc_get_orders') && function_exists('wc_get_product') && function_exists('get_woocommerce_currency')
            && defined('PMPROWC_DIR') && function_exists('pmprowoo_add_membership_from_order');
    }

    /** @return string[] */
    private function environment_conflicts(): array {
        $c = array();
        if ((string) WC_VERSION !== EVC_Commerce_Source_Config::WOOCOMMERCE_VERSION) {
            $c[] = 'unsupported_woocommerce_version';
        }
        if ((string) PMPRO_VERSION !== EVC_Commerce_Source_Config::PMPRO_VERSION) {
            $c[] = 'unsupported_pmpro_version';
        }
        $addon = get_file_data(PMPROWC_DIR . '/pmpro-woocommerce.php', array('Version' => 'Version'));
        if (!isset($addon['Version']) || $addon['Version'] !== EVC_Commerce_Source_Config::PMPRO_WOOCOMMERCE_VERSION) {
            $c[] = 'unsupported_pmpro_woocommerce_version';
        }
        if (get_woocommerce_currency() !== EVC_Commerce_Source_Config::CURRENCY) {
            $c[] = 'store_currency_not_eur';
        }

        $product_id = $this->config->product_id;
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product) {
            $c[] = 'club_product_missing';
        } else {
            if ($product->get_type() !== 'simple' || $product->get_status() !== 'publish') {
                $c[] = 'club_product_not_a_published_simple_product';
            }
            if ($product->get_sold_individually() !== true) {
                $c[] = 'club_product_not_sold_individually';
            }
            if (wc_format_decimal((string) $product->get_regular_price(), 2) !== EVC_Commerce_Source_Config::PRICE
                || wc_format_decimal((string) $product->get_price(), 2) !== EVC_Commerce_Source_Config::PRICE) {
                $c[] = 'club_product_price_not_20_eur';
            }
            if (!empty(get_post_meta($product_id, '_membership_product_autocomplete', true))) {
                $c[] = 'club_product_autocompletes_orders';
            }
        }

        // PMPro WooCommerce 1.11 keeps its product -> level map in this option (pmprowoo_init()).
        $map = get_option('_pmprowoo_product_levels');
        if (!is_array($map) || !isset($map[$product_id]) || (int) $map[$product_id] !== $this->config->level_id) {
            $c[] = 'club_product_not_mapped_to_club_level';
        } else {
            foreach ($map as $other_product => $level) {
                if ((int) $other_product !== $product_id && (int) $level === $this->config->level_id) {
                    $c[] = 'club_level_granted_by_another_product';
                }
            }
        }

        $level = pmpro_getLevel($this->config->level_id);
        if (empty($level) || !isset($level->id) || (int) $level->id !== $this->config->level_id) {
            $c[] = 'club_level_missing';
        } else {
            if ((int) $level->expiration_number !== 1 || (string) $level->expiration_period !== 'Month') {
                $c[] = 'club_level_not_one_month';
            }
            if ((float) $level->billing_amount !== 0.0 || (int) $level->cycle_number !== 0) {
                $c[] = 'club_level_has_recurring_billing';
            }
        }
        return $c;
    }

    /** @return EVC_Pmpro_Membership_Row[] */
    private function membership_rows(int $wp_user_id): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, membership_id, status, startdate, enddate FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d ORDER BY id ASC", $wp_user_id),
            ARRAY_A
        );
        if (!is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('PMPro membership rows could not be read.');
        }
        $out = array();
        foreach ($rows as $r) {
            $out[] = new EVC_Pmpro_Membership_Row(
                $this->config->ref('pmpro_membership_row', (string) $r['id']),
                (int) $r['membership_id'],
                (string) $r['status'],
                $r['startdate'] === null ? null : (string) $r['startdate'],
                $r['enddate'] === null ? null : (string) $r['enddate'],
                false
            );
        }
        return $out;
    }

    /**
     * The user's orders that contain the Club product, WHATEVER their status.
     *
     * Found through WooCommerce's order-items table, which both order storages
     * (HPOS and the legacy posts table) use, so an order can never be skipped
     * because a status-filtered query does not list its status (a status added
     * by another plugin, or one whose plugin was removed). Drafts and trashed
     * orders are not placed orders and are ignored; any other unknown status
     * becomes a conflict in order_problem().
     *
     * @return WC_Order[]
     */
    private function club_orders(int $wp_user_id): array {
        global $wpdb;
        $items = $wpdb->prefix . 'woocommerce_order_items';
        $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $wpdb->last_error = '';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT oi.order_id FROM {$items} oi INNER JOIN {$meta} im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id' AND im.meta_value = %s WHERE oi.order_item_type = 'line_item' ORDER BY oi.order_id ASC",
            (string) $this->config->product_id
        ));
        if (!is_array($ids) || $wpdb->last_error !== '') {
            throw new RuntimeException('WooCommerce order items could not be read.');
        }
        $club = array();
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            // Refunds are separate objects (WC_Order_Refund) of their parent order; a missing order cannot be attributed.
            if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund) {
                continue;
            }
            if ((int) $order->get_customer_id() !== $wp_user_id) {
                continue;
            }
            if (in_array((string) $order->get_status(), self::IGNORED_STATUSES, true)) {
                continue;
            }
            $club[] = $order;
        }
        return $club;
    }

    /** A Club order this setup cannot interpret safely -> conflict flag; null = interpretable. */
    private function order_problem(WC_Order $order, int $wp_user_id): ?string {
        $status = (string) $order->get_status();
        if (!in_array($status, array_merge(self::PENDING_STATUSES, self::FAILED_STATUSES, self::NOT_EVIDENCE_STATUSES), true)) {
            return 'club_order_unknown_status';
        }
        if ((int) $order->get_customer_id() !== $wp_user_id) {
            return 'club_order_customer_mismatch';
        }
        if ((string) $order->get_payment_method() !== EVC_Commerce_Source_Config::PAYMENT_METHOD) {
            return 'club_order_not_bank_transfer';
        }
        if ((string) $order->get_currency() !== EVC_Commerce_Source_Config::CURRENCY) {
            return 'club_order_not_eur';
        }
        $club_items = 0;
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof WC_Order_Item_Product && (int) $item->get_product_id() === $this->config->product_id) {
                $club_items++;
                if ((int) $item->get_quantity() !== 1) {
                    return 'club_order_quantity_not_one';
                }
            }
        }
        return $club_items === 1 ? null : 'club_order_multiple_club_items';
    }
}
