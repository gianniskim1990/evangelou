<?php
/**
 * STAGE-06 TEST-ONLY stand-in for FluentCRM's documented PHP API (loaded only by the wp-fluentcrm suite;
 * never shipped — the packager refuses tests/). Shapes follow developers.fluentcrm.com: FluentCrmApi('contacts')
 * -> getContact(int|string), getContactByUserId(int); FluentCrmApi('tags') proxies find() to the Tag model via
 * __call(); a Subscriber has id / email / user_id and hasAnyTagId / attachTags / detachTags. Every other method
 * that is called is RECORDED as forbidden and throws, so the suite proves the plugin never reaches for it.
 */
define('FLUENTCRM_PLUGIN_VERSION', '3.2.5');

final class EVC_Test_Fluentcrm {
    /** @var array<int,array{id:int,title:string,slug:string}> */
    public static $tags = array();
    /** @var array<int,array{id:int,email:string,user_id:?int,tags:int[],status:string,first_name:string}> */
    public static $contacts = array();
    /** @var string[] */
    public static $calls = array();
    /** @var string[] */
    public static $forbidden = array();
    /** @var string|null */
    public static $fail_on;
    /** @var bool */
    public static $ids_as_strings = true;

    public static function reset(): void {
        self::$tags = array(
            1 => array('id' => 1, 'title' => 'Ευαγγέλου Club', 'slug' => 'ευαγγέλου-club'),
            2 => array('id' => 2, 'title' => 'Active', 'slug' => 'active'),
            3 => array('id' => 3, 'title' => 'Inactive', 'slug' => 'inactive'),
            4 => array('id' => 4, 'title' => 'Pending payment', 'slug' => 'pending-payment'),
        );
        self::$contacts = array();
        self::$calls = array();
        self::$forbidden = array();
        self::$fail_on = null;
        self::$ids_as_strings = true;
    }

    public static function contact(int $id, string $email, ?int $user_id, array $tags = array()): void {
        self::$contacts[$id] = array('id' => $id, 'email' => $email, 'user_id' => $user_id, 'tags' => $tags, 'status' => 'subscribed', 'first_name' => 'Μαρία');
    }

    /** @return int[] */
    public static function tags_of(int $id): array {
        $t = self::$contacts[$id]['tags'];
        sort($t);
        return $t;
    }

    public static function call(string $what): void {
        self::$calls[] = $what;
        if (self::$fail_on === $what) {
            throw new RuntimeException('fake FluentCRM failure');
        }
    }

    public static function forbid(string $what): void {
        self::$forbidden[] = $what;
        throw new BadMethodCallException('The plugin called a FluentCRM method it must never use.');
    }

    /** Database ids arrive as strings, like a real row. */
    public static function id(int $id) {
        return self::$ids_as_strings ? (string) $id : $id;
    }
}

final class EVC_Test_Fluentcrm_Subscriber {
    public $id;
    public $email;
    public $user_id;
    public $status;
    public $first_name;

    public function __construct(array $row) {
        $this->id = EVC_Test_Fluentcrm::id($row['id']);
        $this->email = $row['email'];
        $this->user_id = $row['user_id'] === null ? null : EVC_Test_Fluentcrm::id($row['user_id']);
        $this->status = $row['status'];
        $this->first_name = $row['first_name'];
    }

    public function hasAnyTagId($ids): bool {
        EVC_Test_Fluentcrm::call('hasAnyTagId');
        return (bool) array_intersect(array_map('intval', (array) $ids), EVC_Test_Fluentcrm::$contacts[(int) $this->id]['tags']);
    }

    public function attachTags($ids) {
        EVC_Test_Fluentcrm::call('attachTags');
        foreach ((array) $ids as $tag) {
            if (!is_int($tag) || !isset(EVC_Test_Fluentcrm::$tags[$tag])) {
                EVC_Test_Fluentcrm::forbid('attachTags with ' . gettype($tag)); // a title or slug would CREATE a tag
            }
            if (!in_array($tag, EVC_Test_Fluentcrm::$contacts[(int) $this->id]['tags'], true)) {
                EVC_Test_Fluentcrm::$contacts[(int) $this->id]['tags'][] = $tag;
            }
        }
        return $this;
    }

    public function detachTags($ids) {
        EVC_Test_Fluentcrm::call('detachTags');
        foreach ((array) $ids as $tag) {
            if (!is_int($tag)) {
                EVC_Test_Fluentcrm::forbid('detachTags with ' . gettype($tag));
            }
        }
        EVC_Test_Fluentcrm::$contacts[(int) $this->id]['tags'] = array_values(array_diff(EVC_Test_Fluentcrm::$contacts[(int) $this->id]['tags'], (array) $ids));
        return $this;
    }

    public function __call($name, $args) {
        EVC_Test_Fluentcrm::forbid('subscriber->' . $name);
    }

    public function __set($name, $value) {
        EVC_Test_Fluentcrm::forbid('subscriber->' . $name . ' =');
    }
}

final class EVC_Test_Fluentcrm_Contacts_Api {
    public function getContact($id_or_email) {
        EVC_Test_Fluentcrm::call('getContact');
        foreach (EVC_Test_Fluentcrm::$contacts as $row) {
            $hit = is_int($id_or_email) || ctype_digit((string) $id_or_email) ? $row['id'] === (int) $id_or_email : strtolower($row['email']) === strtolower((string) $id_or_email);
            if ($hit) {
                return new EVC_Test_Fluentcrm_Subscriber($row);
            }
        }
        return false;
    }

    public function getContactByUserId($user_id) {
        EVC_Test_Fluentcrm::call('getContactByUserId');
        foreach (EVC_Test_Fluentcrm::$contacts as $row) {
            if ($row['user_id'] === (int) $user_id) {
                return new EVC_Test_Fluentcrm_Subscriber($row);
            }
        }
        return false;
    }

    /** createOrUpdate, getContactByUserRef, getCurrentContact, query, getInstance, ...: never. */
    public function __call($name, $args) {
        EVC_Test_Fluentcrm::forbid('contacts->' . $name);
    }
}

final class EVC_Test_Fluentcrm_Tag {
    public $id;
    public $title;
    public $slug;

    public function __construct(array $row) {
        $this->id = EVC_Test_Fluentcrm::id($row['id']);
        $this->title = $row['title'];
        $this->slug = $row['slug'];
    }
}

final class EVC_Test_Fluentcrm_Tags_Api {
    /** find() is proxied to the Tag model, as documented; everything else (importBulk, ...) is never used. */
    public function __call($name, $args) {
        if ($name !== 'find') {
            EVC_Test_Fluentcrm::forbid('tags->' . $name);
        }
        EVC_Test_Fluentcrm::call('find');
        $id = $args[0] ?? null;
        return is_int($id) && isset(EVC_Test_Fluentcrm::$tags[$id]) ? new EVC_Test_Fluentcrm_Tag(EVC_Test_Fluentcrm::$tags[$id]) : null;
    }
}

function FluentCrmApi($key = null) {
    EVC_Test_Fluentcrm::call('FluentCrmApi:' . $key);
    if ($key === 'contacts') {
        return new EVC_Test_Fluentcrm_Contacts_Api();
    }
    if ($key === 'tags') {
        return new EVC_Test_Fluentcrm_Tags_Api();
    }
    EVC_Test_Fluentcrm::forbid('FluentCrmApi(' . $key . ')');
}

EVC_Test_Fluentcrm::reset();
