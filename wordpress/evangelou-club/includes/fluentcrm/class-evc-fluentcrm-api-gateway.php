<?php
defined('ABSPATH') || exit;
/**
 * STAGE-06: the ONLY shipped file that may call FluentCRM (packager-enforced). Constructed only by
 * EVC_Fluentcrm_Plugin::wire(), behind EVC_CLUB_FLUENTCRM_SYNC_ENABLED.
 *
 * Uses only the documented PHP API (developers.fluentcrm.com, "Contact API Function" / "Tag API
 * Function" / Subscriber model, checked for FluentCRM 3.2.5):
 *   FluentCrmApi('contacts')->getContactByUserId(int)   read-only; no e-mail fallback, never links
 *   FluentCrmApi('contacts')->getContact(int|string)    read-only; by contact ID or e-mail
 *   FluentCrmApi('tags')->find(int)                     read-only (proxied to the Tag model)
 *   $subscriber->hasAnyTagId(int[]) / attachTags(int[]) / detachTags(int[])
 * Deliberately NEVER: createOrUpdate (creates contacts, writes status), getContactByUserRef (writes the
 * user link when it matches by e-mail), lists, status / double opt-in, custom fields, and tag
 * titles or slugs (attachTags creates a tag for an unknown title; only verified int IDs are passed).
 */
final class EVC_Fluentcrm_Api_Gateway implements EVC_Fluentcrm_Gateway {
    const MIN_VERSION = '3.2.5';
    const VERSION_CONSTANT = 'FLUENTCRM_PLUGIN_VERSION';
    const API_FUNCTION = 'FluentCrmApi';

    public function __construct() {
        EVC_Fluentcrm_Feature::assert_constructible(__CLASS__);
    }

    /** Pure: is this FluentCRM version at least the pinned minimum? */
    public static function version_supported(?string $version): bool {
        return $version !== null && preg_match('/^\d+\.\d+(\.\d+)*$/D', $version) === 1 && version_compare($version, self::MIN_VERSION, '>=');
    }

    /** Resolved when asked (round 2), not at plugins_loaded: FluentCRM may load after this plugin. */
    public function available(): bool {
        if (!function_exists(self::API_FUNCTION) || !self::version_supported(defined(self::VERSION_CONSTANT) ? (string) constant(self::VERSION_CONSTANT) : null)) {
            return false;
        }
        $contacts = $this->contacts();
        $tags = $this->tags();
        return is_object($contacts) && is_object($tags)
            && is_callable(array($contacts, 'getContact')) && is_callable(array($contacts, 'getContactByUserId'))
            && is_callable(array($tags, 'find'));
    }

    public function tag(int $tag_id): ?array {
        $tag = $this->tags()->find($tag_id);
        if (!is_object($tag) || !isset($tag->id)) {
            return null;
        }
        return array('id' => self::int($tag->id), 'title' => (string) ($tag->title ?? ''), 'slug' => (string) ($tag->slug ?? ''));
    }

    /**
     * FluentCRM documents no way (short of the raw model query builder, deliberately unused) to ask
     * whether TWO contacts carry the same user_id; getContactByUserId() returns one. The residual risk is
     * documented; the sync still refuses when the e-mail lookup finds a different contact.
     */
    public function contact_by_user_id(int $wp_user_id): ?array {
        return self::row($this->contacts()->getContactByUserId($wp_user_id));
    }

    public function contact_by_email(string $email): ?array {
        if (strpos($email, '@') === false) {
            return null; // getContact() would read a numeric string as a contact ID
        }
        return self::row($this->contacts()->getContact($email));
    }

    public function has_tag(int $contact_id, int $tag_id): bool {
        return (bool) $this->subscriber($contact_id)->hasAnyTagId(array($tag_id));
    }

    public function attach_tag(int $contact_id, int $tag_id): void {
        $this->subscriber($contact_id)->attachTags(array(self::tag_id($tag_id)));
    }

    public function detach_tag(int $contact_id, int $tag_id): void {
        $this->subscriber($contact_id)->detachTags(array(self::tag_id($tag_id)));
    }

    /** A fresh copy of exactly this contact (never a stale relation), or an exception. */
    private function subscriber(int $contact_id) {
        $subscriber = $this->contacts()->getContact($contact_id);
        if (!is_object($subscriber) || !isset($subscriber->id) || self::int($subscriber->id) !== $contact_id) {
            throw new RuntimeException('FluentCRM contact not found.');
        }
        return $subscriber;
    }

    private function contacts() {
        return FluentCrmApi('contacts');
    }

    private function tags() {
        return FluentCrmApi('tags');
    }

    private static function tag_id(int $tag_id): int {
        if ($tag_id < 1) {
            throw new InvalidArgumentException('Invalid tag ID.');
        }
        return $tag_id;
    }

    /** @return array{id:int,email:string,user_id:?int}|null */
    private static function row($subscriber): ?array {
        if (!is_object($subscriber) || !isset($subscriber->id)) {
            return null;
        }
        $user = $subscriber->user_id ?? null;
        return array(
            'id' => self::int($subscriber->id),
            'email' => (string) ($subscriber->email ?? ''),
            'user_id' => $user === null || $user === '' || (string) $user === '0' ? null : self::int($user),
        );
    }

    /** Database integers may arrive as strings; anything else becomes 0 (never matches). */
    private static function int($value): int {
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) ? (int) $value : 0;
    }
}
