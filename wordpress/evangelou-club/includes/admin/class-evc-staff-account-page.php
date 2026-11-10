<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08: «Evangelou Club → Λογαριασμός ταμείου», the administrator's one screen for the shared till account (D18).
 * Constructed ONLY by EVC_Admin_Tools_Plugin::wire() behind EVC_CLUB_ADMIN_TOOLS_ENABLED.
 *
 * Actions are POSTs handled on the page's load-* hook (before any output): the WordPress nonce, then the capability
 * (EVC_Staff_Account_Service::may_manage), then the action. The nonce is bound to the action AND the target account
 * (nonce_action()), so a captured form of one action cannot be replayed as another, or against another account. A
 * generated password is shown ONCE in the answer to that POST and is never stored (no redirect, no transient), with
 * nocache_headers() and an explicit `Cache-Control: no-store`. Every value printed is escaped; no IP address or
 * personal data is shown. A refused nonce never reaches the service, so it is not audited.
 */
final class EVC_Staff_Account_Page {
    const MENU_SLUG = 'evc-club';
    const TITLE = 'Evangelou Club — Λογαριασμός ταμείου';
    const MENU_TITLE = 'Evangelou Club';
    const SUBMENU_TITLE = 'Λογαριασμός ταμείου';
    const CAPABILITY = 'manage_options';
    const NONCE_ACTION = 'evc_club_staff_account';
    const FIELD_ACTION = 'evc_staff_action';
    const FIELD_USER = 'evc_staff_user';
    const FIELD_LOGIN = 'evc_staff_login';
    const NOT_ALLOWED = 'Δεν έχετε δικαίωμα για αυτή τη σελίδα.';
    const BAD_NONCE = 'bad_nonce';

    const MESSAGES = array(
        'ok' => 'Έγινε.',
        'forbidden' => 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.',
        'exists' => 'Υπάρχει ήδη λογαριασμός ταμείου. Δεν δημιουργήθηκε δεύτερος.',
        'invalid_login' => 'Το όνομα χρήστη δεν είναι έγκυρο (4–60 πεζά λατινικά, αριθμοί, - _ . χωρίς κενά).',
        'login_taken' => 'Το όνομα χρήστη χρησιμοποιείται ήδη.',
        'role_missing' => 'Ο ρόλος του ταμείου δεν υπάρχει. Απενεργοποιήστε και ενεργοποιήστε ξανά το plugin.',
        'not_staff' => 'Αυτός ο χρήστης δεν είναι λογαριασμός ταμείου.',
        'not_restricted' => 'Ο λογαριασμός έχει περισσότερα δικαιώματα από τον ρόλο του ταμείου, οπότε μένει απενεργοποιημένος: δεν παίρνει κωδικό και δεν ενεργοποιείται. Ενημερώστε τον προγραμματιστή.',
        'failed' => 'Η ενέργεια δεν ολοκληρώθηκε. Δεν άλλαξε τίποτα.',
        'bad_nonce' => 'Η σελίδα έληξε. Ανοίξτε την ξανά και δοκιμάστε πάλι.',
    );
    /** Sent with the answer that carries a generated password (on top of nocache_headers()). */
    const NO_STORE_HEADER = 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private';
    const ACTION_LABELS = array('create' => 'Δημιουργία', 'password' => 'Νέος κωδικός', 'disable' => 'Απενεργοποίηση', 'enable' => 'Ενεργοποίηση', 'revoke' => 'Αποσύνδεση παντού');

    /** @var EVC_Staff_Account_Service */
    private $service;
    /** @var array<string,mixed>|null the answer to this request's POST */
    private $result;
    /** @var callable(string): void */
    private $header;

    /** @param callable(string): void|null $header TEST seam (default: PHP header(), replacing) */
    public function __construct(EVC_Staff_Account_Service $service, ?callable $header = null) {
        EVC_Admin_Tools_Feature::assert_constructible(__CLASS__);
        $this->service = $service;
        $this->header = $header ?? function (string $line): void {
            header($line, true);
        };
    }

    /** The nonce action of one form: bound to the action and the target account (0 for create / revoke). */
    public static function nonce_action(string $action, int $user_id): string {
        return self::NONCE_ACTION . '|' . $action . '|' . $user_id;
    }

    /** admin_menu: the top-level «Evangelou Club» menu, opening on this screen. */
    public function add_menu(): void {
        $hook = add_menu_page(self::TITLE, self::MENU_TITLE, self::CAPABILITY, self::MENU_SLUG, array($this, 'render'), 'dashicons-coffee', 58);
        add_submenu_page(self::MENU_SLUG, self::TITLE, self::SUBMENU_TITLE, self::CAPABILITY, self::MENU_SLUG, array($this, 'render'));
        if (is_string($hook) && $hook !== '') {
            add_action('load-' . $hook, array($this, 'handle_load'));
        }
    }

    /** load-*: a POST is checked and carried out here; its answer is rendered below, once. */
    public function handle_load(): void {
        nocache_headers();
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST[self::FIELD_ACTION])) {
            return;
        }
        $result = $this->process(wp_unslash($_POST));
        if ($result['code'] === self::BAD_NONCE || $result['code'] === EVC_Staff_Account_Service::FORBIDDEN) {
            wp_die(esc_html(self::MESSAGES[$result['code']]), '', array('response' => 403));
        }
        if (isset($result['password'])) {
            // The answer carries a password: never stored by a browser or a proxy.
            ($this->header)(self::NO_STORE_HEADER);
        }
        $this->result = $result;
    }

    /**
     * The nonce (bound to this action and this account), then the capability, then the action.
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function process(array $post): array {
        $action = isset($post[self::FIELD_ACTION]) && is_string($post[self::FIELD_ACTION]) ? $post[self::FIELD_ACTION] : '';
        $user = isset($post[self::FIELD_USER]) && is_scalar($post[self::FIELD_USER]) ? (int) $post[self::FIELD_USER] : 0;
        $nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
        if (wp_verify_nonce($nonce, self::nonce_action($action, $user)) === false) {
            return array('code' => self::BAD_NONCE, 'action' => '');
        }
        switch ($action) {
            case 'create':
                $result = $this->service->create(isset($post[self::FIELD_LOGIN]) && is_string($post[self::FIELD_LOGIN]) ? $post[self::FIELD_LOGIN] : '');
                break;
            case 'password':
                $result = $this->service->set_password($user);
                break;
            case 'disable':
            case 'enable':
                $result = $this->service->set_disabled($user, $action === 'disable');
                break;
            case 'revoke':
                $result = $this->service->revoke_all();
                break;
            default:
                $result = array('code' => EVC_Staff_Account_Service::FAILED);
        }
        return $result + array('action' => $action);
    }

    public function render(): void {
        if (!EVC_Staff_Account_Service::may_manage(wp_get_current_user())) {
            wp_die(esc_html(self::NOT_ALLOWED), '', array('response' => 403));
        }
        echo $this->html($this->result); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    /** @param array<string,mixed>|null $result */
    public function html(?array $result): string {
        $out = '<div class="wrap"><h1>' . esc_html(self::TITLE) . '</h1>';
        $out .= '<p>' . esc_html('Ένας κοινός λογαριασμός για το tablet του ταμείου (απόφαση D18). Χωρίς προσωπικούς λογαριασμούς και PIN.') . '</p>';
        if ($result !== null) {
            $code = (string) $result['code'];
            $class = $code === EVC_Staff_Account_Service::OK ? 'notice-success' : 'notice-error';
            $out .= '<div class="notice ' . $class . '"><p>' . esc_html(self::ACTION_LABELS[$result['action']] ?? '') . ': ' . esc_html(self::MESSAGES[$code] ?? self::MESSAGES['failed']) . '</p>';
            if (isset($result['password']) && is_string($result['password'])) {
                $out .= '<p>' . esc_html('Νέος κωδικός (εμφανίζεται ΜΟΝΟ τώρα· σημειώστε τον και δώστε τον στο ταμείο):') . ' <code style="font-size:1.4em">' . esc_html($result['password']) . '</code></p>';
            }
            $out .= '</div>';
        }
        $accounts = $this->service->accounts();
        if (count($accounts) > 1) {
            $out .= '<div class="notice notice-warning"><p>' . esc_html(sprintf('Προσοχή: υπάρχουν %d λογαριασμοί με τον ρόλο του ταμείου. Πρέπει να υπάρχει ένας. Απενεργοποιήστε αυτούς που δεν χρειάζονται· η διαγραφή γίνεται μόνο με το χέρι από διαχειριστή.', count($accounts))) . '</p></div>';
        }
        if ($accounts === array()) {
            $out .= '<h2>' . esc_html('Δεν υπάρχει λογαριασμός ταμείου') . '</h2>' . $this->form('create', 0, 'Δημιουργία λογαριασμού ταμείου', '<label>' . esc_html('Όνομα χρήστη') . ' <input type="text" name="' . self::FIELD_LOGIN . '" value="' . esc_attr(EVC_Staff_Account_Service::DEFAULT_LOGIN) . '" autocomplete="off"></label> ');
        } else {
            $out .= '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html('Όνομα χρήστη') . '</th><th>' . esc_html('Κατάσταση') . '</th><th>' . esc_html('Τελευταία σύνδεση') . '</th><th>' . esc_html('Ανοιχτές συνεδρίες') . '</th><th>' . esc_html('Ενέργειες') . '</th></tr></thead><tbody>';
            foreach ($accounts as $a) {
                $state = $a['disabled'] ? 'Απενεργοποιημένος' : 'Ενεργός';
                if (!$a['restricted']) {
                    $state .= ' — ΠΡΟΣΟΧΗ: έχει δικαιώματα πέρα από τον ρόλο του ταμείου (δεν μπορεί να συνδεθεί στο ταμείο)';
                }
                // An account with more than the staff role gets neither a password nor enable (the service refuses both).
                $actions = !$a['restricted']
                    ? ($a['disabled'] ? '' : $this->form('disable', $a['id'], 'Απενεργοποίηση'))
                    : $this->form('password', $a['id'], 'Νέος κωδικός') . ($a['disabled'] ? $this->form('enable', $a['id'], 'Ενεργοποίηση') : $this->form('disable', $a['id'], 'Απενεργοποίηση'));
                $out .= '<tr><td>' . esc_html((string) $a['id']) . '</td><td>' . esc_html($a['login']) . '</td><td>' . esc_html($state) . '</td><td>'
                    . esc_html($a['last_login'] === null ? '—' : wp_date('d/m/Y H:i', $a['last_login'])) . '</td><td>' . esc_html((string) $a['sessions']) . '</td><td>' . $actions . '</td></tr>';
            }
            $out .= '</tbody></table>';
            $out .= '<h2>' . esc_html('Χαμένο tablet ή αποχώρηση υπαλλήλου') . '</h2>' . $this->form('revoke', 0, 'Αποσύνδεση του ταμείου από όλες τις συσκευές');
        }
        $out .= '<h2>' . esc_html('Ιστορικό ενεργειών') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html('Ώρα') . '</th><th>' . esc_html('Ενέργεια') . '</th><th>' . esc_html('Αποτέλεσμα') . '</th><th>' . esc_html('Διαχειριστής (ID)') . '</th><th>' . esc_html('Λογαριασμός (ID)') . '</th></tr></thead><tbody>';
        foreach (EVC_Staff_Account_Service::audit_entries(20) as $e) {
            $out .= '<tr><td>' . esc_html(wp_date('d/m/Y H:i', (int) $e['at'])) . '</td><td>' . esc_html(self::ACTION_LABELS[$e['action']] ?? (string) $e['action']) . '</td><td>' . esc_html((string) $e['outcome']) . '</td><td>' . esc_html((string) $e['actor']) . '</td><td>' . esc_html((string) $e['target']) . '</td></tr>';
        }
        return $out . '</tbody></table></div>';
    }

    private function form(string $action, int $user_id, string $label, string $extra = ''): string {
        $confirm = in_array($action, array('password', 'disable', 'revoke'), true) ? ' onclick="return confirm(' . esc_attr(wp_json_encode('Σίγουρα; Το tablet του ταμείου θα αποσυνδεθεί.')) . ');"' : '';
        return '<form method="post" style="display:inline-block;margin:0 .5em .5em 0">' . wp_nonce_field(self::nonce_action($action, $user_id), '_wpnonce', true, false)
            . '<input type="hidden" name="' . self::FIELD_ACTION . '" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="' . self::FIELD_USER . '" value="' . esc_attr((string) $user_id) . '">'
            . $extra . '<button type="submit" class="button"' . $confirm . '>' . esc_html($label) . '</button></form>';
    }
}
