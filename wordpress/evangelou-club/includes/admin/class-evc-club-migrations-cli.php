<?php
defined('ABSPATH') || exit;
/**
 * STAGE-04A: `wp evc-club migrations status` and `wp evc-club migrations apply --yes`, for the
 * technician. Registered ONLY when WP-CLI runs and EVC_CLUB_MIGRATIONS_ENABLED is true;
 * constructed ONLY by EVC_Club_Migrations_Plugin::wire(). Same checks as the admin page
 * (EVC_Club_Migrations_Service); `apply` refuses without --yes. Server access (shell + wp-config)
 * is the authority here; the WordPress user (if `--user` is given) is recorded in the audit.
 *
 * Output goes through $io (line / success / error callables); `error` must stop the command
 * (WP_CLI::error exits). Tests pass a recording $io that throws instead.
 */
final class EVC_Club_Migrations_Cli {
    /** @var EVC_Club_Migrations_Service */
    private $service;
    /** @var array{line:callable,success:callable,error:callable} */
    private $io;
    /** @var callable(): int */
    private $actor;

    /**
     * @param array{line:callable,success:callable,error:callable} $io
     * @param callable(): int $actor the WordPress user id (0 when none)
     */
    public function __construct(EVC_Club_Migrations_Service $service, array $io, callable $actor) {
        EVC_Club_Migrations_Feature::assert_constructible(__CLASS__);
        foreach (array('line', 'success', 'error') as $key) {
            if (!isset($io[$key]) || !is_callable($io[$key])) {
                throw new InvalidArgumentException('CLI output callables are required.');
            }
        }
        $this->service = $service;
        $this->io = $io;
        $this->actor = $actor;
    }

    /**
     * Shows the Club database migration status and the safety checks (read-only).
     *
     * ## EXAMPLES
     *
     *     wp evc-club migrations status
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function status($args = array(), $assoc_args = array()): void {
        $report = $this->service->status();
        $this->print_report($report);
        if ($report['refusals']) {
            ($this->io['line'])('Η εφαρμογή ΔΕΝ επιτρέπεται μέχρι να διορθωθούν τα παραπάνω.');
        } elseif ($report['can_apply']) {
            ($this->io['line'])('Μπορεί να γίνει εφαρμογή: wp evc-club migrations apply --yes');
        } else {
            ($this->io['success'])('Η βάση του Club είναι ενημερωμένη.');
        }
    }

    /**
     * Applies the pending shipped migrations to the separate Club database, after every check.
     * Take a backup of the Club database first.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Required. Confirms that a backup exists and the pending migrations should be applied.
     *
     * ## EXAMPLES
     *
     *     wp evc-club migrations apply --yes
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function apply($args = array(), $assoc_args = array()): void {
        if (!is_array($assoc_args) || !array_key_exists('yes', $assoc_args) || ($assoc_args['yes'] !== true && $assoc_args['yes'] !== '')) {
            ($this->io['error'])('Χρειάζεται --yes (αφού πάρετε αντίγραφο ασφαλείας της βάσης του Club). Δεν έγινε καμία αλλαγή.');
            return;
        }
        $outcome = $this->service->apply((int) ($this->actor)(), 'cli');
        $notice = EVC_Club_Migrations_Admin_Page::notice($outcome);
        $text = implode(' ', $notice['lines']);
        if ($notice['kind'] === 'error') {
            ($this->io['error'])($text);
            return;
        }
        if ($notice['kind'] === 'success') {
            ($this->io['success'])($text);
            return;
        }
        ($this->io['line'])($text);
    }

    private function print_report(array $report): void {
        $line = $this->io['line'];
        $config = array('present' => 'υπάρχουν', 'missing' => 'λείπουν', 'invalid' => 'δεν είναι έγκυρα');
        $line('Στοιχεία σύνδεσης: ' . ($config[$report['config']] ?? $report['config']) . ($report['database_hint'] !== null ? ' (βάση ' . $report['database_hint'] . ')' : ''));
        $line('Σύνδεση: ' . $report['connection'] . ($report['connection_code'] !== null ? ' (κωδικός ' . (int) $report['connection_code'] . ')' : ''));
        if ($report['server_version'] !== null) {
            $line('Διακομιστής: ' . $report['server_version']);
        }
        foreach ($report['checks'] as $check => $state) {
            $line(sprintf('Έλεγχος %-24s %s', $check, EVC_Club_Migrations_Admin_Page::CHECK_STATES[$state] ?? $state));
        }
        foreach ($report['refusals'] as $code) {
            $line('ΑΡΝΗΣΗ [' . $code . ']: ' . EVC_Club_Migrations_Service::message($code));
        }
        foreach ($report['applied'] as $row) {
            $line(sprintf('Εφαρμοσμένη %03d %s (%s) αρχείο: %s', $row['version'], $row['name'], $row['applied_at'], $row['checksum_ok'] ? 'ίδιο' : 'ΔΙΑΦΟΡΕΤΙΚΟ'));
        }
        foreach ($report['pending'] as $row) {
            $line(sprintf('Εκκρεμής %03d %s', $row['version'], $row['name']));
        }
    }
}
