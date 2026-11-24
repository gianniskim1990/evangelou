<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 18, OWNER DECISION D30 (2026-11-23): rich messages inside the member app.
 *
 * A push notification can only carry plain text, so a notification sent from «Ειδοποιήσεις» now also leaves ONE message
 * in the member app: title (≤ 50, the notification's title), summary (≤ 150, the notification's text) and an optional
 * formatted body written with WordPress' own editor (bold, italic, headings and sizes, colour, lists, links, images from
 * the media library). The member opens it from the list «Μηνύματα» or by tapping the notification.
 *
 * Storage: the WordPress database (never the Club DB, no migration): the private custom post type evc_club_message.
 *   - not public, not publicly queryable, no rewrite, no query var, no archive, excluded from search, not in the REST
 *     API (show_in_rest false), not in the sitemaps (filter), not in nav menus, no admin screen (show_ui false): the
 *     only writer is the «Ειδοποιήσεις» page (EVC_Push_Campaign::start());
 *   - every capability of the type maps to the Club capability evc_manage_club, and an account with the till role is
 *     refused all of them (map_meta_cap), so the shared till account can never edit, read or delete a message;
 *   - a message waits as 'draft' until its send job has been written, then becomes 'private' (a status that
 *     WordPress never shows to visitors); a refused send deletes the draft.
 * Post meta (counts and labels only): _evc_audience (all | active | expiring), _evc_days, _evc_sent_at (unix time) and,
 * for 'active' / 'expiring', ONE _evc_recipients row (',12,40,' = the WordPress users who were in the audience when it was
 * sent; at most EVC_Push_Campaign::MAX_RECIPIENTS, the sender refuses a bigger audience). Round-18 messages keep their
 * _evc_recipient rows (still read).
 *
 * Who sees a message (GET /me/messages, EVC_Rest_Me_Messages_Controller): an ENABLED Club member (evc_members) only;
 * a message to «όλα τα μέλη» (all) to every such member, a message to active / expiring members only to the members
 * who belonged to that audience at the moment it was sent (the snapshot above). Never another member's message.
 *
 * The body is sanitised on the way in AND on the way out (sanitize_html(): wp_kses with an explicit allowlist, then a
 * DOM pass): only p, br, strong, b, em, i, u, s, del, span, h2–h4, ul, ol, li, blockquote, hr, a (href, title) and img
 * (src, alt, width, height); links only http, https, mailto, tel; style only colour (#hex or rgb()), text-align,
 * text-decoration (underline / line-through) and font-size (8–36 px or pt); images only from this site's media
 * library (the uploads address of wp_get_upload_dir()), anything else is dropped. No script, iframe, form, class, id,
 * event handler or other attribute survives. Without PHP's DOM extension the body keeps text and the basic tags only
 * (no style, no image): fail closed.
 *
 * Unread: the newest message id the member opened, in user meta LAST_READ_META (an integer only).
 */
final class EVC_Club_Messages {
    const POST_TYPE = 'evc_club_message';
    const MAX_HTML = 20000;
    const LIST_LIMIT = 50;
    const LAST_READ_META = 'evc_club_messages_last_read';
    const META_AUDIENCE = '_evc_audience';
    const META_DAYS = '_evc_days';
    const META_SENT_AT = '_evc_sent_at';
    /** Round 18 (legacy): one row per recipient. Still read, no longer written. */
    const META_RECIPIENT = '_evc_recipient';
    /** Round 19: the recipient snapshot as ONE row: ',12,40,77,' (the ids between commas, so a LIKE on ',id,' is exact). */
    const META_RECIPIENTS = '_evc_recipients';
    const STATUS_SENT = 'private';
    const STATUS_DRAFT = 'draft';

    const TAGS = array('p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'span', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'hr', 'a', 'img');
    const STYLED = array('p', 'span', 'h2', 'h3', 'h4', 'li');
    const PROTOCOLS = array('http', 'https', 'mailto', 'tel');

    /** init (only behind EVC_CLUB_PUSH_ENABLED, from EVC_Push_Plugin::boot()). */
    public static function register(): void {
        $cap = EVC_Staff_Role::CAP_MANAGE;
        register_post_type(self::POST_TYPE, array(
            'label' => 'Μηνύματα Club',
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_in_admin_bar' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'can_export' => false,
            'delete_with_user' => false,
            'hierarchical' => false,
            'supports' => array('title', 'editor', 'excerpt'),
            'map_meta_cap' => true,
            'capabilities' => array(
                'edit_post' => 'edit_' . self::POST_TYPE,
                'read_post' => 'read_' . self::POST_TYPE,
                'delete_post' => 'delete_' . self::POST_TYPE,
                'edit_posts' => $cap,
                'edit_others_posts' => $cap,
                'delete_posts' => $cap,
                'publish_posts' => $cap,
                'read_private_posts' => $cap,
                'read' => $cap,
                'delete_private_posts' => $cap,
                'delete_published_posts' => $cap,
                'delete_others_posts' => $cap,
                'edit_private_posts' => $cap,
                'edit_published_posts' => $cap,
                'create_posts' => $cap,
            ),
        ));
    }

    /** Hooks of the type (with register()). */
    public static function hooks(): void {
        add_action('init', array(__CLASS__, 'register'));
        add_filter('map_meta_cap', array(__CLASS__, 'refuse_till'), 10, 4);
        add_filter('wp_sitemaps_post_types', array(__CLASS__, 'not_in_sitemaps'));
    }

    /**
     * map_meta_cap: an account with the till role never gets any capability of a message (edit, read, delete, publish,
     * by the type's own meta capabilities or by WordPress' edit_post / read_post / delete_post / publish_post).
     */
    public static function refuse_till($caps, $cap, $user_id = 0, $args = array()) {
        if (!is_array($caps) || !is_string($cap)) {
            return $caps;
        }
        $ours = strpos($cap, self::POST_TYPE) !== false;
        if (!$ours && in_array($cap, array('edit_post', 'read_post', 'delete_post', 'publish_post'), true) && is_array($args) && isset($args[0]) && is_numeric($args[0])) {
            $ours = get_post_type((int) $args[0]) === self::POST_TYPE;
        }
        if (!$ours) {
            return $caps;
        }
        $user = get_userdata((int) $user_id);
        return !$user instanceof WP_User || EVC_Staff_Role::has_staff_role($user) ? array('do_not_allow') : $caps;
    }

    /** wp_sitemaps_post_types: never listed (it is not public anyway). */
    public static function not_in_sitemaps($types) {
        if (is_array($types)) {
            unset($types[self::POST_TYPE]);
        }
        return $types;
    }

    // ---- the body -------------------------------------------------------------------------------------------------

    /** @return array<string,array<string,bool>> the wp_kses allowlist */
    public static function allowed_html(bool $rich = true): array {
        $out = array();
        foreach (self::TAGS as $tag) {
            $out[$tag] = array();
        }
        if ($rich) {
            foreach (self::STYLED as $tag) {
                $out[$tag]['style'] = true;
            }
            $out['img'] = array('src' => true, 'alt' => true, 'width' => true, 'height' => true);
        } else {
            unset($out['img']);
        }
        $out['a'] = array('href' => true, 'title' => true);
        return $out;
    }

    /** The body as it may be stored and shown (see the class comment). '' for anything empty. */
    public static function sanitize_html($html): string {
        if (!is_string($html) || $html === '' || preg_match('//u', $html) !== 1) {
            return '';
        }
        // Elements whose content is never text for the reader go with their content (kses would keep it as text).
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|noscript|template|textarea|select|svg|math|title|head)\b[^>]*>.*?</\1\s*>#isu', '', $html);
        $rich = class_exists('DOMDocument');
        $clean = wp_kses($html, self::allowed_html($rich), self::PROTOCOLS);
        if ($rich) {
            $clean = self::dom_pass($clean);
        }
        $clean = trim($clean);
        return trim(wp_strip_all_tags($clean, true)) === '' && strpos($clean, '<img') === false ? '' : $clean;
    }

    /** Second pass over the kses output: exact attributes, safe style values, media-library images only. */
    private static function dom_pass(string $html): string {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"?><div id="evc-message-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $doc->getElementById('evc-message-root') : null;
        if (!$root instanceof DOMElement) {
            return '';
        }
        self::clean_children($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function clean_children(DOMNode $node): void {
        $children = array();
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);
            if (!in_array($tag, self::TAGS, true)) {
                // Unknown element: keep its text, drop the element.
                $node->replaceChild($child->ownerDocument->createTextNode($child->textContent), $child);
                continue;
            }
            if ($tag === 'img' && !self::allowed_image($child->getAttribute('src'))) {
                $node->removeChild($child);
                continue;
            }
            $keep = array();
            foreach ($child->attributes as $attr) {
                $keep[$attr->name] = $attr->value;
            }
            foreach (array_keys($keep) as $name) {
                $child->removeAttribute($name);
            }
            foreach (self::attributes($tag, $keep) as $name => $value) {
                $child->setAttribute($name, $value);
            }
            self::clean_children($child);
        }
    }

    /** @return array<string,string> the attributes a tag keeps, cleaned */
    private static function attributes(string $tag, array $attrs): array {
        $out = array();
        if ($tag === 'a') {
            $href = isset($attrs['href']) ? trim((string) $attrs['href']) : '';
            if ($href !== '' && self::allowed_link($href)) {
                $out['href'] = $href;
            }
            if (isset($attrs['title']) && $attrs['title'] !== '') {
                $out['title'] = (string) $attrs['title'];
            }
            return $out;
        }
        if ($tag === 'img') {
            $out['src'] = (string) $attrs['src'];
            $out['alt'] = isset($attrs['alt']) ? (string) $attrs['alt'] : '';
            foreach (array('width', 'height') as $dim) {
                if (isset($attrs[$dim]) && preg_match('/^[1-9][0-9]{0,3}$/D', (string) $attrs[$dim]) === 1) {
                    $out[$dim] = (string) $attrs[$dim];
                }
            }
            return $out;
        }
        if (in_array($tag, self::STYLED, true) && isset($attrs['style'])) {
            $style = self::clean_style((string) $attrs['style']);
            if ($style !== '') {
                $out['style'] = $style;
            }
        }
        return $out;
    }

    /** Only the allowed declarations, rebuilt in a fixed form ('' = none). */
    public static function clean_style(string $style): string {
        $out = array();
        foreach (explode(';', $style) as $decl) {
            $parts = explode(':', $decl, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $prop = strtolower(trim($parts[0]));
            $value = strtolower(trim($parts[1]));
            if ($prop === 'color' && (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/D', $value) === 1 || preg_match('/^rgb\(\s*(25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})\s*,\s*(25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})\s*,\s*(25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})\s*\)$/D', $value) === 1)) {
                $out['color'] = $value;
            } elseif ($prop === 'text-align' && in_array($value, array('left', 'right', 'center', 'justify'), true)) {
                $out['text-align'] = $value;
            } elseif ($prop === 'text-decoration' && in_array($value, array('underline', 'line-through'), true)) {
                $out['text-decoration'] = $value;
            } elseif ($prop === 'font-size' && preg_match('/^([0-9]{1,2})(px|pt)$/D', $value, $m) === 1 && (int) $m[1] >= 8 && (int) $m[1] <= 36) {
                $out['font-size'] = $value;
            }
        }
        $css = array();
        foreach ($out as $prop => $value) {
            $css[] = $prop . ': ' . $value;
        }
        return implode('; ', $css);
    }

    /** http(s), mailto: or tel: only; never javascript:, data: or a relative address. */
    public static function allowed_link(string $href): bool {
        if (preg_match('/[\x00-\x20"\'<>\\\\`]/', $href) === 1) {
            return false;
        }
        if (preg_match('/^(mailto|tel):[^\s]+$/iD', $href) === 1) {
            return true;
        }
        $parts = wp_parse_url($href);
        return is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), array('http', 'https'), true) && $parts['host'] !== '';
    }

    /**
     * An image of this site's media library: below the uploads address, no '..', no query tricks with quotes.
     * Round 19: percent-encoded dots, slashes and backslashes (%2e, %2f, %5c, any case, also double-encoded as %252e) are
     * refused, and the address must still start with this site's uploads address (host and path).
     */
    public static function allowed_image(string $src): bool {
        $src = trim($src);
        $uploads = wp_get_upload_dir();
        $base = is_array($uploads) && isset($uploads['baseurl']) ? rtrim((string) $uploads['baseurl'], '/') : '';
        if ($base === '' || $src === '' || preg_match('/[\x00-\x20"\'<>\\\\`]/', $src) === 1 || strpos($src, '..') !== false) {
            return false;
        }
        // Any encoded '%', dot, slash or backslash: refused (a legitimate media-library name never needs them).
        if (preg_match('/%(?:25|2e|2f|5c)/i', $src) === 1) {
            return false;
        }
        // A decoded control character (%00, %0a ...) is refused too; double-encoding is already refused above (%25).
        if (preg_match('/[\x00-\x1f\x7f]/', rawurldecode($src)) === 1) {
            return false;
        }
        // The same media library over https when the site is reached over https (set_url_scheme).
        foreach (array_unique(array($base, set_url_scheme($base, 'https'))) as $prefix) {
            // The resolved path starts with the uploads path of this site (the prefix includes host and path).
            if (strpos($src, $prefix . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    // ---- storage -------------------------------------------------------------------------------------------------

    /**
     * A draft message (not visible to anyone until publish()). $recipients: the WordPress users of an 'active' /
     * 'expiring' audience at this moment (null for 'all'). @return int the post id, 0 if WordPress refused it
     */
    public static function create_draft(string $title, string $summary, string $html, string $audience, int $days, ?array $recipients, int $author): int {
        $id = wp_insert_post(wp_slash(array(
            'post_type' => self::POST_TYPE,
            'post_status' => self::STATUS_DRAFT,
            'post_title' => $title,
            'post_excerpt' => $summary,
            'post_content' => self::sanitize_html($html),
            'post_author' => $author,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        )), true, false);
        if (!is_int($id) || $id <= 0) {
            return 0;
        }
        update_post_meta($id, self::META_AUDIENCE, $audience);
        update_post_meta($id, self::META_DAYS, $days);
        if ($recipients !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $recipients), static function (int $v): bool {
                return $v > 0;
            })));
            if (count($ids) > EVC_Push_Campaign::MAX_RECIPIENTS) {
                wp_delete_post($id, true);
                return 0;
            }
            update_post_meta($id, self::META_RECIPIENTS, $ids === array() ? ',' : ',' . implode(',', $ids) . ',');
        }
        return $id;
    }

    /** The send job was written: the message becomes visible to its audience. */
    public static function publish(int $id, int $sent_at): void {
        update_post_meta($id, self::META_SENT_AT, $sent_at);
        wp_update_post(array('ID' => $id, 'post_status' => self::STATUS_SENT));
    }

    /** The send was refused: the draft goes. */
    public static function discard(int $id): void {
        if ($id > 0 && get_post_type($id) === self::POST_TYPE && get_post_status($id) === self::STATUS_DRAFT) {
            wp_delete_post($id, true);
        }
    }

    /** Ids of the messages this member may see, newest first (sent messages only). @return int[] */
    public static function visible_ids(int $wp_user_id, int $limit = self::LIST_LIMIT): array {
        if ($wp_user_id <= 0) {
            return array();
        }
        $q = new WP_Query(array(
            'post_type' => self::POST_TYPE,
            'post_status' => self::STATUS_SENT,
            'posts_per_page' => max(1, min(self::LIST_LIMIT, $limit)),
            'orderby' => 'ID',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'ignore_sticky_posts' => true,
            'perm' => '',
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => self::META_AUDIENCE, 'value' => 'all'),
                array('key' => self::META_RECIPIENTS, 'value' => ',' . $wp_user_id . ',', 'compare' => 'LIKE'),
                array('key' => self::META_RECIPIENT, 'value' => (string) $wp_user_id),
            ),
        ));
        return array_values(array_map('intval', $q->posts));
    }

    /** May this member see this message? (sent, of this type, audience 'all' or a recipient) */
    public static function visible_to(int $id, int $wp_user_id): bool {
        if ($id <= 0 || $wp_user_id <= 0) {
            return false;
        }
        $post = get_post($id);
        if (!$post instanceof WP_Post || $post->post_type !== self::POST_TYPE || $post->post_status !== self::STATUS_SENT) {
            return false;
        }
        if ((string) get_post_meta($id, self::META_AUDIENCE, true) === 'all') {
            return true;
        }
        if (strpos((string) get_post_meta($id, self::META_RECIPIENTS, true), ',' . $wp_user_id . ',') !== false) {
            return true;
        }
        return in_array((string) $wp_user_id, array_map('strval', get_post_meta($id, self::META_RECIPIENT, false)), true);
    }

    /** @return array{id:int,title:string,summary:string,sent_at:string} the list entry of a sent message */
    public static function entry(int $id): array {
        $post = get_post($id);
        $at = (int) get_post_meta($id, self::META_SENT_AT, true);
        return array(
            'id' => $id,
            'title' => $post instanceof WP_Post ? (string) $post->post_title : '',
            'summary' => $post instanceof WP_Post ? (string) $post->post_excerpt : '',
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z', $at > 0 ? $at : ($post instanceof WP_Post ? (int) strtotime($post->post_date_gmt . ' UTC') : 0)),
        );
    }

    /** The body, sanitised again on the way out. */
    public static function html_of(int $id): string {
        $post = get_post($id);
        return $post instanceof WP_Post ? self::sanitize_html((string) $post->post_content) : '';
    }

    public static function last_read(int $wp_user_id): int {
        $v = get_user_meta($wp_user_id, self::LAST_READ_META, true);
        return is_numeric($v) ? max(0, (int) $v) : 0;
    }

    /** Opening a message moves the member's read mark forward (never back). */
    public static function mark_read(int $wp_user_id, int $id): void {
        if ($wp_user_id > 0 && $id > self::last_read($wp_user_id)) {
            update_user_meta($wp_user_id, self::LAST_READ_META, $id);
        }
    }
}
