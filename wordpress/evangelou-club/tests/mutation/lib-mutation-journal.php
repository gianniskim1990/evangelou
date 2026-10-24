<?php
/**
 * STAGE-03B.2: crash-safe restoration for the PHP mutation runners.
 *
 * `finally` restores a mutated source only while the PHP process lives. If
 * the runner is killed (session ended, CI cancelled, SIGKILL) the mutant
 * stays in the working copy — this happened once (BC7, STAGE-03B.1). So
 * BEFORE a mutant is written, the original bytes and the hash of the mutated
 * bytes are recorded in a journal OUTSIDE the repository; the journal is
 * cleared once the original is back. Every runner calls
 * evc_mutation_journal_recover() first:
 *  - file still holds exactly the recorded MUTANT  -> original restored;
 *  - file already holds exactly the original       -> nothing to do;
 *  - anything else (someone edited it meanwhile)   -> ABORT, never overwrite.
 *
 * Pure PHP, no PHPUnit; unit-tested by tests/unit/MutationJournalTest.php.
 */

/** Journal path for a plugin root (system temp dir, one per working copy). */
function evc_mutation_journal_path(string $root, ?string $dir = null): string {
    return rtrim($dir ?? sys_get_temp_dir(), '/\\') . '/evc-mutation-journal-' . substr(hash('sha256', (string) realpath($root)), 0, 16) . '.json';
}

/** Records the pending mutation; must be called BEFORE the mutant is written. */
function evc_mutation_journal_begin(string $journal, string $path, string $original, string $mutated): void {
    $entry = array(
        'path' => $path,
        'original_b64' => base64_encode($original),
        'original_sha256' => hash('sha256', $original),
        'mutated_sha256' => hash('sha256', $mutated),
    );
    $tmp = $journal . '.tmp';
    if (file_put_contents($tmp, json_encode($entry)) === false || !rename($tmp, $journal)) {
        throw new RuntimeException('Mutation journal could not be written; refusing to mutate.');
    }
}

/** Clears the journal once the original is verified back in place. */
function evc_mutation_journal_end(string $journal, string $path): void {
    $entry = evc_mutation_journal_read($journal);
    if ($entry === null || $entry['path'] !== $path) {
        throw new RuntimeException('Mutation journal does not describe ' . $path);
    }
    if (hash_file('sha256', $path) !== $entry['original_sha256']) {
        throw new RuntimeException('Original not restored: ' . $path);
    }
    unlink($journal);
}

/** @return array{path:string,original_b64:string,original_sha256:string,mutated_sha256:string}|null */
function evc_mutation_journal_read(string $journal): ?array {
    if (!is_file($journal)) {
        return null;
    }
    $entry = json_decode((string) file_get_contents($journal), true);
    if (!is_array($entry) || !isset($entry['path'], $entry['original_b64'], $entry['original_sha256'], $entry['mutated_sha256'])
        || !is_string($entry['path']) || !is_string($entry['original_b64'])) {
        throw new RuntimeException('Mutation journal is corrupt: ' . $journal . ' (inspect it manually; nothing was changed).');
    }
    $original = base64_decode($entry['original_b64'], true);
    if ($original === false || hash('sha256', $original) !== $entry['original_sha256']) {
        throw new RuntimeException('Mutation journal content does not match its hash: ' . $journal);
    }
    return $entry;
}

/**
 * Restores a mutant left behind by an interrupted run.
 *
 * @return string "clean" (no journal), "restored" or "already_original"
 */
function evc_mutation_journal_recover(string $journal): string {
    $entry = evc_mutation_journal_read($journal);
    if ($entry === null) {
        return 'clean';
    }
    $path = $entry['path'];
    $current = is_file($path) ? hash_file('sha256', $path) : null;
    if ($current === $entry['original_sha256']) {
        unlink($journal);
        return 'already_original';
    }
    if ($current !== $entry['mutated_sha256']) {
        throw new RuntimeException('Interrupted mutation of ' . $path . ' cannot be undone automatically: the file is neither the recorded mutant nor the original. Journal kept at ' . $journal . '.');
    }
    if (file_put_contents($path, base64_decode($entry['original_b64'], true)) === false || hash_file('sha256', $path) !== $entry['original_sha256']) {
        throw new RuntimeException('Could not restore ' . $path . ' from the mutation journal.');
    }
    unlink($journal);
    return 'restored';
}
