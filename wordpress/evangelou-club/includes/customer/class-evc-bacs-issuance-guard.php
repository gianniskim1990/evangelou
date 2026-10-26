<?php
defined('ABSPATH') || exit;

/**
 * STAGE-03B.3b (owner decision 2026-10-26, Option 2): a REQUEST-SCOPED guard
 * around ONE issuance of the Club level by PMPro WooCommerce 1.11 for ONE
 * armed customer, so that PMPro writes its own row with the Club's planned
 * dates and keeps the already paid row active until the new row exists.
 *
 * Verified vendor behaviour it relies on (PMPro 3.8.9, add-on 1.11):
 *  - pmprowoo_add_membership_from_order() builds $custom_level (user_id,
 *    membership_id, startdate as a single-quoted SQL literal, enddate as a
 *    plain 'Y-m-d H:i:s' string), applies `pmprowoo_checkout_level` (ONE
 *    argument; the add-on's own extension runs at priority 10) and calls
 *    pmpro_changeMembershipLevel() through the 3.x shim;
 *  - pmpro_changeMembershipLevel() fires `pmpro_before_change_membership_level`
 *    (level id, user id), then applies `pmpro_deactivate_old_levels` (NO
 *    arguments; default true: the old row is set `changed` BEFORE the insert),
 *    strips single quotes from both dates and INSERTs the new row; its own
 *    duplicate removal (`id != $wpdb->insert_id`) then moves the old row to
 *    `changed` AFTER the insert.
 *
 * While armed (and only then: the callbacks are registered by arm() and
 * removed by disarm(), which callers run in `finally`):
 *  - `pmprowoo_checkout_level` at PRIORITY (> 10, after the add-on's own
 *    extension) replaces startdate / enddate ONLY for the armed customer AND
 *    the armed level, in the add-on's own format;
 *  - `pmpro_deactivate_old_levels` answers false ONLY for the level change
 *    that follows such a replacement for that same customer and level
 *    (tracked through `pmpro_before_change_membership_level`), once.
 * Every callback only reads and returns values: NO database write happens
 * inside a PMPro / WooCommerce callback (a write there would move
 * $wpdb->insert_id, which PMPro's duplicate removal relies on).
 *
 * Unarmed — any completion outside EVC_Bacs_Payment_Issuer, e.g. a manual
 * "Completed" in the admin — nothing of this exists and PMPro / the add-on
 * behave exactly as shipped. Nothing in the plugin constructs this class
 * (the packager forbids it); it is never registered at load.
 */
final class EVC_Bacs_Issuance_Guard {
    /** After the add-on's own priority-10 `pmprowoo_checkout_level` callback. */
    const PRIORITY = 1000;
    const LOCAL_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D';

    /** @var bool */
    private $armed = false;
    /** @var int */
    private $user_id = 0;
    /** @var int */
    private $level_id = 0;
    /** @var string */
    private $startdate = '';
    /** @var string */
    private $enddate = '';
    /** @var bool the armed customer's level just passed through checkout_level() */
    private $matched = false;
    /** @var int[]|null [user id, level id] of the PMPro level change in progress */
    private $changing = null;
    /** @var int how often the dates were replaced while armed (diagnostics only, never evidence) */
    private $applied = 0;

    /**
     * @param string $start_local PMPro site-local start ('Y-m-d H:i:s', see EVC_Pmpro_Local_Time::exact)
     * @param string $end_local   PMPro site-local end
     */
    public function arm(int $user_id, int $level_id, string $start_local, string $end_local): void {
        if ($this->armed) {
            throw new LogicException('The issuance guard is already armed.');
        }
        if ($user_id < 1 || $level_id < 1 || !preg_match(self::LOCAL_PATTERN, $start_local) || !preg_match(self::LOCAL_PATTERN, $end_local) || $end_local <= $start_local) {
            throw new InvalidArgumentException('Invalid issuance guard request.');
        }
        $this->user_id = $user_id;
        $this->level_id = $level_id;
        $this->startdate = $start_local;
        $this->enddate = $end_local;
        $this->matched = false;
        $this->changing = null;
        $this->applied = 0;
        $this->armed = true;
        add_filter('pmprowoo_checkout_level', array($this, 'checkout_level'), self::PRIORITY);
        add_action('pmpro_before_change_membership_level', array($this, 'before_change'), self::PRIORITY, 2);
        add_filter('pmpro_deactivate_old_levels', array($this, 'deactivate_old_levels'), self::PRIORITY);
    }

    /** Always safe to call (also when not armed); callers run it in `finally`. */
    public function disarm(): void {
        remove_filter('pmprowoo_checkout_level', array($this, 'checkout_level'), self::PRIORITY);
        remove_action('pmpro_before_change_membership_level', array($this, 'before_change'), self::PRIORITY);
        remove_filter('pmpro_deactivate_old_levels', array($this, 'deactivate_old_levels'), self::PRIORITY);
        $this->armed = false;
        $this->matched = false;
        $this->changing = null;
    }

    public function is_armed(): bool {
        return $this->armed;
    }

    public function applied(): int {
        return $this->applied;
    }

    /**
     * @param mixed $level the add-on's $custom_level
     * @return mixed
     */
    public function checkout_level($level) {
        if (!$this->armed || !is_array($level) || !isset($level['user_id'], $level['membership_id'])
            || (int) $level['user_id'] !== $this->user_id || (int) $level['membership_id'] !== $this->level_id) {
            return $level;
        }
        // Same shapes the add-on itself passes: start as a quoted SQL literal, end plain.
        $level['startdate'] = "'" . $this->startdate . "'";
        $level['enddate'] = $this->enddate;
        $this->matched = true;
        $this->applied++;
        return $level;
    }

    /** @param mixed $level_id @param mixed $user_id */
    public function before_change($level_id, $user_id = null): void {
        $this->changing = $this->armed ? array((int) $user_id, (int) $level_id) : null;
    }

    /**
     * @param mixed $deactivate
     * @return mixed
     */
    public function deactivate_old_levels($deactivate) {
        if ($this->armed && $this->matched && $this->changing === array($this->user_id, $this->level_id)) {
            $this->matched = false;
            return false;
        }
        return $deactivate;
    }
}
