<?php
defined('ABSPATH') || exit;
/**
 * STAGE-07 round 2: the shop's own WooCommerce cart and checkout keep the Club rules even outside the join flow (two
 * devices, a saved ?add-to-cart= link). Constructed only by EVC_Onboarding_Plugin::wire(), hooked only behind
 * EVC_CLUB_ONBOARDING_ENABLED. Other products are untouched.
 *
 *  - The Club subscription is bought ALONE: adding it to a cart with other products, or other products to a cart
 *    with it, is refused, and so is a checkout of such a mixed cart (a Club order must be exactly one Club item at
 *    20.00 EUR, or the Club cannot verify it: EVC_Wp_Commerce_Pmpro_Reader).
 *  - No second Club order: refused while the customer has an open Club order or a membership that reads pending
 *    payment or unverified (EVC_Onboarding_Handoff::blocks_new_order); an active member may renew early (D2).
 *  - A Club checkout needs an account (a guest Club order could never be attributed to a member).
 *  - Owner decision D15: when the Club product is in the cart (or the order being paid), the ONLY gateway offered is
 *    bank transfer (`bacs`). If bank transfer is not available, NO gateway is offered and a Greek message says so.
 */
final class EVC_Onboarding_Checkout_Guard {
    const GATEWAY = 'bacs';
    const MIXED = 'Η συνδρομή του Evangelou Club αγοράζεται μόνη της. Αφαιρέστε τα άλλα προϊόντα από το καλάθι και δοκιμάστε ξανά.';
    const DUPLICATE = 'Υπάρχει ήδη συνδρομή ή παραγγελία συνδρομής σε αναμονή πληρωμής. Δείτε τον λογαριασμό μέλους σας.';
    const ACCOUNT = 'Για τη συνδρομή του Club χρειάζεται λογαριασμός. Συνδεθείτε ή δημιουργήστε λογαριασμό.';
    const NO_BACS = 'Η συνδρομή του Club πληρώνεται μόνο με τραπεζική μεταφορά, που δεν είναι διαθέσιμη αυτή τη στιγμή. Ρωτήστε στο κατάστημα.';

    /** @var EVC_Onboarding_Handoff */
    private $handoff;
    /** @var callable(): int[] */
    private $cart_products;
    /** @var string|null the last refusal (tests; customers see it as a WooCommerce notice / checkout error) */
    public $last_message;

    /** @param callable(): int[]|null $cart_products TEST seam; default: the product ids of the WooCommerce cart / order being paid */
    public function __construct(EVC_Onboarding_Handoff $handoff, ?callable $cart_products = null) {
        EVC_Onboarding_Feature::assert_constructible(__CLASS__);
        $this->handoff = $handoff;
        $this->cart_products = $cart_products ?? array(__CLASS__, 'wc_cart_products');
    }

    public function register(): void {
        add_filter('woocommerce_add_to_cart_validation', array($this, 'add_to_cart'), 20, 3);
        add_action('woocommerce_after_checkout_validation', array($this, 'checkout'), 20, 2);
        add_filter('woocommerce_available_payment_gateways', array($this, 'gateways'), 100, 1);
        add_filter('woocommerce_no_available_payment_methods_message', array($this, 'no_methods_message'), 100, 1);
    }

    /** woocommerce_add_to_cart_validation */
    public function add_to_cart($passed, $product_id = 0, $quantity = 1) {
        if (!$passed) {
            return $passed;
        }
        $club = $this->handoff->club_product_id();
        if ($club === null) {
            return $passed;
        }
        $adding_club = (int) $product_id === $club;
        $cart = $this->cart();
        $cart_has_club = in_array($club, $cart, true);
        $cart_has_other = array_diff($cart, array($club)) !== array();
        if (($adding_club && $cart_has_other) || (!$adding_club && $cart_has_club)) {
            return $this->refuse(self::MIXED);
        }
        if ($adding_club && is_user_logged_in() && $this->handoff->blocks_new_order(get_current_user_id())) {
            return $this->refuse(self::DUPLICATE);
        }
        return $passed;
    }

    /** woocommerce_after_checkout_validation */
    public function checkout($data, $errors): void {
        if (!$errors instanceof WP_Error) {
            return;
        }
        $club = $this->handoff->club_product_id();
        $cart = $this->cart();
        if ($club === null || !in_array($club, $cart, true)) {
            return;
        }
        if (array_diff($cart, array($club)) !== array()) {
            $this->last_message = self::MIXED;
            $errors->add('evc_club_mixed_cart', self::MIXED);
        }
        if (!is_user_logged_in()) {
            $creating = is_array($data) && !empty($data['createaccount']);
            if (!$creating) {
                $this->last_message = self::ACCOUNT;
                $errors->add('evc_club_account_required', self::ACCOUNT);
            }
            return;
        }
        if ($this->handoff->blocks_new_order(get_current_user_id())) {
            $this->last_message = self::DUPLICATE;
            $errors->add('evc_club_duplicate_order', self::DUPLICATE);
        }
    }

    /** woocommerce_available_payment_gateways (D15): only bank transfer for the Club product; nothing if it is missing. */
    public function gateways($gateways) {
        if (!is_array($gateways) || !$this->club_in_cart()) {
            return $gateways;
        }
        return isset($gateways[self::GATEWAY]) ? array(self::GATEWAY => $gateways[self::GATEWAY]) : array();
    }

    /** woocommerce_no_available_payment_methods_message: the Greek reason when the Club product has no gateway. */
    public function no_methods_message($message) {
        return $this->club_in_cart() ? self::NO_BACS : $message;
    }

    private function club_in_cart(): bool {
        $club = $this->handoff->club_product_id();
        return $club !== null && in_array($club, $this->cart(), true);
    }

    /** @return int[] */
    private function cart(): array {
        try {
            $ids = ($this->cart_products)();
        } catch (Throwable $e) {
            return array();
        }
        return is_array($ids) ? array_values(array_map('intval', $ids)) : array();
    }

    /** @return false */
    private function refuse(string $message): bool {
        $this->last_message = $message;
        if (function_exists('wc_add_notice') && function_exists('WC') && WC()->session !== null) {
            wc_add_notice($message, 'error');
        }
        return false;
    }

    /** @return int[] the products of the WooCommerce cart, or of the order on the "pay for order" page */
    public static function wc_cart_products(): array {
        $ids = array();
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            $order = wc_get_order((int) get_query_var('order-pay'));
            if ($order instanceof WC_Order) {
                foreach ($order->get_items('line_item') as $item) {
                    if ($item instanceof WC_Order_Item_Product) {
                        $ids[] = (int) $item->get_product_id();
                    }
                }
            }
            return $ids;
        }
        if (function_exists('WC') && WC()->cart !== null) {
            foreach (WC()->cart->get_cart() as $line) {
                $ids[] = (int) ($line['product_id'] ?? 0);
            }
        }
        return $ids;
    }
}
