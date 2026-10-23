<?php
defined('ABSPATH') || exit;
/**
 * STAGE-02 configuration of the REAL membership source behind GET /me:
 * WooCommerce + Paid Memberships Pro + PMPro WooCommerce Add On, bank
 * transfer (BACS) only, manual monthly renewal.
 *
 * DISABLED BY DEFAULT. It exists only when wp-config.php (trusted server
 * configuration, never the database, a filter, an option or a request)
 * defines ALL of:
 *
 *   define('EVC_CLUB_MEMBERSHIP_SOURCE', 'pmpro_woocommerce_bacs_v1');
 *   define('EVC_CLUB_PMPRO_LEVEL_ID', 1);          // the Club membership level
 *   define('EVC_CLUB_WC_PRODUCT_ID', 24435);       // the €20 Club product
 *   define('EVC_CLUB_EVIDENCE_KEY', '<>= 32 random bytes>'); // HMAC key for opaque refs
 *
 * with exactly those types. Anything missing or malformed means NO source:
 * GET /me keeps answering 503 "unavailable" (EVC_Unavailable_Membership_Adapter).
 * The customer flag (EVC_CLUB_CUSTOMER_ENABLED) must also be on for /me to exist.
 *
 * The installed plugin versions are PINNED here, exactly as verified for
 * staging on 2026-10-23. Any other version makes the reader report a
 * conflict, so the membership is "unverified" until the code is re-verified
 * against the new version (fail closed).
 */
final class EVC_Commerce_Source_Config {
    const SOURCE_CONSTANT = 'EVC_CLUB_MEMBERSHIP_SOURCE';
    const SOURCE_VALUE = 'pmpro_woocommerce_bacs_v1';
    const LEVEL_CONSTANT = 'EVC_CLUB_PMPRO_LEVEL_ID';
    const PRODUCT_CONSTANT = 'EVC_CLUB_WC_PRODUCT_ID';
    const KEY_CONSTANT = 'EVC_CLUB_EVIDENCE_KEY';

    const WOOCOMMERCE_VERSION = '11.2.1';
    const PMPRO_VERSION = '3.8.9';
    /** PMPro accepted by EVC_Pmpro_Mapper_Config: [3.8.9, 3.8.10). */
    const PMPRO_VERSION_EXCLUSIVE_MAX = '3.8.10';
    const PMPRO_WOOCOMMERCE_VERSION = '1.11';

    /** Owner rules: €20 per paid month, sold individually, one month, no recurring billing. */
    const PRICE = '20.00';
    const CURRENCY = 'EUR';
    const PAYMENT_METHOD = 'bacs';

    /** @var int */
    public $level_id;
    /** @var int */
    public $product_id;
    /** @var string */
    private $evidence_key;

    private function __construct(int $level_id, int $product_id, string $evidence_key) {
        $this->level_id = $level_id;
        $this->product_id = $product_id;
        $this->evidence_key = $evidence_key;
    }

    /** The configured source, or null (= disabled; the default). */
    public static function from_constants(): ?self {
        if (!defined(self::SOURCE_CONSTANT) || !defined(self::LEVEL_CONSTANT) || !defined(self::PRODUCT_CONSTANT) || !defined(self::KEY_CONSTANT)) {
            return null;
        }
        return self::parse(constant(self::SOURCE_CONSTANT), constant(self::LEVEL_CONSTANT), constant(self::PRODUCT_CONSTANT), constant(self::KEY_CONSTANT));
    }

    /** Pure parser (tested directly): exact types only, no coercion. */
    public static function parse($source, $level_id, $product_id, $evidence_key): ?self {
        if ($source !== self::SOURCE_VALUE) {
            return null;
        }
        if (!is_int($level_id) || $level_id < 1 || !is_int($product_id) || $product_id < 1) {
            return null;
        }
        if (!is_string($evidence_key) || strlen($evidence_key) < EVC_Evidence_Ref::MIN_SECRET_BYTES) {
            return null;
        }
        return new self($level_id, $product_id, $evidence_key);
    }

    /** Opaque reference of a source identifier (never the raw id, never personal data). */
    public function ref(string $namespace, string $raw_identifier): string {
        return EVC_Evidence_Ref::hmac($this->evidence_key, $namespace, $raw_identifier);
    }

    public function mapper(): EVC_Pmpro_Entitlement_Mapper {
        return new EVC_Pmpro_Entitlement_Mapper(new EVC_Pmpro_Mapper_Config(array($this->level_id), self::PMPRO_VERSION, self::PMPRO_VERSION_EXCLUSIVE_MAX));
    }
}
