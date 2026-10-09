<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Raw redemption input. Values are kept exactly as supplied (possibly of the
 * wrong type) and validated by EVC_Redemption_Service, so malformed input
 * becomes an invalid_request result rather than a PHP type error.
 * staff_wp_user_id must come from the authenticated server-side session,
 * never from a client body (REST wiring is a later task).
 */
final class EVC_Redemption_Request {
    /** @var mixed */
    private $member_public_id;
    /** @var mixed */
    private $benefit_type;
    /** @var mixed */
    private $coffee_code;
    /** @var mixed */
    private $request_id;
    /** @var mixed */
    private $staff_wp_user_id;

    public function __construct($member_public_id, $benefit_type, $coffee_code, $request_id, $staff_wp_user_id) {
        $this->member_public_id = $member_public_id;
        $this->benefit_type = $benefit_type;
        $this->coffee_code = $coffee_code;
        $this->request_id = $request_id;
        $this->staff_wp_user_id = $staff_wp_user_id;
    }

    public function member_public_id() {
        return $this->member_public_id;
    }

    public function benefit_type() {
        return $this->benefit_type;
    }

    public function coffee_code() {
        return $this->coffee_code;
    }

    public function request_id() {
        return $this->request_id;
    }

    public function staff_wp_user_id() {
        return $this->staff_wp_user_id;
    }
}
