<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 18, OWNER DECISION D31 (2026-11-23): the useful Club addresses, listed inside the plugin so the owner
 * never has to ask for them. Static and read-only: it constructs nothing, writes nothing and changes no setting.
 *
 * Every address is built here, on the server, with the plugin's own URL functions, so it is right for pretty AND plain
 * permalinks and for a site installed in a sub-folder (home_url(), admin_url(), wp_login_url(), menu_page_url()):
 *   join         «Εγγραφή πελάτη»                 EVC_Onboarding_Shell::join_url()         only with EVC_CLUB_ONBOARDING_ENABLED
 *   poster       «Αφίσα με QR για εγγραφή»        EVC_Onboarding_Shell::poster_url()       only with EVC_CLUB_ONBOARDING_ENABLED
 *   member       «Κάρτα πελάτη»                   EVC_Customer_Shell::shell_url()          only with EVC_CLUB_CUSTOMER_ENABLED
 *   member_login «Σύνδεση πελάτη»                 wp_login_url(the member page)            only with EVC_CLUB_CUSTOMER_ENABLED
 *   till         «Ταμείο (σύνδεση προσωπικού)»    EVC_Staff_Shell::shell_url()             only with EVC_CLUB_STAFF_ENABLED
 * and, for the administrators' page ONLY (never in the till app):
 *   push         «Ειδοποιήσεις»                   the page of EVC_CLUB_PUSH_ENABLED, if its menu exists
 *   readiness    «Ετοιμότητα»                     the readiness page (EVC_CLUB_ADMIN_TOOLS_ENABLED), if its menu exists
 *   database     «Βάση δεδομένων»                 the Club DB page (EVC_CLUB_MIGRATIONS_ENABLED), if its menu exists
 *   orders       «Παραγγελίες WooCommerce»        WooCommerce's order list (HPOS page or the classic list), if active
 *                (WooCommerce is detected by its function wc_get_order: class_exists() is refused in this pinned file)
 * A link whose feature is off is simply not listed. The till app receives staff() only (EVC_Staff_Shell config
 * 'links'), validates every address as this site's and shows a copy button per link.
 */
final class EVC_Club_Links {
    const STAFF_KEYS = array('join', 'poster', 'member', 'member_login', 'till');
    const ADMIN_KEYS = array('push', 'readiness', 'database', 'orders');
    const LABELS = array(
        'join' => 'Εγγραφή πελάτη',
        'poster' => 'Αφίσα με QR για εγγραφή',
        'member' => 'Κάρτα πελάτη',
        'member_login' => 'Σύνδεση πελάτη',
        'till' => 'Ταμείο (σύνδεση προσωπικού)',
        'push' => 'Ειδοποιήσεις',
        'readiness' => 'Ετοιμότητα',
        'database' => 'Βάση δεδομένων',
        'orders' => 'Παραγγελίες WooCommerce',
    );

    /** @return array<int,array{key:string,url:string}> the links the till app may show (no administrator page) */
    public static function staff(): array {
        $out = array();
        if (EVC_Onboarding_Feature::enabled()) {
            $out[] = array('key' => 'join', 'url' => EVC_Onboarding_Shell::join_url());
            $out[] = array('key' => 'poster', 'url' => EVC_Onboarding_Shell::poster_url());
        }
        if (EVC_Customer_Feature::enabled()) {
            $out[] = array('key' => 'member', 'url' => EVC_Customer_Shell::shell_url());
            $out[] = array('key' => 'member_login', 'url' => wp_login_url(EVC_Customer_Shell::shell_url()));
        }
        if (EVC_Staff_Feature::enabled()) {
            $out[] = array('key' => 'till', 'url' => EVC_Staff_Shell::shell_url());
        }
        return $out;
    }

    /** @return array<int,array{key:string,url:string}> the administrators' list: staff() and the administrator pages */
    public static function admin(): array {
        $out = self::staff();
        $pages = array(
            'push' => array(EVC_Push_Feature::enabled(), 'evc-club-push'),
            'readiness' => array(EVC_Admin_Tools_Feature::enabled(), 'evc-club-readiness'),
            'database' => array(EVC_Club_Migrations_Feature::enabled(), 'evc-club-db'),
        );
        foreach ($pages as $key => $page) {
            $url = $page[0] && function_exists('menu_page_url') ? (string) menu_page_url($page[1], false) : '';
            if ($url !== '') {
                $out[] = array('key' => $key, 'url' => html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
            }
        }
        $orders = self::orders_url();
        if ($orders !== '') {
            $out[] = array('key' => 'orders', 'url' => $orders);
        }
        return $out;
    }

    /** WooCommerce's order list: the HPOS page when it is registered, else the classic list; '' without WooCommerce. */
    public static function orders_url(): string {
        if (!function_exists('wc_get_order')) {
            return '';
        }
        $hpos = function_exists('menu_page_url') ? (string) menu_page_url('wc-orders', false) : '';
        return $hpos !== '' ? html_entity_decode($hpos, ENT_QUOTES, 'UTF-8') : admin_url('edit.php?post_type=shop_order');
    }

    /** «Σύνδεσμοι Club» for the Evangelou Club page (escaped; every address with a copy button). */
    public static function admin_html(): string {
        $links = self::admin();
        $out = '<h2 id="evc-club-links">Σύνδεσμοι Club</h2>';
        if ($links === array()) {
            return $out . '<p>Δεν είναι ενεργή καμία λειτουργία του Club με δικό της σύνδεσμο.</p>';
        }
        $out .= '<p>Οι διευθύνσεις του Club σε αυτόν τον ιστότοπο. Εμφανίζονται μόνο όσες αντιστοιχούν σε ενεργές λειτουργίες.</p><table class="widefat striped" role="presentation"><tbody>';
        foreach ($links as $i => $link) {
            $id = 'evc-club-link-' . $i;
            $out .= '<tr data-evc-link="' . esc_attr($link['key']) . '"><th scope="row">' . esc_html(self::LABELS[$link['key']]) . '</th>'
                . '<td><input type="text" readonly class="large-text code" id="' . esc_attr($id) . '" value="' . esc_attr($link['url']) . '" onfocus="this.select()"></td>'
                . '<td><button type="button" class="button evc-club-copy" data-evc-copy="' . esc_attr($id) . '">Αντιγραφή</button> <a class="button-link" href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">Άνοιγμα</a></td></tr>';
        }
        $out .= '</tbody></table>';
        // The copy buttons: the clipboard API where the browser allows it, else the selected field and execCommand.
        $out .= '<script>document.querySelectorAll(".evc-club-copy").forEach(function (b) { b.addEventListener("click", function () { var f = document.getElementById(b.getAttribute("data-evc-copy")); if (!f) { return; } var done = function () { b.textContent = "Αντιγράφηκε"; window.setTimeout(function () { b.textContent = "Αντιγραφή"; }, 2000); }; var old = function () { f.select(); try { document.execCommand("copy"); done(); } catch (e) {} }; if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(f.value).then(done, old); } else { old(); } }); });</script>';
        return $out;
    }
}
