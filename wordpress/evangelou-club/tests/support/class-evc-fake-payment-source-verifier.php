<?php
/**
 * TEST-ONLY synthetic payment source verifier and identity fixtures
 * (Task 1D-I). Never shipped: the packager rejects these classes and any
 * shipped "implements EVC_Payment_Source_Verifier". No network, no real
 * provider, no real keys; every id and key below is fake.
 */
final class EVC_Fake_Payment_Source_Verifier implements EVC_Payment_Source_Verifier {
    /** @var array<string,array> */
    private $records = array();
    /** @var bool simulate provider outage */
    public $unavailable = false;
    /** @var EVC_Payment_Source_Ref|null answer every fetch with this ref's record (identity-mismatch test) */
    public $answer_with;
    /** @var int */
    public $calls = 0;

    /**
     * @param EVC_Payment_Source_Ref[] $related
     * @param EVC_Payment_Source_Ref[] $parents
     */
    public function put(EVC_Payment_Source_Ref $ref, string $state, int $amount_minor = 2000, array $related = array(), array $parents = array(), ?string $correction_kind = null, string $currency = 'EUR'): void {
        $this->records[self::key($ref)] = array($ref, $state, $correction_kind, $amount_minor, $currency, $related, $parents);
    }

    public function fetch(EVC_Payment_Source_Ref $ref): EVC_Provider_Record {
        $this->calls++;
        if ($this->unavailable) {
            throw new EVC_Payment_Source_Unavailable_Exception('synthetic outage');
        }
        $key = self::key($this->answer_with ?? $ref);
        if (!isset($this->records[$key])) {
            throw new EVC_Payment_Source_Unavailable_Exception('synthetic record not found');
        }
        list($r, $state, $kind, $amount, $currency, $related, $parents) = $this->records[$key];
        return new EVC_Provider_Record($this, $r, $state, $kind, $amount, $currency, $related, $parents);
    }

    private static function key(EVC_Payment_Source_Ref $ref): string {
        return EVC_Payment_Identity::encode(array($ref->provider(), $ref->merchant_scope(), $ref->environment(), $ref->kind(), $ref->id()));
    }
}

final class EVC_Payment_Identity_Fixture {
    const PAYPAL_MERCHANT = 'PPMERCHANTA01';
    const PAYPAL_MERCHANT_B = 'PPMERCHANTB02';
    const VIVA_MERCHANT = 'viva-merchant-1';
    const SITE = 'club-site-1';
    const CLUB = 'club-receipts';

    /** Deterministic FAKE 32-byte secrets (test only). */
    public static function secret(int $version, string $env = 'live'): string {
        return hash('sha256', 'evc-fake-test-secret/' . $env . '/v' . $version, true);
    }

    /** @param int[] $versions @param int[]|null $issued */
    public static function ring(string $env = 'live', array $versions = array(1), int $identity = 1, int $alias = 1, ?array $issued = null): EVC_Payment_Key_Ring {
        $secrets = array();
        foreach ($versions as $v) {
            $secrets[$v] = self::secret($v, $env);
        }
        return new EVC_Payment_Key_Ring($env, $secrets, $identity, $alias, $issued ?? array($identity));
    }

    public static function identity(?EVC_Payment_Key_Ring $ring = null): EVC_Payment_Identity {
        return new EVC_Payment_Identity($ring ?? self::ring());
    }

    public static function gate(EVC_Payment_Source_Verifier $verifier, string $env = 'live'): EVC_Payment_Evidence_Gate {
        return new EVC_Payment_Evidence_Gate($verifier, $env, array(
            EVC_Payment_Source_Ref::PROVIDER_PAYPAL => array(self::PAYPAL_MERCHANT, self::PAYPAL_MERCHANT_B),
            EVC_Payment_Source_Ref::PROVIDER_VIVA => array(self::VIVA_MERCHANT),
            EVC_Payment_Source_Ref::PROVIDER_WOOCOMMERCE => array(self::SITE),
            EVC_Payment_Source_Ref::PROVIDER_PMPRO => array(self::SITE),
            EVC_Payment_Source_Ref::PROVIDER_MANUAL => array(self::CLUB),
        ));
    }

    public static function paypal_capture(string $id, string $merchant = self::PAYPAL_MERCHANT, string $env = 'live'): EVC_Payment_Source_Ref {
        return new EVC_Payment_Source_Ref('paypal', $merchant, $env, 'capture', $id);
    }

    public static function paypal_refund(string $id, string $merchant = self::PAYPAL_MERCHANT, string $env = 'live'): EVC_Payment_Source_Ref {
        return new EVC_Payment_Source_Ref('paypal', $merchant, $env, 'refund', $id);
    }

    public static function viva_capture(string $id, string $env = 'live'): EVC_Payment_Source_Ref {
        return new EVC_Payment_Source_Ref('viva', self::VIVA_MERCHANT, $env, 'capture', $id);
    }

    public static function viva_refund(string $id, string $env = 'live'): EVC_Payment_Source_Ref {
        return new EVC_Payment_Source_Ref('viva', self::VIVA_MERCHANT, $env, 'refund', $id);
    }

    public static function wc_order(string $id, string $env = 'live'): EVC_Payment_Source_Ref {
        return new EVC_Payment_Source_Ref('woocommerce', self::SITE, $env, 'commerce_order', $id);
    }

    public static function receipt(string $id): EVC_Payment_Source_Ref {
        return new EVC_Payment_Source_Ref('manual', self::CLUB, 'live', 'receipt', $id);
    }
}
