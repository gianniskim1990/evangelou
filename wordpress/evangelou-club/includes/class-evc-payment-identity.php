<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Deterministic opaque identifiers for payment provenance (Task 1D-I).
 * Pure computation: it does NOT verify that any payment happened. Callers
 * must only derive a movement id from a settlement anchor that a trusted
 * source adapter has verified (EVC_Payment_Evidence_Gate).
 *
 * Canonical movement id (64 hex):
 *   HMAC-SHA256( HKDF(secret[v], "evc/movement/v{v}/{env}"),
 *                LP("evc.payment.movement.v1") LP(provider) LP(merchant_scope)
 *                LP(environment) LP(kind) LP(raw settlement id) )
 * where LP(x) = 4-byte big-endian length || x. Length prefixes make the
 * encoding unambiguous ("ab"+"c" never equals "a"+"bc"). Provider, merchant
 * scope, environment and kind are inside the MAC, so overlapping raw ids of
 * two merchants, providers, environments or id kinds never collide.
 * Amounts, times, names, e-mails, phones and client order numbers are never
 * inputs. Alias refs, correction ids and PMPro row refs use the same
 * encoding with their own domain tag AND their own HKDF purpose key.
 *
 * The same verified capture yields the same id whichever source reported it
 * first (WooCommerce or the gateway), provided both resolve to that capture.
 */
final class EVC_Payment_Identity {
    const TAG_MOVEMENT = 'evc.payment.movement.v1';
    const TAG_ALIAS = 'evc.payment.alias.v1';
    const TAG_CORRECTION = 'evc.payment.correction.v1';
    const TAG_ROW = 'evc.pmpro.row.v1';

    /** @var EVC_Payment_Key_Ring */
    private $ring;

    public function __construct(EVC_Payment_Key_Ring $ring) {
        $this->ring = $ring;
    }

    public function ring(): EVC_Payment_Key_Ring {
        return $this->ring;
    }

    /** Unambiguous length-prefixed encoding. */
    public static function encode(array $fields): string {
        $out = '';
        foreach ($fields as $field) {
            $out .= pack('N', strlen($field)) . $field;
        }
        return $out;
    }

    /** Movement id under one identity version (callers normally use candidates/minting via the resolver). */
    public function movement_id(EVC_Payment_Source_Ref $settlement, int $version): string {
        $this->assert_settlement($settlement);
        return $this->ring->mac(EVC_Payment_Key_Ring::PURPOSE_MOVEMENT, $version, self::encode(array(
            self::TAG_MOVEMENT,
            $settlement->provider(),
            $settlement->merchant_scope(),
            $settlement->environment(),
            $settlement->kind(),
            $settlement->id(),
        )));
    }

    /** @return array<int,string> identity version => candidate movement id, for every available issued version */
    public function movement_candidates(EVC_Payment_Source_Ref $settlement): array {
        $out = array();
        foreach ($this->ring->available_identity_versions() as $v) {
            $out[$v] = $this->movement_id($settlement, $v);
        }
        return $out;
    }

    /** Correction id derived from the refund's OWN provider id (scoped), under one identity version. */
    public function correction_id(EVC_Payment_Source_Ref $refund, int $version): string {
        if ($refund->kind() !== EVC_Payment_Source_Ref::KIND_REFUND) {
            throw new InvalidArgumentException('Correction ids derive from refund identifiers only.');
        }
        $this->assert_environment($refund);
        return $this->ring->mac(EVC_Payment_Key_Ring::PURPOSE_CORRECTION, $version, self::encode(array(
            self::TAG_CORRECTION,
            $refund->provider(),
            $refund->merchant_scope(),
            $refund->environment(),
            $refund->kind(),
            $refund->id(),
        )));
    }

    /** @return array<int,string> identity version => candidate correction id */
    public function correction_candidates(EVC_Payment_Source_Ref $refund): array {
        $out = array();
        foreach ($this->ring->available_identity_versions() as $v) {
            $out[$v] = $this->correction_id($refund, $v);
        }
        return $out;
    }

    /** Opaque alias ref (64 hex) of any source identifier under one alias version. */
    public function alias_ref(EVC_Payment_Source_Ref $ref, int $version): string {
        $this->assert_environment($ref);
        return $this->ring->mac(EVC_Payment_Key_Ring::PURPOSE_ALIAS, $version, self::encode(array(
            self::TAG_ALIAS,
            $ref->provider(),
            $ref->merchant_scope(),
            $ref->environment(),
            $ref->kind(),
            $ref->id(),
        )));
    }

    /**
     * Migration-002 alias namespace for a source identifier, or null when
     * migration 002 cannot store it (provider orders, authorizations,
     * refunds: future migration 003).
     */
    public static function namespace_for(EVC_Payment_Source_Ref $ref): ?string {
        $map = array(
            EVC_Payment_Source_Ref::PROVIDER_WOOCOMMERCE . '/' . EVC_Payment_Source_Ref::KIND_COMMERCE_ORDER => EVC_Movement_Alias::NS_WC_ORDER,
            EVC_Payment_Source_Ref::PROVIDER_PMPRO . '/' . EVC_Payment_Source_Ref::KIND_COMMERCE_ORDER => EVC_Movement_Alias::NS_PMPRO_ORDER,
            EVC_Payment_Source_Ref::PROVIDER_MANUAL . '/' . EVC_Payment_Source_Ref::KIND_RECEIPT => EVC_Movement_Alias::NS_MANUAL_RECEIPT,
            EVC_Payment_Source_Ref::PROVIDER_VIVA . '/' . EVC_Payment_Source_Ref::KIND_CAPTURE => EVC_Movement_Alias::NS_VIVA_TRANSACTION,
            EVC_Payment_Source_Ref::PROVIDER_PAYPAL . '/' . EVC_Payment_Source_Ref::KIND_CAPTURE => EVC_Movement_Alias::NS_PAYPAL_TRANSACTION,
        );
        return $map[$ref->provider() . '/' . $ref->kind()] ?? null;
    }

    /** Migration-002 alias under one alias version, or null if not storable in 002. */
    public function movement_alias(EVC_Payment_Source_Ref $ref, int $version): ?EVC_Movement_Alias {
        $ns = self::namespace_for($ref);
        return $ns === null ? null : new EVC_Movement_Alias($ns, $this->alias_ref($ref, $version));
    }

    /** Opaque PMPro membership-row reference (site scoped) under one alias-lifecycle version. */
    public function row_ref(string $site_scope, string $raw_row_id, int $version): string {
        if (!preg_match(EVC_Payment_Source_Ref::TOKEN_PATTERN, $site_scope) || !preg_match(EVC_Payment_Source_Ref::TOKEN_PATTERN, $raw_row_id)) {
            throw new InvalidArgumentException('Row scope and id must be printable ASCII without spaces.');
        }
        return $this->ring->mac(EVC_Payment_Key_Ring::PURPOSE_ROW, $version, self::encode(array(
            self::TAG_ROW,
            $site_scope,
            $this->ring->environment(),
            $raw_row_id,
        )));
    }

    private function assert_settlement(EVC_Payment_Source_Ref $ref): void {
        if (!$ref->is_settlement_anchor()) {
            throw new InvalidArgumentException('Movement identity derives only from a settlement anchor (capture or receipt), never an order.');
        }
        $this->assert_environment($ref);
    }

    private function assert_environment(EVC_Payment_Source_Ref $ref): void {
        if ($ref->environment() !== $this->ring->environment()) {
            throw new InvalidArgumentException('Source identifier belongs to another environment than this key ring.');
        }
    }
}
