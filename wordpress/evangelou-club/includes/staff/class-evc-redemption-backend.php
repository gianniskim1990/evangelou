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
 * The DEFAULT backend: ALWAYS unavailable, so no redemption may happen,
 * whatever database constants exist. STAGE-05A: EVC_Staff_Plugin uses the
 * real EVC_Club_Redemption_Backend instead ONLY when wp-config.php defines
 * EVC_CLUB_REDEMPTION_SOURCE === 'club_db_v1' (default OFF).
 */
final class EVC_Unavailable_Redemption_Backend implements EVC_Redemption_Backend {
    public function service(): ?EVC_Redemption_Service {
        return null;
    }
}
