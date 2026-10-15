<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-04I — versioned staff-role upgrade: PURE planner and dry-run validator.
 *
 * NOTHING here changes WordPress. There is no applier, no hook, no option
 * write and no activation step in shipped code (the packager rejects any
 * shipped call into the planner and any construction of an approval). The
 * shipped role stays EXACTLY version 1 (EVC_Staff_Role::staff_capabilities()).
 *
 * Version 2 is a PROPOSAL (UI-04H capability audit), NOT owner-approved. A
 * plan that adds any capability beyond the shipped version requires an
 * explicit EVC_Staff_Role_Upgrade_Approval whose capability set equals the
 * version definition exactly; none exists in shipped code.
 *
 * Snapshot (read from WordPress by a future, separately approved reader;
 * tests build it from disposable roles):
 *   staff_role         ?array<string,bool>  raw capabilities of evc_club_staff (null = missing)
 *   stored_version     ?int                 recorded applied version (null = never versioned)
 *   roles              array<string,array<string,bool>>  EVERY role (incl. staff) => raw caps
 *   staff_users        list<{id:int, roles:string[], caps:array<string,bool>, super_admin:bool}>
 *   direct_club_grants list<{id:int, caps:string[]}>  any user holding a Club capability directly
 * Design and rollout: docs/staff-role-upgrade.md.
 */
final class EVC_Staff_Role_Versions {
    const ROLE = 'evc_club_staff';
    const VERSION_OPTION = 'evc_staff_role_version';
    /** The version the shipped code defines and accepts. */
    const SHIPPED = 1;

    const VERSIONS = array(
        1 => array('evc_lookup_member', 'evc_redeem_benefit', 'evc_view_redemption_history'),
        // PROPOSED (UI-04H audit) — NOT owner-approved; separate capabilities for least privilege.
        2 => array('evc_lookup_member', 'evc_redeem_benefit', 'evc_view_redemption_history', 'evc_confirm_handoff', 'evc_view_redemption_status', 'evc_view_usual_coffee'),
    );

    /** Never part of any staff role version. */
    const MANAGER_ONLY = array('evc_review_fulfillment', 'evc_manage_club');

    /** @return string[]|null sorted capabilities of a known version */
    public static function caps(int $version): ?array {
        if (!isset(self::VERSIONS[$version])) {
            return null;
        }
        $caps = self::VERSIONS[$version];
        sort($caps);
        return $caps;
    }

    /** @return string[] every capability any staff role version may hold */
    public static function staff_union(): array {
        $all = array();
        foreach (self::VERSIONS as $caps) {
            $all = array_merge($all, $caps);
        }
        $all = array_values(array_unique($all));
        sort($all);
        return $all;
    }
}

/** An owner decision, recorded by reference. Never constructed in shipped code. */
final class EVC_Staff_Role_Upgrade_Approval {
    const REFERENCE_PATTERN = '/^[A-Z][A-Z0-9-]{2,31}$/D';

    /** @var int */
    private $target_version;
    /** @var string[] */
    private $capabilities;
    /** @var string */
    private $reference;

    /** @param string[] $capabilities the exact capability set the owner approved */
    public function __construct(int $target_version, array $capabilities, string $reference) {
        if (!preg_match(self::REFERENCE_PATTERN, $reference)) {
            throw new InvalidArgumentException('Invalid approval reference.');
        }
        foreach ($capabilities as $cap) {
            if (!is_string($cap)) {
                throw new InvalidArgumentException('Invalid approval.');
            }
        }
        sort($capabilities);
        $this->target_version = $target_version;
        $this->capabilities = array_values($capabilities);
        $this->reference = $reference;
    }

    public function target_version(): int {
        return $this->target_version;
    }

    /** @return string[] */
    public function capabilities(): array {
        return $this->capabilities;
    }

    public function reference(): string {
        return $this->reference;
    }
}

final class EVC_Staff_Role_Upgrade_Plan {
    const READY = 'ready';
    const UP_TO_DATE = 'up_to_date';
    const REFUSED = 'refused';

    /** @var array */
    private $data;

    public function __construct(array $data) {
        $this->data = $data + array(
            'status' => self::REFUSED, 'reason' => null, 'from_state' => null, 'stored_version' => null, 'target_version' => null,
            'add' => array(), 'remove' => array(), 'set_version' => null, 'partial' => false, 'violations' => array(), 'other_roles_fingerprint' => null,
        );
    }

    public function status(): string {
        return $this->data['status'];
    }

    public function reason(): ?string {
        return $this->data['reason'];
    }

    /** @return array{status:string,reason:?string,from_state:?string,stored_version:?int,target_version:?int,add:string[],remove:string[],set_version:?int,partial:bool,violations:array,other_roles_fingerprint:?string} */
    public function to_array(): array {
        return $this->data;
    }
}

final class EVC_Staff_Role_Upgrade_Planner {
    const REFUSAL_UNKNOWN_TARGET = 'unknown_target_version';
    const REFUSAL_INVALID_SNAPSHOT = 'invalid_snapshot';
    const REFUSAL_ROLE_MISSING = 'role_missing';
    const REFUSAL_UNKNOWN_STORED = 'unknown_stored_version';
    const REFUSAL_DENIAL = 'role_capability_denial';
    const REFUSAL_ESCALATION = 'unexpected_role_capability';
    const REFUSAL_OTHER_ROLE = 'staff_capability_on_other_role';
    const REFUSAL_ACCOUNT = 'staff_account_violation';
    const REFUSAL_DIRECT = 'unauthorized_direct_capability';
    const REFUSAL_NOT_APPROVED = 'not_approved';

    /**
     * Dry run: what an upgrade (or rollback) to $target would change, or why
     * it must not run. Fails closed on ANY unexpected privilege; never strips
     * an unexpected capability silently (that needs a human investigation).
     */
    public static function plan(array $snapshot, int $target, ?EVC_Staff_Role_Upgrade_Approval $approval): EVC_Staff_Role_Upgrade_Plan {
        $target_caps = EVC_Staff_Role_Versions::caps($target);
        if ($target_caps === null) {
            return self::refuse(self::REFUSAL_UNKNOWN_TARGET);
        }
        if (!self::valid_snapshot($snapshot)) {
            return self::refuse(self::REFUSAL_INVALID_SNAPSHOT);
        }
        $role = $snapshot['staff_role'];
        if ($role === null) {
            return self::refuse(self::REFUSAL_ROLE_MISSING);
        }
        $stored = $snapshot['stored_version'];
        if ($stored !== null && EVC_Staff_Role_Versions::caps($stored) === null) {
            // E.g. recorded by a newer release: never downgrade it silently.
            return self::refuse(self::REFUSAL_UNKNOWN_STORED);
        }
        foreach ($role as $granted) {
            if ($granted !== true) {
                return self::refuse(self::REFUSAL_DENIAL);
            }
        }
        $current = array_keys($role);
        sort($current);
        $union = EVC_Staff_Role_Versions::staff_union();
        $unexpected = array_values(array_diff($current, $union));
        if ($unexpected) {
            return self::refuse(self::REFUSAL_ESCALATION, array('capabilities' => $unexpected));
        }
        $club = array_merge($union, EVC_Staff_Role_Versions::MANAGER_ONLY);
        foreach ($snapshot['roles'] as $name => $caps) {
            if ($name === EVC_Staff_Role_Versions::ROLE) {
                continue;
            }
            $leaked = array_values(array_intersect(array_keys($caps), $union));
            if ($leaked) {
                return self::refuse(self::REFUSAL_OTHER_ROLE, array('role' => (string) $name, 'capabilities' => $leaked));
            }
        }
        $bad_accounts = array();
        foreach ($snapshot['staff_users'] as $u) {
            if (array_values($u['roles']) !== array(EVC_Staff_Role_Versions::ROLE) || $u['caps'] !== array(EVC_Staff_Role_Versions::ROLE => true) || $u['super_admin'] !== false) {
                $bad_accounts[] = $u['id'];
            }
        }
        if ($bad_accounts) {
            return self::refuse(self::REFUSAL_ACCOUNT, array('user_ids' => $bad_accounts));
        }
        $direct = array();
        foreach ($snapshot['direct_club_grants'] as $g) {
            if (array_intersect($g['caps'], $club)) {
                $direct[] = $g['id'];
            }
        }
        if ($direct) {
            return self::refuse(self::REFUSAL_DIRECT, array('user_ids' => $direct));
        }

        $add = array_values(array_diff($target_caps, $current));
        $remove = array_values(array_diff($current, $target_caps));
        $shipped = (array) EVC_Staff_Role_Versions::caps(EVC_Staff_Role_Versions::SHIPPED);
        // Granting anything beyond the shipped definition needs the exact owner approval.
        if (array_diff($add, $shipped) && !self::approved($approval, $target, $target_caps)) {
            return self::refuse(self::REFUSAL_NOT_APPROVED);
        }
        $state = self::state_of($current);
        $partial = strpos($state, 'v') !== 0
            || ($stored !== null && EVC_Staff_Role_Versions::caps($stored) !== $current)
            || ($stored === null && $current !== $shipped);
        $up_to_date = !$add && !$remove && $stored === $target;
        return new EVC_Staff_Role_Upgrade_Plan(array(
            'status' => $up_to_date ? EVC_Staff_Role_Upgrade_Plan::UP_TO_DATE : EVC_Staff_Role_Upgrade_Plan::READY,
            'from_state' => $state,
            'stored_version' => $stored,
            'target_version' => $target,
            'add' => $add,
            'remove' => $remove,
            // Applied LAST, only after every capability change succeeded.
            'set_version' => $up_to_date ? null : $target,
            'partial' => $partial,
            'other_roles_fingerprint' => self::other_roles_fingerprint($snapshot['roles']),
        ));
    }

    /**
     * Post-check after an applier ran $plan: exact target role, recorded
     * version, every OTHER role byte-for-byte unchanged, accounts still
     * restricted, no direct grants. @return string[] failures (empty = verified)
     */
    public static function verify(array $after, EVC_Staff_Role_Upgrade_Plan $plan): array {
        $p = $plan->to_array();
        if ($p['status'] === EVC_Staff_Role_Upgrade_Plan::REFUSED) {
            return array('plan_was_refused');
        }
        if (!self::valid_snapshot($after) || $after['staff_role'] === null) {
            return array('invalid_snapshot');
        }
        $failures = array();
        $caps = array_keys(array_filter($after['staff_role'], function ($v) {
            return $v === true;
        }));
        sort($caps);
        if ($caps !== EVC_Staff_Role_Versions::caps($p['target_version']) || count($after['staff_role']) !== count($caps)) {
            $failures[] = 'role_capabilities_mismatch';
        }
        if ($after['stored_version'] !== $p['target_version']) {
            $failures[] = 'version_not_recorded';
        }
        if (self::other_roles_fingerprint($after['roles']) !== $p['other_roles_fingerprint']) {
            $failures[] = 'unrelated_roles_changed';
        }
        $check = self::plan($after, $p['target_version'], null);
        $codes = array(self::REFUSAL_ACCOUNT, self::REFUSAL_DIRECT, self::REFUSAL_OTHER_ROLE, self::REFUSAL_ESCALATION, self::REFUSAL_DENIAL);
        if ($check->status() === EVC_Staff_Role_Upgrade_Plan::REFUSED && in_array($check->reason(), $codes, true)) {
            $failures[] = 'unsafe_after_upgrade:' . $check->reason();
        }
        return $failures;
    }

    /** sha256 over every role EXCEPT the staff role (canonical key order). */
    public static function other_roles_fingerprint(array $roles): string {
        unset($roles[EVC_Staff_Role_Versions::ROLE]);
        ksort($roles);
        foreach ($roles as $name => $caps) {
            ksort($caps);
            $roles[$name] = $caps;
        }
        return hash('sha256', (string) json_encode($roles));
    }

    private static function approved(?EVC_Staff_Role_Upgrade_Approval $approval, int $target, array $target_caps): bool {
        return $approval !== null && $approval->target_version() === $target && $approval->capabilities() === $target_caps;
    }

    /** v<n> for an exact version, "partial" between versions, "damaged" if shipped capabilities are missing. */
    private static function state_of(array $current): string {
        foreach (array_keys(EVC_Staff_Role_Versions::VERSIONS) as $v) {
            if (EVC_Staff_Role_Versions::caps($v) === $current) {
                return 'v' . $v;
            }
        }
        $shipped = (array) EVC_Staff_Role_Versions::caps(EVC_Staff_Role_Versions::SHIPPED);
        return array_diff($shipped, $current) ? 'damaged' : 'partial';
    }

    private static function valid_snapshot(array $s): bool {
        if (!array_key_exists('staff_role', $s) || !array_key_exists('stored_version', $s) || !isset($s['roles'], $s['staff_users'], $s['direct_club_grants'])
            || !is_array($s['roles']) || !is_array($s['staff_users']) || !is_array($s['direct_club_grants'])
            || ($s['staff_role'] !== null && !is_array($s['staff_role'])) || ($s['stored_version'] !== null && !is_int($s['stored_version']))) {
            return false;
        }
        foreach ($s['roles'] as $caps) {
            if (!is_array($caps)) {
                return false;
            }
        }
        foreach ($s['staff_users'] as $u) {
            if (!is_array($u) || !isset($u['id'], $u['roles'], $u['caps']) || !array_key_exists('super_admin', $u) || !is_int($u['id']) || !is_array($u['roles']) || !is_array($u['caps'])) {
                return false;
            }
        }
        foreach ($s['direct_club_grants'] as $g) {
            if (!is_array($g) || !isset($g['id'], $g['caps']) || !is_array($g['caps'])) {
                return false;
            }
        }
        // The staff role inside "roles" must be the same data as staff_role.
        $in_roles = isset($s['roles'][EVC_Staff_Role_Versions::ROLE]) ? $s['roles'][EVC_Staff_Role_Versions::ROLE] : null;
        return $in_roles === $s['staff_role'];
    }

    private static function refuse(string $reason, array $violations = array()): EVC_Staff_Role_Upgrade_Plan {
        return new EVC_Staff_Role_Upgrade_Plan(array('status' => EVC_Staff_Role_Upgrade_Plan::REFUSED, 'reason' => $reason, 'violations' => $violations));
    }
}
