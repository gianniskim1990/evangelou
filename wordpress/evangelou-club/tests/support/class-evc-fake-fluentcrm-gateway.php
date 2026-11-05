<?php
/**
 * STAGE-06 test double for EVC_Fluentcrm_Gateway: an in-memory FluentCRM with tags, contacts and a
 * call log. Never touches a real FluentCRM.
 */
final class EVC_Fake_Fluentcrm_Gateway implements EVC_Fluentcrm_Gateway {
    /** @var bool */
    public $available = true;
    /** @var array<int,array{id:int,title:string,slug:string}> */
    public $tags = array();
    /** @var array<int,array{id:int,email:string,user_id:?int,tags:int[]}> */
    public $contacts = array();
    /** @var string[] */
    public $calls = array();
    /** @var string|null method name that throws */
    public $fail_on;

    public static function standard(): self {
        $g = new self();
        $g->tags = array(
            1 => array('id' => 1, 'title' => 'Ευαγγέλου Club', 'slug' => 'ευαγγέλου-club'),
            2 => array('id' => 2, 'title' => 'Active', 'slug' => 'active'),
            3 => array('id' => 3, 'title' => 'Inactive', 'slug' => 'inactive'),
            4 => array('id' => 4, 'title' => 'Pending payment', 'slug' => 'pending-payment'),
        );
        return $g;
    }

    public function contact(int $id, string $email, ?int $user_id, array $tags = array()): self {
        $this->contacts[$id] = array('id' => $id, 'email' => $email, 'user_id' => $user_id, 'tags' => $tags);
        return $this;
    }

    /** @return int[] */
    public function tags_of(int $contact_id): array {
        $t = $this->contacts[$contact_id]['tags'];
        sort($t);
        return $t;
    }

    /** Calls that change FluentCRM. @return string[] */
    public function writes(): array {
        return array_values(array_filter($this->calls, function ($c) {
            return strpos($c, 'attach') === 0 || strpos($c, 'detach') === 0;
        }));
    }

    private function call(string $name, string $detail = ''): void {
        $this->calls[] = $name . ($detail === '' ? '' : ':' . $detail);
        if ($this->fail_on === $name) {
            throw new RuntimeException('fake FluentCRM failure');
        }
    }

    public function available(): bool {
        $this->call('available');
        return $this->available;
    }

    public function tag(int $tag_id): ?array {
        $this->call('tag', (string) $tag_id);
        return $this->tags[$tag_id] ?? null;
    }

    public function contact_by_user_id(int $wp_user_id): ?array {
        $this->call('contact_by_user_id');
        foreach ($this->contacts as $c) {
            if ($c['user_id'] === $wp_user_id) {
                return array('id' => $c['id'], 'email' => $c['email'], 'user_id' => $c['user_id']);
            }
        }
        return null;
    }

    public function contact_by_email(string $email): ?array {
        $this->call('contact_by_email');
        foreach ($this->contacts as $c) {
            // FluentCRM's e-mail column compares case-insensitively.
            if (strtolower($c['email']) === strtolower($email)) {
                return array('id' => $c['id'], 'email' => $c['email'], 'user_id' => $c['user_id']);
            }
        }
        return null;
    }

    public function has_tag(int $contact_id, int $tag_id): bool {
        $this->call('has_tag', $contact_id . '/' . $tag_id);
        return in_array($tag_id, $this->contacts[$contact_id]['tags'], true);
    }

    public function attach_tag(int $contact_id, int $tag_id): void {
        $this->call('attach_tag', $contact_id . '/' . $tag_id);
        if (!in_array($tag_id, $this->contacts[$contact_id]['tags'], true)) {
            $this->contacts[$contact_id]['tags'][] = $tag_id;
        }
    }

    public function detach_tag(int $contact_id, int $tag_id): void {
        $this->call('detach_tag', $contact_id . '/' . $tag_id);
        $this->contacts[$contact_id]['tags'] = array_values(array_diff($this->contacts[$contact_id]['tags'], array($tag_id)));
    }
}
