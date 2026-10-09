<?php
/**
 * Targeted mutation testing for the critical business rules.
 *
 * For each mutant: apply ONE exact source edit, run the PHPUnit suite
 * (excluding the slow multi-process group), and require that the suite FAILS
 * ("killed"). The original file is restored in a finally block, and at the
 * end every touched file's SHA-256 must equal its pre-run value.
 *
 * Requires the same EVC_TEST_DB_* environment as the integration tests.
 * Exit code 0 only if: the unmutated baseline passes, every mutant is killed,
 * and all sources are byte-for-byte restored.
 *
 * Usage (from the plugin directory): php tests/mutation/run-mutations.php
 */
$root = dirname(__DIR__, 2);
$phpunit = array(PHP_BINARY, $root . '/vendor/bin/phpunit', '--configuration', $root . '/phpunit.xml.dist', '--exclude-group', 'concurrency');

$mutants = array(
    array(
        'id' => 'M1-remove-one-per-day-unique-key',
        'file' => 'database/001_initial.sql',
        'search' => "  UNIQUE KEY uq_redemptions_one_per_day (member_id, benefit_type, business_date),\n",
        'replace' => '',
    ),
    array(
        'id' => 'M2-allow-second-redemption-per-day',
        'file' => 'database/001_initial.sql',
        'search' => 'uq_redemptions_one_per_day (member_id, benefit_type, business_date)',
        'replace' => 'uq_redemptions_one_per_day (member_id, benefit_type, business_date, request_id)',
    ),
    array(
        'id' => 'M3-business-date-in-utc',
        'file' => 'includes/class-evc-clock.php',
        'search' => "const BUSINESS_TIMEZONE = 'Europe/Athens';",
        'replace' => "const BUSINESS_TIMEZONE = 'UTC';",
    ),
    array(
        'id' => 'M4-bypass-membership-eligibility',
        'file' => 'includes/class-evc-redemption-service.php',
        'search' => '$denial = $entitlement->denial_reason($now);',
        'replace' => '$denial = null;',
    ),
    array(
        'id' => 'M5-ignore-request-fingerprint',
        'file' => 'includes/class-evc-redemption-service.php',
        'search' => "if (EVC_Request_Fingerprint::matches(\$stored['request_fingerprint'], \$fingerprint)) {",
        'replace' => 'if (true) {',
    ),
    array(
        'id' => 'M6-fingerprint-omits-coffee',
        'file' => 'includes/class-evc-request-fingerprint.php',
        'search' => "            \$coffee_code,\n",
        'replace' => '',
    ),
    array(
        'id' => 'M7-expiry-instant-counts-as-active',
        'file' => 'includes/class-evc-entitlement.php',
        'search' => 'if ($at >= $this->expires_at_utc) {',
        'replace' => 'if ($at > $this->expires_at_utc) {',
    ),
    array(
        'id' => 'M8-ignore-payment-verification',
        'file' => 'includes/class-evc-entitlement.php',
        'search' => 'if (!$this->payment_verified) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'M9-missing-expiry-treated-as-eligible',
        'file' => 'includes/class-evc-entitlement.php',
        'search' => "        if (\$this->expires_at_utc === null) {\n            return self::DENIAL_EXPIRY_UNKNOWN;\n        }\n        if (\$at >= \$this->expires_at_utc) {",
        'replace' => "        if (\$this->expires_at_utc !== null && \$at >= \$this->expires_at_utc) {",
    ),
    array(
        'id' => 'M10-skip-coffee-allowlist',
        'file' => 'includes/class-evc-redemption-service.php',
        'search' => 'if (!$this->catalog->contains($coffee_code)) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'M11-no-rollback-on-failure',
        'file' => 'includes/class-evc-club-db.php',
        'search' => "            \$this->rollback_quietly();\n            if (\$e instanceof PDOException) {",
        'replace' => "            if (\$e instanceof PDOException) {",
    ),
    array(
        'id' => 'M12-audit-outside-transaction',
        'file' => 'includes/class-evc-redemption-service.php',
        'search' => '$this->audit->record($db, array(',
        'replace' => '$this->audit_best_effort(array(',
    ),
);

function evc_run_suite(array $command): int {
    $proc = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($proc)) {
        return 255;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

$touched = array();
foreach ($mutants as $m) {
    $path = $root . '/' . $m['file'];
    if (!isset($touched[$path])) {
        $touched[$path] = hash_file('sha256', $path);
    }
}

echo "Baseline (unmutated) run...\n";
$baseline = evc_run_suite($phpunit);
if ($baseline !== 0) {
    fwrite(STDERR, "ABORT: baseline suite is not green (exit $baseline); mutation results would be meaningless.\n");
    exit(1);
}
echo "Baseline: PASS\n";

$survivors = array();
$killed = 0;
foreach ($mutants as $m) {
    $path = $root . '/' . $m['file'];
    $original = file_get_contents($path);
    $occurrences = substr_count($original, $m['search']);
    if ($occurrences !== 1) {
        fwrite(STDERR, sprintf("ABORT: %s pattern found %d times in %s (expected exactly 1).\n", $m['id'], $occurrences, $m['file']));
        exit(1);
    }
    try {
        file_put_contents($path, str_replace($m['search'], $m['replace'], $original));
        $exit = evc_run_suite($phpunit);
    } finally {
        file_put_contents($path, $original);
    }
    $status = $exit !== 0 ? 'KILLED' : 'SURVIVED';
    if ($exit !== 0) {
        $killed++;
    } else {
        $survivors[] = $m['id'];
    }
    printf("%-42s %s (phpunit exit %d)\n", $m['id'], $status, $exit);
}

$restored = true;
foreach ($touched as $path => $hash) {
    if (hash_file('sha256', $path) !== $hash) {
        $restored = false;
        fwrite(STDERR, 'NOT RESTORED: ' . $path . "\n");
    }
}

printf("[evidence] mutation: total=%d killed=%d survived=%d sources_restored=%s\n", count($mutants), $killed, count($survivors), $restored ? 'yes' : 'NO');
if ($survivors || !$restored) {
    if ($survivors) {
        fwrite(STDERR, 'Surviving mutants: ' . implode(', ', $survivors) . "\n");
    }
    exit(1);
}
exit(0);
