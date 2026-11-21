<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): «Evangelou Club → Ειδοποιήσεις», the administrator's page for sending one
 * notification to members. Constructed only by EVC_Push_Plugin::wire() behind EVC_CLUB_PUSH_ENABLED.
 *
 * Who: administrators only (may_send(): manage_options AND the Club capability evc_manage_club, never an account with the
 * till role, never on multisite). The shared till account has neither, so it never sees the menu or the page.
 *
 * Flow (every POST on the page's load-* hook, before any output: the nonce bound to the action, then the capability):
 *   1. form: title (at most 50 characters), text (at most 150), audience (all subscribed members / active members /
 *      members whose membership ends within N days);
 *   2. «Προεπισκόπηση»: the notification as the phone shows it and the number of recipients (nothing sent yet);
 *   3. «Αποστολή σε … συσκευές»: the confirmation starts ONE job (EVC_Push_Campaign::start(): rate limit, audit);
 *   4. the page then sends batch after batch (one POST per batch, submitted by the page itself; WP-Cron continues if the
 *      page is closed) and shows sent / failed / removed; «Διακοπή αποστολής» stops it.
 *      Round 13: when a batch is sending at that moment, the stop is only recorded («Η διακοπή καταχωρήθηκε, η αποστολή
 *      σταματά σε λίγα δευτερόλεπτα») and the batch ends the job before its next message; «Η αποστολή διακόπηκε» is shown
 *      only once the stored job really is 'cancelled'. Without a database named lock nothing is sent (NO_LOCK message).
 *      Round 14: after a batch that got no named lock the page does NOT submit the next batch by itself (no endless
 *      reload against a database that refuses the lock); the buttons stay. A stop that arrives just after the job ended
 *      by itself says «Η αποστολή είχε ήδη ολοκληρωθεί πριν από τη διακοπή.».
 * Every value printed is escaped. No endpoint, key or member is ever shown; only counts.
 */
final class EVC_Push_Admin_Page {
    const MENU_SLUG = 'evc-club-push';
    const PARENT_SLUG = 'evc-club';
    const TITLE = 'Evangelou Club — Ειδοποιήσεις';
    const MENU_TITLE = 'Ειδοποιήσεις';
    const CAPABILITY = 'manage_options';
    const NONCE_ACTION = 'evc_club_push';
    const FIELD_ACTION = 'evc_push_action';
    const ACTIONS = array('preview', 'send', 'step', 'cancel');
    const NOT_ALLOWED = 'Δεν έχετε δικαίωμα για αυτή τη σελίδα.';
    const AUDIENCE_LABELS = array(
        'all' => 'Όλα τα μέλη που έχουν ενεργοποιήσει τις ειδοποιήσεις',
        'active' => 'Μόνο τα μέλη με ενεργή συνδρομή',
        'expiring' => 'Μέλη των οποίων η συνδρομή λήγει μέσα στις επόμενες ημέρες',
    );
    const PROBLEMS = array(
        EVC_Push_Message::PROBLEM_TITLE_EMPTY => 'Γράψτε τίτλο.',
        EVC_Push_Message::PROBLEM_TITLE_LONG => 'Ο τίτλος ξεπερνά τους 50 χαρακτήρες.',
        EVC_Push_Message::PROBLEM_BODY_EMPTY => 'Γράψτε το κείμενο της ειδοποίησης.',
        EVC_Push_Message::PROBLEM_BODY_LONG => 'Το κείμενο ξεπερνά τους 150 χαρακτήρες.',
        EVC_Push_Message::PROBLEM_CHARACTERS => 'Το κείμενο έχει χαρακτήρες που δεν επιτρέπονται.',
        'audience' => 'Διαλέξτε ποιοι θα λάβουν την ειδοποίηση (και, για τη λήξη, από 1 έως 30 ημέρες).',
    );
    const RESULTS = array(
        EVC_Push_Campaign::OK => 'Η αποστολή ξεκίνησε.',
        EVC_Push_Campaign::BUSY => 'Τρέχει ήδη μια αποστολή. Περιμένετε να τελειώσει.',
        EVC_Push_Campaign::RATE_LIMITED => 'Μία αποστολή ανά 15 λεπτά και έως 4 την ημέρα. Δοκιμάστε ξανά αργότερα.',
        EVC_Push_Campaign::INVALID => 'Η ειδοποίηση δεν είναι έγκυρη. Δεν στάλθηκε τίποτα.',
        EVC_Push_Campaign::UNAVAILABLE => 'Οι ειδοποιήσεις δεν είναι διαθέσιμες: δείτε τη σελίδα «Ετοιμότητα» (κλειδιά, βάση του Club, μετάβαση 005).',
        EVC_Push_Campaign::NO_LOCK => 'Η βάση δεδομένων του ιστότοπου δεν επέτρεψε το κλείδωμα που χρειάζεται για ασφαλή αποστολή. Για ασφάλεια δεν στάλθηκε τίποτα. Ενημερώστε τον τεχνικό.',
        EVC_Push_Campaign::NONE => 'Δεν υπάρχει αποστολή σε εξέλιξη.',
        'bad_nonce' => 'Η σελίδα έληξε. Ανοίξτε την ξανά και δοκιμάστε πάλι.',
        'cancelled' => 'Η αποστολή διακόπηκε.',
        'cancel_pending' => 'Η διακοπή καταχωρήθηκε, η αποστολή σταματά σε λίγα δευτερόλεπτα.',
        'done_before_cancel' => 'Η αποστολή είχε ήδη ολοκληρωθεί πριν από τη διακοπή.',
    );
    const STATES = array('running' => 'Σε εξέλιξη', 'done' => 'Ολοκληρώθηκε', 'stopped' => 'Σταμάτησε (η βάση ή τα κλειδιά δεν ήταν διαθέσιμα)', 'cancelled' => 'Διακόπηκε');

    /** @var EVC_Push_Campaign */
    private $campaign;
    /** @var array<string,mixed>|null the answer to this request's POST */
    private $result;
    /** @var callable(string): void */
    private $redirect;

    /** @param callable(string): void|null $redirect TEST seam (default: wp_safe_redirect + exit) */
    public function __construct(EVC_Push_Campaign $campaign, ?callable $redirect = null) {
        EVC_Push_Feature::assert_constructible(__CLASS__);
        $this->campaign = $campaign;
        $this->redirect = $redirect ?? function (string $url): void {
            wp_safe_redirect($url, 303);
            exit;
        };
    }

    /** Administrators of a single site who hold the Club capability; never the till account. */
    public static function may_send($user): bool {
        return $user instanceof WP_User && $user->exists() && user_can($user, 'manage_options') && user_can($user, EVC_Staff_Role::CAP_MANAGE)
            && !EVC_Staff_Role::has_staff_role($user) && !is_multisite();
    }

    public static function nonce_action(string $action): string {
        return self::NONCE_ACTION . '|' . $action;
    }

    public static function page_url(): string {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    /** admin_menu (after the Club menu exists): «Ειδοποιήσεις» under «Evangelou Club», for administrators only. */
    public function add_menu(): void {
        if (!self::may_send(wp_get_current_user())) {
            return;
        }
        $parent = EVC_Admin_Tools_Feature::enabled() ? self::PARENT_SLUG : 'tools.php';
        $hook = add_submenu_page($parent, self::TITLE, self::MENU_TITLE, self::CAPABILITY, self::MENU_SLUG, array($this, 'render'));
        if (is_string($hook) && $hook !== '') {
            add_action('load-' . $hook, array($this, 'handle_load'));
        }
    }

    public function handle_load(): void {
        nocache_headers();
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST[self::FIELD_ACTION])) {
            return;
        }
        $this->result = $this->process(wp_unslash($_POST));
        $flash = array('started' => 'ok', 'stepped' => 'ok', 'cancelled_ok' => 'cancelled', 'cancel_pending' => 'cancel_pending', 'done_before_cancel' => 'done_before_cancel');
        if (isset($flash[$this->result['code']])) {
            ($this->redirect)(add_query_arg('evc_push', $flash[$this->result['code']], self::page_url()));
        }
    }

    /**
     * One POST (already unslashed). Nonce first, then capability, then the action.
     * @return array<string,mixed> code: bad_nonce | forbidden | problem | preview | started | refused | stepped | cancelled_ok
     *         | cancel_pending | done_before_cancel
     */
    public function process(array $post): array {
        $action = isset($post[self::FIELD_ACTION]) && is_string($post[self::FIELD_ACTION]) && in_array($post[self::FIELD_ACTION], self::ACTIONS, true) ? $post[self::FIELD_ACTION] : '';
        $nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
        if ($action === '' || !wp_verify_nonce($nonce, self::nonce_action($action))) {
            return array('code' => 'bad_nonce');
        }
        $user = wp_get_current_user();
        if (!self::may_send($user)) {
            return array('code' => 'forbidden');
        }
        if ($action === 'step') {
            $stepped = $this->campaign->step();
            return $stepped === EVC_Push_Campaign::NO_LOCK ? array('code' => 'refused', 'result' => EVC_Push_Campaign::NO_LOCK) : array('code' => 'stepped');
        }
        if ($action === 'cancel') {
            $cancelled = $this->campaign->cancel($user);
            if ($cancelled === EVC_Push_Campaign::OK) {
                return array('code' => 'cancelled_ok');
            }
            if ($cancelled === EVC_Push_Campaign::DONE_BEFORE_CANCEL) {
                return array('code' => 'done_before_cancel');
            }
            return $cancelled === EVC_Push_Campaign::CANCEL_PENDING ? array('code' => 'cancel_pending') : array('code' => 'refused', 'result' => EVC_Push_Campaign::NONE);
        }
        $title = isset($post['evc_push_title']) && is_string($post['evc_push_title']) ? $post['evc_push_title'] : '';
        $body = isset($post['evc_push_body']) && is_string($post['evc_push_body']) ? $post['evc_push_body'] : '';
        $audience = isset($post['evc_push_audience']) && is_string($post['evc_push_audience']) ? $post['evc_push_audience'] : '';
        $days = isset($post['evc_push_days']) && is_string($post['evc_push_days']) && preg_match('/^\d{1,2}$/D', $post['evc_push_days']) === 1 ? (int) $post['evc_push_days'] : 0;
        $form = array('title' => $title, 'body' => $body, 'audience' => $audience, 'days' => $days);
        $problem = EVC_Push_Message::problem($title, $body);
        if ($problem === null && !EVC_Push_Campaign::valid_audience($audience, $audience === 'expiring' ? $days : 0)) {
            $problem = 'audience';
        }
        if ($problem !== null) {
            return array('code' => 'problem', 'problem' => $problem, 'form' => $form);
        }
        $preview = $this->campaign->preview($audience, $days);
        if ($preview['result'] !== EVC_Push_Campaign::OK) {
            return array('code' => 'refused', 'result' => $preview['result'], 'form' => $form);
        }
        if ($action === 'preview') {
            return array('code' => 'preview', 'form' => $form, 'members' => $preview['members'], 'subscriptions' => $preview['subscriptions']);
        }
        $started = $this->campaign->start($user, $title, $body, $audience, $days, (int) $preview['subscriptions']);
        if ($started['result'] === EVC_Push_Campaign::OK) {
            return array('code' => 'started');
        }
        return array('code' => 'refused', 'result' => $started['result'], 'form' => $form);
    }

    public function render(): void {
        if (!self::may_send(wp_get_current_user())) {
            wp_die(esc_html(self::NOT_ALLOWED), '', array('response' => 403));
        }
        echo $this->html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    /** The page body (escaped). */
    public function html(): string {
        $r = $this->result;
        $out = '<div class="wrap"><h1>' . esc_html(self::TITLE) . '</h1>';
        $flash = isset($_GET['evc_push']) && is_string($_GET['evc_push']) ? sanitize_key(wp_unslash($_GET['evc_push'])) : '';
        $job = EVC_Push_Campaign::fresh_job();
        $state = $job !== null ? (string) ($job['state'] ?? '') : '';
        $running = $state === 'running';
        // Round 13: the stop is reported from the stored job, never from the request that asked for it.
        if ($running && EVC_Push_Campaign::cancel_pending_for($job)) {
            $out .= self::notice(self::RESULTS['cancel_pending'], 'warning');
        } elseif (in_array($flash, array('cancelled', 'cancel_pending'), true) && $state === EVC_Push_Campaign::CANCELLED) {
            $out .= self::notice(self::RESULTS['cancelled'], 'info');
        } elseif (in_array($flash, array('cancel_pending', 'done_before_cancel'), true) && in_array($state, array('done', 'stopped'), true)) {
            $out .= self::notice(self::RESULTS['done_before_cancel'], 'info');
        }
        if ($r !== null) {
            if ($r['code'] === 'bad_nonce') {
                $out .= self::notice(self::RESULTS['bad_nonce'], 'error');
            } elseif ($r['code'] === 'forbidden') {
                $out .= self::notice(self::NOT_ALLOWED, 'error');
            } elseif ($r['code'] === 'problem') {
                $out .= self::notice(self::PROBLEMS[$r['problem']] ?? self::RESULTS[EVC_Push_Campaign::INVALID], 'error');
            } elseif ($r['code'] === 'refused') {
                $out .= self::notice(self::RESULTS[$r['result']] ?? self::RESULTS[EVC_Push_Campaign::INVALID], 'error');
            }
        }
        if ($job !== null) {
            // Round 14: no automatic next batch after a batch that got no named lock.
            $no_lock = $r !== null && $r['code'] === 'refused' && ($r['result'] ?? '') === EVC_Push_Campaign::NO_LOCK;
            $out .= $this->job_html($job, $running, $running && !$no_lock);
        }
        if (!$running) {
            if ($r !== null && $r['code'] === 'preview') {
                $out .= $this->preview_html($r);
            }
            $out .= $this->form_html($r['form'] ?? array());
        }
        $out .= $this->audit_html();
        return $out . '</div>';
    }

    private static function notice(string $text, string $kind): string {
        return '<div class="notice notice-' . esc_attr($kind) . '"><p>' . esc_html($text) . '</p></div>';
    }

    private static function hidden(string $action): string {
        return '<input type="hidden" name="' . esc_attr(self::FIELD_ACTION) . '" value="' . esc_attr($action) . '">' . wp_nonce_field(self::nonce_action($action), '_wpnonce', true, false);
    }

    private function job_html(array $job, bool $running, bool $continue): string {
        $out = '<h2>Τελευταία αποστολή</h2><p>' . esc_html(sprintf(
            '%s · κοινό: %s · σχεδιασμένες συσκευές: %d · στάλθηκαν: %d · απέτυχαν: %d · αφαιρέθηκαν (η συσκευή δεν δέχεται πια ειδοποιήσεις): %d · παραλείφθηκαν: %d',
            self::STATES[$job['state'] ?? ''] ?? '—',
            self::audience_text((string) ($job['audience'] ?? ''), (int) ($job['days'] ?? 0)),
            (int) ($job['planned'] ?? 0), (int) ($job['sent'] ?? 0), (int) ($job['failed'] ?? 0), (int) ($job['removed'] ?? 0), (int) ($job['skipped'] ?? 0)
        )) . '</p>';
        if ($running) {
            $out .= '<form method="post" id="evc-push-step">' . self::hidden('step') . '<p><button type="submit" class="button button-primary">Συνέχεια αποστολής</button></p></form>';
            $out .= '<form method="post">' . self::hidden('cancel') . '<p><button type="submit" class="button">Διακοπή αποστολής</button></p></form>';
        }
        if ($continue) {
            // The page sends the next batch by itself while it stays open (WP-Cron continues when it is closed).
            $out .= '<script>window.setTimeout(function () { var f = document.getElementById("evc-push-step"); if (f) { f.submit(); } }, 1500);</script>';
        }
        return $out;
    }

    private static function audience_text(string $audience, int $days): string {
        $label = self::AUDIENCE_LABELS[$audience] ?? '—';
        return $audience === 'expiring' ? $label . ' (' . $days . ')' : $label;
    }

    private function preview_html(array $r): string {
        $f = $r['form'];
        $out = '<h2>Προεπισκόπηση</h2>';
        $out .= '<div style="max-width:360px;border:1px solid #c3c4c7;border-radius:12px;padding:12px 16px;background:#fff">'
            . '<strong>' . esc_html((string) EVC_Push_Message::normalise($f['title'])) . '</strong><br>' . esc_html((string) EVC_Push_Message::normalise($f['body'])) . '</div>';
        $out .= '<p>' . esc_html(sprintf('Κοινό: %s. Παραλήπτες τώρα: %d μέλη, %d συσκευές.', self::audience_text($f['audience'], (int) $f['days']), (int) $r['members'], (int) $r['subscriptions'])) . '</p>';
        if ((int) $r['subscriptions'] === 0) {
            return $out . self::notice('Δεν υπάρχει κανένας παραλήπτης για αυτό το κοινό. Δεν θα σταλεί τίποτα.', 'warning');
        }
        $out .= '<form method="post">' . self::hidden('send');
        foreach (array('title', 'body', 'audience') as $k) {
            $out .= '<input type="hidden" name="evc_push_' . $k . '" value="' . esc_attr((string) $f[$k]) . '">';
        }
        $out .= '<input type="hidden" name="evc_push_days" value="' . esc_attr((string) (int) $f['days']) . '">';
        return $out . '<p><button type="submit" class="button button-primary">' . esc_html(sprintf('Αποστολή σε %d συσκευές', (int) $r['subscriptions'])) . '</button></p></form>';
    }

    private function form_html(array $f): string {
        $audience = isset($f['audience']) && is_string($f['audience']) ? $f['audience'] : 'all';
        $out = '<h2>Νέα ειδοποίηση</h2><form method="post">' . self::hidden('preview') . '<table class="form-table" role="presentation">';
        $out .= '<tr><th scope="row"><label for="evc-push-title">Τίτλος (έως 50 χαρακτήρες)</label></th><td><input type="text" class="regular-text" id="evc-push-title" name="evc_push_title" maxlength="50" required value="' . esc_attr((string) ($f['title'] ?? '')) . '"></td></tr>';
        $out .= '<tr><th scope="row"><label for="evc-push-body">Κείμενο (έως 150 χαρακτήρες)</label></th><td><textarea class="large-text" rows="3" id="evc-push-body" name="evc_push_body" maxlength="150" required>' . esc_textarea((string) ($f['body'] ?? '')) . '</textarea></td></tr>';
        $out .= '<tr><th scope="row">Ποιοι τη λαμβάνουν</th><td><fieldset>';
        foreach (self::AUDIENCE_LABELS as $value => $label) {
            $out .= '<label><input type="radio" name="evc_push_audience" value="' . esc_attr($value) . '"' . checked($audience, $value, false) . '> ' . esc_html($label) . '</label><br>';
        }
        $days = isset($f['days']) && (int) $f['days'] > 0 ? (int) $f['days'] : 7;
        $out .= '<label for="evc-push-days">Ημέρες μέχρι τη λήξη (1–30):</label> <input type="number" min="1" max="30" id="evc-push-days" name="evc_push_days" value="' . esc_attr((string) $days) . '">';
        $out .= '</fieldset></td></tr></table><p><button type="submit" class="button button-primary">Προεπισκόπηση</button></p></form>';
        return $out . '<p>Οι ειδοποιήσεις φτάνουν μόνο σε μέλη που τις ζήτησαν από την κάρτα τους. Μία αποστολή ανά 15 λεπτά, έως 4 την ημέρα.</p>';
    }

    private function audit_html(): string {
        $rows = array_slice(EVC_Push_Campaign::audit(), 0, 10);
        if ($rows === array()) {
            return '';
        }
        $events = array('started' => 'Ξεκίνησε', 'done' => 'Ολοκληρώθηκε', 'stopped' => 'Σταμάτησε', 'cancelled' => 'Διακόπηκε', 'auto_activated' => 'Αυτόματη: «Η συνδρομή σας ενεργοποιήθηκε»', 'auto_expiry' => 'Αυτόματη: «Η συνδρομή σας λήγει σε 3 ημέρες»');
        $out = '<h2>Ιστορικό αποστολών</h2><table class="widefat striped"><thead><tr><th>Πότε</th><th>Ποιος</th><th>Τι</th><th>Κοινό</th><th>Στάλθηκαν</th><th>Απέτυχαν</th><th>Αφαιρέθηκαν</th></tr></thead><tbody>';
        foreach ($rows as $e) {
            $out .= '<tr><td>' . esc_html(wp_date('d/m/Y H:i', (int) ($e['at'] ?? 0))) . '</td><td>' . esc_html((string) ($e['user_login'] ?? '') !== '' ? (string) $e['user_login'] : 'αυτόματα') . '</td><td>' . esc_html($events[$e['event'] ?? ''] ?? '—')
                . '</td><td>' . esc_html(self::audience_text((string) ($e['audience'] ?? ''), (int) ($e['days'] ?? 0))) . '</td><td>' . (int) ($e['sent'] ?? 0) . '</td><td>' . (int) ($e['failed'] ?? 0) . '</td><td>' . (int) ($e['removed'] ?? 0) . '</td></tr>';
        }
        return $out . '</tbody></table>';
    }
}
