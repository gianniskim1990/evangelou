<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-03D: the signed "completed by shop user X at T" marker. Pure: shape,
 * signature, canonical bytes and binding to the order it is read from.
 */
final class WcCompletionMarkerTest extends TestCase {
    const KEY = 'test-only-evidence-key-0123456789abcdef-0123456789';

    private static function instant(string $s = '2026-10-26 10:15:07'): DateTimeImmutable {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    private static function marker(array $o = array()): EVC_Wc_Completion_Marker {
        return EVC_Wc_Completion_Marker::create($o['env'] ?? 'live', $o['order'] ?? 100, $o['customer'] ?? 42, $o['actor'] ?? 7, $o['at'] ?? self::instant(), $o['reason'] ?? 'member_locked', $o['addon'] ?? EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $o['floor'] ?? 12);
    }

    private static function codec(string $key = self::KEY): EVC_Wc_Completion_Marker_Codec {
        return new EVC_Wc_Completion_Marker_Codec($key);
    }

    public function test_round_trip_is_canonical_and_bound_to_the_order(): void {
        $m = self::marker();
        $raw = self::codec()->encode($m);
        $this->assertMatchesRegularExpression('/^\{"v":1,"environment":"live","order_id":100,"customer_id":42,"actor_id":7,"completed_at":"2026-10-26T10:15:07Z","reason":"member_locked","addon":"suppressed","row_floor":12,"sig":"[0-9a-f]{64}"\}$/D', $raw);
        $read = self::codec()->decode($raw, 100, 42, 'live');
        $this->assertSame(EVC_Wc_Completion_Marker_Read::VALID, $read->outcome);
        $this->assertTrue($read->marker->same_as($m));
        $this->assertSame($raw, $read->raw);
        $this->assertSame(7, $read->marker->actor_id());
        $this->assertEquals(self::instant(), $read->marker->completed_at_utc());
        $this->assertSame('cross_order', self::codec()->decode($raw, 101, 42, 'live')->reason);
        $this->assertSame('cross_customer', self::codec()->decode($raw, 100, 43, 'live')->reason);
        $this->assertSame('cross_environment', self::codec()->decode($raw, 100, 42, 'staging')->reason);
    }

    public function test_tampering_another_key_or_another_signature_domain_is_rejected(): void {
        $raw = self::codec()->encode(self::marker());
        $this->assertSame('signature_mismatch', self::codec()->decode(str_replace('"actor_id":7', '"actor_id":8', $raw), 100, 42, 'live')->reason);
        $this->assertSame('signature_mismatch', self::codec()->decode(str_replace('"addon":"suppressed"', '"addon":"shipped"', $raw), 100, 42, 'live')->reason);
        $this->assertSame('signature_mismatch', self::codec(self::KEY . 'x')->decode($raw, 100, 42, 'live')->reason);
        $this->assertSame('non_canonical', self::codec()->decode(str_replace(',"sig"', ' ,"sig"', $raw), 100, 42, 'live')->reason);
        $this->assertSame('unexpected_shape', self::codec()->decode(str_replace('"reason":"member_locked",', '', $raw), 100, 42, 'live')->reason);
        $this->assertSame('signature_missing', self::codec()->decode(preg_replace('/"sig":"[0-9a-f]+"/', '"sig":"x"', $raw), 100, 42, 'live')->reason);
        foreach (array('', null, 5, str_repeat('a', 3000), '{bad') as $bad) {
            $this->assertSame(EVC_Wc_Completion_Marker_Read::INVALID, self::codec()->decode($bad, 100, 42, 'live')->outcome);
        }
        // A BACS intent signature over the same bytes never verifies as a marker (own key and domain).
        $intent_sig = hash_hmac('sha256', "EVC-BACS-INTENT\0" . '1' . "\0" . substr($raw, 0, strpos($raw, ',"sig"')) . '}', hash_hmac('sha256', EVC_Bacs_Intent_Codec::KEY_LABEL, self::KEY, true));
        $forged = preg_replace('/"sig":"[0-9a-f]+"/', '"sig":"' . $intent_sig . '"', $raw);
        $this->assertSame('signature_mismatch', self::codec()->decode($forged, 100, 42, 'live')->reason);
    }

    public function test_invariants(): void {
        foreach (array(
            array('actor' => 42),
            array('actor' => 0),
            array('order' => 0),
            array('reason' => 'Member Locked'),
            array('addon' => 'ran'),
            array('floor' => -1),
            array('env' => 'Live'),
            array('at' => new DateTimeImmutable('2026-10-26 13:15:07', new DateTimeZone('Europe/Athens'))),
            array('at' => self::instant('2026-10-26 10:15:07.5')),
        ) as $bad) {
            try {
                self::marker($bad);
                $this->fail('accepted ' . json_encode(array_keys($bad)));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new EVC_Wc_Completion_Marker_Codec('short');
    }

    public function test_no_personal_or_bank_data_fields(): void {
        $this->assertSame(array('v', 'environment', 'order_id', 'customer_id', 'actor_id', 'completed_at', 'reason', 'addon', 'row_floor'), EVC_Wc_Completion_Marker::FIELDS);
    }
}
