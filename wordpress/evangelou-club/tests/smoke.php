<?php
// Dependency-free smoke checks (no Composer, no database).
// Run using: php wordpress/evangelou-club/tests/smoke.php
// The full suite is PHPUnit: see README "Running the tests".
define('EVC_STANDALONE_TEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$evc_smoke_count = 0;
function evc_assert_same($actual, $expected, $label) {
    global $evc_smoke_count;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    $evc_smoke_count++;
}

evc_assert_same(EVC_Clock::business_date(new DateTimeImmutable('2026-01-01T21:59:59+00:00')), '2026-01-01', 'Winter before midnight');
evc_assert_same(EVC_Clock::business_date(new DateTimeImmutable('2026-01-01T22:00:00+00:00')), '2026-01-02', 'Winter midnight');
evc_assert_same(EVC_Clock::business_date(new DateTimeImmutable('2026-07-01T20:59:59+00:00')), '2026-07-01', 'Summer before midnight');
evc_assert_same(EVC_Clock::business_date(new DateTimeImmutable('2026-07-01T21:00:00+00:00')), '2026-07-02', 'Summer midnight');
evc_assert_same(EVC_Clock::business_timestamp(new DateTimeImmutable('2026-01-01T22:00:00+00:00')), '2026-01-02T00:00:00+02:00', 'Winter offset');
evc_assert_same(EVC_Clock::business_timestamp(new DateTimeImmutable('2026-03-29T01:00:00+00:00')), '2026-03-29T04:00:00+03:00', 'Spring DST jump');
evc_assert_same(EVC_Clock::business_timestamp(new DateTimeImmutable('2026-10-25T01:30:00+00:00')), '2026-10-25T03:30:00+02:00', 'Autumn DST repeat');
$t1 = EVC_Qr_Token::mint();
$t2 = EVC_Qr_Token::mint();
evc_assert_same(EVC_Qr_Token::is_valid_format($t1), true, 'Valid mint');
evc_assert_same($t1 !== $t2, true, 'Fresh randomness');
evc_assert_same(strlen(EVC_Qr_Token::digest($t1)), 64, 'SHA-256 digest');
evc_assert_same(EVC_Qr_Token::is_valid_format('evc_test'), false, 'Reject malformed tokens');
evc_assert_same(EVC_Member_Id::is_valid_format(EVC_Member_Id::mint()), true, 'Member id format');
$schema = file_get_contents(__DIR__ . '/../database/001_initial.sql');
evc_assert_same(strpos($schema, 'UNIQUE KEY uq_redemptions_one_per_day (member_id, benefit_type, business_date)') !== false, true, 'Schema declares one-per-day key');
evc_assert_same(strpos($schema, 'UNIQUE KEY uq_redemptions_request (request_id)') !== false, true, 'Schema declares idempotency key');
evc_assert_same(preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i', $schema), 0, 'Schema does not hide drift behind IF NOT EXISTS');
echo "Evangelou Club: $evc_smoke_count smoke checks passed (static only; behaviour is proven by the PHPUnit DB suite).\n";
