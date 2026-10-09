<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Explicit allowlist of coffee codes staff may record. A client-supplied
 * coffee_code is accepted only if it is well-formed AND present here.
 *
 * The plugin ships NO production list yet: the shop's final menu is an open
 * owner decision (D3). Tests use clearly marked fixtures in tests/fixtures.
 */
final class EVC_Coffee_Catalog {
    const CODE_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/D';

    /** @var array<string,string> code => display label */
    private $items = array();

    /** @param array<string,string> $items code => label */
    public function __construct(array $items) {
        foreach ($items as $code => $label) {
            if (!self::is_valid_format($code)) {
                throw new InvalidArgumentException('Invalid coffee code in catalogue.');
            }
            if (!is_string($label) || trim($label) === '') {
                throw new InvalidArgumentException('Coffee label must be a non-empty string.');
            }
            $this->items[$code] = $label;
        }
    }

    public static function is_valid_format($code): bool {
        return is_string($code) && (bool) preg_match(self::CODE_PATTERN, $code);
    }

    public function contains($code): bool {
        return self::is_valid_format($code) && array_key_exists($code, $this->items);
    }

    /** @return string[] */
    public function codes(): array {
        return array_keys($this->items);
    }

    public function label(string $code): ?string {
        return $this->contains($code) ? $this->items[$code] : null;
    }
}
