<?php
defined('ABSPATH') || exit;
/**
 * STAGE-04A: «Evangelou Club — Βάση δεδομένων» under WordPress Tools, for administrators only
 * (manage_options, and never a Club staff account). Constructed ONLY by
 * EVC_Club_Migrations_Plugin::wire() behind EVC_CLUB_MIGRATIONS_ENABLED.
 *
 * GET shows the read-only status. Applying needs ALL of: a POST, the WordPress nonce
 * (check_admin_referer), the capability checked again, and the exact typed word
 * EVC_Club_Migrations_Service::CONFIRM_WORD. Every value printed is escaped; no host, user,
 * password or database name is ever printed (the name only as a masked hint).
 */
final class EVC_Club_Migrations_Admin_Page {
    const SLUG = 'evc-club-db';
    const TITLE = 'Evangelou Club — Βάση δεδομένων';
    const CAPABILITY = 'manage_options';
    const NONCE_ACTION = 'evc_club_migrations_apply';
    const FIELD_ACTION = 'evc_club_migrations_action';
    const FIELD_CONFIRM = 'evc_club_migrations_confirm';
    const ACTION_APPLY = 'apply';
    const NOT_ALLOWED = 'Δεν έχετε δικαίωμα για αυτή τη σελίδα.';
    const WRONG_WORD = 'Η λέξη επιβεβαίωσης δεν είναι σωστή. Δεν έγινε καμία αλλαγή.';

    const CHECK_LABELS = array(
        'config' => 'Στοιχεία σύνδεσης στο wp-config.php',
        'connection' => 'Σύνδεση με τη βάση του Club',
        'separate_from_wordpress' => 'Ξεχωριστή από τη βάση του WordPress',
        'no_wordpress_tables' => 'Χωρίς πίνακες του WordPress',
        'no_unknown_tables' => 'Χωρίς πίνακες που δεν ανήκουν στο Club',
        'club_tables_recorded' => 'Οι πίνακες του Club ταιριάζουν με τις καταγεγραμμένες μεταβάσεις',
        'no_drift' => 'Οι εφαρμοσμένες μεταβάσεις ταιριάζουν με τα αρχεία του plugin',
    );
    const CHECK_STATES = array('passed' => 'Εντάξει', 'failed' => 'ΑΠΟΤΥΧΙΑ', 'not_checked' => 'Δεν ελέγχθηκε');

    /** @var EVC_Club_Migrations_Service */
    private $service;

    public function __construct(EVC_Club_Migrations_Service $service) {
        EVC_Club_Migrations_Feature::assert_constructible(__CLASS__);
        $this->service = $service;
    }

    public function register(): void {
        add_action('admin_menu', array($this, 'add_menu'));
    }

    public function add_menu(): void {
        add_management_page(self::TITLE, self::TITLE, self::CAPABILITY, self::SLUG, array($this, 'render'));
    }

    /** Administrators only: manage_options and not a Club staff account. */
    public static function may_manage($user): bool {
        return $user instanceof WP_User && $user->exists() && user_can($user, self::CAPABILITY) && !EVC_Staff_Role::has_staff_role($user);
    }

    public function render(): void {
        if (!self::may_manage(wp_get_current_user())) {
            wp_die(esc_html(self::NOT_ALLOWED), '', array('response' => 403));
        }
        $notice = null;
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST[self::FIELD_ACTION])) {
            $notice = $this->handle_post();
        }
        echo $this->html($this->service->status(), $notice); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    /** @return array{kind:string,lines:string[]} */
    private function handle_post(): array {
        check_admin_referer(self::NONCE_ACTION);
        $user = wp_get_current_user();
        if (!self::may_manage($user)) {
            wp_die(esc_html(self::NOT_ALLOWED), '', array('response' => 403));
        }
        if (wp_unslash($_POST[self::FIELD_ACTION]) !== self::ACTION_APPLY) {
            return array('kind' => 'error', 'lines' => array(EVC_Club_Migrations_Service::message('unknown')));
        }
        $typed = isset($_POST[self::FIELD_CONFIRM]) ? wp_unslash($_POST[self::FIELD_CONFIRM]) : '';
        if (!EVC_Club_Migrations_Service::confirmation_matches($typed)) {
            return array('kind' => 'error', 'lines' => array(self::WRONG_WORD));
        }
        return self::notice($this->service->apply((int) $user->ID, 'admin'));
    }

    /** @return array{kind:string,lines:string[]} */
    public static function notice(array $outcome): array {
        $versions = EVC_Club_Migrations_Service::version_list($outcome['versions']);
        switch ($outcome['result']) {
            case EVC_Club_Migrations_Service::RESULT_APPLIED:
                $lines = array(EVC_Club_Migrations_Service::message(EVC_Club_Migrations_Service::RESULT_APPLIED, array('versions' => $versions)));
                $lines[] = $outcome['audit'] === 'written'
                    ? 'Η ενέργεια καταγράφηκε στο ημερολόγιο ελέγχου του Club.'
                    : 'Προσοχή: η καταγραφή στο ημερολόγιο ελέγχου του Club δεν έγινε· υπάρχει μόνο στο αρχείο σφαλμάτων του διακομιστή.';
                return array('kind' => 'success', 'lines' => $lines);
            case EVC_Club_Migrations_Service::RESULT_NOTHING_PENDING:
                return array('kind' => 'info', 'lines' => array(EVC_Club_Migrations_Service::message(EVC_Club_Migrations_Service::RESULT_NOTHING_PENDING)));
            case EVC_Club_Migrations_Service::RESULT_FAILED:
                return array('kind' => 'error', 'lines' => array(EVC_Club_Migrations_Service::message(EVC_Club_Migrations_Service::RESULT_FAILED, array('version' => $outcome['failed_version'], 'versions' => $versions))));
            default:
                $lines = array();
                foreach ($outcome['refusals'] as $code) {
                    $lines[] = EVC_Club_Migrations_Service::message($code);
                }
                return array('kind' => 'error', 'lines' => $lines ?: array(EVC_Club_Migrations_Service::message('unknown')));
        }
    }

    private function html(array $status, ?array $notice): string {
        $h = '<div class="wrap evc-club-db"><h1>' . esc_html(self::TITLE) . '</h1>';
        $h .= '<p>' . esc_html('Εδώ βλέπετε την κατάσταση της ξεχωριστής βάσης δεδομένων του Club και, όταν όλοι οι έλεγχοι είναι εντάξει, μπορείτε να εφαρμόσετε τις εκκρεμείς μεταβάσεις (δημιουργία πινάκων). Τίποτα δεν διαγράφεται και τίποτα υπάρχον δεν αλλάζει.') . '</p>';
        if ($notice !== null) {
            $class = $notice['kind'] === 'success' ? 'notice-success' : ($notice['kind'] === 'info' ? 'notice-info' : 'notice-error');
            $h .= '<div class="notice ' . $class . ' evc-club-db-result" data-kind="' . esc_attr($notice['kind']) . '">';
            foreach ($notice['lines'] as $line) {
                $h .= '<p>' . esc_html($line) . '</p>';
            }
            $h .= '</div>';
        }

        $config = array('present' => 'Υπάρχουν', 'missing' => 'Λείπουν', 'invalid' => 'Δεν είναι έγκυρα');
        $connection = array('ok' => 'Επιτυχής', 'failed' => 'Απέτυχε', 'not_attempted' => 'Δεν δοκιμάστηκε');
        $h .= '<h2>' . esc_html('Κατάσταση') . '</h2><table class="widefat striped" style="max-width:60em"><tbody>';
        $h .= self::row('Στοιχεία σύνδεσης (wp-config.php)', $config[$status['config']] ?? $status['config']);
        if ($status['database_hint'] !== null) {
            $h .= self::row('Όνομα βάσης (κρυμμένο)', (string) $status['database_hint']);
        }
        $conn = $connection[$status['connection']] ?? $status['connection'];
        if ($status['connection_code'] !== null) {
            $conn .= ' (κωδικός σφάλματος ' . (int) $status['connection_code'] . ')';
        }
        $h .= self::row('Σύνδεση', $conn);
        if ($status['server_version'] !== null) {
            $h .= self::row('Έκδοση διακομιστή βάσης', (string) $status['server_version']);
        }
        $h .= '</tbody></table>';

        $h .= '<h2>' . esc_html('Έλεγχοι ασφαλείας') . '</h2><table class="widefat striped evc-club-db-checks" style="max-width:60em"><tbody>';
        foreach ($status['checks'] as $check => $state) {
            $h .= '<tr data-check="' . esc_attr($check) . '" data-state="' . esc_attr($state) . '"><td>' . esc_html(self::CHECK_LABELS[$check] ?? $check) . '</td><td><strong>' . esc_html(self::CHECK_STATES[$state] ?? $state) . '</strong></td></tr>';
        }
        $h .= '</tbody></table>';
        if ($status['refusals']) {
            $h .= '<div class="notice notice-warning inline evc-club-db-refusals">';
            foreach ($status['refusals'] as $code) {
                $h .= '<p data-refusal="' . esc_attr($code) . '">' . esc_html(EVC_Club_Migrations_Service::message($code)) . '</p>';
            }
            $h .= '</div>';
        }

        $h .= '<h2>' . esc_html('Εφαρμοσμένες μεταβάσεις') . '</h2>';
        if ($status['applied']) {
            $h .= '<table class="widefat striped" style="max-width:60em"><thead><tr><th>' . esc_html('Μετάβαση') . '</th><th>' . esc_html('Όνομα') . '</th><th>' . esc_html('Εφαρμόστηκε (UTC)') . '</th><th>' . esc_html('Αρχείο') . '</th></tr></thead><tbody>';
            foreach ($status['applied'] as $row) {
                $h .= '<tr><td>' . esc_html(sprintf('%03d', $row['version'])) . '</td><td>' . esc_html($row['name']) . '</td><td>' . esc_html($row['applied_at']) . '</td><td>' . esc_html($row['checksum_ok'] ? 'Ίδιο (εντάξει)' : 'ΔΙΑΦΟΡΕΤΙΚΟ ή λείπει') . '</td></tr>';
            }
            $h .= '</tbody></table>';
        } else {
            $h .= '<p>' . esc_html('Καμία.') . '</p>';
        }
        $h .= '<h2>' . esc_html('Εκκρεμείς μεταβάσεις') . '</h2>';
        if ($status['pending']) {
            $h .= '<ul class="evc-club-db-pending">';
            foreach ($status['pending'] as $row) {
                $h .= '<li>' . esc_html(sprintf('%03d', $row['version']) . ' — ' . $row['name']) . '</li>';
            }
            $h .= '</ul>';
        } else {
            $h .= '<p>' . esc_html('Καμία.') . '</p>';
        }

        if ($status['can_apply']) {
            $h .= '<h2>' . esc_html('Εφαρμογή') . '</h2>';
            $h .= '<p>' . esc_html('Πριν συνεχίσετε: βεβαιωθείτε ότι ο τεχνικός έχει πάρει αντίγραφο ασφαλείας της βάσης του Club. Για να εφαρμόσετε τις εκκρεμείς μεταβάσεις, γράψτε τη λέξη ' . EVC_Club_Migrations_Service::CONFIRM_WORD . ' με κεφαλαία και πατήστε το κουμπί.') . '</p>';
            $h .= '<form method="post" class="evc-club-db-apply">' . wp_nonce_field(self::NONCE_ACTION, '_wpnonce', true, false);
            $h .= '<input type="hidden" name="' . esc_attr(self::FIELD_ACTION) . '" value="' . esc_attr(self::ACTION_APPLY) . '">';
            $h .= '<p><label>' . esc_html('Λέξη επιβεβαίωσης:') . ' <input type="text" name="' . esc_attr(self::FIELD_CONFIRM) . '" autocomplete="off" required></label></p>';
            $h .= '<p><button type="submit" class="button button-primary">' . esc_html('Εφαρμογή εκκρεμών μεταβάσεων') . '</button></p></form>';
        } elseif ($status['inspected'] && $status['refusals'] === array()) {
            $h .= '<p>' . esc_html('Η βάση του Club είναι ενημερωμένη.') . '</p>';
        } else {
            $h .= '<p>' . esc_html('Η εφαρμογή δεν είναι διαθέσιμη μέχρι να διορθωθούν τα παραπάνω.') . '</p>';
        }
        return $h . '</div>';
    }

    private static function row(string $label, string $value): string {
        return '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($value) . '</td></tr>';
    }
}
