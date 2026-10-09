<?php
/** Audit log that throws for selected outcomes and delegates otherwise. */
final class EVC_Failing_Audit_Log implements EVC_Audit_Log {
    /** @var EVC_Audit_Log */
    private $inner;
    /** @var string[] */
    private $fail_outcomes;

    public function __construct(EVC_Audit_Log $inner, array $fail_outcomes) {
        $this->inner = $inner;
        $this->fail_outcomes = $fail_outcomes;
    }

    public function record(EVC_Club_Db $db, array $event): int {
        if (in_array($event['outcome'], $this->fail_outcomes, true)) {
            throw new RuntimeException('Injected audit failure');
        }
        return $this->inner->record($db, $event);
    }
}
