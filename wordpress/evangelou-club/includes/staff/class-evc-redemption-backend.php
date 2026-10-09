<?php
defined('ABSPATH') || exit;

/**
 * Supplies a fully wired redemption engine to the REST controller, or null
 * when the real dependencies are not available — in which case the
 * controller fails CLOSED (503 server_error) and grants nothing.
 */
interface EVC_Redemption_Backend {
    public function service(): ?EVC_Redemption_Service;
}

/**
 * Production backend for Task 1C-C: ALWAYS unavailable. There is no
 * production membership adapter (PMPro integration is a later task) and no
 * approved coffee catalogue, so no redemption may happen, whatever database
 * constants exist. Replacing this requires a reviewed PMPro adapter task.
 */
final class EVC_Unavailable_Redemption_Backend implements EVC_Redemption_Backend {
    public function service(): ?EVC_Redemption_Service {
        return null;
    }
}
