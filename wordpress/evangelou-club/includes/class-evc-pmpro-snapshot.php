<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Normalised facts about ONE WordPress user, as reported by an
 * EVC_Pmpro_Reader at one moment. Plain data holder: deliberately permissive
 * so that malformed source data reaches EVC_Pmpro_Entitlement_Mapper, which
 * judges it (fail closed) instead of crashing the request.
 */
final class EVC_Pmpro_Snapshot {
    /** Version of THIS normalised contract (not of PMPro). */
    const CONTRACT_VERSION = '1';

    /** @var string */
    public $contract_version;
    /** @var bool Is PMPro (and any required integration) loaded and readable? */
    public $source_available;
    /** @var string|null Installed PMPro version as reported by the plugin. */
    public $pmpro_version;
    /** @var string|null WordPress timezone_string (empty/offset = not named). */
    public $site_timezone;
    /** @var bool */
    public $user_exists;
    /** @var EVC_Pmpro_Membership_Row[] all PMPro membership rows of the user */
    public $memberships;
    /** @var EVC_Pmpro_Payment_Fact[] all payment facts the reader can attribute to the user */
    public $payments;
    /** @var string[] reader-detected disagreements (e.g. WooCommerce vs PMPro); any entry fails closed */
    public $source_conflicts;

    /**
     * @param EVC_Pmpro_Membership_Row[] $memberships
     * @param EVC_Pmpro_Payment_Fact[] $payments
     * @param string[] $source_conflicts
     */
    public function __construct(
        string $contract_version,
        bool $source_available,
        ?string $pmpro_version,
        ?string $site_timezone,
        bool $user_exists,
        array $memberships,
        array $payments,
        array $source_conflicts = array()
    ) {
        foreach ($memberships as $row) {
            if (!$row instanceof EVC_Pmpro_Membership_Row) {
                throw new InvalidArgumentException('memberships must be EVC_Pmpro_Membership_Row objects.');
            }
        }
        foreach ($payments as $payment) {
            if (!$payment instanceof EVC_Pmpro_Payment_Fact) {
                throw new InvalidArgumentException('payments must be EVC_Pmpro_Payment_Fact objects.');
            }
        }
        $this->contract_version = $contract_version;
        $this->source_available = $source_available;
        $this->pmpro_version = $pmpro_version;
        $this->site_timezone = $site_timezone;
        $this->user_exists = $user_exists;
        $this->memberships = array_values($memberships);
        $this->payments = array_values($payments);
        $this->source_conflicts = array_values($source_conflicts);
    }
}
