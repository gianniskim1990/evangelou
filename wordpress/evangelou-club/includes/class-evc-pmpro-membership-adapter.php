<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * EVC_Membership_Adapter backed by an EVC_Pmpro_Reader + the pure mapper
 * (Task 1D-B). NOT used by production: EVC_Unavailable_Redemption_Backend
 * still supplies no engine (503), and no concrete WordPress reader exists.
 *
 * A reader failure is rethrown as EVC_Membership_Source_Exception (no
 * underlying message), which the redemption engine turns into server_error:
 * a read error never grants a coffee.
 */
final class EVC_Pmpro_Membership_Adapter implements EVC_Membership_Adapter {
    /** @var EVC_Pmpro_Reader */
    private $reader;
    /** @var EVC_Pmpro_Entitlement_Mapper */
    private $mapper;

    public function __construct(EVC_Pmpro_Reader $reader, EVC_Pmpro_Entitlement_Mapper $mapper) {
        $this->reader = $reader;
        $this->mapper = $mapper;
    }

    public function entitlement_for(int $wp_user_id, DateTimeImmutable $at_utc): EVC_Entitlement {
        try {
            $snapshot = $this->reader->snapshot($wp_user_id);
        } catch (Throwable $e) {
            throw new EVC_Membership_Source_Exception('Membership source unavailable.');
        }
        return $this->mapper->map($snapshot, $at_utc);
    }
}
