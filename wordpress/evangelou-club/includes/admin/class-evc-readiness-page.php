<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08: «Evangelou Club → Ετοιμότητα», a READ-ONLY page: every check of EVC_Readiness with its status. No form,
 * no action, no write; it handles no POST. Constructed ONLY by EVC_Admin_Tools_Plugin::wire().
 */
final class EVC_Readiness_Page {
    const SLUG = 'evc-club-readiness';
    const TITLE = 'Evangelou Club — Ετοιμότητα';
    const SUBMENU_TITLE = 'Ετοιμότητα';
    const SECTIONS = array(
        'database' => 'Βάση δεδομένων του Club',
        'flags' => 'Ρυθμίσεις ενεργοποίησης (wp-config.php)',
        'shop' => 'Κατάστημα',
        'crm' => 'Το FluentCRM',
        'server' => 'Διακομιστής',
        'staff' => 'Ταμείο',
    );

    /** @var EVC_Readiness */
    private $readiness;

    public function __construct(EVC_Readiness $readiness) {
        EVC_Admin_Tools_Feature::assert_constructible(__CLASS__);
        $this->readiness = $readiness;
    }

    public function add_menu(): void {
        add_submenu_page(EVC_Staff_Account_Page::MENU_SLUG, self::TITLE, self::SUBMENU_TITLE, EVC_Staff_Account_Page::CAPABILITY, self::SLUG, array($this, 'render'));
    }

    public function render(): void {
        if (!EVC_Staff_Account_Service::may_manage(wp_get_current_user())) {
            wp_die(esc_html(EVC_Staff_Account_Page::NOT_ALLOWED), '', array('response' => 403));
        }
        echo $this->html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    public function html(): string {
        $rows = $this->readiness->checks();
        $counts = array_count_values(array_column($rows, 'status'));
        $out = '<div class="wrap"><h1>' . esc_html(self::TITLE) . '</h1>';
        $out .= '<p>' . esc_html('Μόνο ανάγνωση: η σελίδα δεν αλλάζει τίποτα και δεν δείχνει κωδικούς ή κλειδιά. Η σειρά ενεργοποίησης και τι ελέγχεται μετά από κάθε βήμα: docs/go-live.md.') . '</p>';
        $summary = array();
        foreach (EVC_Readiness::STATUS_LABELS as $status => $label) {
            $summary[] = $label . ': ' . (int) ($counts[$status] ?? 0);
        }
        $out .= '<p><strong>' . esc_html(implode(' · ', $summary)) . '</strong></p>';
        foreach (self::SECTIONS as $section => $title) {
            $out .= '<h2>' . esc_html($title) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html('Έλεγχος') . '</th><th>' . esc_html('Κατάσταση') . '</th><th>' . esc_html('Λεπτομέρειες') . '</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                if ($row['section'] === $section) {
                    $out .= '<tr data-check="' . esc_attr($row['key']) . '" data-status="' . esc_attr($row['status']) . '"><td>' . esc_html($row['label']) . '</td><td><strong>'
                        . esc_html(EVC_Readiness::STATUS_LABELS[$row['status']] ?? $row['status']) . '</strong></td><td>' . esc_html($row['detail']) . '</td></tr>';
                }
            }
            $out .= '</tbody></table>';
        }
        return $out . '</div>';
    }
}
