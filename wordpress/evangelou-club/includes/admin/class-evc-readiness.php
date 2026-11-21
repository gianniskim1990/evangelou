<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08: the READ-ONLY go-live checks behind «Evangelou Club → Ετοιμότητα». Constructed ONLY by
 * EVC_Admin_Tools_Plugin::wire() behind EVC_CLUB_ADMIN_TOOLS_ENABLED.
 *
 * It reuses the existing checks instead of repeating them: the feature classes' own parsers, EVC_Db_Config /
 * EVC_Club_Db::connect, EVC_Club_Migrations_Feature::target_version(), EVC_Commerce_Source_Config,
 * EVC_Wp_Commerce_Pmpro_Reader::environment_conflicts() (pinned versions, product, price 20.00, sold individually,
 * level), EVC_Bacs_Payment_Issuer::site_problem() (Athens clock, level group), EVC_Onboarding_Shell::same_site_url()
 * (D13 terms URL), EVC_Fluentcrm_Feature::tag_config() and EVC_Fluentcrm_Plugin::problem() (the tag IDs, verified
 * read-only).
 *
 * It NEVER changes anything: only reads (options, constants, plain SELECTs on the Club database, FluentCRM read
 * calls). It holds no till-account service: the shared account is read through EVC_Staff_Account_Reader (read-only). It never prints a secret: no password, key, host, user, database name, IP address or header value; only
 * statuses, version numbers, IDs of products / levels / tags and problem codes.
 */
final class EVC_Readiness {
    const OK = 'ok';
    const MISSING = 'missing';
    const WRONG = 'wrong';
    const MANUAL = 'manual';
    const OFF = 'off';
    const UNREADABLE = 'unreadable';
    const STATUS_LABELS = array(
        self::OK => 'Εντάξει',
        self::MISSING => 'Λείπει',
        self::WRONG => 'Λάθος',
        self::MANUAL => 'Έλεγχος με το χέρι',
        self::OFF => 'Ανενεργό (προεπιλογή)',
        self::UNREADABLE => 'Δεν διαβάζεται',
    );

    /** The payment-provenance switch: only this exact string records payments in the Club database (same rule as
     *  EVC_Customer_Qr_Backend::provenance_recorded(), the WooCommerce completion and the redemption backends). */
    const PROVENANCE_CONSTANT = 'EVC_CLUB_PAYMENT_PROVENANCE';
    const PROVENANCE_VALUE = 'club_db_v1';
    /** The lookup-proof secret of the member QR / staff scan (EVC_Club_Qr_Services::is_lookup_secret: >= 32 bytes). */
    const LOOKUP_KEY_CONSTANT = 'EVC_CLUB_LOOKUP_KEY';
    /** D22: the member-QR derivation secret (EVC_Qr_Token::is_derivation_secret: >= 32 bytes, not the lookup key, not obviously non-random). */
    const QR_SECRET_CONSTANT = 'EVC_CLUB_QR_SECRET';
    /** The ledger table of EVC_Migrator. */
    const LEDGER_TABLE = 'evc_schema_migrations';
    /** Round 10 (LOW-1): the table of migration 004 the till writes the preparation into, and what it needs on it. */
    const PREPARATIONS_TABLE = EVC_Db_Grants::PREPARATIONS_TABLE;
    const PREPARATIONS_PRIVILEGES = EVC_Db_Grants::PREPARATIONS_PRIVILEGES;
    /** Round 12 (D26 part 2): the push subscriptions table of migration 005 and what the Club database user needs on it. */
    const PUSH_TABLE = EVC_Db_Grants::PUSH_TABLE;
    const PUSH_PRIVILEGES = EVC_Db_Grants::PUSH_PRIVILEGES;
    /** Migration 005. */
    const PUSH_SCHEMA_VERSION = 5;
    /** Greek text of each EVC_Push_Vapid problem code (never a value). */
    const VAPID_PROBLEMS = array(
        'openssl_missing' => 'Η PHP του διακομιστή δεν έχει όσα χρειάζονται για την κρυπτογράφηση των ειδοποιήσεων (επέκταση openssl).',
        'public_missing' => 'Λείπει το EVC_CLUB_PUSH_VAPID_PUBLIC.',
        'public_invalid' => 'Το EVC_CLUB_PUSH_VAPID_PUBLIC δεν είναι έγκυρο δημόσιο κλειδί.',
        'private_missing' => 'Λείπει το EVC_CLUB_PUSH_VAPID_PRIVATE.',
        'private_invalid' => 'Το EVC_CLUB_PUSH_VAPID_PRIVATE δεν είναι έγκυρο ιδιωτικό κλειδί.',
        'pair_mismatch' => 'Τα δύο κλειδιά δεν ανήκουν στο ίδιο ζεύγος: φτιάξτε νέο ζεύγος και αντιγράψτε και τα δύο μαζί.',
        'subject_missing' => 'Λείπει το EVC_CLUB_PUSH_SUBJECT (mailto: με τη διεύθυνση του καταστήματος).',
        'subject_invalid' => 'Το EVC_CLUB_PUSH_SUBJECT πρέπει να είναι mailto: με πραγματική διεύθυνση του καταστήματος.',
    );

    /** The tested matrix (CI): PHP 7.4 / 8.2 / 8.4; MySQL 8.0 / 8.4, MariaDB 10.11. */
    const SUPPORTED_PHP = array('7.4', '8.2', '8.4');
    const SUPPORTED_DB = array('mysql 8.0', 'mysql 8.4', 'mariadb 10.11');

    /** Known page-cache plugins (plugin file => name). */
    const CACHE_PLUGINS = array(
        'wp-rocket/wp-rocket.php' => 'WP Rocket',
        'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
        'wp-super-cache/wp-cache.php' => 'WP Super Cache',
        'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
        'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
        'breeze/breeze.php' => 'Breeze',
        'sg-cachepress/sg-cachepress.php' => 'SiteGround Speed Optimizer',
        'cache-enabler/cache-enabler.php' => 'Cache Enabler',
        'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
        'wp-optimize/wp-optimize.php' => 'WP-Optimize',
        'nitropack/main.php' => 'NitroPack',
    );
    /** Request headers a proxy / CDN adds. Only their NAMES are ever shown. */
    const PROXY_HEADERS = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_CLUSTER_CLIENT_IP');

    /** @var callable(): ?EVC_Db_Config */
    private $db_config;
    /** @var callable(EVC_Db_Config): EVC_Club_Db */
    private $connect;
    /** @var array<string,mixed> */
    private $server;
    /** @var array<string,mixed>|null */
    private $constants;

    /**
     * @param callable(): ?EVC_Db_Config|null $db_config TEST seam (default: the EVC_CLUB_DB_* constants)
     * @param callable(EVC_Db_Config): EVC_Club_Db|null $connect TEST seam (default: EVC_Club_Db::connect)
     * @param array<string,mixed>|null $server TEST seam (default: $_SERVER)
     * @param array<string,mixed>|null $constants TEST seam: values standing in for the provenance / lookup-key constants
     */
    public function __construct(?callable $db_config = null, ?callable $connect = null, ?array $server = null, ?array $constants = null) {
        EVC_Admin_Tools_Feature::assert_constructible(__CLASS__);
        $this->constants = $constants;
        $this->db_config = $db_config ?? array('EVC_Db_Config', 'from_constants');
        $this->connect = $connect ?? array('EVC_Club_Db', 'connect');
        $this->server = $server ?? $_SERVER;
    }

    /** @return array<int,array{section:string,key:string,label:string,status:string,detail:string}> */
    public function checks(): array {
        $sections = array(
            'database' => $this->database(),
            'flags' => $this->flags(),
            'shop' => $this->shop(),
            'crm' => $this->crm(),
            'push' => $this->push(),
            'server' => $this->server(),
            'staff' => $this->staff(),
        );
        $rows = array();
        foreach ($sections as $section => $list) {
            foreach ($list as $row) {
                $rows[] = array('section' => $section) + $row;
            }
        }
        return $rows;
    }

    private static function row(string $key, string $label, string $status, string $detail = ''): array {
        return array('key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail);
    }

    // ---- the Club database ------------------------------------------------------------------------------------

    private function database(): array {
        $config = ($this->db_config)();
        if (!$config instanceof EVC_Db_Config) {
            return array(self::row('club_db', 'Βάση δεδομένων του Club', self::MISSING, 'Δεν υπάρχουν τα EVC_CLUB_DB_* στο wp-config.php.'));
        }
        try {
            $db = ($this->connect)($config);
        } catch (Throwable $e) {
            $code = $e instanceof EVC_Db_Exception ? $e->driver_code() : null;
            return array(self::row('club_db', 'Βάση δεδομένων του Club', self::WRONG, 'Η σύνδεση απέτυχε (' . get_class($e) . ($code !== null ? ', σφάλμα MySQL ' . $code : '') . '). ' . self::db_connect_hint($code)));
        }
        $rows = array(self::row('club_db', 'Βάση δεδομένων του Club', self::OK, 'Η σύνδεση πέτυχε.'));
        $target = EVC_Club_Migrations_Feature::target_version();
        $unreadable = null;
        $present = false;
        try {
            // The ledger table of EVC_Migrator: absent = nothing applied; present = the highest applied version.
            $present = (int) ($db->fetch_one('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array(self::LEDGER_TABLE))['n'] ?? 0) > 0;
            $applied = $present ? (int) ($db->fetch_one('SELECT COALESCE(MAX(version), 0) AS v FROM ' . self::LEDGER_TABLE)['v'] ?? 0) : 0;
        } catch (Throwable $e) {
            // Never read as "no migration applied": the ledger exists or cannot be checked, and it could not be read.
            $applied = 0;
            $unreadable = get_class($e);
        }
        if ($target === null) {
            $rows[] = self::row('club_db_schema', 'Έκδοση σχήματος', self::WRONG, 'Το EVC_CLUB_MIGRATIONS_TARGET δεν είναι θετικός ακέραιος.');
        } elseif ($unreadable !== null) {
            $rows[] = self::row('club_db_schema', 'Έκδοση σχήματος', self::UNREADABLE, sprintf('Ο πίνακας μεταβάσεων δεν διαβάστηκε (%s). Δείτε τους ελέγχους στη σελίδα Εργαλεία → «Evangelou Club — Βάση δεδομένων» (στόχος %d).', $unreadable, $target));
        } elseif ($applied === 0) {
            // information_schema lists only the tables this user may see: no ledger = missing OR no privilege on it.
            $rows[] = self::row('club_db_schema', 'Έκδοση σχήματος', self::MISSING, $present
                ? sprintf('Δεν έχει εφαρμοστεί καμία μετάβαση (στόχος %d).', $target)
                : sprintf('Ο πίνακας μεταβάσεων λείπει ή ο χρήστης της βάσης δεν έχει δικαίωμα να τον δει· ελέγξτε τη σελίδα Εργαλεία → «Evangelou Club — Βάση δεδομένων» (στόχος %d).', $target));
        } else {
            $rows[] = self::row('club_db_schema', 'Έκδοση σχήματος', $applied >= $target ? self::OK : self::WRONG, sprintf('Εφαρμοσμένη έκδοση %d, στόχος %d. Κάθε νέα μετάβαση (π.χ. η 004) θέλει δική της έγκριση από τον ιδιοκτήτη.', $applied, $target));
        }
        $grants = null;
        if ($unreadable === null && $applied >= EVC_Coffee_History::PREPARED_SCHEMA_VERSION) {
            $grants = self::preparation_grants($db);
        }
        $rows[] = self::prepared_row($applied, $unreadable !== null, $grants);
        // Round 12 (D26 part 2): migration 005 and the rights on its table.
        $push_grants = null;
        if ($unreadable === null && $applied >= self::PUSH_SCHEMA_VERSION) {
            $push_grants = self::table_grants($db, self::PUSH_TABLE, self::PUSH_PRIVILEGES);
        }
        $rows[] = self::push_schema_row($applied, $unreadable !== null, $push_grants);
        try {
            $version = (string) ($db->fetch_one('SELECT VERSION() AS v')['v'] ?? '');
        } catch (Throwable $e) {
            $version = '';
        }
        $rows[] = self::db_version_row('club_db_server', 'Διακομιστής βάσης του Club', $version);
        return $rows;
    }

    /**
     * Round 10 (LOW-1): may the Club database user of this site write and read the preparation table of 004? Read
     * from the user's own SHOW GRANTS (a read; nothing is written, no test row): EVC_Db_Grants decides. Any failure
     * to read is "unknown", never "ok". The lines and the database name are never shown or kept.
     * @return array{state:string,missing:string[]}
     */
    private static function preparation_grants(EVC_Club_Db $db): array {
        return self::table_grants($db, self::PREPARATIONS_TABLE, self::PREPARATIONS_PRIVILEGES);
    }

    /**
     * The user's own rights on one table of the Club database, from SHOW GRANTS (a read only; EVC_Db_Grants decides).
     * @param string[] $privileges
     * @return array{state:string,missing:string[]}
     */
    private static function table_grants(EVC_Club_Db $db, string $table, array $privileges): array {
        try {
            $database = (string) ($db->fetch_one('SELECT DATABASE() AS d')['d'] ?? '');
            $lines = array();
            foreach ($db->fetch_all('SHOW GRANTS') as $row) {
                $lines[] = is_array($row) ? reset($row) : null;
            }
        } catch (Throwable $e) {
            return array('state' => EVC_Db_Grants::UNKNOWN, 'missing' => $privileges);
        }
        return EVC_Db_Grants::table_privileges($lines, $database, $table, $privileges);
    }

    /**
     * Round 12 (owner decision D26 part 2): can member push notifications be stored (migration 005 + the rights SELECT,
     * INSERT and UPDATE on its table)? Pure. Below 5 this is not an error: push simply stays hidden.
     * @param array{state:string,missing:string[]}|null $grants
     */
    public static function push_schema_row(int $applied, bool $unreadable, ?array $grants = null): array {
        $label = 'Ειδοποιήσεις μελών: πίνακας συνδρομών (μετάβαση 005)';
        if ($unreadable) {
            return self::row('push_schema', $label, self::UNREADABLE, 'Η έκδοση σχήματος δεν διαβάστηκε· δείτε τη γραμμή «Έκδοση σχήματος».');
        }
        if ($applied < self::PUSH_SCHEMA_VERSION) {
            return self::row('push_schema', $label, self::MANUAL, sprintf('Δεν υπάρχει ακόμη: εφαρμοσμένη έκδοση %d. Οι ειδοποιήσεις μένουν κρυφές στην κάρτα μέλους και δεν στέλνεται τίποτα. Για να ενεργοποιηθούν: έγκριση της μετάβασης 005 από τον ιδιοκτήτη, EVC_CLUB_MIGRATIONS_TARGET 5 στο wp-config.php και εφαρμογή από τη σελίδα μεταβάσεων.', $applied));
        }
        $state = $grants['state'] ?? EVC_Db_Grants::UNKNOWN;
        $names = implode(', ', self::PUSH_PRIVILEGES);
        if ($state === EVC_Db_Grants::MISSING) {
            return self::row('push_schema', $label, self::WRONG, sprintf('Η μετάβαση 005 έχει εφαρμοστεί, αλλά ο χρήστης της βάσης του Club (EVC_CLUB_DB_USER) δεν έχει δικαίωμα %s στον πίνακα %s: οι ειδοποιήσεις δεν θα αποθηκεύονται. Ζητήστε από τον τεχνικό να δώσει στον χρήστη τα δικαιώματα %s σε αυτόν τον πίνακα (ποτέ διαγραφή). Ο έλεγχος διαβάζει μόνο τα δικαιώματα· δεν γράφει τίποτα στη βάση.', implode(', ', $grants['missing'] ?? self::PUSH_PRIVILEGES), self::PUSH_TABLE, $names));
        }
        if ($state !== EVC_Db_Grants::OK) {
            return self::row('push_schema', $label, self::MANUAL, sprintf('Η μετάβαση 005 έχει εφαρμοστεί, αλλά από εδώ δεν μπορεί να επιβεβαιωθεί ότι ο χρήστης της βάσης του Club (EVC_CLUB_DB_USER) έχει τα δικαιώματα %s στον πίνακα %s (π.χ. επειδή δίνονται μέσω ρόλου ή η λίστα δικαιωμάτων δεν διαβάστηκε). Ζητήστε από τον τεχνικό να το επιβεβαιώσει. Ο έλεγχος διαβάζει μόνο τα δικαιώματα· δεν γράφει τίποτα στη βάση.', $names, self::PUSH_TABLE));
        }
        return self::row('push_schema', $label, self::OK, sprintf('Υπάρχει, και ο χρήστης της βάσης έχει τα δικαιώματα %s στον πίνακα %s.', $names, self::PUSH_TABLE));
    }

    /**
     * Round 9 (owner decision D24): can the till record HOW the coffee was made (migration 004 + the approved
     * catalogue)? Pure. Below 4 this is not an error: the till keeps recording the coffee type only.
     * Round 10 (LOW-1): at 4, also whether the database user may write and read the preparation table ($grants from
     * EVC_Db_Grants; null = not checked, read as "cannot tell").
     * @param array{state:string,missing:string[]}|null $grants
     */
    public static function prepared_row(int $applied, bool $unreadable, ?array $grants = null): array {
        $label = 'Καταγραφή προετοιμασίας καφέ στο ταμείο (μετάβαση 004)';
        if ($unreadable) {
            return self::row('prepared_redemption', $label, self::UNREADABLE, 'Η έκδοση σχήματος δεν διαβάστηκε· δείτε την προηγούμενη γραμμή.');
        }
        if ($applied < EVC_Coffee_History::PREPARED_SCHEMA_VERSION) {
            return self::row('prepared_redemption', $label, self::MANUAL, sprintf('Ανενεργή: εφαρμοσμένη έκδοση %d. Το ταμείο καταχωρεί μόνο το είδος του καφέ και το ιστορικό δείχνει μόνο ονόματα καφέ. Για να ενεργοποιηθεί: έγκριση της μετάβασης 004 από τον ιδιοκτήτη, EVC_CLUB_MIGRATIONS_TARGET 4 στο wp-config.php και εφαρμογή από τη σελίδα μεταβάσεων.', $applied));
        }
        if (!EVC_Club_Prep_Catalogue_Authority::is_authorised()) {
            return self::row('prepared_redemption', $label, self::WRONG, 'Ο κατάλογος καφέδων του πρόσθετου δεν ταιριάζει με τον εγκεκριμένο: το ταμείο καταχωρεί μόνο το είδος του καφέ. Ειδοποιήστε τον προγραμματιστή.');
        }
        $state = $grants['state'] ?? EVC_Db_Grants::UNKNOWN;
        $names = implode(', ', self::PREPARATIONS_PRIVILEGES);
        if ($state === EVC_Db_Grants::MISSING) {
            return self::row('prepared_redemption', $label, self::WRONG, sprintf('Η μετάβαση 004 έχει εφαρμοστεί, αλλά ο χρήστης της βάσης του Club (EVC_CLUB_DB_USER) δεν έχει δικαίωμα %s στον πίνακα %s: το ταμείο δεν θα μπορεί να καταχωρίσει την προετοιμασία του καφέ. Ζητήστε από τον τεχνικό να δώσει στον χρήστη δικαίωμα καταχώρησης και ανάγνωσης (%s) σε αυτόν τον πίνακα. Ο έλεγχος διαβάζει μόνο τα δικαιώματα· δεν γράφει τίποτα στη βάση.', implode(', ', $grants['missing'] ?? self::PREPARATIONS_PRIVILEGES), self::PREPARATIONS_TABLE, $names));
        }
        if ($state !== EVC_Db_Grants::OK) {
            return self::row('prepared_redemption', $label, self::MANUAL, sprintf('Η μετάβαση 004 έχει εφαρμοστεί και ο κατάλογος καφέδων είναι ο εγκεκριμένος (%s), αλλά από εδώ δεν μπορεί να επιβεβαιωθεί ότι ο χρήστης της βάσης του Club (EVC_CLUB_DB_USER) έχει δικαίωμα καταχώρησης και ανάγνωσης (%s) στον πίνακα %s, π.χ. επειδή τα δικαιώματα δίνονται μέσω ρόλου ή η λίστα δικαιωμάτων δεν διαβάστηκε. Ζητήστε από τον τεχνικό να το επιβεβαιώσει. Ο έλεγχος διαβάζει μόνο τα δικαιώματα· δεν γράφει τίποτα στη βάση.', EVC_Club_Prep_Catalogue_Authority::VERSION_LABEL, $names, self::PREPARATIONS_TABLE));
        }
        return self::row('prepared_redemption', $label, self::OK, sprintf('Ενεργή: το ταμείο καταγράφει δόση, ζάχαρη, γάλα, μέγεθος και σημείωση (κατάλογος %s). Ο χρήστης της βάσης έχει δικαίωμα καταχώρησης και ανάγνωσης (%s) στον πίνακα %s.', EVC_Club_Prep_Catalogue_Authority::VERSION_LABEL, $names, self::PREPARATIONS_TABLE));
    }

    /**
     * Pure: what to check after a failed Club-database connection, by the numeric driver code only (the driver's
     * message, which names the user and the server, is never kept: EVC_Db_Exception). STAGE-08 round 4: on staging
     * the database name in wp-config.php had one underscore instead of two and the old hint did not say where to look.
     * No value is ever shown, only which constant to compare letter by letter.
     */
    public static function db_connect_hint(?int $code): string {
        $values = ' Οι τιμές δεν εμφανίζονται εδώ: συγκρίνετέ τες γράμμα προς γράμμα (και κάθε «_») με τα στοιχεία του παρόχου.';
        switch ($code) {
            case 1044:
                return 'Ο χρήστης δεν έχει πρόσβαση σε αυτή τη βάση: ελέγξτε στο wp-config.php ότι το EVC_CLUB_DB_NAME είναι ακριβώς το όνομα της βάσης και ότι ο χρήστης (EVC_CLUB_DB_USER) έχει δικαιώματα σε αυτήν.' . $values;
            case 1045:
                return 'Ο χρήστης ή ο κωδικός δεν έγινε δεκτός: ελέγξτε στο wp-config.php τα EVC_CLUB_DB_USER και EVC_CLUB_DB_PASSWORD.' . $values;
            case 1049:
                return 'Η βάση δεν υπάρχει: ελέγξτε στο wp-config.php το EVC_CLUB_DB_NAME.' . $values;
            case 2002:
            case 2003:
            case 2005:
            case 2006:
                return 'Ο διακομιστής της βάσης δεν απαντά: ελέγξτε στο wp-config.php τα EVC_CLUB_DB_HOST και EVC_CLUB_DB_PORT.' . $values;
            default:
                return 'Ελέγξτε στο wp-config.php τα EVC_CLUB_DB_NAME, EVC_CLUB_DB_USER, EVC_CLUB_DB_PASSWORD και EVC_CLUB_DB_HOST, και ότι ο χρήστης έχει δικαιώματα σε αυτή τη βάση (π.χ. σφάλμα MySQL 1044).' . $values;
        }
    }

    // ---- the Club flags (each class's own parser) -------------------------------------------------------------

    private function flags(): array {
        $rows = array(
            self::flag('flag_customer', 'Σελίδα μέλους (EVC_CLUB_CUSTOMER_ENABLED)', EVC_Customer_Feature::CONSTANT, array('EVC_Customer_Feature', 'is_enabled_value')),
            self::flag('flag_staff', 'Ταμείο (EVC_CLUB_STAFF_ENABLED)', EVC_Staff_Feature::CONSTANT, array('EVC_Staff_Feature', 'is_enabled_value')),
            self::flag('flag_redemption', 'Εξαργύρωση από τη βάση του Club (EVC_CLUB_REDEMPTION_SOURCE)', EVC_Club_Redemption_Feature::CONSTANT, array('EVC_Club_Redemption_Feature', 'is_enabled_value')),
            self::flag('flag_onboarding', 'Εγγραφή στο κατάστημα (EVC_CLUB_ONBOARDING_ENABLED)', EVC_Onboarding_Feature::CONSTANT, array('EVC_Onboarding_Feature', 'is_enabled_value')),
            self::flag('flag_fluentcrm', 'Ετικέτες FluentCRM (EVC_CLUB_FLUENTCRM_SYNC_ENABLED)', EVC_Fluentcrm_Feature::CONSTANT, array('EVC_Fluentcrm_Feature', 'is_enabled_value')),
            self::flag('flag_wc_completion', 'Επιβεβαίωση πληρωμής από το WooCommerce (EVC_CLUB_WC_COMPLETION_ENABLED)', EVC_Wc_Completion_Feature::CONSTANT, array('EVC_Wc_Completion_Feature', 'is_enabled_value')),
            self::flag('flag_migrations', 'Σελίδα μεταβάσεων βάσης (EVC_CLUB_MIGRATIONS_ENABLED)', EVC_Club_Migrations_Feature::CONSTANT, array('EVC_Club_Migrations_Feature', 'is_enabled_value')),
            self::flag('flag_admin_tools', 'Σελίδες διαχείρισης Club (EVC_CLUB_ADMIN_TOOLS_ENABLED)', EVC_Admin_Tools_Feature::CONSTANT, array('EVC_Admin_Tools_Feature', 'is_enabled_value')),
            self::flag('flag_push', 'Ειδοποιήσεις μελών (EVC_CLUB_PUSH_ENABLED)', EVC_Push_Feature::CONSTANT, array('EVC_Push_Feature', 'is_enabled_value')),
            self::flag('flag_push_auto_activated', 'Αυτόματη ειδοποίηση «Η συνδρομή σας ενεργοποιήθηκε» (EVC_CLUB_PUSH_AUTO_ACTIVATED)', EVC_Push_Feature::AUTO_ACTIVATED_CONSTANT, array('EVC_Push_Feature', 'is_enabled_value')),
            self::flag('flag_push_auto_expiry', 'Αυτόματη ειδοποίηση «Η συνδρομή σας λήγει σε 3 ημέρες» (EVC_CLUB_PUSH_AUTO_EXPIRY)', EVC_Push_Feature::AUTO_EXPIRY_CONSTANT, array('EVC_Push_Feature', 'is_enabled_value')),
        );
        if (defined(EVC_Push_Feature::CONSTANT) && EVC_Push_Feature::is_enabled_value(constant(EVC_Push_Feature::CONSTANT)) && !EVC_Customer_Feature::is_enabled_value(defined(EVC_Customer_Feature::CONSTANT) ? constant(EVC_Customer_Feature::CONSTANT) : null)) {
            $rows[8] = self::row('flag_push', $rows[8]['label'], self::WRONG, 'Ενεργή, αλλά χρειάζεται και τη σελίδα μέλους (EVC_CLUB_CUSTOMER_ENABLED).');
        }
        if (defined(EVC_Onboarding_Feature::CONSTANT) && EVC_Onboarding_Feature::is_enabled_value(constant(EVC_Onboarding_Feature::CONSTANT)) && !EVC_Onboarding_Feature::enabled()) {
            $rows[3] = self::row('flag_onboarding', $rows[3]['label'], self::WRONG, 'Ενεργή, αλλά χρειάζεται και τη σελίδα μέλους (EVC_CLUB_CUSTOMER_ENABLED).');
        }
        return $rows;
    }

    private static function flag(string $key, string $label, string $constant, callable $parser): array {
        if (!defined($constant)) {
            return self::row($key, $label, self::OFF, 'Δεν έχει οριστεί.');
        }
        return $parser(constant($constant)) ? self::row($key, $label, self::OK, 'Ενεργή.') : self::row($key, $label, self::WRONG, 'Ορίστηκε, αλλά όχι με την ακριβή τιμή που την ενεργοποιεί.');
    }

    // ---- the shop -------------------------------------------------------------------------------------------

    private function shop(): array {
        $rows = array();
        $config = EVC_Commerce_Source_Config::from_constants();
        if ($config === null) {
            $any = false;
            foreach (array(EVC_Commerce_Source_Config::SOURCE_CONSTANT, EVC_Commerce_Source_Config::LEVEL_CONSTANT, EVC_Commerce_Source_Config::PRODUCT_CONSTANT, EVC_Commerce_Source_Config::KEY_CONSTANT) as $c) {
                $any = $any || defined($c);
            }
            $rows[] = self::row('commerce_config', 'Ρυθμίσεις καταστήματος (προϊόν, επίπεδο, κλειδί)', $any ? self::WRONG : self::MISSING, $any ? 'Ορίστηκαν, αλλά λείπει κάποιο ή δεν είναι έγκυρο.' : 'Δεν έχουν οριστεί.');
        } else {
            $rows[] = self::row('commerce_config', 'Ρυθμίσεις καταστήματος (προϊόν, επίπεδο, κλειδί)', self::OK, sprintf('Προϊόν #%d, επίπεδο PMPro #%d.', $config->product_id, $config->level_id));
            if (!EVC_Wp_Commerce_Pmpro_Reader::commerce_plugins_loaded()) {
                $rows[] = self::row('commerce_environment', 'WooCommerce / PMPro', self::MISSING, 'Δεν είναι ενεργά.');
            } else {
                $conflicts = EVC_Wp_Commerce_Pmpro_Reader::environment_conflicts($config);
                $rows[] = self::row('commerce_environment', 'Προϊόν Club: εκδόσεις, τιμή 20.00 €, μόνο του, επίπεδο ενός μήνα', $conflicts === array() ? self::OK : self::WRONG, $conflicts === array() ? 'Όλα όπως πρέπει.' : 'Κωδικοί: ' . implode(', ', $conflicts));
                $site = EVC_Bacs_Payment_Issuer::site_problem($config);
                $rows[] = self::row('commerce_site', 'Επίπεδο PMPro και ρυθμίσεις ιστότοπου', $site === null ? self::OK : self::WRONG, $site === null ? 'Όλα όπως πρέπει.' : 'Κωδικός: ' . $site);
            }
        }
        $rows[] = $this->provenance_row();
        $rows[] = $this->lookup_key_row();
        $rows[] = $this->qr_secret_row();
        if (!function_exists('WC')) {
            $rows[] = self::row('bacs', 'Τραπεζική μεταφορά (BACS)', self::MISSING, 'Το WooCommerce δεν είναι ενεργό.');
        } else {
            $bacs = get_option('woocommerce_bacs_settings', array());
            $on = is_array($bacs) && ($bacs['enabled'] ?? '') === 'yes';
            $rows[] = self::row('bacs', 'Τραπεζική μεταφορά (BACS)', $on ? self::OK : self::WRONG, $on ? 'Ενεργή (ο μόνος τρόπος πληρωμής της συνδρομής, D15).' : 'Ανενεργή: η συνδρομή δεν θα μπορεί να πληρωθεί (D15).');
        }
        if (!defined('EVC_CLUB_TERMS_URL')) {
            $rows[] = self::row('terms_url', 'Σελίδα όρων του Club (EVC_CLUB_TERMS_URL, D13)', self::MISSING, 'Δεν έχει οριστεί· εμφανίζεται μόνο η πρόταση των όρων.');
        } else {
            $url = is_string(constant('EVC_CLUB_TERMS_URL')) ? EVC_Onboarding_Shell::same_site_url((string) constant('EVC_CLUB_TERMS_URL')) : '';
            $rows[] = self::row('terms_url', 'Σελίδα όρων του Club (EVC_CLUB_TERMS_URL, D13)', $url !== '' ? self::OK : self::WRONG, $url !== '' ? 'Ορίστηκε, στον ίδιο ιστότοπο.' : 'Δεν είναι διεύθυνση αυτού του ιστότοπου.');
        }
        return $rows;
    }

    /** @return array{0:bool,1:mixed} defined?, value (the TEST seam stands in for the real constant) */
    private function constant_value(string $name): array {
        if ($this->constants !== null && array_key_exists($name, $this->constants)) {
            return array(true, $this->constants[$name]);
        }
        return defined($name) ? array(true, constant($name)) : array(false, null);
    }

    /** Pure: the status of EVC_CLUB_PAYMENT_PROVENANCE. OK only for the exact string 'club_db_v1'. */
    public static function provenance_status(bool $defined, $value): string {
        if (!$defined) {
            return self::MISSING;
        }
        return $value === self::PROVENANCE_VALUE ? self::OK : self::WRONG;
    }

    /** Pure: the status of EVC_CLUB_LOOKUP_KEY, by the lookup backend's own rule (a string of at least 32 bytes). */
    public static function lookup_key_status(bool $defined, $value): string {
        if (!$defined) {
            return self::MISSING;
        }
        return EVC_Club_Qr_Services::is_lookup_secret($value) ? self::OK : self::WRONG;
    }

    /** Pure: the status of EVC_CLUB_QR_SECRET, by the customer QR backend's own rule (D22). */
    public static function qr_secret_status(bool $defined, $value, $lookup_key = null): string {
        if (!$defined) {
            return self::MISSING;
        }
        return EVC_Qr_Token::is_derivation_secret($value, $lookup_key) ? self::OK : self::WRONG;
    }

    /** The value is never shown, only whether it is the exact enabling value. */
    private function provenance_row(): array {
        list($defined, $value) = $this->constant_value(self::PROVENANCE_CONSTANT);
        $status = self::provenance_status($defined, $value);
        $details = array(
            self::OK => 'Ορίστηκε ακριβώς: οι πληρωμές καταγράφονται στη βάση του Club.',
            self::MISSING => 'Δεν έχει οριστεί: η επιβεβαίωση πληρωμής, η σελίδα μέλους με QR και η εξαργύρωση μένουν κλειστές.',
            self::WRONG => 'Ορίστηκε, αλλά όχι με την ακριβή τιμή που το ενεργοποιεί: η επιβεβαίωση πληρωμής, η σελίδα μέλους με QR και η εξαργύρωση μένουν κλειστές.',
        );
        return self::row('payment_provenance', 'Καταγραφή πληρωμών στη βάση του Club (EVC_CLUB_PAYMENT_PROVENANCE)', $status, $details[$status]);
    }

    /** The key is never shown, nor its length: only present-and-valid or not. */
    private function lookup_key_row(): array {
        list($defined, $value) = $this->constant_value(self::LOOKUP_KEY_CONSTANT);
        $status = self::lookup_key_status($defined, $value);
        $details = array(
            self::OK => 'Ορίστηκε και είναι έγκυρο (τουλάχιστον 32 bytes).',
            self::MISSING => 'Δεν έχει οριστεί: το σκανάρισμα του QR στο ταμείο μένει κλειστό.',
            self::WRONG => 'Ορίστηκε, αλλά δεν είναι έγκυρο (χρειάζεται κείμενο τουλάχιστον 32 bytes): το σκανάρισμα του QR μένει κλειστό.',
        );
        return self::row('lookup_key', 'Κλειδί σκαναρίσματος QR (EVC_CLUB_LOOKUP_KEY)', $status, $details[$status]);
    }

    /** D22: the secret is never shown, nor its length: only present-and-valid or not. */
    private function qr_secret_row(): array {
        list($defined, $value) = $this->constant_value(self::QR_SECRET_CONSTANT);
        list($lookup_defined, $lookup) = $this->constant_value(self::LOOKUP_KEY_CONSTANT);
        $status = self::qr_secret_status($defined, $value, $lookup_defined ? $lookup : null);
        $details = array(
            self::OK => 'Ορίστηκε και είναι έγκυρο (τουλάχιστον 32 χαρακτήρες, τυχαίο, διαφορετικό από το κλειδί σκαναρίσματος).',
            self::MISSING => 'Δεν έχει οριστεί: η κάρτα μέλους δεν δείχνει κωδικό QR.',
            self::WRONG => 'Ορίστηκε, αλλά δεν είναι έγκυρο (χρειάζεται τυχαίο κείμενο τουλάχιστον 32 χαρακτήρων: όχι το παράδειγμα των οδηγιών, ούτε έναν χαρακτήρα που επαναλαμβάνεται, και διαφορετικό από το κλειδί σκαναρίσματος): η κάρτα μέλους δεν δείχνει κωδικό QR.',
        );
        return self::row('qr_secret', 'Μυστικό κλειδί του κωδικού QR μέλους (EVC_CLUB_QR_SECRET)', $status, $details[$status]);
    }

    // ---- member push notifications (round 12, owner decision D26 part 2) ------------------------------------------

    /** Pure: the status of the three VAPID constants by EVC_Push_Vapid's own rule. No value, no length is ever shown. */
    public static function vapid_row(bool $any_defined, ?string $problem, bool $push_on): array {
        $label = 'Κλειδιά ειδοποιήσεων (EVC_CLUB_PUSH_VAPID_PUBLIC, EVC_CLUB_PUSH_VAPID_PRIVATE, EVC_CLUB_PUSH_SUBJECT)';
        if (!$any_defined) {
            return self::row('push_vapid', $label, $push_on ? self::MISSING : self::OFF, 'Δεν έχουν οριστεί. Φτιάχνονται με την εντολή μίας γραμμής του οδηγού (member-push.md) στον υπολογιστή του τεχνικού ή στον διακομιστή, και μπαίνουν κατευθείαν στο wp-config.php: δεν στέλνονται ποτέ σε κανέναν. Χωρίς αυτά οι ειδοποιήσεις μένουν κρυφές.');
        }
        if ($problem !== null) {
            return self::row('push_vapid', $label, self::WRONG, (self::VAPID_PROBLEMS[$problem] ?? 'Δεν είναι έγκυρα.') . ' Οι ειδοποιήσεις μένουν κρυφές και δεν στέλνεται τίποτα μέχρι να διορθωθούν. Οι τιμές δεν εμφανίζονται εδώ.');
        }
        return self::row('push_vapid', $label, self::OK, 'Ορίστηκαν και είναι έγκυρα: το δημόσιο κλειδί ταιριάζει με το ιδιωτικό. Οι τιμές δεν εμφανίζονται εδώ.');
    }

    private function push(): array {
        $push_on = defined(EVC_Push_Feature::CONSTANT) && EVC_Push_Feature::is_enabled_value(constant(EVC_Push_Feature::CONSTANT));
        $values = array();
        $any = false;
        foreach (array('public' => 'EVC_CLUB_PUSH_VAPID_PUBLIC', 'private' => 'EVC_CLUB_PUSH_VAPID_PRIVATE', 'subject' => 'EVC_CLUB_PUSH_SUBJECT') as $k => $name) {
            list($defined, $value) = $this->constant_value($name);
            $any = $any || $defined;
            $values[$k] = $defined ? $value : null;
        }
        $rows = array(self::vapid_row($any, $any ? EVC_Push_Vapid::keys_problem($values['public'], $values['private'], $values['subject']) : null, $push_on));
        if ($push_on && (string) get_option('permalink_structure', '') === '') {
            $rows[] = self::row('push_worker', 'Ειδοποιήσεις: εφαρμογή παρασκηνίου της κάρτας', self::WRONG, 'Οι μόνιμοι σύνδεσμοι είναι «Απλοί»: η κάρτα δεν έχει εφαρμογή παρασκηνίου, οπότε δεν μπορεί να δεχτεί ειδοποιήσεις (το μέλος βλέπει μόνο μια εξήγηση). Ρυθμίσεις → Μόνιμοι σύνδεσμοι → Όνομα άρθρου.');
        } else {
            $rows[] = self::row('push_worker', 'Ειδοποιήσεις: εφαρμογή παρασκηνίου της κάρτας', self::MANUAL, 'Ανοίξτε στον περιηγητή τις δύο διευθύνσεις της κάρτας που γράφει ο οδηγός member-pwa.md (η εφαρμογή παρασκηνίου και η περιγραφή της εφαρμογής, κάτω από το /club-member/): πρέπει να φορτώνουν, όχι σφάλμα 404. Ένας κανόνας του διακομιστή για αρχεία σεναρίων μπορεί να τις κόβει.');
        }
        $rows[] = self::row('push_outbound', 'Ειδοποιήσεις: έξοδος του διακομιστή προς τις υπηρεσίες ειδοποιήσεων', self::MANUAL, 'Ο τεχνικός επιβεβαίωσε (2026-11-20) έξοδο HTTPS προς fcm.googleapis.com, web.push.apple.com και updates.push.services.mozilla.com. Για τον Microsoft Edge στα Windows χρειάζεται επίσης έξοδος προς *.notify.windows.com (δεν έχει επιβεβαιωθεί). Η σελίδα αυτή δεν κάνει δοκιμαστική αποστολή.');
        return $rows;
    }

    // ---- CRM ----------------------------------------------------------------------------------------------

    private function crm(): array {
        if (EVC_Fluentcrm_Feature::enabled()) {
            $problem = EVC_Fluentcrm_Plugin::problem();
            $rows = array(self::row('crm_tags', 'Ετικέτες FluentCRM', $problem === null ? self::OK : self::WRONG, $problem === null ? 'Επαληθεύτηκαν στο FluentCRM.' : 'Κωδικός: ' . $problem));
        } else {
            $any = false;
            foreach (EVC_Fluentcrm_Feature::TAG_CONSTANTS as $pair) {
                $any = $any || defined($pair[0]) || defined($pair[1]);
            }
            list($config, $problem) = EVC_Fluentcrm_Feature::tag_config();
            if (!$any) {
                $rows = array(self::row('crm_tags', 'Ετικέτες FluentCRM', self::OFF, 'Δεν έχουν οριστεί (χρειάζονται μόνο με τον συγχρονισμό).'));
            } elseif ($config === null) {
                $rows = array(self::row('crm_tags', 'Ετικέτες FluentCRM', self::WRONG, 'Κωδικός: ' . (string) $problem));
            } else {
                $rows = array(self::row('crm_tags', 'Ετικέτες FluentCRM', self::MANUAL, 'Ορίστηκαν· επαληθεύονται στο FluentCRM όταν ενεργοποιηθεί ο συγχρονισμός.'));
            }
        }
        $rows[] = self::row('crm_registration', 'Στο FluentCRM: νέες επαφές από εγγραφή χρήστη', self::MANUAL, 'Στο FluentCRM → Settings → General: το «create new FluentCRM contacts on WP user registration» πρέπει να μείνει ΑΠΟΕΠΙΛΕΓΜΕΝΟ (D12).');
        $rows[] = self::row('crm_funnels', 'Στο FluentCRM: αυτοματισμοί «New User Sign Up»', self::MANUAL, 'Στο FluentCRM → Automations: κανένας ενεργός αυτοματισμός με έναυσμα «New User Sign Up», ή εξαιρείται ο ρόλος customer.');
        // D20 (2026-11-18): the WooCommerce integration stays, with Default Contact Status «Transactional»; consent comes
        // only from FluentCRM's own checkout checkbox, unchecked by default. FluentCRM keeps both settings in its own
        // storage, which this page does not read (only FluentCRM's API may be called, and only by the gateway), so
        // they stay manual checks.
        $rows[] = self::row('crm_woocommerce', 'Στο FluentCRM: σύνδεση με WooCommerce (D20)', self::MANUAL, 'Στο FluentCRM → Settings → Integrations → WooCommerce: το «Default Contact Status» πρέπει να είναι «Transactional» (όχι «Subscribed»), ώστε κανένας αγοραστής, ούτε όσοι γράφονται στο Club, να μη γίνεται συνδρομητής newsletter χωρίς να το ζητήσει (D20).');
        $rows[] = self::row('crm_checkout_checkbox', 'Στο FluentCRM: κουτάκι newsletter στο checkout (D20)', self::MANUAL, 'Στο FluentCRM → Settings → General: ενεργό το «Add a subscription checkbox to the WooCommerce checkout page», με το αυτόματο τσεκάρισμα ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΟ (το κουτάκι ξεκινά άδειο). Το FluentCRM δείχνει το κουτάκι μόνο στο κλασικό checkout (shortcode)· ελέγξτε το στη σελίδα ολοκλήρωσης αγοράς. Χρειάζεται τη συγκατάθεση του καταστήματος πριν από την παραγωγή.');
        return $rows;
    }

    // ---- the server -------------------------------------------------------------------------------------------

    private function server(): array {
        global $wpdb;
        $headers = array();
        foreach (self::PROXY_HEADERS as $h) {
            if (isset($this->server[$h]) && $this->server[$h] !== '') {
                $headers[] = substr($h, 5);
            }
        }
        $rows = array(self::row('remote_addr', 'Πραγματική διεύθυνση πελάτη (REMOTE_ADDR)', self::MANUAL, $headers === array()
            ? 'Δεν φάνηκε επικεφαλίδα proxy σε αυτό το αίτημα. Επιβεβαιώστε το όπως λέει ο οδηγός (go-live.md).'
            : 'Υπάρχουν επικεφαλίδες proxy/CDN (' . implode(', ', $headers) . '): ο διακομιστής πρέπει να επαναφέρει τη διεύθυνση του πελάτη, αλλιώς όλοι μοιράζονται ένα όριο.'));
        $active = (array) get_option('active_plugins', array());
        $found = array();
        foreach (self::CACHE_PLUGINS as $file => $name) {
            if (in_array($file, $active, true)) {
                $found[] = $name;
            }
        }
        if (defined('WP_CACHE') && WP_CACHE) {
            $found[] = 'WP_CACHE';
        }
        $rows[] = self::row('page_cache', 'Cache σελίδων', $found === array() ? self::OK : self::MANUAL, $found === array()
            ? 'Δεν βρέθηκε plugin cache σελίδων.'
            : 'Βρέθηκαν: ' . implode(', ', $found) . '. Βεβαιωθείτε ότι δεν αποθηκεύει τα /club/join/ και /club-member/.');
        $rows[] = self::permalinks_row((string) get_option('permalink_structure', ''));
        $zone = (string) get_option('timezone_string', '');
        $rows[] = self::row('timezone', 'Ζώνη ώρας του ιστότοπου', $zone === EVC_Membership_Calendar::ZONE ? self::OK : self::WRONG, $zone === EVC_Membership_Calendar::ZONE ? EVC_Membership_Calendar::ZONE : 'Είναι «' . ($zone === '' ? 'UTC±' : $zone) . '», πρέπει να είναι ' . EVC_Membership_Calendar::ZONE . ' (Ρυθμίσεις → Γενικά).');
        $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $rows[] = self::row('php_version', 'Έκδοση PHP', in_array($php, self::SUPPORTED_PHP, true) ? self::OK : (version_compare($php, '7.4', '<') ? self::WRONG : self::MANUAL), 'PHP ' . $php . ' (δοκιμασμένες: ' . implode(', ', self::SUPPORTED_PHP) . ').');
        $rows[] = self::db_version_row('wp_db_server', 'Διακομιστής βάσης του WordPress', method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '');
        return $rows;
    }

    /**
     * Pure: Settings → Permalinks. STAGE-08 round 4: staging ran with «Plain»; /club-admin/ and /club-member/ were then an
     * Apache 404 that never reached WordPress. The plugin now also works with plain URLs (?evc_club_admin=1, …), but
     * the printed poster QR, the bookmarks on the till tablet and the tested go-live path all use the pretty ones, so
     * «Plain» reads WRONG; an /index.php/… structure is a manual check.
     */
    public static function permalinks_row(string $structure): array {
        $label = 'Μόνιμοι σύνδεσμοι (Permalinks)';
        $fix = 'Ρυθμίσεις → Μόνιμοι σύνδεσμοι → Όνομα άρθρου.';
        if ($structure === '') {
            return self::row('permalinks', $label, self::WRONG, 'Είναι «Απλοί»: οι σελίδες /club-admin/, /club-member/ και /club/join/ δεν φτάνουν στο WordPress και οι συνδέσεις οδηγούν σε σφάλμα 404. ' . $fix);
        }
        if (strpos($structure, 'index.php') !== false) {
            return self::row('permalinks', $label, self::MANUAL, 'Περιέχουν index.php: ανοίξτε τις σελίδες /club-member/ και /club-admin/ για να βεβαιωθείτε ότι φορτώνουν, αλλιώς ' . $fix);
        }
        return self::row('permalinks', $label, self::OK, 'Όμορφοι σύνδεσμοι: οι σελίδες /club-admin/, /club-member/ και /club/join/ φτάνουν στο WordPress.');
    }

    /** "8.0.36" / "5.5.5-10.11.6-MariaDB" -> "mysql 8.0" / "mariadb 10.11", compared with the tested matrix. */
    public static function db_family(string $version): string {
        if (stripos($version, 'mariadb') !== false && preg_match('/(?:^|-)(1\d)\.(\d+)/', $version, $m) === 1) {
            return 'mariadb ' . $m[1] . '.' . $m[2];
        }
        return preg_match('/^(\d+)\.(\d+)/', $version, $m) === 1 ? 'mysql ' . $m[1] . '.' . $m[2] : '';
    }

    private static function db_version_row(string $key, string $label, string $version): array {
        $family = self::db_family($version);
        if ($family === '') {
            return self::row($key, $label, self::MANUAL, 'Η έκδοση δεν διαβάστηκε.');
        }
        return self::row($key, $label, in_array($family, self::SUPPORTED_DB, true) ? self::OK : self::MANUAL, $family . ' (δοκιμασμένες: ' . implode(', ', self::SUPPORTED_DB) . ').');
    }

    // ---- the shared till account (D18) --------------------------------------------------------------------------

    private function staff(): array {
        $accounts = EVC_Staff_Account_Reader::accounts();
        $label = 'Κοινός λογαριασμός ταμείου (D18)';
        if ($accounts === array()) {
            return array(self::row('staff_account', $label, self::MISSING, 'Δεν υπάρχει· δημιουργήστε τον από «Λογαριασμός ταμείου».'));
        }
        if (count($accounts) > 1) {
            return array(self::row('staff_account', $label, self::WRONG, sprintf('Υπάρχουν %d λογαριασμοί ταμείου· πρέπει να είναι ένας.', count($accounts))));
        }
        if (!$accounts[0]['restricted']) {
            return array(self::row('staff_account', $label, self::WRONG, 'Έχει δικαιώματα πέρα από τον ρόλο του ταμείου.'));
        }
        return array(self::row('staff_account', $label, $accounts[0]['disabled'] ? self::MANUAL : self::OK, $accounts[0]['disabled'] ? 'Υπάρχει, αλλά είναι απενεργοποιημένος.' : 'Υπάρχει ένας, ενεργός.'));
    }
}
