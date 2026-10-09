<?php
// Run using: php wordpress/evangelou-club/tests/smoke.php
define('EVC_STANDALONE_TEST', true);
require_once __DIR__ . '/../includes/class-evc-clock.php';
require_once __DIR__ . '/../includes/class-evc-qr-token.php';

function evc_assert_same($actual, $expected, $label) {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

evc_assert_same(EVC_Clock::business_date(new DateTimeImmutable('2026-01-01T21:59:59+00:00')), '2026-01-01', 'Winter before midnight');
evc_assert_same(EVC_Clock::business_date(new DateTimeImmutable('2026-01-01T22:00:00+00:00')), '2026-01-02', 'Winter midnight');
evc_assert_same(EVC_Clock::business_date(new DateTimeImmutable('2026-07-01T20:59:59+00:00')), '2026-07-01', 'Summer before midnight');
evc_assert_same(EVC_Clock::business_date(new DateTimeImmutable('2026-07-01T21:00:00+00:00')), '2026-07-02', 'Summer midnight');
evc_assert_same(EVC_Clock::business_timestamp(new DateTimeImmutable('2026-01-01T22:00:00+00:00')), '2026-01-02T00:00:00+02:00', 'DST offset');
$t1 = EVC_Qr_Token::mint();
$t2 = EVC_Qr_Token::mint();
evc_assert_same(EVC_Qr_Token::is_valid_format($t1), true, 'Valid mint');
evc_assert_same($t1 !== $t2, true, 'Fresh randomness');
evc_assert_same(strlen(EVC_Qr_Token::digest($t1)), 64, 'SHA-256 digest');
evc_assert_same(EVC_Qr_Token::is_valid_format('evc_test'), false, 'Reject malformed tokens');
$schema = file_get_contents(__DIR__ . '/../database/001_initial.sql');
evc_assert_same(strpos($schema, 'UNIQUE KEY uq_one_per_business_day') !== false, true, 'DB uniqueness');
evc_assert_same(strpos($schema, 'UNIQUE KEY uq_idempotency') !== false, true, 'DB idempotency');
echo "Evangelou Club: 11 foundation smoke tests passed.\n";
