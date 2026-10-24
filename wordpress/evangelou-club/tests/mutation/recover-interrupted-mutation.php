<?php
/**
 * STAGE-03B.2: restores a source file left mutated by a KILLED mutation run
 * (see lib-mutation-journal.php). Every runner does this itself on start;
 * this script does only the recovery, e.g. after a cancelled CI job or an
 * interrupted local session, before reviewing `git status`.
 *
 * Usage (from the plugin directory): php tests/mutation/recover-interrupted-mutation.php
 */
require_once __DIR__ . '/lib-mutation-journal.php';
try {
    $result = evc_mutation_journal_recover(evc_mutation_journal_path(dirname(__DIR__, 2)));
} catch (RuntimeException $e) {
    fwrite(STDERR, 'ABORT: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "[evidence] mutation-journal: {$result}\n";
exit(0);
