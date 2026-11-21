<?php

use PHPUnit\Framework\TestCase;

/**
 * STAGE-08 round 12 (owner decision D26 part 2): RFC 8292 VAPID: the key checks that keep push closed, the ES256 token
 * (verified here with OpenSSL against the public key), the Authorization header, and the one-line key command of
 * docs/member-push.md (run here exactly as written there).
 */
final class PushVapidTest extends TestCase {
    const PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    const PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    const OTHER_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    const SUBJECT = 'mailto:info@evangelouprofiterole.gr';

    public function test_a_matching_pair_and_subject_pass(): void {
        $this->assertNull(EVC_Push_Vapid::keys_problem(self::PUBLIC, self::PRIVATE, self::SUBJECT));
    }

    public function test_every_problem_keeps_push_closed(): void {
        $off = EVC_Push_Crypto::b64u_decode(self::PUBLIC);
        $off[40] = chr(ord($off[40]) ^ 1);
        $cases = array(
            array(null, self::PRIVATE, self::SUBJECT, 'public_missing'),
            array('', self::PRIVATE, self::SUBJECT, 'public_missing'),
            array(EVC_Push_Crypto::b64u_encode($off), self::PRIVATE, self::SUBJECT, 'public_invalid'),
            array(substr(self::PUBLIC, 0, -2), self::PRIVATE, self::SUBJECT, 'public_invalid'),
            array(self::PUBLIC . '+', self::PRIVATE, self::SUBJECT, 'public_invalid'),
            array(true, self::PRIVATE, self::SUBJECT, 'public_invalid'),
            array(self::PUBLIC, null, self::SUBJECT, 'private_missing'),
            array(self::PUBLIC, '...', self::SUBJECT, 'private_invalid'),
            array(self::PUBLIC, EVC_Push_Crypto::b64u_encode(str_repeat("\0", 32)), self::SUBJECT, 'private_invalid'),
            array(self::PUBLIC, EVC_Push_Crypto::b64u_encode(str_repeat("\xff", 32)), self::SUBJECT, 'private_invalid'),
            array(self::OTHER_PUBLIC, self::PRIVATE, self::SUBJECT, 'pair_mismatch'),
            array(self::PUBLIC, self::PRIVATE, null, 'subject_missing'),
            array(self::PUBLIC, self::PRIVATE, 'info@evangelouprofiterole.gr', 'subject_invalid'),
            array(self::PUBLIC, self::PRIVATE, 'https://evangelouprofiterole.gr', 'subject_invalid'),
            array(self::PUBLIC, self::PRIVATE, 'mailto:shop@example.com', 'subject_invalid'),
            array(self::PUBLIC, self::PRIVATE, 'mailto:shop@localhost', 'subject_invalid'),
            array(self::PUBLIC, self::PRIVATE, 'mailto:a b@shop.gr', 'subject_invalid'),
            array(self::PUBLIC, self::PRIVATE, 'mailto:' . str_repeat('a', 200) . '@shop.gr', 'subject_invalid'),
        );
        foreach ($cases as $i => $c) {
            $this->assertSame($c[3], EVC_Push_Vapid::keys_problem($c[0], $c[1], $c[2]), 'case ' . $i);
        }
    }

    public function test_the_token_is_es256_signed_with_the_private_key_and_bounded_in_time(): void {
        $now = 1795000000;
        $jwt = EVC_Push_Vapid::jwt('https://fcm.googleapis.com', $now, self::PRIVATE, self::SUBJECT);
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
        $this->assertSame(array('typ' => 'JWT', 'alg' => 'ES256'), json_decode(EVC_Push_Crypto::b64u_decode($parts[0]), true));
        $claims = json_decode(EVC_Push_Crypto::b64u_decode($parts[1]), true);
        $this->assertSame(array('aud' => 'https://fcm.googleapis.com', 'exp' => $now + 43200, 'sub' => self::SUBJECT), $claims);
        $this->assertLessThanOrEqual($now + 86400, $claims['exp'], 'RFC 8292: at most 24 hours');
        $raw = EVC_Push_Crypto::b64u_decode($parts[2]);
        $this->assertSame(64, strlen($raw), 'raw R || S (RFC 7518 section 3.4), not DER');
        $public = EVC_Push_Crypto::public_key(EVC_Push_Crypto::b64u_decode(self::PUBLIC));
        $this->assertSame(1, openssl_verify($parts[0] . '.' . $parts[1], self::raw_to_der($raw), $public, OPENSSL_ALGO_SHA256));
        $other = EVC_Push_Crypto::public_key(EVC_Push_Crypto::b64u_decode(self::OTHER_PUBLIC));
        $this->assertSame(0, openssl_verify($parts[0] . '.' . $parts[1], self::raw_to_der($raw), $other, OPENSSL_ALGO_SHA256));
    }

    public function test_the_authorization_header_carries_the_token_and_the_public_key(): void {
        $header = EVC_Push_Vapid::authorization('https://web.push.apple.com', 1795000000, self::PUBLIC, self::PRIVATE, self::SUBJECT);
        $this->assertSame(1, preg_match('/^vapid t=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/D', $header, $m));
        $this->assertSame(self::PUBLIC, $m[2]);
        $this->assertSame('https://web.push.apple.com', json_decode(EVC_Push_Crypto::b64u_decode(explode('.', $m[1])[1]), true)['aud']);
    }

    public function test_der_signatures_are_converted_strictly(): void {
        $r = "\x00" . str_repeat("\x80", 32);
        $s = "\x01\x02";
        $der = "\x30" . chr(2 + 33 + 2 + 2) . "\x02\x21" . $r . "\x02\x02" . $s;
        $this->assertSame(str_repeat("\x80", 32) . str_pad($s, 32, "\0", STR_PAD_LEFT), EVC_Push_Vapid::der_to_raw($der));
        $this->assertNull(EVC_Push_Vapid::der_to_raw($der . "\x00"), 'trailing bytes');
        $inner = "\x02\x21" . $r . "\x02\x02" . $s . "\x05\x00";
        $this->assertNull(EVC_Push_Vapid::der_to_raw("\x30" . chr(strlen($inner)) . $inner), 'a third element inside the sequence');
        $this->assertNull(EVC_Push_Vapid::der_to_raw("\x31" . substr($der, 1)), 'not a sequence');
        $this->assertNull(EVC_Push_Vapid::der_to_raw(substr($der, 0, -1)), 'cut short');
        $this->assertNull(EVC_Push_Vapid::der_to_raw("\x30\x06\x02\x01\x01\x03\x01\x01"), 'second element not an integer');
    }

    public function test_the_one_line_key_command_of_the_owner_guide_makes_a_valid_pair(): void {
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/member-push.md');
        $this->assertSame(1, preg_match("/^php -r '([^'\\n]+)'$/m", $doc, $m), 'docs/member-push.md has the one-line command');
        $proc = proc_open(array(PHP_BINARY, '-r', $m[1]), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);
        $this->assertSame(1, preg_match("/^define\\('EVC_CLUB_PUSH_VAPID_PUBLIC', '([A-Za-z0-9_-]{87})'\\);\\ndefine\\('EVC_CLUB_PUSH_VAPID_PRIVATE', '([A-Za-z0-9_-]{43})'\\);\\n$/D", $out, $keys), $out);
        $this->assertNull(EVC_Push_Vapid::keys_problem($keys[1], $keys[2], self::SUBJECT));
    }

    private static function raw_to_der(string $raw): string {
        $int = function (string $b): string {
            $b = ltrim($b, "\0");
            if ($b === '' || ord($b[0]) >= 0x80) {
                $b = "\0" . $b;
            }
            return "\x02" . chr(strlen($b)) . $b;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        return "\x30" . chr(strlen($seq)) . $seq;
    }
}
