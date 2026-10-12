<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Canonical identity and verified alias resolution (Task 1D-I). Pure PHP on
 * top of the read API of EVC_Provenance_Store; it writes nothing and grants
 * nothing. NOT WIRED to any payment flow.
 *
 * It accepts only gate-issued evidence (L3 EVC_Verified_Payment, R
 * EVC_Verified_Refund) and maps it onto the EXISTING recorder contract
 * (EVC_Verified_Movement / EVC_Movement_Correction, migration 002), which
 * still applies every rule (amount, level, order anchor, chronology,
 * refund-first quarantine, CAS).
 *
 * Before attributing anything NEW it checks every retained identity version
 * and every retained alias version:
 *  - an existing movement under any identity version is reused (never a
 *    second id after an identity-key change);
 *  - two identity versions both recorded -> conflict;
 *  - an alias (order anchor or capture alias) owned by another movement ->
 *    conflict (this is also how a second capture on an already-anchored
 *    order fails closed: multi-capture per order is NOT supported);
 *  - an identity version that was issued but is no longer available ->
 *    unresolved: no new id is minted, because the payment might already
 *    exist under the missing version.
 */
final class EVC_Payment_Identity_Resolver {
    /** @var EVC_Payment_Identity */
    private $identity;
    /** @var EVC_Provenance_Store */
    private $store;

    public function __construct(EVC_Payment_Identity $identity, EVC_Provenance_Store $store) {
        $this->identity = $identity;
        $this->store = $store;
    }

    public function resolve_payment(EVC_Verified_Payment $payment): EVC_Payment_Identity_Resolution {
        try {
            $refs = array_merge($this->storable_orders($payment->related_refs()), array($payment->settlement_ref()));
            return $this->resolve_movement($payment->settlement_ref(), $refs);
        } catch (EVC_Provenance_Store_Exception $e) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNAVAILABLE, 'store_unavailable');
        } catch (EVC_Payment_Key_Unavailable_Exception $e) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNRESOLVED, 'key_unavailable');
        }
    }

    /**
     * The refund resolves through its VERIFIED parent capture to the same
     * canonical movement id the payment has (or will have), so a refund seen
     * first keeps refund-first quarantine on the right id.
     */
    public function resolve_refund(EVC_Verified_Refund $refund): EVC_Payment_Identity_Resolution {
        try {
            $movement = $this->resolve_movement($refund->parent_settlement_ref(), array($refund->parent_settlement_ref()));
            if ($movement->status() !== EVC_Payment_Identity_Resolution::RESOLVED) {
                return $movement;
            }
            $found = array();
            foreach ($this->identity->correction_candidates($refund->refund_ref()) as $v => $id) {
                $existing = $this->store->correction($id);
                if ($existing !== null) {
                    $found[$v] = $existing;
                }
            }
            if (count($found) > 1) {
                return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::CONFLICT, 'identity_versions_disagree');
            }
            if ($found !== array()) {
                $existing = reset($found);
                if ($existing->movement_id() !== $movement->movement_id() || $existing->kind() !== $refund->kind()) {
                    return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::CONFLICT, 'refund_facts_changed');
                }
                return EVC_Payment_Identity_Resolution::resolved($movement->movement_id(), $movement->alias_version(), $movement->existing(), $existing->correction_id(), true);
            }
            if (!$this->identity->ring()->identity_history_complete()) {
                return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNRESOLVED, 'identity_history_incomplete');
            }
            $id = $this->identity->correction_id($refund->refund_ref(), $this->identity->ring()->identity_version());
            return EVC_Payment_Identity_Resolution::resolved($movement->movement_id(), $movement->alias_version(), $movement->existing(), $id, false);
        } catch (EVC_Provenance_Store_Exception $e) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNAVAILABLE, 'store_unavailable');
        } catch (EVC_Payment_Key_Unavailable_Exception $e) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNRESOLVED, 'key_unavailable');
        }
    }

    /** Which recorded movement owns this (storable) source identifier, across retained alias versions. */
    public function resolve_alias(EVC_Payment_Source_Ref $ref): EVC_Payment_Identity_Resolution {
        if (EVC_Payment_Identity::namespace_for($ref) === null) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNRESOLVED, 'namespace_not_in_migration_002');
        }
        try {
            $owners = $this->owners($ref);
        } catch (EVC_Provenance_Store_Exception $e) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNAVAILABLE, 'store_unavailable');
        }
        $distinct = array_values(array_unique($owners));
        if (count($distinct) > 1) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::CONFLICT, 'alias_owned_by_two_movements');
        }
        if ($distinct === array()) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNRESOLVED, 'unknown_alias');
        }
        return EVC_Payment_Identity_Resolution::resolved($distinct[0], (int) array_keys($owners)[0], true, null, false);
    }

    /**
     * Existing-contract movement for a RESOLVED payment, or null when it has
     * no single migration-002 order anchor (the recorder would refuse it too).
     * $verified_at_utc stays an explicit input: owner decision O1 is open.
     */
    public function build_movement(EVC_Verified_Payment $payment, EVC_Payment_Identity_Resolution $res, int $wp_user_id, int $level_id, string $pmpro_row_ref, DateTimeImmutable $verified_at_utc): ?EVC_Verified_Movement {
        $settlement = $payment->settlement_ref();
        if ($res->status() !== EVC_Payment_Identity_Resolution::RESOLVED || !in_array($res->movement_id(), $this->identity->movement_candidates($settlement), true)) {
            throw new InvalidArgumentException('Resolution does not belong to this payment.');
        }
        $aliases = array();
        $anchor_ns = null;
        foreach (array_merge($this->storable_orders($payment->related_refs()), array($settlement)) as $ref) {
            $alias = $this->identity->movement_alias($ref, $res->alias_version());
            if ($alias === null) {
                continue;
            }
            $aliases[] = $alias;
            if ($alias->is_order_anchor()) {
                $anchor_ns = $anchor_ns === null ? $alias->namespace_name() : 'multiple';
            }
        }
        if ($anchor_ns === null || $anchor_ns === 'multiple') {
            return null;
        }
        $manual = $settlement->provider() === EVC_Payment_Source_Ref::PROVIDER_MANUAL;
        $kinds = array(
            EVC_Movement_Alias::NS_WC_ORDER => EVC_Payment_Evidence::KIND_WC_ORDER,
            EVC_Movement_Alias::NS_PMPRO_ORDER => EVC_Payment_Evidence::KIND_PMPRO_ORDER,
            EVC_Movement_Alias::NS_MANUAL_RECEIPT => EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION,
        );
        return new EVC_Verified_Movement(
            $res->movement_id(),
            $settlement->environment(),
            $wp_user_id,
            $level_id,
            $pmpro_row_ref,
            $verified_at_utc,
            $payment->amount_minor(),
            $payment->currency(),
            $aliases,
            $manual ? EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN : EVC_Verified_Movement::AUTHORITY_GATEWAY_API,
            $kinds[$anchor_ns],
            EVC_Verified_Movement::STATE_CONFIRMED
        );
    }

    /** Existing-contract correction for a RESOLVED refund. */
    public function build_correction(EVC_Verified_Refund $refund, EVC_Payment_Identity_Resolution $res, DateTimeImmutable $effective_at_utc): EVC_Movement_Correction {
        if ($res->status() !== EVC_Payment_Identity_Resolution::RESOLVED || $res->correction_id() === null
            || !in_array($res->correction_id(), $this->identity->correction_candidates($refund->refund_ref()), true)
            || !in_array($res->movement_id(), $this->identity->movement_candidates($refund->parent_settlement_ref()), true)) {
            throw new InvalidArgumentException('Resolution does not belong to this refund.');
        }
        $manual = $refund->refund_ref()->provider() === EVC_Payment_Source_Ref::PROVIDER_MANUAL;
        return new EVC_Movement_Correction(
            $res->correction_id(),
            $res->movement_id(),
            $refund->kind(),
            $manual ? EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN : EVC_Verified_Movement::AUTHORITY_GATEWAY_API,
            $effective_at_utc
        );
    }

    /**
     * @param EVC_Payment_Source_Ref[] $refs aliases to check: orders first, settlement last
     */
    private function resolve_movement(EVC_Payment_Source_Ref $settlement, array $refs): EVC_Payment_Identity_Resolution {
        $existing = array();
        foreach ($this->identity->movement_candidates($settlement) as $v => $id) {
            if ($this->store->movement($id) !== null) {
                $existing[$v] = $id;
            }
        }
        if (count($existing) > 1) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::CONFLICT, 'identity_versions_disagree');
        }
        $known = $existing === array() ? null : reset($existing);
        $alias_version = null;
        foreach ($refs as $ref) {
            foreach ($this->owners($ref) as $v => $owner) {
                if ($owner !== $known) {
                    return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::CONFLICT, 'alias_owned_by_other_movement');
                }
                if ($alias_version === null) {
                    $alias_version = $v; // reuse the version the stored aliases were written with
                }
            }
        }
        if ($known !== null) {
            return EVC_Payment_Identity_Resolution::resolved($known, $alias_version ?? $this->identity->ring()->alias_version(), true, null, false);
        }
        if (!$this->identity->ring()->identity_history_complete()) {
            return EVC_Payment_Identity_Resolution::refused(EVC_Payment_Identity_Resolution::UNRESOLVED, 'identity_history_incomplete');
        }
        $minted = $this->identity->movement_id($settlement, $this->identity->ring()->identity_version());
        return EVC_Payment_Identity_Resolution::resolved($minted, $this->identity->ring()->alias_version(), false, null, false);
    }

    /** @return array<int,string> alias version => owning movement id (only versions with an owner) */
    private function owners(EVC_Payment_Source_Ref $ref): array {
        $out = array();
        foreach ($this->identity->ring()->retained_versions() as $v) {
            $alias = $this->identity->movement_alias($ref, $v);
            if ($alias === null) {
                continue;
            }
            $owner = $this->store->movement_for_alias($alias->key());
            if ($owner !== null) {
                $out[$v] = $owner;
            }
        }
        return $out;
    }

    /**
     * @param EVC_Payment_Source_Ref[] $refs
     * @return EVC_Payment_Source_Ref[] related identifiers storable in migration 002 (commerce orders)
     */
    private function storable_orders(array $refs): array {
        return array_values(array_filter($refs, function (EVC_Payment_Source_Ref $r) {
            return EVC_Payment_Identity::namespace_for($r) !== null;
        }));
    }
}

/** Result of identity resolution. Only RESOLVED carries ids; it still grants nothing by itself. */
final class EVC_Payment_Identity_Resolution {
    const RESOLVED = 'resolved';
    const UNRESOLVED = 'unresolved';
    const CONFLICT = 'conflict';
    const UNAVAILABLE = 'unavailable';

    /** @var string */
    private $status;
    /** @var string|null */
    private $reason;
    /** @var string|null */
    private $movement_id;
    /** @var int|null */
    private $alias_version;
    /** @var bool */
    private $existing;
    /** @var string|null */
    private $correction_id;
    /** @var bool */
    private $correction_existing;

    private function __construct(string $status, ?string $reason, ?string $movement_id, ?int $alias_version, bool $existing, ?string $correction_id, bool $correction_existing) {
        $this->status = $status;
        $this->reason = $reason;
        $this->movement_id = $movement_id;
        $this->alias_version = $alias_version;
        $this->existing = $existing;
        $this->correction_id = $correction_id;
        $this->correction_existing = $correction_existing;
    }

    public static function resolved(string $movement_id, int $alias_version, bool $existing, ?string $correction_id, bool $correction_existing): self {
        return new self(self::RESOLVED, null, $movement_id, $alias_version, $existing, $correction_id, $correction_existing);
    }

    public static function refused(string $status, string $reason): self {
        if ($status === self::RESOLVED) {
            throw new InvalidArgumentException('A refusal cannot be resolved.');
        }
        return new self($status, $reason, null, null, false, null, false);
    }

    public function status(): string {
        return $this->status;
    }

    public function reason(): ?string {
        return $this->reason;
    }

    public function movement_id(): ?string {
        return $this->movement_id;
    }

    public function alias_version(): ?int {
        return $this->alias_version;
    }

    /** True when the movement is already recorded under one of the retained identity versions. */
    public function existing(): bool {
        return $this->existing;
    }

    public function correction_id(): ?string {
        return $this->correction_id;
    }

    /** True when the same refund was already recorded (idempotent repeat). */
    public function correction_existing(): bool {
        return $this->correction_existing;
    }
}
