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
 *
 * Both checkouts (round 3, R1): the classic shortcode checkout (woocommerce_after_checkout_validation) AND the Block
 * Checkout / Store API (WooCommerce 11.2.1): woocommerce_store_api_cart_errors (mixed cart, second order; fired by
 * CartController::validate_cart() before the order exists) and woocommerce_store_api_checkout_update_order_from_request
 * (account required; POST only, throwing the Store API RouteException). Store API cart/add-item applies
 * woocommerce_add_to_cart_validation itself and turns our notice into its error, so the same filter covers it. The
 * gateway filter is the one both checkouts read.
 *
 * Paying an existing order (round 4, G1): the classic "pay for order" page reads the order (wc_cart_products). For
 * the Store API route POST /wc/store/v1/checkout/{id} (CheckoutOrder, WooCommerce 11.2.1), which checks the gateway
 * against get_available_payment_gateways() before any Store API action fires, the guard reads THAT order's items
 * for the whole request (rest_request_before_callbacks .. rest_request_after_callbacks), never the session cart.
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
    /** @var int[]|null the products of the order paid through the Store API in this request (null = the cart) */
    private $paying;

    /** @param callable(): int[]|null $cart_products TEST seam; default: the product ids of the WooCommerce cart / order being paid */
    public function __construct(EVC_Onboarding_Handoff $handoff, ?callable $cart_products = null) {
        EVC_Onboarding_Feature::assert_constructible(__CLASS__);
        $this->handoff = $handoff;
        $this->cart_products = $cart_products ?? array(__CLASS__, 'wc_cart_products');
    }

    public function register(): void {
        // Priority 5: before other validators, so the customer sees the Club's own reason (a later validator never
        // un-refuses; this one never overrides an earlier refusal either).
        add_filter('woocommerce_add_to_cart_validation', array($this, 'add_to_cart'), 5, 3);
        add_action('woocommerce_after_checkout_validation', array($this, 'checkout'), 20, 2);
        add_action('woocommerce_store_api_cart_errors', array($this, 'store_api_cart_errors'), 20, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'store_api_order'), 20, 2);
        add_filter('rest_request_before_callbacks', array($this, 'start_of_request'), 10, 3);
        add_filter('rest_request_after_callbacks', array($this, 'end_of_request'), 10, 1);
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
        if (!$this->club_in_cart()) {
            return;
        }
        $this->cart_rules($errors);
        if (!is_user_logged_in()) {
            $creating = is_array($data) && !empty($data['createaccount']);
            if (!$creating) {
                $this->last_message = self::ACCOUNT;
                $errors->add('evc_club_account_required', self::ACCOUNT);
            }
        }
    }

    /** woocommerce_store_api_cart_errors (Block Checkout): mixed cart and second order, before any order exists. */
    public function store_api_cart_errors($errors, $cart = null): void {
        if ($errors instanceof WP_Error && $this->club_in_cart()) {
            $this->cart_rules($errors);
        }
    }

    /**
     * woocommerce_store_api_checkout_update_order_from_request (Block Checkout, POST /checkout only): a guest Club
     * order needs the account that THIS checkout will create (registration enabled, and required or requested).
     *
     * @throws Exception the Store API RouteException (400) with the Greek reason
     */
    public function store_api_order($order, $request): void {
        if (!$request instanceof WP_REST_Request || $request->get_method() !== 'POST') {
            return;
        }
        if (self::is_pay_for_order($request) || is_user_logged_in() || !$this->club_in_cart()) {
            return;
        }
        $checkout = WC()->checkout();
        $creates = filter_var($checkout->is_registration_enabled(), FILTER_VALIDATE_BOOLEAN)
            && (filter_var($checkout->is_registration_required(), FILTER_VALIDATE_BOOLEAN) || filter_var($request['create_account'], FILTER_VALIDATE_BOOLEAN));
        if (!$creates) {
            $this->last_message = self::ACCOUNT;
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('evc_club_account_required', esc_html(self::ACCOUNT), 400);
        }
    }

    /** rest_request_before_callbacks: paying an existing order through the Store API reads that order's items. */
    public function start_of_request($response, $handler = null, $request = null) {
        $this->paying = null;
        if ($request instanceof WP_REST_Request && self::is_pay_for_order($request)) {
            $order = wc_get_order((int) $request['id']);
            $this->paying = $order instanceof WC_Order ? self::order_products($order) : array();
        }
        return $response;
    }

    private static function is_pay_for_order(WP_REST_Request $request): bool {
        return $request->get_method() === 'POST' && preg_match('#^/wc/store(?:/v\d+)?/checkout/\d+$#D', (string) $request->get_route()) === 1;
    }

    /** rest_request_after_callbacks: the order being paid applies to one request only. */
    public function end_of_request($response) {
        $this->paying = null;
        return $response;
    }

    /** The rules of a cart holding the Club product, shared by both checkouts. */
    private function cart_rules(WP_Error $errors): void {
        $club = (int) $this->handoff->club_product_id();
        if (array_diff($this->cart(), array($club)) !== array()) {
            $this->last_message = self::MIXED;
            $errors->add('evc_club_mixed_cart', self::MIXED);
        }
        if (is_user_logged_in() && $this->handoff->blocks_new_order(get_current_user_id())) {
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
        if ($this->paying !== null) {
            return $this->paying;
        }
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

    /** @return int[] */
    private static function order_products(WC_Order $order): array {
        $ids = array();
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $ids[] = (int) $item->get_product_id();
            }
        }
        return $ids;
    }

    /** @return int[] the products of the WooCommerce cart, or of the order on the "pay for order" page */
    public static function wc_cart_products(): array {
        $ids = array();
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            $order = wc_get_order((int) get_query_var('order-pay'));
            return $order instanceof WC_Order ? self::order_products($order) : array();
        }
        if (function_exists('WC') && WC()->cart !== null) {
            foreach (WC()->cart->get_cart() as $line) {
                $ids[] = (int) ($line['product_id'] ?? 0);
            }
        }
        return $ids;
    }
}
