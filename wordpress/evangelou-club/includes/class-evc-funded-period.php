<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * The paid period ONE recorded money movement funds (Task 1D-E). Computed
 * once, with EVC_Membership_Calendar (D1/D2/D5), at recording time, and
 * never edited afterwards. `predecessor_movement_id` links the member's
 * periods into one ordered chain (null for the first one).
 */
final class EVC_Funded_Period {
    const KIND_INITIAL = 'initial';
    const KIND_EARLY = 'early';
    const KIND_LATE = 'late';

    /** @var string */
    private $movement_id;
    /** @var int */
    private $wp_user_id;
    /** @var int */
    private $level_id;
    /** @var string */
    private $pmpro_row_ref;
    /** @var DateTimeImmutable */
    private $start_utc;
    /** @var DateTimeImmutable */
    private $end_utc;
    /** @var string */
    private $kind;
    /** @var string|null */
    private $predecessor_movement_id;

    public function __construct(
        string $movement_id,
        int $wp_user_id,
        int $level_id,
        string $pmpro_row_ref,
        DateTimeImmutable $start_utc,
        DateTimeImmutable $end_utc,
        string $kind,
        ?string $predecessor_movement_id
    ) {
        $start_utc = EVC_Clock::to_utc($start_utc);
        $end_utc = EVC_Clock::to_utc($end_utc);
        if ($end_utc <= $start_utc) {
            throw new InvalidArgumentException('A funded period must end after it starts.');
        }
        if (!in_array($kind, array(self::KIND_INITIAL, self::KIND_EARLY, self::KIND_LATE), true)) {
            throw new InvalidArgumentException('Unknown period kind.');
        }
        if (($kind === self::KIND_INITIAL) !== ($predecessor_movement_id === null)) {
            throw new InvalidArgumentException('Only the initial period has no predecessor.');
        }
        $this->movement_id = $movement_id;
        $this->wp_user_id = $wp_user_id;
        $this->level_id = $level_id;
        $this->pmpro_row_ref = $pmpro_row_ref;
        $this->start_utc = $start_utc;
        $this->end_utc = $end_utc;
        $this->kind = $kind;
        $this->predecessor_movement_id = $predecessor_movement_id;
    }

    public function movement_id(): string {
        return $this->movement_id;
    }

    public function wp_user_id(): int {
        return $this->wp_user_id;
    }

    public function level_id(): int {
        return $this->level_id;
    }

    public function pmpro_row_ref(): string {
        return $this->pmpro_row_ref;
    }

    public function start_utc(): DateTimeImmutable {
        return $this->start_utc;
    }

    public function end_utc(): DateTimeImmutable {
        return $this->end_utc;
    }

    public function kind(): string {
        return $this->kind;
    }

    public function predecessor_movement_id(): ?string {
        return $this->predecessor_movement_id;
    }
}
