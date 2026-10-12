<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Immutable input for resolving UNRESOLVED corrections (Task 1D-E.R1): a
 * refund/reversal/void that was verified before the payment it corrects had
 * been recorded.
 *
 * A FUTURE trusted adapter builds this only after it has independently
 * re-verified the movement's CURRENT source state (never from a webhook
 * payload) and has seen each acknowledged correction in that state. The
 * acknowledged ids must equal, in order, exactly the unresolved corrections
 * the store holds for the movement; the recorder then records the movement,
 * its period and those corrections as one atomic write, so the payment can
 * never exist without its refund. This class verifies nothing itself and
 * carries no refund policy: the mapper still decides what a refund means.
 */
final class EVC_Movement_Reconciliation {
    /** @var EVC_Verified_Movement */
    private $movement;
    /** @var string[] */
    private $acknowledged_correction_ids;

    /** @param string[] $acknowledged_correction_ids oldest first */
    public function __construct(EVC_Verified_Movement $movement, array $acknowledged_correction_ids) {
        if ($acknowledged_correction_ids === array()) {
            throw new InvalidArgumentException('A reconciliation must acknowledge at least one correction.');
        }
        $seen = array();
        foreach ($acknowledged_correction_ids as $id) {
            if (!is_string($id) || !preg_match(EVC_Verified_Movement::ID_PATTERN, $id)) {
                throw new InvalidArgumentException('Correction ids must be opaque 64-hex values.');
            }
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Correction ids must be unique.');
            }
            $seen[$id] = true;
        }
        $this->movement = $movement;
        $this->acknowledged_correction_ids = array_values($acknowledged_correction_ids);
    }

    public function movement(): EVC_Verified_Movement {
        return $this->movement;
    }

    /** @return string[] oldest first */
    public function acknowledged_correction_ids(): array {
        return $this->acknowledged_correction_ids;
    }
}
