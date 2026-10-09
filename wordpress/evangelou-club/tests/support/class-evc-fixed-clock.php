<?php
/** Deterministic test clock. Counts reads so tests can prove "read once". */
final class EVC_Fixed_Clock implements EVC_Clock_Source {
    /** @var DateTimeImmutable */
    private $now;
    /** @var string|null Modifier applied after every read (e.g. "+1 day"). */
    private $advance_after_read;
    /** @var int */
    public $reads = 0;

    public function __construct(string $instant, ?string $advance_after_read = null) {
        $this->set($instant);
        $this->advance_after_read = $advance_after_read;
    }

    public function set(string $instant): void {
        $this->now = EVC_Clock::to_utc(new DateTimeImmutable($instant));
    }

    public function now(): DateTimeImmutable {
        $this->reads++;
        $value = $this->now;
        if ($this->advance_after_read !== null) {
            $this->now = $this->now->modify($this->advance_after_read);
        }
        return $value;
    }
}
