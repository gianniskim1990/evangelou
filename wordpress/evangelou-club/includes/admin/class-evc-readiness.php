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
    /** The ledger table of EVC_Migrator. */
    const LEDGER_TABLE = 'evc_schema_migrations';

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
            return array(self::row('club_db', 'Βάση δεδομένων του Club', self::WRONG, 'Η σύνδεση απέτυχε (' . get_class($e) . '). Ελέγξτε τα στοιχεία σύνδεσης και τα δικαιώματα του χρήστη (π.χ. σφάλμα MySQL 1044).'));
        }
        $rows = array(self::row('club_db', 'Βάση δεδομένων του Club', self::OK, 'Η σύνδεση πέτυχε.'));
        $target = EVC_Club_Migrations_Feature::target_version();
        $unreadable = null;
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
            $rows[] = self::row('club_db_schema', 'Έκδοση σχήματος', self::MISSING, sprintf('Δεν έχει εφαρμοστεί καμία μετάβαση (στόχος %d).', $target));
        } else {
            $rows[] = self::row('club_db_schema', 'Έκδοση σχήματος', $applied >= $target ? self::OK : self::WRONG, sprintf('Εφαρμοσμένη έκδοση %d, στόχος %d. Η μετάβαση 003 θέλει δική της έγκριση.', $applied, $target));
        }
        try {
            $version = (string) ($db->fetch_one('SELECT VERSION() AS v')['v'] ?? '');
        } catch (Throwable $e) {
            $version = '';
        }
        $rows[] = self::db_version_row('club_db_server', 'Διακομιστής βάσης του Club', $version);
        return $rows;
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
        );
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
        $rows[] = self::row('crm_woocommerce', 'Στο FluentCRM: σύνδεση με WooCommerce', self::MANUAL, 'Στο FluentCRM → Settings → Integrations → WooCommerce: ανενεργή, ή εξαιρούνται αυτοί οι πελάτες.');
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
        $zone = (string) get_option('timezone_string', '');
        $rows[] = self::row('timezone', 'Ζώνη ώρας του ιστότοπου', $zone === EVC_Membership_Calendar::ZONE ? self::OK : self::WRONG, $zone === EVC_Membership_Calendar::ZONE ? EVC_Membership_Calendar::ZONE : 'Είναι «' . ($zone === '' ? 'UTC±' : $zone) . '», πρέπει να είναι ' . EVC_Membership_Calendar::ZONE . ' (Ρυθμίσεις → Γενικά).');
        $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $rows[] = self::row('php_version', 'Έκδοση PHP', in_array($php, self::SUPPORTED_PHP, true) ? self::OK : (version_compare($php, '7.4', '<') ? self::WRONG : self::MANUAL), 'PHP ' . $php . ' (δοκιμασμένες: ' . implode(', ', self::SUPPORTED_PHP) . ').');
        $rows[] = self::db_version_row('wp_db_server', 'Διακομιστής βάσης του WordPress', method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '');
        return $rows;
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
