<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * One PMPro membership row (pmpro_memberships_users) as a fact. Dates are the
 * stored SITE-LOCAL wall-clock strings ("Y-m-d H:i:s"), untouched: PMPro
 * stores current_time() values without a zone, and only the mapper may turn
 * them into instants (Europe/Athens, conservative DST rule).
 */
final class EVC_Pmpro_Membership_Row {
    /** @var string opaque 64-hex reference (HMAC of the row id) */
    public $row_ref;
    /** @var int PMPro membership level id */
    public $level_id;
    /** @var string raw PMPro status: active, expired, cancelled, admin_cancelled, changed, admin_changed, inactive */
    public $status;
    /** @var string|null */
    public $startdate_local;
    /** @var string|null */
    public $enddate_local;
    /** @var bool a cancellation was requested but its effective time is not known */
    public $cancellation_pending;

    public function __construct(string $row_ref, int $level_id, string $status, ?string $startdate_local, ?string $enddate_local, bool $cancellation_pending = false) {
        $this->row_ref = $row_ref;
        $this->level_id = $level_id;
        $this->status = $status;
        $this->startdate_local = $startdate_local;
        $this->enddate_local = $enddate_local;
        $this->cancellation_pending = $cancellation_pending;
    }
}
