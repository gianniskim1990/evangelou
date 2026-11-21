<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): the message encryption of Web Push, RFC 8291 ("Message Encryption for
 * Web Push") over the "aes128gcm" content coding of RFC 8188, written directly on PHP's openssl extension (no library,
 * no gmp / bcmath needed). Pure: no WordPress, no network, no storage. Tested against the example of RFC 8291 section 5
 * and its intermediate values (Appendix A), byte for byte (tests/unit/PushCryptoTest.php).
 *
 *   ecdh_secret = ECDH(as_private, ua_public)                       P-256, openssl_pkey_derive()
 *   PRK_key     = HKDF-Extract(auth_secret, ecdh_secret)
 *   IKM         = HKDF-Expand(PRK_key, "WebPush: info" || 0x00 || ua_public || as_public, 32)
 *   PRK         = HKDF-Extract(salt, IKM)
 *   CEK         = HKDF-Expand(PRK, "Content-Encoding: aes128gcm" || 0x00, 16)
 *   NONCE       = HKDF-Expand(PRK, "Content-Encoding: nonce" || 0x00, 12)
 *   body        = salt(16) || rs(4, 4096) || idlen(1, 65) || as_public(65) || AES-128-GCM(CEK, NONCE, plaintext || 0x02) || tag(16)
 *
 * One record only (RFC 8291 section 4), the padding delimiter 0x02 and no further padding. A new random salt and a new
 * sender key pair for every message; the test seams $salt and $sender exist only so the RFC example can be reproduced.
 * The subscriber's public key is checked to be a point of P-256 (OpenSSL refuses a point off the curve when the key is
 * parsed), as RFC 8291 section 6 requires. Nothing here is ever logged.
 */
final class EVC_Push_Crypto {
    /** RFC 8291 section 4 / RFC 8188: the record size written in the header. */
    const RECORD_SIZE = 4096;
    /** 4096 - 86 (header) - 1 (delimiter) - 16 (tag): the largest plaintext a push service must accept. */
    const MAX_PLAINTEXT = 3993;
    const POINT_LENGTH = 65;
    const AUTH_LENGTH = 16;
    const SALT_LENGTH = 16;
    /** DER of a P-256 SubjectPublicKeyInfo up to the 65-byte uncompressed point (id-ecPublicKey, prime256v1). */
    const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    /** DER of a SEC 1 ECPrivateKey (version 1, 32-byte scalar) around the scalar; OpenSSL computes the public point. */
    const SEC1_PREFIX = '30310201010420';
    const SEC1_SUFFIX = 'a00a06082a8648ce3d030107';
    /** The order n of P-256: a private scalar must lie in [1, n - 1]. */
    const CURVE_ORDER = 'ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551';

    /** base64url without padding (RFC 4648 section 5), as browsers and RFC 8292 use it. */
    public static function b64u_encode(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Strict base64url: only the URL-safe alphabet, at most two '=' at the end; null for anything else. */
    public static function b64u_decode($text): ?string {
        if (!is_string($text) || $text === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $text) !== 1) {
            return null;
        }
        $text = rtrim($text, '=');
        if (strlen($text) % 4 === 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if (!is_string($bytes) || self::b64u_encode($bytes) !== $text) {
            return null; // non-canonical trailing bits
        }
        return $bytes;
    }

    public static function available(): bool {
        return function_exists('openssl_pkey_derive') && function_exists('openssl_encrypt') && function_exists('hash_hkdf')
            && in_array('aes-128-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
    }

    /** The OpenSSL public key of an uncompressed P-256 point, or null (wrong form, or not a point of the curve). */
    public static function public_key(string $point) {
        if (strlen($point) !== self::POINT_LENGTH || $point[0] !== "\x04") {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX) . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        return $key === false ? null : $key;
    }

    public static function is_public_key(string $point): bool {
        return self::public_key($point) !== null;
    }

    /** The OpenSSL private key of a raw 32-byte P-256 scalar, or null (wrong length, 0 or not below the order). */
    public static function private_key(string $scalar) {
        if (strlen($scalar) !== 32 || $scalar === str_repeat("\0", 32) || strcmp($scalar, hex2bin(self::CURVE_ORDER)) >= 0) {
            return null;
        }
        $der = hex2bin(self::SEC1_PREFIX) . $scalar . hex2bin(self::SEC1_SUFFIX);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        $key = @openssl_pkey_get_private($pem);
        return $key === false ? null : $key;
    }

    /** The uncompressed public point of an OpenSSL EC key, or null. */
    public static function point_of($key): ?string {
        $details = $key === null || $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            return null;
        }
        $x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return strlen($x) === 32 && strlen($y) === 32 ? "\x04" . $x . $y : null;
    }

    /**
     * The aes128gcm body of one push message for one subscription.
     *
     * @param string      $plaintext   at most MAX_PLAINTEXT bytes
     * @param string      $ua_public   the subscription's p256dh key (65-byte uncompressed point)
     * @param string      $auth_secret the subscription's auth secret (16 bytes)
     * @param string|null $salt        TEST seam (16 bytes); null = random
     * @param string|null $sender      TEST seam: the sender's private scalar (32 bytes); null = a new key pair
     * @throws InvalidArgumentException for a bad key, secret, salt or size; RuntimeException when OpenSSL fails
     */
    public static function encrypt(string $plaintext, string $ua_public, string $auth_secret, ?string $salt = null, ?string $sender = null): string {
        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new InvalidArgumentException('Push payload too large.');
        }
        if (strlen($auth_secret) !== self::AUTH_LENGTH) {
            throw new InvalidArgumentException('Bad auth secret.');
        }
        $peer = self::public_key($ua_public);
        if ($peer === null) {
            throw new InvalidArgumentException('Bad subscriber key.');
        }
        $salt = $salt ?? random_bytes(self::SALT_LENGTH);
        if (strlen($salt) !== self::SALT_LENGTH) {
            throw new InvalidArgumentException('Bad salt.');
        }
        if ($sender === null) {
            $local = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        } else {
            $local = self::private_key($sender);
        }
        $as_public = $local ? self::point_of($local) : null;
        if (!$local || $as_public === null) {
            throw new RuntimeException('No sender key.');
        }
        $ecdh = openssl_pkey_derive($peer, $local, 0);
        if (!is_string($ecdh) || strlen($ecdh) !== 32) {
            throw new RuntimeException('Key agreement failed.');
        }
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $ua_public . $as_public, $auth_secret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            throw new RuntimeException('Encryption failed.');
        }
        return $salt . pack('N', self::RECORD_SIZE) . chr(self::POINT_LENGTH) . $as_public . $cipher . $tag;
    }
}
