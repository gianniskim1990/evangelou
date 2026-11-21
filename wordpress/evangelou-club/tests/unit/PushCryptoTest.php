<?php

use PHPUnit\Framework\TestCase;

/**
 * STAGE-08 round 12 (owner decision D26 part 2): RFC 8291 message encryption, checked against the example of RFC 8291
 * section 5 and the intermediate values of its Appendix A (byte for byte), plus a round trip with fresh keys decrypted
 * here the way a browser does (the test decrypts with the user agent's private key; the plugin never decrypts).
 */
final class PushCryptoTest extends TestCase {
    // RFC 8291, section 5 / Appendix A.
    const PLAINTEXT = 'V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24';
    const AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';
    const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
    const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    const ECDH = 'kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs';
    const PRK_KEY = 'Snr3JMxaHVDXHWJn5wdC52WjpCtd2EIEGBykDcZW32k';
    const KEY_INFO = 'V2ViUHVzaDogaW5mbwAEJXGyvs3942BVGq8e0PTNNmwRzr5VX4m8t7GGpTM5FzFo7OLr4BhZe9MEebhuPI-OztV3ylkYfpJGmQ22ggCLDgT-M_SrDepxkU21WCP3O1SUj0EwbZIHMtu5pZpTKGSCIA5Zent7wmC6HCJ5mFgJkuk5cwAvMBKiiujwa7t45ewP';
    const IKM = 'S4lYMb_L0FxCeq0WhDx813KgSYqU26kOyzWUdsXYyrg';
    const PRK = '09_eUZGrsvxChDCGRCdkLiDXrReGOEVeSCdCcPBSJSc';
    const CEK = 'oIhVW04MRdy2XN9CiKLxTg';
    const NONCE = '4h_95klXJ5E_qnoN';
    const HEADER = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    const CIPHERTEXT = '8pfeW0KbunFT06SuDKoJH9Ql87S1QUrdirN6GcG7sFz1y1sqLgVi1VhjVkHsUoEsbI_0LpXMuGvnzQ';
    const BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    private static function d(string $b64u): string {
        $raw = EVC_Push_Crypto::b64u_decode($b64u);
        self::assertNotNull($raw, $b64u);
        return $raw;
    }

    public function test_the_rfc_8291_example_is_reproduced_byte_for_byte(): void {
        $body = EVC_Push_Crypto::encrypt(self::d(self::PLAINTEXT), self::d(self::UA_PUBLIC), self::d(self::AUTH), self::d(self::SALT), self::d(self::AS_PRIVATE));
        $this->assertSame(self::BODY, EVC_Push_Crypto::b64u_encode($body));
        // 86 (header) + 41 + 1 (delimiter) + 16 (tag). The example's HTTP header says Content-Length: 145; the body it shows is 144 octets.
        $this->assertSame(144, strlen($body));
        $this->assertSame(self::HEADER, EVC_Push_Crypto::b64u_encode(substr($body, 0, 86)), 'the 86-octet header');
        $this->assertSame(self::CIPHERTEXT, EVC_Push_Crypto::b64u_encode(substr($body, 86)));
    }

    public function test_the_rfc_8291_intermediate_values(): void {
        $as = EVC_Push_Crypto::private_key(self::d(self::AS_PRIVATE));
        $this->assertSame(self::d(self::AS_PUBLIC), EVC_Push_Crypto::point_of($as), 'the public key of as_private');
        $ua = EVC_Push_Crypto::public_key(self::d(self::UA_PUBLIC));
        $ecdh = openssl_pkey_derive($ua, $as, 0);
        $this->assertSame(self::ECDH, EVC_Push_Crypto::b64u_encode($ecdh));
        $this->assertSame(self::PRK_KEY, EVC_Push_Crypto::b64u_encode(hash_hmac('sha256', $ecdh, self::d(self::AUTH), true)));
        $info = "WebPush: info\0" . self::d(self::UA_PUBLIC) . self::d(self::AS_PUBLIC);
        $this->assertSame(self::KEY_INFO, EVC_Push_Crypto::b64u_encode($info));
        $ikm = hash_hkdf('sha256', $ecdh, 32, $info, self::d(self::AUTH));
        $this->assertSame(self::IKM, EVC_Push_Crypto::b64u_encode($ikm));
        $this->assertSame(self::PRK, EVC_Push_Crypto::b64u_encode(hash_hmac('sha256', $ikm, self::d(self::SALT), true)));
        $this->assertSame(self::CEK, EVC_Push_Crypto::b64u_encode(hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", self::d(self::SALT))));
        $this->assertSame(self::NONCE, EVC_Push_Crypto::b64u_encode(hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", self::d(self::SALT))));
        // The receiver's side of the same secret.
        $this->assertSame($ecdh, openssl_pkey_derive(EVC_Push_Crypto::public_key(self::d(self::AS_PUBLIC)), EVC_Push_Crypto::private_key(self::d(self::UA_PRIVATE)), 0));
    }

    public function test_the_rfc_example_decrypts_with_the_user_agent_key(): void {
        $this->assertSame('When I grow up, I want to be a watermelon', self::decrypt(self::d(self::BODY), self::d(self::UA_PRIVATE), self::d(self::AUTH)));
    }

    public function test_a_real_message_uses_fresh_salt_and_sender_key_and_round_trips(): void {
        $ua = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        $details = openssl_pkey_get_details($ua);
        $scalar = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
        $point = EVC_Push_Crypto::point_of($ua);
        $auth = random_bytes(16);
        $payload = '{"title":"Καλημέρα","body":"Ο καφές της ημέρας σας περιμένει."}';
        $one = EVC_Push_Crypto::encrypt($payload, $point, $auth);
        $two = EVC_Push_Crypto::encrypt($payload, $point, $auth);
        $this->assertNotSame(substr($one, 0, 16), substr($two, 0, 16), 'a new salt every time');
        $this->assertNotSame(substr($one, 21, 65), substr($two, 21, 65), 'a new sender key every time');
        $this->assertSame(4096, unpack('N', substr($one, 16, 4))[1]);
        $this->assertSame(65, ord($one[20]));
        $this->assertSame($payload, self::decrypt($one, $scalar, $auth));
        $this->assertSame($payload, self::decrypt($two, $scalar, $auth));
        $this->assertSame(strlen($payload) + 86 + 1 + 16, strlen($one), 'one record, the 0x02 delimiter, no further padding');
    }

    public function test_a_wrong_auth_secret_cannot_decrypt(): void {
        $ua = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        $scalar = str_pad(openssl_pkey_get_details($ua)['ec']['d'], 32, "\0", STR_PAD_LEFT);
        $body = EVC_Push_Crypto::encrypt('x', EVC_Push_Crypto::point_of($ua), str_repeat('a', 16));
        $this->assertNull(self::decrypt($body, $scalar, str_repeat('b', 16)));
    }

    public function test_bad_inputs_are_refused(): void {
        $point = self::d(self::UA_PUBLIC);
        $off = $point;
        $off[40] = chr(ord($off[40]) ^ 1);
        $this->assertNull(EVC_Push_Crypto::public_key($off), 'a point off the curve (RFC 8291 section 6)');
        $this->assertNull(EVC_Push_Crypto::public_key("\x02" . substr($point, 1)), 'not the uncompressed form');
        $this->assertNull(EVC_Push_Crypto::public_key(substr($point, 0, 64)));
        foreach (array(
            'off-curve key' => array('x', $off, str_repeat('a', 16)),
            'short auth' => array('x', $point, str_repeat('a', 15)),
            'long auth' => array('x', $point, str_repeat('a', 17)),
            'too large' => array(str_repeat('x', 3994), $point, str_repeat('a', 16)),
        ) as $label => $case) {
            try {
                EVC_Push_Crypto::encrypt($case[0], $case[1], $case[2]);
                $this->fail($label . ' accepted');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(EVC_Push_Crypto::MAX_PLAINTEXT, 3993);
        $this->assertSame(4096, strlen(EVC_Push_Crypto::encrypt(str_repeat('x', 3993), $point, str_repeat('a', 16))), 'the largest message fills one 4096-octet record');
        try {
            EVC_Push_Crypto::encrypt('x', $point, str_repeat('a', 16), str_repeat('s', 15));
            $this->fail('short salt accepted');
        } catch (InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_private_scalars_outside_the_curve_order_are_refused(): void {
        $this->assertNull(EVC_Push_Crypto::private_key(str_repeat("\0", 32)));
        $this->assertNull(EVC_Push_Crypto::private_key(hex2bin(EVC_Push_Crypto::CURVE_ORDER)), 'n itself');
        $this->assertNull(EVC_Push_Crypto::private_key(str_repeat("\xff", 32)));
        $this->assertNull(EVC_Push_Crypto::private_key(str_repeat("\x01", 31)));
        $this->assertNotNull(EVC_Push_Crypto::private_key(str_repeat("\0", 31) . "\x01"), '1 is a valid scalar');
        $below = hex2bin(EVC_Push_Crypto::CURVE_ORDER);
        $below[31] = chr(ord($below[31]) - 1);
        $this->assertNotNull(EVC_Push_Crypto::private_key($below), 'n - 1 is a valid scalar');
    }

    public function test_base64url_is_strict(): void {
        $this->assertSame("\xfb\xff", EVC_Push_Crypto::b64u_decode('-_8'));
        $this->assertSame("\xfb\xff", EVC_Push_Crypto::b64u_decode('-_8='));
        $this->assertSame('-_8', EVC_Push_Crypto::b64u_encode("\xfb\xff"));
        foreach (array('', '+/8', 'ab cd', 'abc===', 'a', '-_9', 'ab=c', null, 12) as $bad) {
            $this->assertNull(EVC_Push_Crypto::b64u_decode($bad), var_export($bad, true));
        }
    }

    /** What a browser does with the body (RFC 8291 + RFC 8188), for the tests only. */
    private static function decrypt(string $body, string $ua_private, string $auth): ?string {
        $salt = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $as_public = substr($body, 21, $idlen);
        $rest = substr($body, 21 + $idlen);
        $ua = EVC_Push_Crypto::private_key($ua_private);
        $ecdh = openssl_pkey_derive(EVC_Push_Crypto::public_key($as_public), $ua, 0);
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . EVC_Push_Crypto::point_of($ua) . $as_public, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($rest, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rest, -16));
        if (!is_string($plain) || substr($plain, -1) !== "\x02") {
            return null;
        }
        return substr($plain, 0, -1);
    }
}
