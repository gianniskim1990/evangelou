<?php
defined('ABSPATH') || exit;
/**
 * STAGE-04A: migration status and the guarded "apply pending migrations" step for the SEPARATE
 * Club database. Constructed ONLY by EVC_Club_Migrations_Plugin::wire() behind
 * EVC_CLUB_MIGRATIONS_ENABLED (the packager enforces it).
 *
 * - status() is read-only: it never creates, alters or writes anything (not even the
 *   bookkeeping table).
 * - apply() runs ONLY the shipped database/NNN_*.sql files, through EVC_Migrator, after every
 *   separation check below passed, under the migrator's named lock (taken first, with no wait,
 *   and held across the checks and the migration: GET_LOCK is re-entrant per connection, so
 *   EVC_Migrator's own GET_LOCK nests inside it). Nothing is ever dropped or altered; there is
 *   no way to pass SQL in.
 * - Separation checks (any failure refuses): the Club database must not be the WordPress one
 *   (configured name + host, and the server's own @@hostname/@@port/DATABASE() identity); it
 *   must contain no WordPress tables and no table outside `evc_`; every `evc_` table must have
 *   been created by a migration recorded in evc_schema_migrations; recorded migrations must
 *   match the shipped files (checksum, name, order) and their tables must exist.
 * - Secrets: host, user and password are never part of a report, a message or a log line (the
 *   database name only as a masked hint). Exceptions are mapped to fixed codes; their messages
 *   are never shown (EVC_Club_Db already discards driver messages).
 */
final class EVC_Club_Migrations_Service {
    /** The word the administrator must type to apply (WP admin page). */
    const CONFIRM_WORD = 'ΕΦΑΡΜΟΓΗ';
    const AUDIT_EVENT = 'club_db_migration';
    const CLUB_TABLE_PREFIX = 'evc_';
    const REQUIRED_CONSTANTS = array('EVC_CLUB_DB_HOST', 'EVC_CLUB_DB_NAME', 'EVC_CLUB_DB_USER', 'EVC_CLUB_DB_PASSWORD');
    /** Core WordPress table names (after any prefix): never in the Club database. */
    const WORDPRESS_TABLES = array('options', 'posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links');
    const CHANNELS = array('admin', 'cli');

    // Refusal codes (each has a Greek message in message()).
    const CONFIG_MISSING = 'config_missing';
    const CONFIG_INVALID = 'config_invalid';
    const CONNECTION_FAILED = 'connection_failed';
    const SAME_DATABASE = 'same_database_as_wordpress';
    const WORDPRESS_IDENTITY_UNKNOWN = 'wordpress_identity_unknown';
    const WORDPRESS_TABLES_PRESENT = 'wordpress_tables_present';
    const WORDPRESS_PREFIX_AMBIGUOUS = 'wordpress_prefix_ambiguous';
    const UNKNOWN_TABLES_PRESENT = 'unknown_tables_present';
    const BOOKKEEPING_INVALID = 'bookkeeping_table_invalid';
    const DRIFT = 'drift';
    const UNRECORDED_CLUB_TABLES = 'unrecorded_club_tables';
    const MISSING_CLUB_TABLES = 'missing_club_tables';
    const MIGRATION_FILES_INVALID = 'migration_files_invalid';
    const INSPECTION_FAILED = 'inspection_failed';
    const LOCK_BUSY = 'lock_busy';

    // Apply results.
    const RESULT_APPLIED = 'applied';
    const RESULT_NOTHING_PENDING = 'nothing_pending';
    const RESULT_REFUSED = 'refused';
    const RESULT_FAILED = 'failed';

    /** Display checks => the refusal codes that fail them. */
    const CHECKS = array(
        'config' => array(self::CONFIG_MISSING, self::CONFIG_INVALID),
        'connection' => array(self::CONNECTION_FAILED),
        'separate_from_wordpress' => array(self::SAME_DATABASE, self::WORDPRESS_IDENTITY_UNKNOWN),
        'no_wordpress_tables' => array(self::WORDPRESS_TABLES_PRESENT, self::WORDPRESS_PREFIX_AMBIGUOUS),
        'no_unknown_tables' => array(self::UNKNOWN_TABLES_PRESENT),
        'club_tables_recorded' => array(self::UNRECORDED_CLUB_TABLES, self::MISSING_CLUB_TABLES),
        'no_drift' => array(self::DRIFT, self::BOOKKEEPING_INVALID, self::MIGRATION_FILES_INVALID),
    );

    /** @var callable(): ?EVC_Db_Config */
    private $config;
    /** @var callable(EVC_Db_Config): EVC_Club_Db */
    private $connect;
    /** @var callable(EVC_Club_Db): EVC_Migrator */
    private $migrator;
    /** @var array{name:string,host:string,prefix:string} */
    private $wordpress;
    /** @var callable(): ?array */
    private $wordpress_server;
    /** @var EVC_Audit_Log */
    private $audit;
    /** @var callable(string): void */
    private $log;
    /** @var callable(): DateTimeImmutable */
    private $now;

    /**
     * @param callable(): ?EVC_Db_Config $config null = constants missing; a throw = invalid
     * @param callable(EVC_Db_Config): EVC_Club_Db $connect
     * @param callable(EVC_Club_Db): EVC_Migrator $migrator over the shipped database/ directory
     * @param array{name:string,host:string,prefix:string} $wordpress DB_NAME, DB_HOST, $wpdb->prefix
     * @param callable(): ?array $wordpress_server the WordPress connection's ['h' => @@hostname, 'p' => @@port, 'd' => DATABASE()]
     * @param callable(string): void $log minimal, secret-free lines (error_log in production)
     * @param callable(): DateTimeImmutable $now
     */
    public function __construct(callable $config, callable $connect, callable $migrator, array $wordpress, callable $wordpress_server, EVC_Audit_Log $audit, callable $log, callable $now) {
        EVC_Club_Migrations_Feature::assert_constructible(__CLASS__);
        $this->config = $config;
        $this->connect = $connect;
        $this->migrator = $migrator;
        $this->wordpress = array(
            'name' => (string) ($wordpress['name'] ?? ''),
            'host' => (string) ($wordpress['host'] ?? ''),
            'prefix' => (string) ($wordpress['prefix'] ?? ''),
        );
        $this->wordpress_server = $wordpress_server;
        $this->audit = $audit;
        $this->log = $log;
        $this->now = $now;
    }

    /**
     * Read-only report.
     * @return array<string,mixed>
     */
    public function status(): array {
        $report = self::blank_report();
        $config = $this->load_config($report);
        if ($config === null || !$this->separate_by_configuration($config, $report)) {
            return self::finish($report);
        }
        $db = $this->open($config, $report);
        if ($db !== null) {
            $this->inspect($db, $report);
        }
        return self::finish($report);
    }

    /**
     * Applies the pending shipped migrations, in order, only when every check passes. The caller
     * (admin page / CLI) has already checked who asks (capability, nonce, typed word / --yes).
     *
     * @return array{result:string,versions:int[],refusals:string[],failed_version:?int,state_unknown:bool,audit:string}
     */
    public function apply(int $actor_user_id, string $channel): array {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Unknown channel.');
        }
        $outcome = array('result' => self::RESULT_REFUSED, 'versions' => array(), 'refusals' => array(), 'failed_version' => null, 'state_unknown' => false, 'audit' => 'not_written');
        $report = self::blank_report();
        $config = $this->load_config($report);
        if ($config !== null && $this->separate_by_configuration($config, $report)) {
            $db = $this->open($config, $report);
            if ($db !== null) {
                $outcome = $this->apply_locked($db, $report, $actor_user_id, $channel, $outcome);
            }
        }
        if ($outcome['result'] === self::RESULT_REFUSED) {
            $outcome['refusals'] = array_values(array_unique($report['refusals']));
        }
        $this->log_line($actor_user_id, $channel, $outcome);
        return $outcome;
    }

    /** True when $typed is exactly the confirmation word (surrounding whitespace ignored). */
    public static function confirmation_matches($typed): bool {
        return is_string($typed) && trim($typed) === self::CONFIRM_WORD;
    }

    /**
     * Plain Greek for a refusal / result code. $context['version'] names a failed migration.
     */
    public static function message(string $code, array $context = array()): string {
        $versions = isset($context['versions']) ? (string) $context['versions'] : '';
        $version = isset($context['version']) && $context['version'] !== null ? sprintf('%03d', (int) $context['version']) : '';
        switch ($code) {
            case self::CONFIG_MISSING:
                return 'Δεν έχουν οριστεί τα στοιχεία σύνδεσης της βάσης του Club στο wp-config.php (' . implode(', ', self::REQUIRED_CONSTANTS) . '). Ζητήστε από τον τεχνικό να τα προσθέσει.';
            case self::CONFIG_INVALID:
                return 'Τα στοιχεία σύνδεσης της βάσης του Club στο wp-config.php δεν είναι έγκυρα (π.χ. όνομα βάσης ή θύρα). Ζητήστε από τον τεχνικό να τα ελέγξει.';
            case self::CONNECTION_FAILED:
                return 'Δεν έγινε σύνδεση με τη βάση του Club. Ζητήστε από τον τεχνικό να ελέγξει ότι η βάση υπάρχει και ότι τα στοιχεία σύνδεσης είναι σωστά. Δεν έγινε καμία αλλαγή.';
            case self::SAME_DATABASE:
                return 'Η βάση του Club είναι η ίδια με τη βάση του WordPress. Το Club χρειάζεται δική του, ξεχωριστή βάση. Δεν έγινε καμία αλλαγή.';
            case self::WORDPRESS_IDENTITY_UNKNOWN:
                return 'Δεν μπόρεσε να επιβεβαιωθεί ότι η βάση του Club είναι διαφορετική από τη βάση του WordPress. Δεν έγινε καμία αλλαγή.';
            case self::WORDPRESS_TABLES_PRESENT:
                return 'Η βάση του Club περιέχει πίνακες του WordPress, άρα μάλλον δείχνει σε λάθος βάση. Δεν έγινε καμία αλλαγή.';
            case self::WORDPRESS_PREFIX_AMBIGUOUS:
                return 'Το πρόθεμα πινάκων του WordPress μοιάζει με το πρόθεμα «evc_» του Club, άρα οι πίνακες δεν μπορούν να ξεχωριστούν με ασφάλεια. Ενημερώστε τον προγραμματιστή. Δεν έγινε καμία αλλαγή.';
            case self::UNKNOWN_TABLES_PRESENT:
                return 'Η βάση του Club περιέχει πίνακες που δεν ανήκουν στο Club. Χρειάζεται μια άδεια, ξεχωριστή βάση μόνο για το Club. Δεν έγινε καμία αλλαγή.';
            case self::BOOKKEEPING_INVALID:
                return 'Ο πίνακας που καταγράφει τις μεταβάσεις (evc_schema_migrations) έχει απρόσμενη μορφή. Μην προχωρήσετε· ενημερώστε τον προγραμματιστή. Δεν έγινε καμία αλλαγή.';
            case self::DRIFT:
                return 'Μια μετάβαση που έχει ήδη εφαρμοστεί δεν ταιριάζει με τα αρχεία του plugin (άλλαξε, λείπει ή είναι εκτός σειράς). Μην προχωρήσετε· ενημερώστε τον προγραμματιστή. Δεν έγινε καμία αλλαγή.';
            case self::UNRECORDED_CLUB_TABLES:
                return 'Υπάρχουν πίνακες του Club που δεν έχουν καταγραφεί ως εφαρμοσμένοι (η βάση φτιάχτηκε με άλλο τρόπο ή έμεινε μισή). Μην προχωρήσετε· ενημερώστε τον προγραμματιστή. Δεν έγινε καμία αλλαγή.';
            case self::MISSING_CLUB_TABLES:
                return 'Λείπουν πίνακες του Club από μεταβάσεις που έχουν καταγραφεί ως εφαρμοσμένες. Μην προχωρήσετε· ενημερώστε τον προγραμματιστή. Δεν έγινε καμία αλλαγή.';
            case self::MIGRATION_FILES_INVALID:
                return 'Τα αρχεία μεταβάσεων του plugin δεν είναι σωστά (λάθος όνομα ή διπλός αριθμός). Εγκαταστήστε ξανά το plugin. Δεν έγινε καμία αλλαγή.';
            case self::INSPECTION_FAILED:
                return 'Ο έλεγχος της βάσης του Club δεν ολοκληρώθηκε (σφάλμα βάσης). Δεν έγινε καμία αλλαγή.';
            case self::LOCK_BUSY:
                return 'Αυτή τη στιγμή τρέχει ήδη μια άλλη εφαρμογή μεταβάσεων. Περιμένετε λίγα λεπτά και δοκιμάστε ξανά. Δεν έγινε καμία αλλαγή.';
            case self::RESULT_APPLIED:
                return 'Η βάση του Club ενημερώθηκε. Εφαρμόστηκαν οι μεταβάσεις: ' . $versions . '.';
            case self::RESULT_NOTHING_PENDING:
                return 'Δεν υπάρχει καμία εκκρεμής μετάβαση. Δεν έγινε καμία αλλαγή.';
            case self::RESULT_FAILED:
                if (!empty($context['state_unknown'])) {
                    return 'Η εφαρμογή μεταβάσεων απέτυχε και η κατάσταση της βάσης του Club δεν μπόρεσε να διαβαστεί μετά (άγνωστο ποιες μεταβάσεις εφαρμόστηκαν). Η βάση μπορεί να έμεινε μισοτελειωμένη: επαναφέρετε το αντίγραφο ασφαλείας που πήρατε πριν και ενημερώστε τον προγραμματιστή.';
                }
                return 'Η μετάβαση ' . ($version !== '' ? $version . ' ' : '') . 'απέτυχε. Η βάση μπορεί να έμεινε μισοτελειωμένη: επαναφέρετε το αντίγραφο ασφαλείας που πήρατε πριν και ενημερώστε τον προγραμματιστή.' . ($versions !== '' ? ' Πριν από την αποτυχία εφαρμόστηκαν: ' . $versions . '.' : '');
            default:
                return 'Άγνωστη κατάσταση. Δεν έγινε καμία αλλαγή.';
        }
    }

    /** "001, 002" */
    public static function version_list(array $versions): string {
        return implode(', ', array_map(function ($v) {
            return sprintf('%03d', (int) $v);
        }, $versions));
    }

    /** First character, dots, last character and length: never the whole database name. */
    public static function mask(string $value): string {
        $length = strlen($value);
        if ($length <= 2) {
            return '••• (' . $length . ')';
        }
        return $value[0] . '•••' . $value[$length - 1] . ' (' . $length . ')';
    }

    // ---- internals ----------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function blank_report(): array {
        return array(
            'config' => 'missing',
            'database_hint' => null,
            'connection' => 'not_attempted',
            'connection_code' => null,
            'server_version' => null,
            'inspected' => false,
            'applied' => array(),
            'pending' => array(),
            'refusals' => array(),
        );
    }

    /** Adds can_apply and the per-check states. */
    private static function finish(array $report): array {
        $report['refusals'] = array_values(array_unique($report['refusals']));
        $checks = array();
        foreach (self::CHECKS as $check => $codes) {
            if (array_intersect($codes, $report['refusals'])) {
                $checks[$check] = 'failed';
            } elseif ($check === 'config') {
                $checks[$check] = $report['config'] === 'present' ? 'passed' : 'not_checked';
            } elseif ($check === 'connection') {
                $checks[$check] = $report['connection'] === 'ok' ? 'passed' : 'not_checked';
            } else {
                $checks[$check] = $report['inspected'] ? 'passed' : 'not_checked';
            }
        }
        $report['checks'] = $checks;
        $report['can_apply'] = $report['inspected'] && $report['refusals'] === array() && $report['pending'] !== array();
        return $report;
    }

    private function load_config(array &$report): ?EVC_Db_Config {
        try {
            $config = ($this->config)();
        } catch (Throwable $e) {
            $report['config'] = 'invalid';
            $report['refusals'][] = self::CONFIG_INVALID;
            return null;
        }
        if (!($config instanceof EVC_Db_Config)) {
            $report['config'] = 'missing';
            $report['refusals'][] = self::CONFIG_MISSING;
            return null;
        }
        $report['config'] = 'present';
        $report['database_hint'] = self::mask($config->database());
        return $config;
    }

    /** False (with the refusal) when the configured name and host are WordPress's own. */
    private function separate_by_configuration(EVC_Db_Config $config, array &$report): bool {
        $host = preg_match('/host=([^;]*);/', $config->dsn(), $m) ? $m[1] : '';
        // Exact (case-sensitive) names here: names differing only in case may be two schemas
        // (lower_case_table_names = 0), which the server identity check in inspect() decides.
        if ($config->database() === $this->wordpress['name']
            && self::normalized_host($host) === self::normalized_host($this->wordpress['host'])) {
            $report['refusals'][] = self::SAME_DATABASE;
            return false;
        }
        return true;
    }

    /** Lower-case host without port / socket; every loopback spelling is the same host. */
    public static function normalized_host(string $host): string {
        $host = strtolower(trim($host));
        if ($host !== '' && $host[0] === '[') {
            $end = strpos($host, ']');
            $host = $end === false ? substr($host, 1) : substr($host, 1, $end - 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = (string) substr($host, 0, (int) strpos($host, ':'));
        }
        if ($host === '' || $host === 'localhost' || $host === '::1' || strpos($host, '127.') === 0) {
            return 'loopback';
        }
        return $host;
    }

    private function open(EVC_Db_Config $config, array &$report): ?EVC_Club_Db {
        try {
            $db = ($this->connect)($config);
        } catch (Throwable $e) {
            $report['connection'] = 'failed';
            $report['connection_code'] = $e instanceof EVC_Db_Exception ? $e->driver_code() : null;
            $report['refusals'][] = self::CONNECTION_FAILED;
            return null;
        }
        if (!($db instanceof EVC_Club_Db)) {
            $report['connection'] = 'failed';
            $report['refusals'][] = self::CONNECTION_FAILED;
            return null;
        }
        $report['connection'] = 'ok';
        return $db;
    }

    /** Fills server version, applied / pending migrations and the separation refusals. Read-only. */
    private function inspect(EVC_Club_Db $db, array &$report): void {
        try {
            $version = $db->fetch_one('SELECT VERSION() AS v');
            $report['server_version'] = $version === null ? null : (string) $version['v'];

            $club = $db->fetch_one('SELECT @@hostname AS h, @@port AS p, DATABASE() AS d, @@lower_case_table_names AS l');
            $wordpress = ($this->wordpress_server)();
            if (!is_array($wordpress) || !isset($wordpress['h'], $wordpress['p'], $wordpress['d']) || $club === null) {
                $report['refusals'][] = self::WORDPRESS_IDENTITY_UNKNOWN;
            } elseif (strtolower((string) $club['h']) === strtolower((string) $wordpress['h'])
                && (int) $club['p'] === (int) $wordpress['p']
                && self::same_schema_name((string) $club['d'], (string) $wordpress['d'], (int) $club['l'])) {
                $report['refusals'][] = self::SAME_DATABASE;
            }

            $prefix = strtolower($this->wordpress['prefix']);
            if ($prefix !== '' && strpos(self::CLUB_TABLE_PREFIX, $prefix) === 0) {
                // 'e', 'ev', 'evc', 'evc_': WordPress tables and Club tables cannot be told apart.
                $report['refusals'][] = self::WORDPRESS_PREFIX_AMBIGUOUS;
            }

            $tables = array();
            foreach ($db->fetch_all('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $row) {
                $tables[] = (string) $row['t'];
            }
            $club_tables = array();
            foreach ($tables as $table) {
                $kind = $this->table_kind($table);
                if ($kind === 'wordpress') {
                    $report['refusals'][] = self::WORDPRESS_TABLES_PRESENT;
                } elseif ($kind === 'unknown') {
                    $report['refusals'][] = self::UNKNOWN_TABLES_PRESENT;
                } else {
                    $club_tables[] = strtolower($table);
                }
            }

            $migrator = ($this->migrator)($db);
            try {
                $available = $migrator->discover();
            } catch (EVC_Migration_Exception $e) {
                $report['refusals'][] = self::MIGRATION_FILES_INVALID;
                $available = array();
            }

            $applied = array();
            if (in_array(EVC_Migrator::TABLE, $club_tables, true)) {
                try {
                    foreach ($db->fetch_all('SELECT version, name, checksum, applied_at_utc FROM ' . EVC_Migrator::TABLE . ' ORDER BY version') as $row) {
                        $applied[(int) $row['version']] = $row;
                    }
                } catch (EVC_Db_Exception $e) {
                    $report['refusals'][] = self::BOOKKEEPING_INVALID;
                }
            }

            $expected = array(EVC_Migrator::TABLE);
            $latest = $applied ? max(array_keys($applied)) : 0;
            foreach ($applied as $version => $row) {
                $file = $available[$version] ?? null;
                $checksum_ok = $file !== null && hash_equals($file['checksum'], (string) $row['checksum']) && $file['name'] === (string) $row['name'];
                if (!$checksum_ok) {
                    $report['refusals'][] = self::DRIFT;
                }
                if ($file !== null) {
                    $expected = array_merge($expected, self::created_tables($file['path']));
                }
                $report['applied'][] = array(
                    'version' => $version,
                    'name' => (string) $row['name'],
                    'applied_at' => (string) $row['applied_at_utc'],
                    'checksum_ok' => $checksum_ok,
                );
            }
            foreach ($available as $version => $file) {
                if (isset($applied[$version])) {
                    continue;
                }
                if ($version < $latest) {
                    $report['refusals'][] = self::DRIFT;
                }
                $report['pending'][] = array('version' => $version, 'name' => $file['name']);
            }
            foreach ($club_tables as $table) {
                if (!in_array($table, $expected, true)) {
                    $report['refusals'][] = self::UNRECORDED_CLUB_TABLES;
                }
            }
            if ($applied) {
                foreach ($expected as $table) {
                    if (!in_array($table, $club_tables, true)) {
                        $report['refusals'][] = self::MISSING_CLUB_TABLES;
                    }
                }
            }
            $report['inspected'] = true;
        } catch (Throwable $e) {
            $report['refusals'][] = self::INSPECTION_FAILED;
        }
    }

    /** Schema names on one server: case-sensitive only where the server keeps them so (lower_case_table_names = 0). */
    public static function same_schema_name(string $a, string $b, int $lower_case_table_names): bool {
        return $lower_case_table_names === 0 ? $a === $b : strtolower($a) === strtolower($b);
    }

    /** 'wordpress' | 'club' | 'unknown' */
    private function table_kind(string $table): string {
        $name = strtolower($table);
        $prefix = strtolower($this->wordpress['prefix']);
        if ($prefix !== '' && strpos($name, $prefix) === 0) {
            return 'wordpress';
        }
        if (strpos($name, self::CLUB_TABLE_PREFIX) === 0) {
            return 'club';
        }
        foreach (self::WORDPRESS_TABLES as $core) {
            if ($name === $core || substr($name, -strlen('_' . $core)) === '_' . $core) {
                return 'wordpress';
            }
        }
        return 'unknown';
    }

    /** @return string[] lower-case names of the tables a shipped migration file creates */
    private static function created_tables(string $path): array {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new EVC_Migration_Exception('Cannot read a migration file.');
        }
        $names = array();
        foreach (EVC_Migrator::split_statements($sql) as $statement) {
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $statement, $m)) {
                $names[] = strtolower($m[1]);
            }
        }
        return $names;
    }

    private function apply_locked(EVC_Club_Db $db, array &$report, int $actor, string $channel, array $outcome): array {
        try {
            $lock = $db->fetch_one('SELECT GET_LOCK(CONCAT(?, SHA1(DATABASE())), 0) AS acquired', array(EVC_Migrator::LOCK_PREFIX));
        } catch (Throwable $e) {
            $report['refusals'][] = self::INSPECTION_FAILED;
            return $outcome;
        }
        if ($lock === null || (int) $lock['acquired'] !== 1) {
            $report['refusals'][] = self::LOCK_BUSY;
            return $outcome;
        }
        try {
            $this->inspect($db, $report);
            if ($report['refusals'] !== array() || !$report['inspected']) {
                return $outcome;
            }
            if ($report['pending'] === array()) {
                $outcome['result'] = self::RESULT_NOTHING_PENDING;
                return $outcome;
            }
            $before = array_map(function ($row) {
                return (int) $row['version'];
            }, $report['applied']);
            try {
                $outcome['versions'] = array_map('intval', ($this->migrator)($db)->migrate());
                $outcome['result'] = self::RESULT_APPLIED;
            } catch (Throwable $e) {
                $outcome['result'] = self::RESULT_FAILED;
                $now_applied = $this->recorded_versions($db);
                if ($now_applied === null) {
                    // Cannot read back what was recorded: never guess which migration failed.
                    $outcome['state_unknown'] = true;
                } else {
                    $outcome['versions'] = array_values(array_diff($now_applied, $before));
                    foreach ($report['pending'] as $pending) {
                        if (!in_array((int) $pending['version'], $now_applied, true)) {
                            $outcome['failed_version'] = (int) $pending['version'];
                            break;
                        }
                    }
                }
            }
            $outcome['audit'] = $this->audit_event($db, $actor, $channel, $outcome);
            return $outcome;
        } finally {
            try {
                $db->fetch_one('SELECT RELEASE_LOCK(CONCAT(?, SHA1(DATABASE()))) AS released', array(EVC_Migrator::LOCK_PREFIX));
            } catch (Throwable $ignored) {
                // Released when the connection closes.
            }
        }
    }

    /** @return int[]|null null when the bookkeeping table cannot be read */
    private function recorded_versions(EVC_Club_Db $db): ?array {
        try {
            return array_map(function ($row) {
                return (int) $row['version'];
            }, $db->fetch_all('SELECT version FROM ' . EVC_Migrator::TABLE . ' ORDER BY version'));
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 'written' | 'no_audit_table' | 'failed' */
    private function audit_event(EVC_Club_Db $db, int $actor, string $channel, array $outcome): string {
        try {
            $exists = $db->fetch_one("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evc_audit_events'");
            if ($exists === null || (int) $exists['n'] !== 1) {
                return 'no_audit_table';
            }
            $details = array('channel' => $channel, 'versions' => self::version_list($outcome['versions']));
            if ($outcome['failed_version'] !== null) {
                $details['failed_version'] = sprintf('%03d', $outcome['failed_version']);
            }
            if ($outcome['state_unknown']) {
                $details['state'] = 'unknown';
            }
            $this->audit->record($db, array(
                'event_type' => self::AUDIT_EVENT,
                'outcome' => $outcome['result'],
                'staff_wp_user_id' => $actor > 0 ? $actor : null,
                'occurred_at_utc' => ($this->now)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'details' => $details,
            ));
            return 'written';
        } catch (Throwable $e) {
            return 'failed';
        }
    }

    private function log_line(int $actor, string $channel, array $outcome): void {
        $line = sprintf(
            'Evangelou Club: Club DB migrations apply via %s by user #%d: result=%s versions=%s',
            $channel,
            $actor,
            $outcome['result'],
            $outcome['versions'] ? str_replace(' ', '', self::version_list($outcome['versions'])) : '-'
        );
        if ($outcome['failed_version'] !== null) {
            $line .= sprintf(' failed=%03d', $outcome['failed_version']);
        }
        if ($outcome['state_unknown']) {
            $line .= ' state=unknown';
        }
        if ($outcome['refusals']) {
            $line .= ' reasons=' . implode(',', $outcome['refusals']);
        }
        $line .= ' audit=' . $outcome['audit'];
        try {
            ($this->log)($line);
        } catch (Throwable $ignored) {
            // Logging never changes the result.
        }
    }
}
