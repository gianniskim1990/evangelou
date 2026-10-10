<?php
/**
 * Targeted mutation tests for the Task 1D-B membership entitlement rules
 * (calendar/DST, renewal, payment-to-period binding, fail-closed mapper).
 *
 * Same discipline as run-mutations.php: each mutant applies ONE exact source
 * edit (pattern must occur exactly once), runs the DB-free unit suite and
 * must make it FAIL. Originals are restored in `finally` and verified by
 * SHA-256 at the end. No database is needed.
 *
 * Usage (from the plugin directory): php tests/mutation/run-membership-mutations.php
 */
$root = dirname(__DIR__, 2);
$phpunit = array(PHP_BINARY, $root . '/vendor/bin/phpunit', '--configuration', $root . '/phpunit.xml.dist', '--testsuite', 'unit');

$mapper = 'includes/class-evc-pmpro-entitlement-mapper.php';
$calendar = 'includes/class-evc-membership-calendar.php';

$mutants = array(
    array(
        'id' => 'B1-pending-payment-counts-as-paid',
        'file' => 'includes/class-evc-pmpro-payment-fact.php',
        'search' => 'return in_array($this->status, array(self::STATUS_CONFIRMED,',
        'replace' => 'return in_array($this->status, array(self::STATUS_PENDING, self::STATUS_CONFIRMED,',
    ),
    array(
        'id' => 'B2-payment-evidence-not-required',
        'file' => $mapper,
        'search' => "            return \$this->indeterminate('no_payment_evidence');\n",
        'replace' => "            return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, \$row_end, \$level_ref, self::SOURCE);\n",
    ),
    array(
        'id' => 'B3-old-payment-accepted-for-later-period',
        'file' => $mapper,
        'search' => "if (\$last['end'] != \$row_end || \$last['payment']->membership_row_ref !== \$row->row_ref) {",
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B4-expiry-inclusive',
        'file' => $mapper,
        'search' => "if (\$at >= \$last['end']) {",
        'replace' => "if (\$at > \$last['end']) {",
    ),
    array(
        'id' => 'B5-trust-pmpro-active-status-and-cron',
        'file' => $mapper,
        'search' => "            \$flags[] = 'paid_period_ended';\n",
        'replace' => "            return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, \$at->modify('+1 day'), \$level_ref, self::SOURCE);\n",
    ),
    array(
        'id' => 'B6-calendar-month-clamp-removed',
        'file' => $calendar,
        'search' => "\$day = min((int) \$local->format('j'), self::days_in_month(\$year, \$month));",
        'replace' => "\$day = (int) \$local->format('j');",
    ),
    array(
        'id' => 'B7-naive-php-plus-one-month',
        'file' => $calendar,
        'search' => "        return self::add_calendar_month_resolved(\$start)['instant'];\n",
        'replace' => "        return EVC_Clock::to_utc(EVC_Clock::to_utc(\$start)->setTimezone(self::zone())->modify('+1 month'));\n",
    ),
    array(
        'id' => 'B8-fixed-30-day-month',
        'file' => $calendar,
        'search' => "        return self::add_calendar_month_resolved(\$start)['instant'];\n",
        'replace' => "        return EVC_Clock::to_utc(\$start)->modify('+30 days');\n",
    ),
    array(
        'id' => 'B9-pending-renewal-extends',
        'file' => $mapper,
        'search' => "                    \$pending = true;\n",
        'replace' => "                    \$pending = true;\n                    \$confirmed[] = \$p;\n",
    ),
    array(
        'id' => 'B10-late-renewal-stacks-on-old-end',
        'file' => $calendar,
        'search' => "            \$start = \$confirmed_at;\n",
        'replace' => "            \$start = \$current_paid_end === null ? \$confirmed_at : EVC_Clock::to_utc(\$current_paid_end);\n",
    ),
    array(
        'id' => 'B11-early-renewal-restarts-at-payment',
        'file' => $calendar,
        'search' => "            \$start = EVC_Clock::to_utc(\$current_paid_end);\n",
        'replace' => "            \$start = \$confirmed_at;\n",
    ),
    array(
        'id' => 'B12-conflicting-payment-events-accepted',
        'file' => $mapper,
        'search' => 'if (!$this->same_payment($group[0], $other)) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B13-recorded-period-not-verified',
        'file' => $mapper,
        'search' => "if (\$expected['start'] != EVC_Clock::to_utc(\$p->period_start_utc) || \$expected['end'] != EVC_Clock::to_utc(\$p->period_end_utc)) {",
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B14-unlinked-payment-accepted',
        'file' => $mapper,
        'search' => "                || !in_array(\$p->membership_row_ref, \$club_row_refs, true)) {",
        'replace' => "                && false) {",
    ),
    array(
        'id' => 'B15-unsupported-level-eligible',
        'file' => 'includes/class-evc-pmpro-mapper-config.php',
        'search' => 'return in_array($level_id, $this->club_level_ids, true);',
        'replace' => 'return $level_id > 0;',
    ),
    array(
        'id' => 'B16-fixed-offset-timezone-accepted',
        'file' => $mapper,
        'search' => 'if ($s->site_timezone !== self::BUSINESS_TIMEZONE) {',
        'replace' => 'if ($s->site_timezone === null) {',
    ),
    array(
        'id' => 'B17-calendar-uses-fixed-offset',
        'file' => $calendar,
        'search' => "const ZONE = 'Europe/Athens';",
        'replace' => "const ZONE = '+03:00';",
    ),
    array(
        'id' => 'B18-partial-refund-treated-as-paid',
        'file' => $mapper,
        'search' => "if (\$period['payment']->status === EVC_Pmpro_Payment_Fact::STATUS_PARTIALLY_REFUNDED) {",
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B19-full-refund-ignored',
        'file' => $mapper,
        'search' => "if (\$period['payment']->status === EVC_Pmpro_Payment_Fact::STATUS_REFUNDED) {",
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B20-mapper-error-grants-entitlement',
        'file' => $mapper,
        'search' => "            return EVC_Entitlement::indeterminate(self::SOURCE, array('mapper_error'));\n",
        'replace' => "            return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, \$at->modify('+1 day'), null, self::SOURCE);\n",
    ),
    array(
        'id' => 'B21-reader-error-grants-entitlement',
        'file' => 'includes/class-evc-pmpro-membership-adapter.php',
        'search' => "            throw new EVC_Membership_Source_Exception('Membership source unavailable.');\n",
        'replace' => "            return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, \$at_utc->modify('+1 day'), null, 'pmpro');\n",
    ),
    array(
        'id' => 'B22-sandbox-payment-accepted',
        'file' => $mapper,
        'search' => 'if ($p->environment !== EVC_Pmpro_Payment_Fact::ENV_LIVE) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B23-undeduplicable-payment-accepted',
        'file' => $mapper,
        'search' => 'if ($p->dedupe_key === null || !preg_match(self::REF_PATTERN, $p->dedupe_key)) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B24-future-dated-payment-accepted',
        'file' => $mapper,
        'search' => 'if (EVC_Clock::to_utc($p->confirmed_at_utc) > $at) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B25-multiple-active-levels-accepted',
        'file' => $mapper,
        'search' => 'if (count($active) > 1) {',
        'replace' => 'if (count($active) > 2) {',
    ),
    array(
        'id' => 'B26-cancellation-pending-ignored',
        'file' => $mapper,
        'search' => 'if ($row->cancellation_pending) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B27-dst-repeat-uses-later-occurrence',
        'file' => $calendar,
        'search' => "'instant' => self::instant(min(\$valid), \$microsecond)",
        'replace' => "'instant' => self::instant(max(\$valid), \$microsecond)",
    ),
    array(
        'id' => 'B28-dst-gap-php-normalisation',
        'file' => $calendar,
        'search' => "return array('instant' => self::instant((int) \$t['ts'], 0), 'resolution' => self::RESOLVED_SKIPPED_TRANSITION);",
        'replace' => "return array('instant' => self::instant(\$to, \$microsecond), 'resolution' => self::RESOLVED_SKIPPED_TRANSITION);",
    ),
    array(
        'id' => 'B29-magic-end-date-accepted',
        'file' => $mapper,
        'search' => 'if ((int) $m[1] < self::MIN_REAL_YEAR) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B30-evidence-without-verified-payment',
        'file' => 'includes/class-evc-entitlement.php',
        'search' => 'if ($payment_evidence !== null && !$payment_verified) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'B31-raw-identifier-as-evidence-reference',
        'file' => 'includes/class-evc-payment-evidence.php',
        'search' => "const REFERENCE_PATTERN = '/^[0-9a-f]{64}\$/D';",
        'replace' => "const REFERENCE_PATTERN = '/^.+\$/D';",
    ),
    array(
        'id' => 'B32-indeterminate-counts-as-eligible',
        'file' => 'includes/class-evc-entitlement.php',
        'search' => "        if (\$this->status !== self::STATUS_ACTIVE) {\n            return \$this->status;\n        }",
        'replace' => "        if (\$this->status !== self::STATUS_ACTIVE && \$this->status !== self::STATUS_INDETERMINATE) {\n            return \$this->status;\n        }",
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

echo "Baseline (unmutated) unit run...\n";
$baseline = evc_run_suite($phpunit);
if ($baseline !== 0) {
    fwrite(STDERR, "ABORT: baseline unit suite is not green (exit $baseline); mutation results would be meaningless.\n");
    exit(1);
}
echo "Baseline: PASS\n";

$survivors = array();
$killed = 0;
foreach ($mutants as $m) {
    $path = $root . '/' . $m['file'];
    $original = file_get_contents($path);
    // Working copies may use CRLF (Windows); patterns are written with LF.
    $normalized = str_replace("\r\n", "\n", $original);
    $occurrences = substr_count($normalized, $m['search']);
    if ($occurrences !== 1) {
        fwrite(STDERR, sprintf("ABORT: %s pattern found %d times in %s (expected exactly 1).\n", $m['id'], $occurrences, $m['file']));
        exit(1);
    }
    try {
        file_put_contents($path, str_replace($m['search'], $m['replace'], $normalized));
        $exit = evc_run_suite($phpunit);
    } finally {
        file_put_contents($path, $original);
    }
    if ($exit !== 0) {
        $killed++;
    } else {
        $survivors[] = $m['id'];
    }
    printf("%-46s %s (phpunit exit %d)\n", $m['id'], $exit !== 0 ? 'KILLED' : 'SURVIVED', $exit);
}

$restored = true;
foreach ($touched as $path => $hash) {
    if (hash_file('sha256', $path) !== $hash) {
        $restored = false;
        fwrite(STDERR, 'NOT RESTORED: ' . $path . "\n");
    }
}

printf("[evidence] membership-mutation: total=%d killed=%d survived=%d sources_restored=%s\n", count($mutants), $killed, count($survivors), $restored ? 'yes' : 'NO');
if ($survivors || !$restored) {
    if ($survivors) {
        fwrite(STDERR, 'Surviving mutants: ' . implode(', ', $survivors) . "\n");
    }
    exit(1);
}
exit(0);
