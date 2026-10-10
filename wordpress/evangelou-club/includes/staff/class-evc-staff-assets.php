<?php
defined('ABSPATH') || exit;

/**
 * Resolves the hashed staff-app assets from the Vite manifest that the
 * packaging step copies to <plugin>/staff-app/manifest.json.
 *
 * Fail closed: a missing/corrupt manifest, a missing entry, a path that is
 * not a plain hashed asset name, or any referenced file that does not exist
 * inside the asset directory returns null, and the shell answers 503 without
 * any bootstrap data. Because every referenced file must exist, a stale
 * manifest from another build cannot silently load mismatched code.
 */
final class EVC_Staff_Assets {
    const ENTRY = 'staff.html';
    const ASSET_PATTERN = '/^assets\/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.(?:js|css)$/D';

    /** @var string */
    private $dir;
    /** @var string */
    private $url;

    public function __construct(string $dir, string $url) {
        $this->dir = rtrim($dir, '/\\');
        $this->url = rtrim($url, '/');
    }

    public static function production(): self {
        return new self(EVC_CLUB_PATH . 'staff-app', plugins_url('staff-app', EVC_CLUB_FILE));
    }

    /** @return array{scripts:string[],preloads:string[],styles:string[]}|null */
    public function resolve(): ?array {
        $manifest_file = $this->dir . '/manifest.json';
        if (!is_file($manifest_file) || !is_readable($manifest_file)) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($manifest_file), true);
        if (!is_array($manifest) || !isset($manifest[self::ENTRY]) || !is_array($manifest[self::ENTRY])) {
            return null;
        }
        $entry = $manifest[self::ENTRY];
        if (empty($entry['isEntry']) || !isset($entry['file'])) {
            return null;
        }

        $scripts = array();
        $preloads = array();
        $styles = array();
        $seen = array();
        $ok = $this->collect($manifest, self::ENTRY, $scripts, $preloads, $styles, $seen, true);
        if (!$ok || count($scripts) !== 1) {
            return null;
        }
        return array('scripts' => $scripts, 'preloads' => $preloads, 'styles' => $styles);
    }

    private function collect(array $manifest, string $key, array &$scripts, array &$preloads, array &$styles, array &$seen, bool $is_entry): bool {
        if (isset($seen[$key])) {
            return true;
        }
        $seen[$key] = true;
        if (!isset($manifest[$key]) || !is_array($manifest[$key]) || !isset($manifest[$key]['file'])) {
            return false;
        }
        $chunk = $manifest[$key];
        $file = $this->asset_url($chunk['file']);
        if ($file === null) {
            return false;
        }
        if ($is_entry) {
            $scripts[] = $file;
        } else {
            $preloads[] = $file;
        }
        foreach (isset($chunk['css']) && is_array($chunk['css']) ? $chunk['css'] : array() as $css) {
            $url = $this->asset_url($css);
            if ($url === null) {
                return false;
            }
            if (!in_array($url, $styles, true)) {
                $styles[] = $url;
            }
        }
        foreach (isset($chunk['imports']) && is_array($chunk['imports']) ? $chunk['imports'] : array() as $import) {
            if (!is_string($import) || !$this->collect($manifest, $import, $scripts, $preloads, $styles, $seen, false)) {
                return false;
            }
        }
        return true;
    }

    private function asset_url($relative): ?string {
        if (!is_string($relative) || !preg_match(self::ASSET_PATTERN, $relative)) {
            return null;
        }
        $path = $this->dir . '/' . $relative;
        $real = realpath($path);
        $base = realpath($this->dir . '/assets');
        if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            return null;
        }
        return $this->url . '/' . $relative;
    }
}
