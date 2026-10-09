<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Injected source of "now". The redemption service reads it exactly once per
 * request, so membership eligibility and the business date are decided
 * against the same instant.
 */
interface EVC_Clock_Source {
    /** Current instant in UTC. */
    public function now(): DateTimeImmutable;
}
