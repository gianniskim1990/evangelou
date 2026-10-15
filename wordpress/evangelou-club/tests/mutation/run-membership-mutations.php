<?php
/**
 * Targeted mutation tests for the Task 1D-B membership entitlement rules
 * (calendar/DST, renewal, payment-to-period binding, fail-closed mapper) and
 * the Task 1D-B.R1 hardening (R1-R9: membership start, payment identity,
 * payment-to-row level binding) and Task 1D-E (P1-P22: provenance recorder,
 * incl. the in-memory store's atomicity / compare-and-set contract) and
 * Task 1D-E.R1 (P23-P29: commit-time correction guards, refund-first safety)
 * and Task 1D-I (I1-I17: canonical payment identity, key ring, trust gate)
 * and Task 1D-I.R1 (J1-J7: gate approval provenance, cross-version refund-first,
 * pinned-fingerprint minting, row-ref stability) and Task 1D-I.R2 (K1-K4:
 * historical key integrity before minting) and UI-03C (M1-M14: preparation
 * contract core) and UI-03D.A (N1-N28: atomic redemption + preparation
 * transaction model, incl. the in-memory store's atomicity) and UI-03D.C
 * (H1-H27: safe fulfillment contract — replay instructions, handoff
 * idempotency/uniqueness/atomicity, late policy, authorisation, read model).
 * And UI-03E (Q1-Q22: usual-coffee recommendation — authorisation, history
 * filtering, ranking, thresholds, catalogue compatibility, privacy).
 * And UI-04B (T1-T11: strict canonical UTC timestamps, chronology, immutable
 * usual-coffee policy) and UI-04C (L1-L26: QR tokens, member lookup, rate
 * limiting, signed lookup-context proof).
 * And UI-04F (S1-S20: member identity, trusted synchronisation, account
 * status administration, QR coordination).
 * And UI-04G (C1-C26: pure REST response contracts and HTTP status map).
 * And UI-04H (V2-1..V2-15: route-aware v2 envelope policy).
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
        'search' => "                || !isset(\$club_rows_by_ref[\$p->membership_row_ref])) {",
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
    // ---- Task 1D-B.R1 -------------------------------------------------------
    array(
        'id' => 'R1-future-membership-start-accepted',
        'file' => $mapper,
        'search' => 'if ($at < $row_start) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'R2-start-before-paid-run-accepted',
        'file' => $mapper,
        'search' => 'if ($row_start < $run_start) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'R3-start-after-end-accepted',
        'file' => $mapper,
        'search' => 'if ($row_start >= $row_end) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'R4-start-resolved-to-earliest-dst-reading',
        'file' => $mapper,
        'search' => "\$this->local_instant(\$row->startdate_local, 'start_missing', EVC_Membership_Calendar::LATEST);",
        'replace' => "\$this->local_instant(\$row->startdate_local, 'start_missing', EVC_Membership_Calendar::EARLIEST);",
    ),
    array(
        'id' => 'R5-missing-start-tolerated',
        'file' => $mapper,
        'search' => "        if (is_string(\$row_start)) {\n            return \$this->indeterminate(\$row_start);\n        }",
        'replace' => "        if (is_string(\$row_start)) {\n            \$row_start = \$row_end->modify('-1 day');\n        }",
    ),
    array(
        'id' => 'R6-payment-reference-reuse-accepted',
        'file' => $mapper,
        'search' => 'if ($first->dedupe_key !== $p->dedupe_key || !$this->same_payment($first, $p)) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'R7-payment-level-not-bound-to-row',
        'file' => $mapper,
        'search' => 'if ($club_rows_by_ref[$p->membership_row_ref]->level_id !== $p->level_id) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'R8-paid-run-may-mix-levels',
        'file' => $mapper,
        'search' => "if (\$period['payment']->level_id !== \$row->level_id) {",
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'R9-duplicate-row-identity-accepted',
        'file' => $mapper,
        'search' => 'if (isset($rows_by_ref[$row->row_ref])) {',
        'replace' => 'if (false) {',
    ),
    // ---- Task 1D-E: payment-provenance recorder core ----------------------
    array(
        'id' => 'P1-duplicate-movement-creates-second-period',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (\$existing !== null) {\n            return \$this->repeat_of(\$existing, \$m);\n        }\n",
        'replace' => "",
    ),
    array(
        'id' => 'P2-cross-source-alias-credits-twice',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "            if (\$this->store->movement_for_alias(\$alias->key()) !== null) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P3-conflicting-alias-on-repeat-accepted',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "            if (\$owner !== null && \$owner !== \$m->movement_id()) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P4-pending-payment-grants-period',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (\$m->state() !== EVC_Verified_Movement::STATE_CONFIRMED) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P5-member-mismatch-ignored',
        'file' => 'includes/class-evc-verified-movement.php',
        'search' => "            && \$this->wp_user_id === \$other->wp_user_id\n",
        'replace' => "",
    ),
    array(
        'id' => 'P6-unapproved-level-accepted',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (!\$this->levels->is_club_level(\$m->level_id())) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P7-early-renewal-loses-remaining-time',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "EVC_Membership_Calendar::next_period(\$latest === null ? null : \$latest->end_utc(), \$m->verified_at_utc());",
        'replace' => "EVC_Membership_Calendar::next_period(null, \$m->verified_at_utc());",
    ),
    array(
        'id' => 'P8-late-renewal-stacks-on-old-end',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "EVC_Membership_Calendar::next_period(\$latest === null ? null : \$latest->end_utc(), \$m->verified_at_utc());",
        'replace' => "EVC_Membership_Calendar::next_period(\$latest === null ? null : \$latest->end_utc(), \$latest === null ? \$m->verified_at_utc() : \$latest->end_utc()->modify('-1 second'));",
    ),
    array(
        'id' => 'P9-refund-ignored',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        \$this->store->commit(EVC_Provenance_Write::correction(\$c, \$prior === null ? null : \$prior->correction_id(), \$recorded));\n",
        'replace' => "",
    ),
    array(
        'id' => 'P10-failed-write-leaves-partial-period',
        'file' => 'tests/support/class-evc-in-memory-provenance-store.php',
        'search' => "        if (\$this->fail_next_commit_after_apply) {",
        'replace' => "        \$this->state = \$next;\n        if (\$this->fail_next_commit_after_apply) {",
    ),
    array(
        'id' => 'P11-error-reported-as-success',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "            return \$this->record_or_throw(\$movement);\n        } catch (EVC_Provenance_Conflict_Exception \$e) {\n            return EVC_Record_Outcome::conflict('concurrent_update');\n        } catch (Throwable \$e) {\n            return EVC_Record_Outcome::unavailable(\$e instanceof EVC_Provenance_Commit_Unknown_Exception ? 'commit_outcome_unknown' : 'store_unavailable');",
        'replace' => "            return \$this->record_or_throw(\$movement);\n        } catch (EVC_Provenance_Conflict_Exception \$e) {\n            return EVC_Record_Outcome::conflict('concurrent_update');\n        } catch (Throwable \$e) {\n            return EVC_Record_Outcome::recorded(null);",
    ),
    array(
        'id' => 'P12-payment-timestamp-silently-changed',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "            \$next['start'],\n",
        'replace' => "            \$next['start']->modify('-1 hour'),\n",
    ),
    array(
        'id' => 'P13-fractional-instant-silently-accepted',
        'file' => 'includes/class-evc-verified-movement.php',
        'search' => "        if (\$verified_at_utc->format('u') !== '000000') {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P14-same-instant-payments-silently-ordered',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "            if (\$other->verified_at_utc() == \$m->verified_at_utc()) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P15-out-of-order-payment-appended',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "            if (\$other->verified_at_utc() > \$m->verified_at_utc()) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P16-unanchored-gateway-signal-credited',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (count(\$anchors) === 0) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P17-wrong-amount-funds-a-month',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (\$m->amount_minor() !== \$this->amount_minor || \$m->currency() !== \$this->currency) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P18-sandbox-environment-accepted',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (\$m->environment() !== \$this->environment) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P19-correction-state-regression-accepted',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (!EVC_Movement_Correction::allowed_after(\$prior === null ? null : \$prior->kind(), \$c->kind())) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P20-repeat-with-other-order-anchor-accepted',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (\$m->order_anchors()[0]->key() !== \$known_anchor->key()) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P21-store-compare-and-set-removed',
        'file' => 'tests/support/class-evc-in-memory-provenance-store.php',
        'search' => "            if (\$current_latest !== \$w->expected_predecessor || \$p->predecessor_movement_id() !== \$current_latest) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P22-changed-facts-on-repeat-accepted',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (!\$existing->same_facts_as(\$m)) {",
        'replace' => "        if (false) {",
    ),
    // ---- Task 1D-E.R1: correction atomicity and refund-first safety --------
    array(
        'id' => 'P23-store-correction-compare-and-set-removed',
        'file' => 'tests/support/class-evc-in-memory-provenance-store.php',
        'search' => "            if (\$prior !== \$w->expected_prior_correction) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P24-store-commit-time-transition-guard-removed',
        'file' => 'tests/support/class-evc-in-memory-provenance-store.php',
        'search' => "            if (!EVC_Movement_Correction::allowed_after(\$prior === null ? null : \$next['corrections'][\$prior]->kind(), \$c->kind())) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P25-store-refund-first-guard-removed',
        'file' => 'tests/support/class-evc-in-memory-provenance-store.php',
        'search' => "            if ((\$next['history'][\$id] ?? array()) !== \$w->expected_unresolved) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P26-recorder-refund-first-guard-removed',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (\$this->store->corrections_for(\$m->movement_id()) !== array()) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'P27-refund-before-payment-dropped',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        \$recorded = \$this->store->movement(\$c->movement_id()) !== null;\n",
        'replace' => "        \$recorded = \$this->store->movement(\$c->movement_id()) !== null;\n        if (!\$recorded) {\n            return EVC_Record_Outcome::indeterminate('unknown_movement');\n        }\n",
    ),
    array(
        'id' => 'P28-store-recorded-state-check-removed',
        'file' => 'tests/support/class-evc-in-memory-provenance-store.php',
        'search' => "            if (isset(\$next['movements'][\$c->movement_id()]) !== \$w->expected_movement_recorded) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'P29-reconciliation-acknowledgement-unchecked',
        'file' => 'includes/class-evc-provenance-recorder.php',
        'search' => "        if (\$held !== \$acknowledged) {",
        'replace' => "        if (false) {",
    ),
    // ---- Task 1D-I: canonical payment identity core --------------------------
    array(
        'id' => 'I1-merchant-scope-removed-from-identity',
        'file' => 'includes/class-evc-payment-identity.php',
        'search' => "            \$settlement->merchant_scope(),\n",
        'replace' => '',
    ),
    array(
        'id' => 'I2-environment-removed-from-identity',
        'file' => 'includes/class-evc-payment-identity.php',
        'search' => "            \$settlement->environment(),\n",
        'replace' => '',
    ),
    array(
        'id' => 'I3-environment-removed-from-hkdf-purpose',
        'file' => 'includes/class-evc-payment-key-ring.php',
        'search' => "'evc/' . \$purpose . '/v' . \$version . '/' . \$this->environment",
        'replace' => "'evc/' . \$purpose . '/v' . \$version",
    ),
    array(
        'id' => 'I4-hmac-domain-separation-removed',
        'file' => 'includes/class-evc-payment-identity.php',
        'search' => "EVC_Payment_Key_Ring::PURPOSE_ALIAS, \$version, self::encode(array(\n            self::TAG_ALIAS,",
        'replace' => "EVC_Payment_Key_Ring::PURPOSE_MOVEMENT, \$version, self::encode(array(\n            self::TAG_MOVEMENT,",
    ),
    array(
        'id' => 'I5-only-current-alias-version-looked-up',
        'file' => 'includes/class-evc-payment-identity-resolver.php',
        'search' => "        foreach (\$this->identity->ring()->retained_versions() as \$v) {",
        'replace' => "        foreach (array(\$this->identity->ring()->alias_version()) as \$v) {",
    ),
    array(
        'id' => 'I6-only-pinned-identity-version-checked',
        'file' => 'includes/class-evc-payment-identity.php',
        'search' => "        foreach (\$this->ring->available_identity_versions() as \$v) {\n            \$out[\$v] = \$this->movement_id(\$settlement, \$v);",
        'replace' => "        foreach (array(\$this->ring->identity_version()) as \$v) {\n            \$out[\$v] = \$this->movement_id(\$settlement, \$v);",
    ),
    array(
        'id' => 'I7-second-id-minted-with-incomplete-history',
        'file' => 'includes/class-evc-payment-key-ring.php',
        'search' => "        foreach (\$this->issued_identity_versions as \$v) {\n            if (\$this->key_status(\$v) === self::KEY_MISSING) {\n                return 'identity_history_incomplete';\n            }\n        }\n",
        'replace' => '',
    ),
    array(
        'id' => 'I8-authenticated-notification-funds-membership',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "        return \$level === self::L3_CAPTURED;",
        'replace' => "        return \$level !== self::Q_UNRESOLVED;",
    ),
    array(
        'id' => 'I9-record-from-any-verifier-trusted',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "\$record->fetched_by() !== \$this->verifier || ",
        'replace' => '',
    ),
    array(
        'id' => 'I10-order-only-evidence-treated-as-payment',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "        if (!\$settlement->is_settlement_anchor()) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'I11-refund-parent-not-verified',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "        \$parent = \$this->fetch(\$parent_ref);\n",
        'replace' => "        \$parent = new EVC_Provider_Record(\$this->verifier, \$parent_ref, EVC_Provider_Record::STATE_CAPTURED, null, 1, 'EUR', array(), array());\n",
    ),
    array(
        'id' => 'I12-duplicate-refund-gets-new-correction',
        'file' => 'includes/class-evc-payment-identity-resolver.php',
        'search' => "            if (\$found !== array()) {",
        'replace' => "            if (false) {",
    ),
    array(
        'id' => 'I13-unknown-refund-parent-accepted',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "        if (\$parents === array()) {\n            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::UNRESOLVED, 'parent_unknown');\n        }\n",
        'replace' => "        if (\$parents === array()) {\n            \$parents = array(\$refund);\n        }\n",
    ),
    array(
        'id' => 'I14-alias-ownership-conflict-ignored',
        'file' => 'includes/class-evc-payment-identity-resolver.php',
        'search' => "                if (\$owner !== \$known) {",
        'replace' => "                if (false) {",
    ),
    array(
        'id' => 'I15-merchant-scope-not-enforced-by-gate',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "        if (!in_array(\$ref->merchant_scope(), \$this->scopes[\$ref->provider()] ?? array(), true)) {",
        'replace' => "        if (false) {",
    ),
    array(
        'id' => 'I16-changed-refund-facts-accepted',
        'file' => 'includes/class-evc-payment-identity-resolver.php',
        'search' => "                if (\$existing->movement_id() !== \$movement->movement_id() || \$existing->kind() !== \$refund->kind()) {",
        'replace' => "                if (false) {",
    ),
    array(
        'id' => 'I17-authorization-treated-as-capture',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "            case EVC_Provider_Record::STATE_CAPTURED:\n                break;\n            case EVC_Provider_Record::STATE_AUTHORIZED:\n                return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'authorization_not_capture');",
        'replace' => "            case EVC_Provider_Record::STATE_CAPTURED:\n            case EVC_Provider_Record::STATE_AUTHORIZED:\n                break;",
    ),
    // ---- Task 1D-I.R1 ----------------------------------------------------------
    array(
        'id' => 'J1-approval-not-required-same-verifier-forgery',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "        if (!\$this->approvals->contains(\$record) || \$this->approvals[\$record] !== \$purpose) {\n            return false;\n        }\n",
        'replace' => '',
    ),
    array(
        'id' => 'J2-approval-not-consumed-replay',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "        \$this->approvals->detach(\$record);\n",
        'replace' => '',
    ),
    array(
        'id' => 'J3-approval-granted-on-every-fetch',
        'file' => 'includes/class-evc-payment-evidence-gate.php',
        'search' => "            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'record_identity_mismatch');\n        }\n        return \$record;",
        'replace' => "            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'record_identity_mismatch');\n        }\n        \$this->approve(\$record, self::APPROVED_PAYMENT);\n        return \$record;",
    ),
    array(
        'id' => 'J4-quarantined-candidate-ignored-cross-version',
        'file' => 'includes/class-evc-payment-identity-resolver.php',
        'search' => "            if (\$has_movement || \$this->store->corrections_for(\$id) !== array()) {",
        'replace' => "            if (\$has_movement) {",
    ),
    array(
        'id' => 'J5-competing-candidates-not-a-conflict',
        'file' => 'includes/class-evc-payment-identity-resolver.php',
        'search' => "        if (count(\$occupied) > 1) {",
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'J6-minting-without-pinned-fingerprint',
        'file' => 'includes/class-evc-payment-key-ring.php',
        'search' => "        if (\$this->key_status(\$this->identity_version) !== self::KEY_VERIFIED) {\n            return 'identity_key_unpinned';",
        'replace' => "        if (false) {\n            return 'identity_key_unpinned';",
    ),
    array(
        'id' => 'J7-row-ref-follows-alias-version',
        'file' => 'includes/class-evc-payment-identity.php',
        'search' => "        return \$this->row_ref_for_version(\$site_scope, \$raw_row_id, \$this->ring->identity_version());",
        'replace' => "        return \$this->row_ref_for_version(\$site_scope, \$raw_row_id, \$this->ring->alias_version());",
    ),
    // ---- Task 1D-I.R2: historical key integrity --------------------------------
    array(
        'id' => 'K1-only-current-identity-fingerprint-checked',
        'file' => 'includes/class-evc-payment-key-ring.php',
        'search' => "        foreach (array_unique(array_merge(\$this->retained_versions(), \$this->issued_identity_versions)) as \$v) {",
        'replace' => "        foreach (array(\$this->identity_version) as \$v) {",
    ),
    array(
        'id' => 'K2-historical-fingerprint-absence-ignored',
        'file' => 'includes/class-evc-payment-key-ring.php',
        'search' => "            if (\$this->key_status(\$v) !== self::KEY_VERIFIED) {\n                return 'historical_key_unverified';",
        'replace' => "            if (\$this->key_status(\$v) === self::KEY_MISSING) {\n                return 'historical_key_unverified';",
    ),
    array(
        'id' => 'K3-historical-alias-integrity-bypassed',
        'file' => 'includes/class-evc-payment-key-ring.php',
        'search' => "        foreach (array_unique(array_merge(\$this->retained_versions(), \$this->issued_identity_versions)) as \$v) {",
        'replace' => "        foreach (\$this->issued_identity_versions as \$v) {",
    ),
    array(
        'id' => 'K4-key-presence-treated-as-verified',
        'file' => 'includes/class-evc-payment-key-ring.php',
        'search' => "        return isset(\$this->pinned[\$version]) ? self::KEY_VERIFIED : self::KEY_UNVERIFIED;",
        'replace' => "        return self::KEY_VERIFIED;",
    ),
    // UI-03C preparation contract core (M1-M14).
    array(
        'id' => 'M1-free-text-note-accepted',
        'file' => 'includes/class-evc-prep-contract.php',
        'search' => "if (array_key_exists('note', \$preparation) && \$preparation['note'] !== null) {",
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'M2-modifiers-not-sorted',
        'file' => 'includes/class-evc-prep-contract.php',
        'search' => "        ksort(\$modifiers, SORT_STRING);\n",
        'replace' => '',
    ),
    array(
        'id' => 'M3-catalogue-checked-before-replay',
        'file' => 'includes/class-evc-prep-redeem-precheck.php',
        'search' => "\$stored = \$this->lookup->fingerprint_for_request(\$body['request_id']);",
        'replace' => "\$stored = \$body['catalogue_version'] !== \$this->catalogue->version() ? null : \$this->lookup->fingerprint_for_request(\$body['request_id']);",
    ),
    array(
        'id' => 'M4-different-preparation-replays',
        'file' => 'includes/class-evc-prep-redeem-precheck.php',
        'search' => 'return hash_equals($stored, $fingerprint) ? EVC_Prep_Decision::replay($fingerprint) : EVC_Prep_Decision::conflict();',
        'replace' => 'return EVC_Prep_Decision::replay($fingerprint);',
    ),
    array(
        'id' => 'M5-fingerprint-ignores-preparation',
        'file' => 'includes/class-evc-prep-contract.php',
        'search' => "            \$prep_hash,\n",
        'replace' => "            '',\n",
    ),
    array(
        'id' => 'M6-fingerprint-v2-domain-tag-reused',
        'file' => 'includes/class-evc-prep-contract.php',
        'search' => "const FINGERPRINT_VERSION = 'evc-redeem-v2';",
        'replace' => "const FINGERPRINT_VERSION = 'evc-redeem-v1';",
    ),
    array(
        'id' => 'M7-hidden-group-accepted',
        'file' => 'includes/class-evc-prep-contract.php',
        'search' => "                throw EVC_Prep_Rejection::preparation('hidden_group_present');\n",
        'replace' => '',
    ),
    array(
        'id' => 'M8-temperature-notes-not-exclusive',
        'file' => 'includes/class-evc-prep-contract.php',
        'search' => "                    throw EVC_Prep_Rejection::preparation('exclusive_conflict');\n",
        'replace' => '',
    ),
    array(
        'id' => 'M9-illustrative-catalogue-activatable',
        'file' => 'includes/class-evc-prep-redeem-precheck.php',
        'search' => 'if (!$active_catalogue->is_approved() && $illustrative_opt_in !== self::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'M10-catalogue-accepts-pricing',
        'file' => 'includes/class-evc-prep-catalogue.php',
        'search' => "if (\$d['pricing_policy'] !== self::PRICING_UNDECIDED) {",
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'M11-catalogue-allows-free-text',
        'file' => 'includes/class-evc-prep-catalogue.php',
        'search' => "if (\$d['free_text_note'] !== false) {",
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'M12-label-length-in-bytes',
        'file' => 'includes/class-evc-prep-catalogue.php',
        'search' => "return preg_match_all('/./su', \$v) <= self::MAX_LABEL_CODE_POINTS;",
        'replace' => 'return strlen($v) <= self::MAX_LABEL_CODE_POINTS;',
    ),
    array(
        'id' => 'M13-hot-note-allowed-on-cold-coffee',
        'file' => 'includes/class-evc-prep-catalogue.php',
        'search' => "|| (\$applies === self::APPLIES_HOT && \$c['temperature'] === self::TEMPERATURE_HOT)",
        'replace' => "|| (\$applies === self::APPLIES_HOT)",
    ),
    array(
        'id' => 'M14-required-group-not-enforced',
        'file' => 'includes/class-evc-prep-contract.php',
        'search' => "if (\$groups[\$group]['required'] && !isset(\$modifiers[\$group])) {",
        'replace' => 'if (false) {',
    ),
    // UI-03D.A atomic redemption + preparation transaction model (N1-N28).
    array(
        'id' => 'N1-replay-lookup-skipped',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '        if ($stored !== null) {' . "\n" . '            return $this->replay_or_reject($stored, $fingerprint, $canonical, null);' . "\n" . '        }' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'N2-different-payload-replays',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'if (!is_string($ledger[\'request_fingerprint\']) || !hash_equals($ledger[\'request_fingerprint\'], $fingerprint)) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'N3-missing-snapshot-tolerated',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            return \'snapshot_missing\';' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'N4-extra-snapshots-tolerated',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'if (count($snapshots) !== 1) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'N5-stored-snapshot-not-verified',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '$stored_canonical = EVC_Prep_Snapshot::verify($snapshot);',
        'replace' => '$stored_canonical = $canonical;',
    ),
    array(
        'id' => 'N6-ledger-snapshot-hash-not-compared',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            || !is_string($ledger[\'snapshot_hash\']) || !hash_equals($ledger[\'snapshot_hash\'], $snapshot[\'snapshot_hash\'])' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'N7-fingerprint-version-not-checked',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'if ($ledger[\'fingerprint_version\'] !== EVC_Prep_Canonical::FINGERPRINT_VERSION) {' . "\n" . '            return',
        'replace' => 'if (false) {' . "\n" . '            return',
    ),
    array(
        'id' => 'N8-commit-unknown-reported-as-redeemed',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'return EVC_Prepared_Redemption_Outcome::unresolved(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, $request_id);',
        'replace' => 'return EVC_Prepared_Redemption_Outcome::redeemed(self::view(0, $member_public_id, $ledger, $snapshot));',
    ),
    array(
        'id' => 'N9-commit-unknown-retried-blindly',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '                $this->report(\'commit_unknown\', $e);' . "\n" . '                return',
        'replace' => '                $this->report(\'commit_unknown\', $e);' . "\n" . '                continue;' . "\n" . '                return',
    ),
    array(
        'id' => 'N10-unknown-outcome-without-original-request-id',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'unresolved(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, $request_id)',
        'replace' => 'unresolved(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, null)',
    ),
    array(
        'id' => 'N11-unresolved-outcome-instructs-prepare',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '        return new self($kind, null, $details, $request_id, null, self::DO_NOT_PREPARE, null);',
        'replace' => '        return new self($kind, null, $details, $request_id, null, self::PREPARE, null);',
    ),
    array(
        'id' => 'N12-retryable-failure-not-retried',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'if ($attempt >= self::MAX_TRANSACTION_ATTEMPTS) {',
        'replace' => 'if ($attempt >= 1) {',
    ),
    array(
        'id' => 'N13-member-day-duplicate-served',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, array(',
        'replace' => 'return EVC_Prepared_Redemption_Outcome::redeemed(array(',
    ),
    array(
        'id' => 'N14-body-may-carry-extra-keys',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '|| !EVC_Prep_Catalogue::is_object_with($body, self::BODY_KEYS)',
        'replace' => '|| !is_array($body) || !isset($body[\'request_id\'], $body[\'catalogue_version\'], $body[\'coffee_code\'], $body[\'preparation\'])',
    ),
    array(
        'id' => 'N15-clock-read-twice',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '$business_date = EVC_Clock::business_date($this->now);',
        'replace' => '$business_date = EVC_Clock::business_date($this->clock->now());',
    ),
    array(
        'id' => 'N16-catalogue-status-trusted-without-approval',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'if ($approved === null || !hash_equals($approved, EVC_Prep_Catalogue_Digest::of($data))) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'N17-approval-not-bound-to-document',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => ' || !hash_equals($approved, EVC_Prep_Catalogue_Digest::of($data))',
        'replace' => '',
    ),
    array(
        'id' => 'N18-illustrative-catalogue-without-opt-in',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'return $this->illustrative_opt_in === self::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS ? $catalogue : null;',
        'replace' => 'return $catalogue;',
    ),
    array(
        'id' => 'N19-stale-catalogue-version-accepted',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'if ($body[\'catalogue_version\'] !== $catalogue->version()) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'N20-write-set-prep-hash-unchecked',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            || $ledger[\'prep_hash\'] !== $snapshot[\'prep_hash\']' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'N21-write-set-snapshot-hash-unchecked',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            || $ledger[\'snapshot_hash\'] !== $snapshot[\'snapshot_hash\']' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'N22-write-set-audit-request-unchecked',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            || $audit[\'request_id\'] !== $ledger[\'request_id\']' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'N23-disabled-member-served',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'if ($member === null || $member[\'status\'] !== \'enabled\') {',
        'replace' => 'if ($member === null) {',
    ),
    array(
        'id' => 'N24-eligibility-ignored',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => 'if ($denial !== null) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'N25-snapshot-labels-unprotected',
        'file' => 'includes/class-evc-prep-snapshot.php',
        'search' => 'if (!hash_equals($raw[\'snapshot_hash\'], self::snapshot_hash($raw))) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'N26-snapshot-codes-unprotected',
        'file' => 'includes/class-evc-prep-snapshot.php',
        'search' => 'if (!hash_equals($raw[\'prep_hash\'], EVC_Prep_Canonical::prep_hash($canonical))) {',
        'replace' => 'if (false) {',
    ),
    array(
        'id' => 'N27-snapshot-accepts-a-note',
        'file' => 'includes/class-evc-prep-snapshot.php',
        'search' => '\'prep_hash\', \'snapshot_hash\'))) {',
        'replace' => '\'prep_hash\', \'snapshot_hash\'), array(\'note\'))) {',
    ),
    array(
        'id' => 'N28-fake-store-not-atomic',
        'file' => 'tests/support/class-evc-in-memory-prepared-redemption-store.php',
        'search' => '        $staged = $this->state;' . "\n" . '' . "\n" . '        $this->reach(\'before_ledger\');',
        'replace' => '        $staged = &$this->state;' . "\n" . '' . "\n" . '        $this->reach(\'before_ledger\');',
    ),
    // UI-03D.C safe fulfillment contract (H1-H27).
    array(
        'id' => 'H1-replay-instructs-prepare',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            $instruction = self::VERIFY_HANDOFF;',
        'replace' => '            $instruction = self::PREPARE;',
    ),
    array(
        'id' => 'H2-earlier-day-replay-treated-as-today',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '        if (!$same_business_date) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'H3-recorded-handoff-ignored-on-replay',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '        } elseif ($handoff !== null) {',
        'replace' => '        } elseif (false) {',
    ),
    array(
        'id' => 'H4-replay-business-date-not-compared',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            (string) $ledger[\'business_date\'] === EVC_Clock::business_date($this->now),',
        'replace' => '            true,',
    ),
    array(
        'id' => 'H5-replay-ignores-stored-handoff',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            count($stored[\'handoffs\']) === 1 ? EVC_Handoff_Record::view($stored[\'handoffs\'][0]) : null',
        'replace' => '            null',
    ),
    array(
        'id' => 'H6-handoff-integrity-not-checked-on-replay',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            $problem = EVC_Prepared_Redemption_Record::handoff_problem($ledger, $stored[\'handoffs\']);' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'H7-unknown-claims-entitlement-state',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '        if ($this->kind === self::OUTCOME_UNKNOWN || $this->kind === self::SERVER_ERROR || $this->kind === self::RECORD_INTEGRITY_FAILURE) {' . "\n" . '            return null;',
        'replace' => '        if ($this->kind === self::OUTCOME_UNKNOWN || $this->kind === self::SERVER_ERROR || $this->kind === self::RECORD_INTEGRITY_FAILURE) {' . "\n" . '            return true;',
    ),
    array(
        'id' => 'H8-refusal-instructs-prepare',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '        return new self($kind, null, $details, null, null, self::DO_NOT_PREPARE, null);',
        'replace' => '        return new self($kind, null, $details, null, null, self::PREPARE, null);',
    ),
    array(
        'id' => 'H9-unknown-handoff-claimed-confirmed',
        'file' => 'includes/class-evc-handoff.php',
        'search' => 'return EVC_Handoff_Outcome::unresolved(EVC_Handoff_Outcome::OUTCOME_UNKNOWN, $handoff_request_id);',
        'replace' => 'return EVC_Handoff_Outcome::recorded(EVC_Handoff_Outcome::CONFIRMED, EVC_Handoff_Record::view($record));',
    ),
    array(
        'id' => 'H10-unknown-handoff-retried-blindly',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '                $this->report(\'commit_unknown\', $e);' . "\n" . '                return',
        'replace' => '                $this->report(\'commit_unknown\', $e);' . "\n" . '                continue;' . "\n" . '                return',
    ),
    array(
        'id' => 'H11-store-allows-two-handoffs-per-redemption',
        'file' => 'tests/support/class-evc-in-memory-prepared-redemption-store.php',
        'search' => '        if (isset($staged[\'handoff_by_redemption\'][$record[\'redemption_id\']])) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'H12-same-id-race-reported-as-another-handoff',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '        if (count($stored[\'handoffs\']) === 1 && $stored[\'handoffs\'][0][\'handoff_request_id\'] === $handoff_request_id) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'H13-changed-handoff-payload-replays',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '        if (!is_string($existing[\'handoff_fingerprint\']) || !hash_equals($existing[\'handoff_fingerprint\'], $fingerprint)) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'H14-other-members-redemption-confirmable',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '        if ($stored === null || $stored[\'ledger\'][\'member_public_id\'] !== $member_public_id) {' . "\n" . '            $this->audit_rejection(',
        'replace' => '        if ($stored === null) {' . "\n" . '            $this->audit_rejection(',
    ),
    array(
        'id' => 'H15-confirmation-without-permission',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '        if (!$this->authorizer->allows($actor, EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF)) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'H16-late-confirmation-without-policy',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            && !(strcmp($business_date, $today) < 0 && $this->late_policy->allows_late_confirmation($business_date, $this->now))) {',
        'replace' => '            && false) {',
    ),
    array(
        'id' => 'H17-shipped-policy-allows-late-confirmation',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '    public function allows_late_confirmation(string $redemption_business_date, DateTimeImmutable $now_utc): bool {' . "\n" . '        return false;',
        'replace' => '    public function allows_late_confirmation(string $redemption_business_date, DateTimeImmutable $now_utc): bool {' . "\n" . '        return true;',
    ),
    array(
        'id' => 'H18-earlier-claim-invents-a-handoff-time',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '        return $claim === self::CLAIM_HANDED_NOW ? $recorded_at_utc : null;',
        'replace' => '        return $recorded_at_utc;',
    ),
    array(
        'id' => 'H19-stored-handoff-not-verified',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '        return count($handoffs) === 1 ? EVC_Handoff_Record::problem($handoffs[0], $ledger) : null;',
        'replace' => '        return null;',
    ),
    array(
        'id' => 'H20-handoff-fingerprint-binding-unchecked',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            || !hash_equals($h[\'handoff_fingerprint\'], self::fingerprint((string) $ledger[\'member_public_id\'], (string) $ledger[\'request_id\'], $h[\'claim\'], $h[\'staff_wp_user_id\']))) {',
        'replace' => '            || false) {',
    ),
    array(
        'id' => 'H21-handoff-write-audit-unchecked',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            || $audit[\'request_id\'] !== $record[\'handoff_request_id\']' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'H22-status-of-other-members-redemption-disclosed',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            if ($stored === null || $stored[\'ledger\'][\'member_public_id\'] !== $member_public_id) {' . "\n" . '                return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::NOT_FOUND);',
        'replace' => '            if ($stored === null) {' . "\n" . '                return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::NOT_FOUND);',
    ),
    array(
        'id' => 'H23-review-without-permission',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '        if (!$this->authorizer->allows($actor, EVC_Club_Staff_Authorizer::REVIEW_FULFILLMENT)) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'H24-status-integrity-unchecked',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            if (EVC_Handoff_Service::record_problem($stored) !== null) {' . "\n" . '                return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::RECORD_INTEGRITY_FAILURE);',
        'replace' => '            if (false) {' . "\n" . '                return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::RECORD_INTEGRITY_FAILURE);',
    ),
    array(
        'id' => 'H25-confirmation-skips-redemption-integrity',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '        $problem = self::record_problem($stored);' . "\n" . '        if ($problem !== null) {' . "\n" . '            $this->audit_rejection(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, (int)',
        'replace' => '        $problem = null;' . "\n" . '        if ($problem !== null) {' . "\n" . '            $this->audit_rejection(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, (int)',
    ),
    array(
        'id' => 'H26-handoff-body-may-carry-extra-keys',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            || !EVC_Prep_Catalogue::is_object_with($body, self::BODY_KEYS, array(\'device_ref\'))',
        'replace' => '            || !is_array($body) || !isset($body[\'handoff_request_id\'], $body[\'redemption_request_id\'], $body[\'claim\'])',
    ),
    array(
        'id' => 'H27-fake-store-handoff-not-atomic',
        'file' => 'tests/support/class-evc-in-memory-prepared-redemption-store.php',
        'search' => '        $staged = $this->state;' . "\n" . '' . "\n" . '        $this->reach(\'before_handoff\');',
        'replace' => '        $staged = &$this->state;' . "\n" . '' . "\n" . '        $this->reach(\'before_handoff\');',
    ),
    // UI-03E usual-coffee recommendation core (Q1-Q22).
    array(
        'id' => 'Q1-usual-without-capability',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        if (!$this->authorizer->allows($actor, self::CAPABILITY)) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'Q2-usual-without-member-lookup-context',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        if (!$this->context->verify($actor, $member_public_id, $lookup_ref)) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'Q3-disabled-member-gets-suggestion',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        if ($member === null || $member[\'status\'] !== \'enabled\') {',
        'replace' => '        if ($member === null) {',
    ),
    array(
        'id' => 'Q4-other-members-rows-counted',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            if (($ledger[\'member_id\'] ?? null) !== $member[\'id\'] || ($ledger[\'member_public_id\'] ?? null) !== $member[\'member_public_id\']) {',
        'replace' => '            if (false) {',
    ),
    array(
        'id' => 'Q5-damaged-records-counted',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '                if (EVC_Handoff_Service::record_problem($stored) !== null) {',
        'replace' => '                if (false) {',
    ),
    array(
        'id' => 'Q6-lookback-window-ignored',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            if (strcmp($date, $from) < 0 || strcmp($date, $today) > 0) {',
        'replace' => '            if (false) {',
    ),
    array(
        'id' => 'Q7-duplicated-request-ids-counted',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            if ($seen[$request_id] === 1) {',
        'replace' => '            if (true) {',
    ),
    array(
        'id' => 'Q8-recency-window-ignored',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        $considered = array_slice($valid, 0, $this->policy->max_considered());',
        'replace' => '        $considered = $valid;',
    ),
    array(
        'id' => 'Q9-oldest-redemptions-considered-first',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            return strcmp($b[\'sort\'], $a[\'sort\']);',
        'replace' => '            return strcmp($a[\'sort\'], $b[\'sort\']);',
    ),
    array(
        'id' => 'Q10-insufficient-history-suggested',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        if (count($considered) < $this->policy->min_valid()) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'Q11-minimum-share-ignored',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            || $winner[\'count\'] * 100 < $this->policy->min_share_percent() * count($considered)) {',
        'replace' => '            ) {',
    ),
    array(
        'id' => 'Q12-minimum-occurrences-ignored',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        if ($winner[\'count\'] < $this->policy->min_occurrences()' . "\n" . '',
        'replace' => '        if (false' . "\n" . '',
    ),
    array(
        'id' => 'Q13-ties-not-broken-by-recency',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            $recency = strcmp($b[\'latest\'], $a[\'latest\']);',
        'replace' => '            $recency = 0;',
    ),
    array(
        'id' => 'Q14-least-frequent-wins',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '                return $b[\'count\'] - $a[\'count\'];',
        'replace' => '                return $a[\'count\'] - $b[\'count\'];',
    ),
    array(
        'id' => 'Q15-unofferable-usual-silently-substituted',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            return EVC_Usual_Coffee_Result::none(EVC_Usual_Coffee_Result::REASON_NOT_OFFERABLE);',
        'replace' => '            $order = EVC_Prep_Validator::validate($catalogue, \'cappuccino\', array(\'milk\' => \'plant\', \'shots\' => \'single\', \'sweetness\' => \'none\'), array(\'cinnamon\'));',
    ),
    array(
        'id' => 'Q16-unapproved-catalogue-trusted',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        return $approved !== null && hash_equals($approved, EVC_Prep_Catalogue_Digest::of($data)) ? $catalogue : null;',
        'replace' => '        return $catalogue;',
    ),
    array(
        'id' => 'Q17-illustrative-catalogue-without-opt-in',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            return $this->illustrative_opt_in === self::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS ? $catalogue : null;',
        'replace' => '            return $catalogue;',
    ),
    array(
        'id' => 'Q18-habit-split-by-catalogue-version',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '                \'key\' => EVC_Prep_Canonical::canonical(1, $snapshot[\'coffee_code\'], $modifiers, $quick),',
        'replace' => '                \'key\' => EVC_Prep_Canonical::canonical($snapshot[\'catalogue_version\'], $snapshot[\'coffee_code\'], $modifiers, $quick),',
    ),
    array(
        'id' => 'Q19-lookback-window-one-day-too-long',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        $from = self::shift_date($today, -($this->policy->lookback_days() - 1));',
        'replace' => '        $from = self::shift_date($today, -$this->policy->lookback_days());',
    ),
    array(
        'id' => 'Q20-suggestion-claims-to-be-an-order',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '    public function is_confirmed_order(): bool {' . "\n" . '        return false;',
        'replace' => '    public function is_confirmed_order(): bool {' . "\n" . '        return true;',
    ),
    array(
        'id' => 'Q21-failure-report-leaks-details',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            call_user_func($this->error_reporter, array(\'stage\' => \'usual_coffee\', \'exception\' => get_class($e)));',
        'replace' => '            call_user_func($this->error_reporter, array(\'stage\' => \'usual_coffee\', \'exception\' => get_class($e), \'message\' => $e->getMessage()));',
    ),
    array(
        'id' => 'Q22-historical-labels-shown',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '            \'coffee_label\' => $order->coffee_label(),',
        'replace' => '            \'coffee_label\' => $winner[\'coffee_code\'] === \'espresso\' ? \'Espresso\' : $order->coffee_label(),',
    ),
    // UI-04B pure-core hardening: strict UTC timestamps + immutable policy (T1-T11).
    array(
        'id' => 'T1-handoff-time-format-unchecked',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            || !EVC_Utc_Timestamp::is_canonical($h[\'recorded_at_utc\'])' . "\n" . '',
        'replace' => '            || !is_string($h[\'recorded_at_utc\'])' . "\n" . '',
    ),
    array(
        'id' => 'T2-handoff-before-redemption-accepted',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            || EVC_Utc_Timestamp::compare($h[\'recorded_at_utc\'], $ledger[\'redeemed_at_utc\']) < 0) {',
        'replace' => '            || false) {',
    ),
    array(
        'id' => 'T3-ledger-time-off-its-business-date',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            || EVC_Utc_Timestamp::athens_date($ledger[\'redeemed_at_utc\']) !== ($ledger[\'business_date\'] ?? null)) {',
        'replace' => '            ) {',
    ),
    array(
        'id' => 'T4-redemption-write-time-unchecked',
        'file' => 'includes/class-evc-prepared-redemption.php',
        'search' => '            throw new InvalidArgumentException(\'Inconsistent redemption write: time.\');' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'T5-handoff-write-time-unchecked',
        'file' => 'includes/class-evc-handoff.php',
        'search' => '            || !EVC_Utc_Timestamp::is_canonical($record[\'recorded_at_utc\'])' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'T6-timestamp-pattern-allows-trailing-newline',
        'file' => 'includes/class-evc-utc-timestamp.php',
        'search' => '(\\d{6})$/D\';',
        'replace' => '(\\d{6})$/\';',
    ),
    array(
        'id' => 'T7-impossible-dates-accepted',
        'file' => 'includes/class-evc-utc-timestamp.php',
        'search' => '            && checkdate((int) $m[2], (int) $m[3], $year)' . "\n" . '',
        'replace' => '            && true' . "\n" . '',
    ),
    array(
        'id' => 'T8-impossible-times-accepted',
        'file' => 'includes/class-evc-utc-timestamp.php',
        'search' => '            && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 59;',
        'replace' => '            && true;',
    ),
    array(
        'id' => 'T9-chronology-reversed',
        'file' => 'includes/class-evc-utc-timestamp.php',
        'search' => '        return strcmp($a, $b) <=> 0;',
        'replace' => '        return strcmp($b, $a) <=> 0;',
    ),
    array(
        'id' => 'T10-policy-silently-mutable',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '        throw new LogicException(\'EVC_Usual_Coffee_Policy is immutable.\');' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'T11-policy-field-public',
        'file' => 'includes/class-evc-usual-coffee.php',
        'search' => '    private $lookback_days;' . "\n" . '',
        'replace' => '    public $lookback_days;' . "\n" . '',
    ),
    // UI-04C QR tokens, member lookup and lookup-context proof (L1-L26).
    array(
        'id' => 'L1-lookup-without-capability',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '        if (!$this->authorizer->allows($actor, self::CAPABILITY) || $actor->session_ref() === null) {',
        'replace' => '        if ($actor->session_ref() === null) {',
    ),
    array(
        'id' => 'L2-lookup-without-session-binding',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '        if (!$this->authorizer->allows($actor, self::CAPABILITY) || $actor->session_ref() === null) {',
        'replace' => '        if (!$this->authorizer->allows($actor, self::CAPABILITY)) {',
    ),
    array(
        'id' => 'L3-rate-limit-ignored',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            if (!$this->limiter->allow($keys, $action)) {',
        'replace' => '            if (!$this->limiter->allow($keys, $action) && false) {',
    ),
    array(
        'id' => 'L4-unavailable-limiter-fails-open',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            $this->report($e);' . "\n" . '            return EVC_Member_Lookup_Result::of(EVC_Member_Lookup_Result::UNAVAILABLE);' . "\n" . '        }' . "\n" . '        try {' . "\n" . '            return $lookup($keys);',
        'replace' => '            $this->report($e);' . "\n" . '        }' . "\n" . '        try {' . "\n" . '            return $lookup($keys);',
    ),
    array(
        'id' => 'L5-revoked-token-accepted',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            if ($row === null || $row[\'status\'] !== \'active\' || $this->token_policy->is_expired($row[\'issued_at_utc\'], $now)) {',
        'replace' => '            if ($row === null || $this->token_policy->is_expired($row[\'issued_at_utc\'], $now)) {',
    ),
    array(
        'id' => 'L6-token-age-ignored',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            if ($row === null || $row[\'status\'] !== \'active\' || $this->token_policy->is_expired($row[\'issued_at_utc\'], $now)) {',
        'replace' => '            if ($row === null || $row[\'status\'] !== \'active\') {',
    ),
    array(
        'id' => 'L7-disabled-member-found-by-qr',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            if ($member === null || $member[\'status\'] !== \'enabled\') {' . "\n" . '                return $this->failed($keys, self::ACTION_QR);',
        'replace' => '            if ($member === null) {' . "\n" . '                return $this->failed($keys, self::ACTION_QR);',
    ),
    array(
        'id' => 'L8-ambiguous-phone-picks-a-member',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            if (count($members) > 1) {',
        'replace' => '            if (false) {',
    ),
    array(
        'id' => 'L9-phone-not-canonical',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '        return \'+30\' . $digits;',
        'replace' => '        return $digits;',
    ),
    array(
        'id' => 'L10-plaintext-phone-in-limiter-keys',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => 'array(\'phone:\' . EVC_Phone_Canonical::pseudonym($this->keys, $canonical));',
        'replace' => 'array(\'phone:\' . $canonical);',
    ),
    array(
        'id' => 'L11-no-per-phone-limit',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '        $extra = $canonical === null ? array() : array(\'phone:\' . EVC_Phone_Canonical::pseudonym($this->keys, $canonical));',
        'replace' => '        $extra = array();',
    ),
    array(
        'id' => 'L12-failures-not-recorded',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            $this->limiter->record_failure($keys, $action);' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'L13-proof-mac-not-checked',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '        if (!hash_equals($expected, $parts[3])) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'L14-proof-not-bound-to-member',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            || !hash_equals($p[\'member\'], $member_public_id) || $p[\'purpose\'] !== $purpose) {',
        'replace' => '            || $p[\'purpose\'] !== $purpose) {',
    ),
    array(
        'id' => 'L15-proof-not-bound-to-session',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => ' || !hash_equals($p[\'session\'], (string) $actor->session_ref())' . "\n" . '',
        'replace' => '' . "\n" . '',
    ),
    array(
        'id' => 'L16-proof-not-bound-to-purpose',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            || !hash_equals($p[\'member\'], $member_public_id) || $p[\'purpose\'] !== $purpose) {',
        'replace' => '            || !hash_equals($p[\'member\'], $member_public_id)) {',
    ),
    array(
        'id' => 'L17-proof-expiry-ignored',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '        if ($now < $p[\'iat\'] || $now >= $p[\'exp\'] || ',
        'replace' => '        if ($now < $p[\'iat\'] || ',
    ),
    array(
        'id' => 'L18-proof-outlives-current-policy',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '$p[\'exp\'] - $p[\'iat\'] > $policy->ttl_seconds() || ',
        'replace' => '',
    ),
    array(
        'id' => 'L19-proof-usable-before-issue',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '        if ($now < $p[\'iat\'] || $now >= $p[\'exp\']',
        'replace' => '        if ($now >= $p[\'exp\']',
    ),
    array(
        'id' => 'L20-member-may-issue-for-another-record',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            if ($member === null || $member[\'wp_user_id\'] !== $requester->wp_user_id()) {',
        'replace' => '            if ($member === null) {',
    ),
    array(
        'id' => 'L21-administrator-without-capability',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            && !$this->authorizer->allows($requester->actor(), self::CAPABILITY_MANAGE)) {',
        'replace' => '            && false) {',
    ),
    array(
        'id' => 'L22-disabled-member-gets-a-token',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '            if ($member[\'status\'] !== \'enabled\') {' . "\n" . '                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::MEMBER_DISABLED);',
        'replace' => '            if (false) {' . "\n" . '                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::MEMBER_DISABLED);',
    ),
    array(
        'id' => 'L23-uncertain-token-returned',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::OUTCOME_UNKNOWN);' . "\n" . '            }' . "\n" . '            return EVC_Qr_Token_Result::issued($token, $now);',
        'replace' => '                return EVC_Qr_Token_Result::issued($token, $now);' . "\n" . '            }' . "\n" . '            return EVC_Qr_Token_Result::issued($token, $now);',
    ),
    array(
        'id' => 'L24-weak-key-accepted',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '|| strlen($secret) < self::MIN_SECRET_BYTES) {',
        'replace' => '|| strlen($secret) < 1) {',
    ),
    array(
        'id' => 'L25-disabled-member-counts-for-a-phone',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '                if ($member !== null && $member[\'status\'] === \'enabled\') {',
        'replace' => '                if ($member !== null) {',
    ),
    array(
        'id' => 'L26-lookup-claims-entitlement',
        'file' => 'includes/class-evc-member-lookup.php',
        'search' => '    public function grants_entitlement(): bool {' . "\n" . '        return false;',
        'replace' => '    public function grants_entitlement(): bool {' . "\n" . '        return true;',
    ),
    // UI-04F member identity + trusted synchronisation (S1-S20).
    array(
        'id' => 'S1-newsletter-source-accepted',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '    const SOURCES = array(\'pmpro\', \'woocommerce\', \'gateway\', \'scheduled_reconcile\');',
        'replace' => '    const SOURCES = array(\'pmpro\', \'woocommerce\', \'gateway\', \'scheduled_reconcile\', \'fluentcrm\');',
    ),
    array(
        'id' => 'S2-privileged-account-not-refused',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if ($kind === EVC_Wp_Account_Directory::RESTRICTED) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'S3-unknown-wordpress-account-provisioned',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if ($kind !== EVC_Wp_Account_Directory::CUSTOMER) {',
        'replace' => '        if ($kind === \'never\') {',
    ),
    array(
        'id' => 'S4-directory-failure-treated-as-customer',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '            $this->report(\'account\', $e);' . "\n" . '            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::IDENTITY_UNAVAILABLE);',
        'replace' => '            $kind = EVC_Wp_Account_Directory::CUSTOMER;',
    ),
    array(
        'id' => 'S5-identity-without-paid-membership',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if (!$entitlement->is_eligible_at($now) || !$entitlement->payment_verified()) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'S6-membership-outage-misreported',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::MEMBERSHIP_UNAVAILABLE);',
        'replace' => '            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::NOT_ELIGIBLE);',
    ),
    array(
        'id' => 'S7-payment-reenables-disabled-member',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if ($member[\'status\'] !== \'enabled\') {' . "\n" . '            // Manually disabled',
        'replace' => '        if (false) {' . "\n" . '            // Manually disabled',
    ),
    array(
        'id' => 'S8-identity-hint-mismatch-ignored',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '            || ($signal->member_public_id_hint() !== null && $signal->member_public_id_hint() !== $member[\'member_public_id\'])) {',
        'replace' => '            ) {',
    ),
    array(
        'id' => 'S9-foreign-identity-hint-allows-create',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if ($signal->member_public_id_hint() !== null && $this->members->find_by_public_id($signal->member_public_id_hint()) !== null) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'S10-concurrent-create-not-reread',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '            $winner = $this->members->find_by_wp_user($wp_user_id);' . "\n" . '            return $winner !== null ? $this->existing($winner, $signal) : EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::SERVER_ERROR);',
        'replace' => '            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::SERVER_ERROR);',
    ),
    array(
        'id' => 'S11-uncertain-create-reported-provisioned',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '            $this->report(\'commit_unknown\', $e);' . "\n" . '            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::OUTCOME_UNKNOWN);',
        'replace' => '            return EVC_Member_Sync_Result::member(EVC_Member_Sync_Result::PROVISIONED, array(\'member_public_id\' => $public_id, \'status\' => \'enabled\'));',
    ),
    array(
        'id' => 'S12-malformed-public-id-accepted',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if (!EVC_Member_Id::is_valid_format($public_id)) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'S13-status-change-without-capability',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if (!$this->authorizer->allows($actor, self::CAPABILITY)) {' . "\n" . '            return new EVC_Member_Account_Result(EVC_Member_Account_Result::FORBIDDEN);',
        'replace' => '        if (false) {' . "\n" . '            return new EVC_Member_Account_Result(EVC_Member_Account_Result::FORBIDDEN);',
    ),
    array(
        'id' => 'S14-status-reason-policy-ignored',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if (!in_array($reason, $allowed, true)) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'S15-status-conflict-reported-changed',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '            } catch (EVC_Member_Status_Conflict $e) {' . "\n" . '                return new EVC_Member_Account_Result(EVC_Member_Account_Result::CONCURRENT_CHANGE);',
        'replace' => '            } catch (EVC_Member_Status_Conflict $e) {' . "\n" . '                return new EVC_Member_Account_Result(EVC_Member_Account_Result::CHANGED, $target);',
    ),
    array(
        'id' => 'S16-first-qr-offered-without-policy',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        return $result->kind() === EVC_Member_Sync_Result::PROVISIONED && $this->policy->offer_first_qr_after_provisioning()',
        'replace' => '        return $result->kind() === EVC_Member_Sync_Result::PROVISIONED',
    ),
    array(
        'id' => 'S17-first-qr-offered-despite-active-token',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '        if ($this->tokens->active_token_hash($member[\'id\']) !== null) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'S18-sync-claims-entitlement',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '    public function grants_entitlement(): bool {' . "\n" . '        return false;' . "\n" . '    }' . "\n" . '' . "\n" . '    /** Synchronisation never issues',
        'replace' => '    public function grants_entitlement(): bool {' . "\n" . '        return true;' . "\n" . '    }' . "\n" . '' . "\n" . '    /** Synchronisation never issues',
    ),
    array(
        'id' => 'S19-sync-report-leaks-user',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '            call_user_func($this->error_reporter, array(\'stage\' => \'member_sync_\' . $stage, \'exception\' => get_class($e)));',
        'replace' => '            call_user_func($this->error_reporter, array(\'stage\' => \'member_sync_\' . $stage, \'exception\' => get_class($e), \'detail\' => $e->getMessage()));',
    ),
    array(
        'id' => 'S20-free-text-status-reason-accepted',
        'file' => 'includes/class-evc-member-sync.php',
        'search' => '|| !is_string($reason) || !preg_match(self::REASON_PATTERN, $reason)) {',
        'replace' => '|| !is_string($reason)) {',
    ),
    // UI-04G pure REST response contracts (C1-C26).
    array(
        'id' => 'C1-same-day-replay-instruction-unchecked',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '                if ($instruction !== ($handoff !== null ? \'do_not_prepare\' : \'verify_handoff\')) {',
        'replace' => '                if (false) {',
    ),
    array(
        'id' => 'C2-contradictory-fresh-commit-accepted',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '                if ($o->recording() !== EVC_Prepared_Redemption_Outcome::RECORDED_NOW || $o->instruction() !== EVC_Prepared_Redemption_Outcome::PREPARE' . "\n" . '                    || $o->fulfillment() !== EVC_Prepared_Redemption_Outcome::FULFILLMENT_UNCONFIRMED || $handoff !== null || $o->entitlement_consumed() !== true) {',
        'replace' => '                if (false) {',
    ),
    array(
        'id' => 'C3-earlier-day-replay-on-the-same-day',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '                if (strcmp($today, $record[\'business_date\']) <= 0) {',
        'replace' => '                if (false) {',
    ),
    array(
        'id' => 'C4-earlier-day-replay-reports-record-date',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '                $server_date = $today;' . "\n" . '            } else {',
        'replace' => '                $server_date = $record[\'business_date\'];' . "\n" . '            } else {',
    ),
    array(
        'id' => 'C5-fresh-commit-uses-response-clock-date',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '\'prepare\', true, $record[\'business_date\'], $record, null);',
        'replace' => '\'prepare\', true, $today, $record, null);',
    ),
    array(
        'id' => 'C6-record-of-another-request-accepted',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '            || $view[\'request_id\'] !== $request_id || !EVC_Member_Id::is_valid_format($view[\'member_public_id\'])',
        'replace' => '            || !EVC_Member_Id::is_valid_format($view[\'member_public_id\'])',
    ),
    array(
        'id' => 'C7-redeemed-at-off-business-date-accepted',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '            || EVC_Utc_Timestamp::athens_date($view[\'redeemed_at_utc\']) !== $view[\'business_date\']) {',
        'replace' => '            ) {',
    ),
    array(
        'id' => 'C8-refusal-claims-consumption',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '            return self::body($kind, $request_id, null, null, \'do_not_prepare\', false, $today, null, null);',
        'replace' => '            return self::body($kind, $request_id, null, null, \'do_not_prepare\', true, $today, null, null);',
    ),
    array(
        'id' => 'C9-uncertain-redemption-without-retry-id',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '        $body[\'retry_request_id\'] = $request_id;',
        'replace' => '        $body[\'retry_request_id\'] = null;',
    ),
    array(
        'id' => 'C10-unknown-commit-reported-as-error',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '            return self::unresolved($kind, $request_id, $today);',
        'replace' => '            return self::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $request_id, $today);',
    ),
    array(
        'id' => 'C11-arbitrary-request-id-echoed',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '        return is_string($id) && preg_match(self::UUID_V4, $id) ? $id : null;',
        'replace' => '        return is_string($id) ? $id : null;',
    ),
    array(
        'id' => 'C12-handoff-ownership-unchecked',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '                || ($kind === EVC_Handoff_Outcome::ALREADY_CONFIRMED) !== ($view[\'handoff_request_id\'] !== $hid)) {',
        'replace' => '                ) {',
    ),
    array(
        'id' => 'C13-earlier-handoff-with-invented-time',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => 'CLAIM_ALREADY_HANDED_EARLIER && $v[\'handoff_at_utc\'] === null &&',
        'replace' => 'CLAIM_ALREADY_HANDED_EARLIER &&',
    ),
    array(
        'id' => 'C14-handed-now-time-not-recording-time',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '            return $v[\'handoff_at_utc\'] === $v[\'confirmation_recorded_at_utc\'] && $v[\'handoff_time\']',
        'replace' => '            return $v[\'handoff_time\']',
    ),
    array(
        'id' => 'C15-handoff-view-extra-fields',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '        if (!is_array($v) || array_keys($v) !== self::VIEW_KEYS ||',
        'replace' => '        if (!is_array($v) ||',
    ),
    array(
        'id' => 'C16-uncertain-handoff-without-retry-id',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '\'handoff\' => null, \'retry_handoff_request_id\' => $hid);',
        'replace' => '\'handoff\' => null, \'retry_handoff_request_id\' => null);',
    ),
    array(
        'id' => 'C17-status-fulfillment-contradicts-handoff',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '            || $d[\'fulfillment\'] !== ($d[\'handoff\'] === null ? \'unconfirmed\' : \'confirmed\')) {',
        'replace' => '            ) {',
    ),
    array(
        'id' => 'C18-status-extra-fields',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '        if (!is_array($d) || array_keys($d) !== self::DATA_KEYS ||',
        'replace' => '        if (!is_array($d) ||',
    ),
    array(
        'id' => 'C19-lookup-leaks-extra-member-data',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => 'array_keys($m) !== array(\'member_public_id\', \'lookup_ref\', \'lookup_ref_expires_at_utc\')',
        'replace' => '!isset($m[\'member_public_id\'], $m[\'lookup_ref\'], $m[\'lookup_ref_expires_at_utc\'])',
    ),
    array(
        'id' => 'C20-suggestion-presented-as-confirmed-order',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '\'suggestion\' => $suggestion, \'confirmed_order\' => false);',
        'replace' => '\'suggestion\' => $suggestion, \'confirmed_order\' => true);',
    ),
    array(
        'id' => 'C21-unknown-commit-as-definite-failure',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '        \'record_integrity_failure\' => 500, \'outcome_unknown\' => 503, \'server_error\' => 503,' . "\n" . '    );' . "\n" . '    const HANDOFF',
        'replace' => '        \'record_integrity_failure\' => 500, \'outcome_unknown\' => 409, \'server_error\' => 503,' . "\n" . '    );' . "\n" . '    const HANDOFF',
    ),
    array(
        'id' => 'C22-rate-limit-not-429',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '\'rate_limited\' => 429',
        'replace' => '\'rate_limited\' => 503',
    ),
    array(
        'id' => 'C23-unknown-body-gets-success-status',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => 'isset($map[$surface][$key]) ? $map[$surface][$key] : 500;',
        'replace' => 'isset($map[$surface][$key]) ? $map[$surface][$key] : 200;',
    ),
    array(
        'id' => 'C24-integrity-failure-claims-no-consumption',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '            return self::body($kind, $request_id, null, null, \'do_not_prepare\', null, $today, null, null);',
        'replace' => '            return self::body($kind, $request_id, null, null, \'do_not_prepare\', false, $today, null, null);',
    ),
    array(
        'id' => 'C25-replay-fulfillment-unchecked',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '$o->fulfillment() !== $expected_fulfillment || $o->entitlement_consumed() !== true',
        'replace' => '$o->entitlement_consumed() !== true',
    ),
    array(
        'id' => 'C26-domain-shape-superset-accepted',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '        return in_array($keys, $shapes, true);',
        'replace' => '        foreach ($shapes as $shape) {' . "\n" . '            if (!array_diff($shape, $keys)) {' . "\n" . '                return true;' . "\n" . '            }' . "\n" . '        }' . "\n" . '        return false;',
    ),
    // UI-04H route-aware v2 envelope policy (V2-1..V2-15).
    array(
        'id' => 'V2-1-namespace-prefix-accepted',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        return $route === $base || strpos($route, $base . \'/\') === 0;' . "\n" . '    }' . "\n" . '' . "\n" . '    /** @return array|null the route definition',
        'replace' => '        return strpos($route, $base) === 0;' . "\n" . '    }' . "\n" . '' . "\n" . '    /** @return array|null the route definition',
    ),
    array(
        'id' => 'V2-2-sealed-body-outside-v2-passes',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '            return $sealed !== null ? self::envelope(\'server_error\', 500) : array(\'action\' => self::IGNORE);',
        'replace' => '            return array(\'action\' => self::IGNORE);',
    ),
    array(
        'id' => 'V2-3-http-401-may-carry-domain-body',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        if ($status === 401) {' . "\n" . '            // HTTP 401 is always',
        'replace' => '        if (false) {' . "\n" . '            // HTTP 401 is always',
    ),
    array(
        'id' => 'V2-4-unsealed-mapping-skipped',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        if ($sealed === null) {' . "\n" . '            return self::unsealed(',
        'replace' => '        if ($sealed === null && false) {' . "\n" . '            return self::unsealed(',
    ),
    array(
        'id' => 'V2-5-output-shaping-params-ignored',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        if (!empty($in[\'transport_params\'])) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'V2-6-unauthenticated-domain-body',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        if (empty($in[\'authenticated\'])) {',
        'replace' => '        if (false) {',
    ),
    array(
        'id' => 'V2-7-seal-not-bound-to-route',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '            || $sealed[\'route_id\'] !== $route[\'id\'] || $sealed[\'surface\'] !== $route[\'surface\']) {',
        'replace' => '            ) {',
    ),
    array(
        'id' => 'V2-8-designation-ignores-method',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '$matched_route === \'/\' . self::NAMESPACE_V2 . $r[\'route\'] && $method === $r[\'method\']',
        'replace' => '$matched_route === \'/\' . self::NAMESPACE_V2 . $r[\'route\']',
    ),
    array(
        'id' => 'V2-9-tampered-data-passes',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        if (!array_key_exists(\'data\', $in) || $in[\'data\'] !== $body || $status !== $sealed[\'status\']',
        'replace' => '        if (!array_key_exists(\'data\', $in) || $status !== $sealed[\'status\']',
    ),
    array(
        'id' => 'V2-10-contract-status-unchecked',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '            || $status !== EVC_Contract_Http::status($route[\'surface\'], $body)) {',
        'replace' => '            ) {',
    ),
    array(
        'id' => 'V2-11-surface-shape-unchecked',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '            || !EVC_Contract_Envelope_Rule::is_domain_body_for($route[\'surface\'], $body)' . "\n" . '',
        'replace' => '',
    ),
    array(
        'id' => 'V2-12-invalid-nonce-not-unauthorized',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        if ($code === \'rest_cookie_invalid_nonce\' || $status === 401) {',
        'replace' => '        if ($status === 401) {',
    ),
    array(
        'id' => 'V2-13-unknown-success-becomes-client-error',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        if ($status >= 400 && $status < 500) {',
        'replace' => '        if ($status >= 200 && $status < 500) {',
    ),
    array(
        'id' => 'V2-14-unavailable-becomes-500',
        'file' => 'includes/class-evc-rest-v2-boundary.php',
        'search' => '        if ($status === 503) {' . "\n" . '            return self::envelope(\'server_error\', 503);',
        'replace' => '        if ($status === 503) {' . "\n" . '            return self::envelope(\'server_error\', 500);',
    ),
    array(
        'id' => 'V2-15-unknown-outcome-recognised',
        'file' => 'includes/class-evc-response-contract.php',
        'search' => '        return isset($shapes[$surface]) && in_array($keys, $shapes[$surface], true) && EVC_Contract_Http::knows($surface, $data);',
        'replace' => '        return isset($shapes[$surface]) && in_array($keys, $shapes[$surface], true);',
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
