<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Explicit configuration for EVC_Pmpro_Entitlement_Mapper. There are no
 * defaults: the approved Club level ids and the PMPro version range must be
 * supplied once the technician confirms the installed setup. Until then no
 * real configuration exists and nothing can be mapped to "active".
 */
final class EVC_Pmpro_Mapper_Config {
    const VERSION_PATTERN = '/^\d+(\.\d+){0,3}$/D';

    /** @var int[] */
    private $club_level_ids;
    /** @var string */
    private $min_pmpro_version;
    /** @var string */
    private $max_pmpro_version_exclusive;

    /**
     * @param int[] $club_level_ids approved Club membership level ids (non-empty)
     */
    public function __construct(array $club_level_ids, string $min_pmpro_version, string $max_pmpro_version_exclusive) {
        if ($club_level_ids === array()) {
            throw new InvalidArgumentException('At least one Club level id is required.');
        }
        foreach ($club_level_ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Club level ids must be positive integers.');
            }
        }
        if (!preg_match(self::VERSION_PATTERN, $min_pmpro_version) || !preg_match(self::VERSION_PATTERN, $max_pmpro_version_exclusive)
            || version_compare($min_pmpro_version, $max_pmpro_version_exclusive, '>=')) {
            throw new InvalidArgumentException('Invalid PMPro version range.');
        }
        $this->club_level_ids = array_values(array_unique($club_level_ids));
        $this->min_pmpro_version = $min_pmpro_version;
        $this->max_pmpro_version_exclusive = $max_pmpro_version_exclusive;
    }

    public function is_club_level(int $level_id): bool {
        return in_array($level_id, $this->club_level_ids, true);
    }

    public function supports_pmpro_version(?string $version): bool {
        return $version !== null
            && (bool) preg_match(self::VERSION_PATTERN, $version)
            && version_compare($version, $this->min_pmpro_version, '>=')
            && version_compare($version, $this->max_pmpro_version_exclusive, '<');
    }
}
