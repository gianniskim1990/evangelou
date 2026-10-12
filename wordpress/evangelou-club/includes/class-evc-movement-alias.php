<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * One VERIFIED identifier under which a source system knows a money movement
 * (Task 1D-E). Opaque: `ref` is a 64-hex value (e.g. EVC_Evidence_Ref::hmac()
 * keyed by a future trusted adapter); it is not reversible and is only ever
 * compared for equality.
 *
 * ORDER namespaces name the commercial order a payment settles. Every
 * movement must carry exactly one of them (its "order anchor"); gateway
 * namespaces are additional aliases. See EVC_Provenance_Recorder.
 */
final class EVC_Movement_Alias {
    const NS_WC_ORDER = 'wc_order';
    const NS_PMPRO_ORDER = 'pmpro_order';
    const NS_MANUAL_RECEIPT = 'manual_receipt';
    const NS_VIVA_TRANSACTION = 'viva_transaction';
    const NS_PAYPAL_TRANSACTION = 'paypal_transaction';

    const REF_PATTERN = '/^[0-9a-f]{64}$/D';

    /** @var string */
    private $namespace;
    /** @var string */
    private $ref;

    public function __construct(string $namespace, string $ref) {
        if (!in_array($namespace, self::namespaces(), true)) {
            throw new InvalidArgumentException('Unknown alias namespace.');
        }
        if (!preg_match(self::REF_PATTERN, $ref)) {
            throw new InvalidArgumentException('Alias reference must be an opaque 64-hex value.');
        }
        $this->namespace = $namespace;
        $this->ref = $ref;
    }

    /** @return string[] */
    public static function namespaces(): array {
        return array(self::NS_WC_ORDER, self::NS_PMPRO_ORDER, self::NS_MANUAL_RECEIPT, self::NS_VIVA_TRANSACTION, self::NS_PAYPAL_TRANSACTION);
    }

    /** @return string[] namespaces that identify the settled ORDER */
    public static function order_namespaces(): array {
        return array(self::NS_WC_ORDER, self::NS_PMPRO_ORDER, self::NS_MANUAL_RECEIPT);
    }

    public function is_order_anchor(): bool {
        return in_array($this->namespace, self::order_namespaces(), true);
    }

    public function namespace_name(): string {
        return $this->namespace;
    }

    public function ref(): string {
        return $this->ref;
    }

    /** Store key: namespace + ref (one key maps to at most one movement). */
    public function key(): string {
        return $this->namespace . ':' . $this->ref;
    }
}
