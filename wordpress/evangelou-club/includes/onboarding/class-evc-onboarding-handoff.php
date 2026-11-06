<?php
defined('ABSPATH') || exit;
/**
 * STAGE-07: where a signed-in customer goes next in the join flow, and the hand-off to the EXISTING WooCommerce
 * checkout for the EXISTING Club product (nothing new about payments: the order is created by WooCommerce's own
 * checkout, paid by bank transfer, and stays pending until the shop confirms it through the STAGE-03D path).
 * Constructed only by EVC_Onboarding_Plugin::wire(); the ONLY shipped file that builds an add-to-cart URL.
 *
 * Never a second order: a customer who already has an open Club order (pending / on-hold / processing), or whose
 * membership reads active, pending payment or unverified (a PMPro membership the Club cannot verify yet), goes to
 * the member area instead. If the membership cannot be read, the answer is "unavailable", never a checkout.
 */
final class EVC_Onboarding_Handoff {
    const NEXT_MEMBER = 'member';
    const NEXT_CHECKOUT = 'checkout';
    const NEXT_TERMS = 'terms';
    const NEXT_UNAVAILABLE = 'unavailable';
    const OPEN_STATUSES = array('pending', 'on-hold', 'processing');
    const TERMS_META = '_evc_club_terms';
    /** States that already have (or are about to have) a membership: no new order from the join flow. */
    const MEMBER_STATES = array(EVC_Customer_Membership::ACTIVE, EVC_Customer_Membership::PENDING_PAYMENT, EVC_Customer_Membership::UNVERIFIED);

    /** @var EVC_Commerce_Source_Config|null */
    private $config;
    /** @var EVC_Membership_Adapter */
    private $membership;
    /** @var EVC_Clock_Source */
    private $clock;

    public function __construct(?EVC_Commerce_Source_Config $config, EVC_Membership_Adapter $membership, EVC_Clock_Source $clock) {
        EVC_Onboarding_Feature::assert_constructible(__CLASS__);
        $this->config = $config;
        $this->membership = $membership;
        $this->clock = $clock;
    }

    public static function terms_accepted(int $user_id): bool {
        $meta = get_user_meta($user_id, self::TERMS_META, true);
        return is_array($meta) && ($meta['version'] ?? null) === EVC_Onboarding_Rules::TERMS_VERSION;
    }

    /** Records the acceptance of the CURRENT Club terms (version + UTC instant; nothing else). */
    public static function accept_terms(int $user_id): bool {
        if ($user_id < 1) {
            return false;
        }
        update_user_meta($user_id, self::TERMS_META, array('version' => EVC_Onboarding_Rules::TERMS_VERSION, 'accepted_at' => gmdate('Y-m-d\TH:i:s\Z')));
        return self::terms_accepted($user_id);
    }

    /** member | terms | checkout | unavailable (an existing member never has to accept terms again here). */
    public function next_step(int $user_id): string {
        if ($this->config === null || $user_id < 1 || !function_exists('wc_get_orders') || !function_exists('wc_get_checkout_url')) {
            return self::NEXT_UNAVAILABLE;
        }
        try {
            if ($this->has_open_club_order($user_id)) {
                return self::NEXT_MEMBER;
            }
            $state = EVC_Customer_Membership::describe($this->membership->entitlement_for($user_id, $this->clock->now()), $this->clock->now())['state'];
        } catch (Throwable $e) {
            return self::NEXT_UNAVAILABLE;
        }
        if (in_array($state, self::MEMBER_STATES, true)) {
            return self::NEXT_MEMBER;
        }
        return self::terms_accepted($user_id) ? self::NEXT_CHECKOUT : self::NEXT_TERMS;
    }

    /** The WooCommerce checkout with the Club product in the cart (WooCommerce's own add-to-cart handling). */
    public function checkout_url(): string {
        if ($this->config === null) {
            throw new LogicException('No Club product configured.');
        }
        return add_query_arg('add-to-cart', (string) $this->config->product_id, wc_get_checkout_url());
    }

    /**
     * STAGE-07 round 2 (D15 / review 4): may WooCommerce take a NEW Club order from this customer? Not while a Club order
     * is open, and not while the membership reads pending payment or unverified. An ACTIVE member may (the approved
     * early renewal, D2), and so may an expired, cancelled or new customer. If anything cannot be read: no.
     */
    public function blocks_new_order(int $user_id): bool {
        if ($this->config === null || $user_id < 1 || !function_exists('wc_get_orders')) {
            return true;
        }
        try {
            if ($this->has_open_club_order($user_id)) {
                return true;
            }
            $state = EVC_Customer_Membership::describe($this->membership->entitlement_for($user_id, $this->clock->now()), $this->clock->now())['state'];
        } catch (Throwable $e) {
            return true;
        }
        return in_array($state, array(EVC_Customer_Membership::PENDING_PAYMENT, EVC_Customer_Membership::UNVERIFIED), true);
    }

    public function club_product_id(): ?int {
        return $this->config === null ? null : $this->config->product_id;
    }

    /** ALL the customer's open orders are read (round 2: no page limit, so an older open Club order is never missed). */
    private function has_open_club_order(int $user_id): bool {
        $ids = wc_get_orders(array('customer_id' => $user_id, 'status' => self::OPEN_STATUSES, 'type' => 'shop_order', 'limit' => -1, 'return' => 'ids'));
        if (!is_array($ids)) {
            throw new RuntimeException('Orders could not be read.');
        }
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            if (!$order instanceof WC_Order) {
                continue;
            }
            foreach ($order->get_items('line_item') as $item) {
                if ($item instanceof WC_Order_Item_Product && (int) $item->get_product_id() === $this->config->product_id) {
                    return true;
                }
            }
        }
        return false;
    }
}
