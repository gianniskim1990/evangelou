<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/** Production clock: the server's real time, always expressed in UTC. */
final class EVC_System_Clock implements EVC_Clock_Source {
    public function now(): DateTimeImmutable {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
