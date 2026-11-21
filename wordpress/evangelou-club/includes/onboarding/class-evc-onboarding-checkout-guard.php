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
 *
 * STAGE-08 round 11 (review): two checkouts of the same account sent at the same moment (two phones, a double tap)
 * could both pass the "no open Club order" check before either order existed. A submitted Club checkout of a
 * signed-in customer (the classic checkout's validation, or the Store API's POST /checkout) now first takes a
 * per-account lock of the WordPress database server, MySQL / MariaDB GET_LOCK (atomic, no table, no migration),
 * and only then reads the open orders. The lock is held until the end of the request (released at shutdown, and by
 * the server anyway when the connection closes), i.e. until the first order has been written, so the second
 * checkout either waits for it and then sees that order (DUPLICATE), or gives up after LOCK_WAIT_SECONDS (BUSY).
 * An atomic add_option() was not used: WordPress writes options with INSERT … ON DUPLICATE KEY UPDATE, so two
 * add_option() calls racing on a missing option can both return true. If the database server cannot take the lock
 * (GET_LOCK answers NULL, e.g. a cluster without named locks) the check runs as before round 11: a known,
 * documented risk (docs/go-live.md K5), never a refused checkout.
 */
final class EVC_Onboarding_Checkout_Guard {
    const GATEWAY = 'bacs';
    const MIXED = 'Η συνδρομή του Evangelou Club αγοράζεται μόνη της. Αφαιρέστε τα άλλα προϊόντα από το καλάθι και δοκιμάστε ξανά.';
    const DUPLICATE = 'Υπάρχει ήδη συνδρομή ή παραγγελία συνδρομής σε αναμονή πληρωμής. Δείτε τον λογαριασμό μέλους σας.';
    const ACCOUNT = 'Για τη συνδρομή του Club χρειάζεται λογαριασμός. Συνδεθείτε ή δημιουργήστε λογαριασμό.';
    const NO_BACS = 'Η συνδρομή του Club πληρώνεται μόνο με τραπεζική μεταφορά, που δεν είναι διαθέσιμη αυτή τη στιγμή. Ρωτήστε στο κατάστημα.';
    const BUSY = 'Μια άλλη πληρωμή συνδρομής για τον λογαριασμό σας είναι σε εξέλιξη. Περιμένετε λίγα δευτερόλεπτα και δοκιμάστε ξανά.';
    /** How long a second checkout of the same account waits for the first one (seconds). */
    const LOCK_WAIT_SECONDS = 10;

    /** @var EVC_Onboarding_Handoff */
    private $handoff;
    /** @var callable(): int[] */
    private $cart_products;
    /** @var string|null the last refusal (tests; customers see it as a WooCommerce notice / checkout error) */
    public $last_message;
    /** @var int[]|null the products of the order paid through the Store API in this request (null = the cart) */
    private $paying;
    /** @var bool this REST request is a Store API checkout submission (POST /wc/store/v1/checkout) */
    private $submitting = false;
    /** @var callable(int): ?bool */
    private $lock;
    /** @var int the account whose checkout lock this request holds (0 = none) */
    private $locked_for = 0;

    /**
     * @param callable(): int[]|null $cart_products TEST seam; default: the product ids of the WooCommerce cart / order being paid
     * @param callable(int): ?bool|null $lock TEST seam; default: checkout_lock() (true = held, false = busy, null = cannot lock)
     */
    public function __construct(EVC_Onboarding_Handoff $handoff, ?callable $cart_products = null, ?callable $lock = null) {
        EVC_Onboarding_Feature::assert_constructible(__CLASS__);
        $this->handoff = $handoff;
        $this->cart_products = $cart_products ?? array(__CLASS__, 'wc_cart_products');
        $this->lock = $lock ?? static function (int $user_id): ?bool {
            return self::checkout_lock($user_id);
        };
    }

    /** The per-account lock name: this site's database and table prefix (staging clones share a server), and the account. */
    public static function lock_name(int $user_id): string {
        global $wpdb;
        $site = (defined('DB_NAME') ? (string) DB_NAME : '') . '|' . (string) $wpdb->prefix;
        return 'evc_club_checkout_' . substr(hash('sha256', $site), 0, 16) . '_' . $user_id;
    }

    /**
     * Round 11: GET_LOCK on the WordPress database connection, waiting at most LOCK_WAIT_SECONDS. True = held by this
     * request until it ends; false = another checkout of this account still holds it; null = the server cannot lock.
     */
    public static function checkout_lock(int $user_id, int $wait_seconds = self::LOCK_WAIT_SECONDS): ?bool {
        global $wpdb;
        $name = self::lock_name($user_id);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $wait_seconds));
        if ((string) $got === '1') {
            register_shutdown_function(array(__CLASS__, 'release_lock'), $name);
            return true;
        }
        return (string) $got === '0' ? false : null;
    }

    /** At the end of the request (the order is written by then); the server also drops it when the connection closes. */
    public static function release_lock(string $name): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
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
        $this->cart_rules($errors, true);
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
            // Round 11: the lock only for the submission itself, never for a cart read.
            $this->cart_rules($errors, $this->submitting);
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
        $this->submitting = $request instanceof WP_REST_Request && $request->get_method() === 'POST' && preg_match('#^/wc/store(?:/v\d+)?/checkout$#D', (string) $request->get_route()) === 1;
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
        $this->submitting = false;
        return $response;
    }

    /**
     * The rules of a cart holding the Club product, shared by both checkouts. $submitting (round 11): this is the
     * checkout submission, so the account's checkout lock is taken BEFORE the open orders are read.
     */
    private function cart_rules(WP_Error $errors, bool $submitting = false): void {
        $club = (int) $this->handoff->club_product_id();
        if (array_diff($this->cart(), array($club)) !== array()) {
            $this->last_message = self::MIXED;
            $errors->add('evc_club_mixed_cart', self::MIXED);
        }
        if (!is_user_logged_in()) {
            return;
        }
        $user = get_current_user_id();
        if ($submitting && $this->hold_lock($user) === false) {
            $this->last_message = self::BUSY;
            $errors->add('evc_club_checkout_busy', self::BUSY);
            return;
        }
        if ($this->handoff->blocks_new_order($user)) {
            $this->last_message = self::DUPLICATE;
            $errors->add('evc_club_duplicate_order', self::DUPLICATE);
        }
    }

    /** Once per request and account (GET_LOCK would count every call again). */
    private function hold_lock(int $user_id): ?bool {
        if ($this->locked_for === $user_id) {
            return true;
        }
        try {
            $held = ($this->lock)($user_id);
        } catch (Throwable $e) {
            $held = null;
        }
        if ($held === true) {
            $this->locked_for = $user_id;
        }
        return $held === true || $held === false ? $held : null;
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
