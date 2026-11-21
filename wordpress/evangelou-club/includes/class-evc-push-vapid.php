<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): the application server's identity towards the push services, RFC 8292
 * ("VAPID"): a JSON Web Token signed with ES256 (ECDSA P-256 + SHA-256) and the public key, sent as
 *     Authorization: vapid t=<JWT>, k=<public key, base64url>
 * Pure: no WordPress, no network, no storage; the private key is only ever read from wp-config.php (never stored in a
 * database, never logged, never shown, never sent anywhere but as a signature).
 *
 * Keys (wp-config.php, docs/member-push.md has the one-line command that makes them on the technician's own machine or on
 * the server, so nobody ever sends them to anyone):
 *   EVC_CLUB_PUSH_VAPID_PUBLIC   base64url of the 65-byte uncompressed P-256 point (87 characters)
 *   EVC_CLUB_PUSH_VAPID_PRIVATE  base64url of the 32-byte private scalar (43 characters)
 *   EVC_CLUB_PUSH_SUBJECT        mailto:<shop address> (RFC 8292 section 2.1 "sub"; a push service may write to it)
 * keys_problem() checks all three and that the public key IS the private key's (OpenSSL computes the point of the
 * scalar): any problem and push stays off (fail closed: no control in the member app, no sending).
 *
 * The token: header {"typ":"JWT","alg":"ES256"}; claims {"aud": "<origin of the push resource>", "exp": now + 12 h,
 * "sub": "<subject>"} (RFC 8292 section 2: exp at most 24 hours ahead); signature = the raw 64-byte R || S of RFC 7518
 * section 3.4 (OpenSSL gives DER; converted here).
 */
final class EVC_Push_Vapid {
    const PUBLIC_CONSTANT = 'EVC_CLUB_PUSH_VAPID_PUBLIC';
    const PRIVATE_CONSTANT = 'EVC_CLUB_PUSH_VAPID_PRIVATE';
    const SUBJECT_CONSTANT = 'EVC_CLUB_PUSH_SUBJECT';
    /** RFC 8292 section 2: at most 24 hours; 12 hours keeps a margin for clock differences. */
    const LIFETIME_SECONDS = 43200;
    const MAX_SUBJECT_LENGTH = 200;

    const PROBLEM_OPENSSL = 'openssl_missing';
    const PROBLEM_PUBLIC_MISSING = 'public_missing';
    const PROBLEM_PUBLIC_INVALID = 'public_invalid';
    const PROBLEM_PRIVATE_MISSING = 'private_missing';
    const PROBLEM_PRIVATE_INVALID = 'private_invalid';
    const PROBLEM_PAIR = 'pair_mismatch';
    const PROBLEM_SUBJECT_MISSING = 'subject_missing';
    const PROBLEM_SUBJECT_INVALID = 'subject_invalid';

    /** @return array{public:mixed,private:mixed,subject:mixed} the three wp-config.php values (null when undefined) */
    public static function constants(): array {
        return array(
            'public' => defined(self::PUBLIC_CONSTANT) ? constant(self::PUBLIC_CONSTANT) : null,
            'private' => defined(self::PRIVATE_CONSTANT) ? constant(self::PRIVATE_CONSTANT) : null,
            'subject' => defined(self::SUBJECT_CONSTANT) ? constant(self::SUBJECT_CONSTANT) : null,
        );
    }

    /** Null when the three values are a usable key pair and subject; otherwise the first problem code (never a value). */
    public static function keys_problem($public, $private, $subject): ?string {
        if (!EVC_Push_Crypto::available()) {
            return self::PROBLEM_OPENSSL;
        }
        if ($public === null || $public === '') {
            return self::PROBLEM_PUBLIC_MISSING;
        }
        $point = EVC_Push_Crypto::b64u_decode($public);
        if ($point === null || !EVC_Push_Crypto::is_public_key($point)) {
            return self::PROBLEM_PUBLIC_INVALID;
        }
        if ($private === null || $private === '') {
            return self::PROBLEM_PRIVATE_MISSING;
        }
        $scalar = EVC_Push_Crypto::b64u_decode($private);
        $key = $scalar === null ? null : EVC_Push_Crypto::private_key($scalar);
        if ($key === null) {
            return self::PROBLEM_PRIVATE_INVALID;
        }
        $derived = EVC_Push_Crypto::point_of($key);
        if ($derived === null || !hash_equals($derived, $point)) {
            return self::PROBLEM_PAIR;
        }
        if ($subject === null || $subject === '') {
            return self::PROBLEM_SUBJECT_MISSING;
        }
        return self::is_subject($subject) ? null : self::PROBLEM_SUBJECT_INVALID;
    }

    /** A plain mailto: address of a real domain (no placeholder, no example or local domain, no spaces). */
    public static function is_subject($subject): bool {
        if (!is_string($subject) || strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            return false;
        }
        if (preg_match('/^mailto:[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/D', $subject, $m) !== 1) {
            return false;
        }
        $domain = strtolower(substr($subject, strrpos($subject, '@') + 1));
        foreach (array('example.com', 'example.org', 'example.net', 'localhost', 'invalid', 'test') as $reserved) {
            if ($domain === $reserved || substr($domain, -strlen('.' . $reserved)) === '.' . $reserved) {
                return false;
            }
        }
        return true;
    }

    /**
     * The JWT for one push service origin (e.g. https://fcm.googleapis.com). The caller has already checked the keys
     * (keys_problem() === null).
     * @throws RuntimeException when the key cannot be used or signing fails
     */
    public static function jwt(string $audience, int $now, string $private, string $subject): string {
        $scalar = EVC_Push_Crypto::b64u_decode($private);
        $key = $scalar === null ? null : EVC_Push_Crypto::private_key($scalar);
        if ($key === null) {
            throw new RuntimeException('Unusable VAPID key.');
        }
        $header = EVC_Push_Crypto::b64u_encode((string) json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
        $claims = EVC_Push_Crypto::b64u_encode((string) json_encode(array('aud' => $audience, 'exp' => $now + self::LIFETIME_SECONDS, 'sub' => $subject), JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;
        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed.');
        }
        $raw = self::der_to_raw($der);
        if ($raw === null) {
            throw new RuntimeException('VAPID signature not understood.');
        }
        return $input . '.' . EVC_Push_Crypto::b64u_encode($raw);
    }

    /** The Authorization header value of RFC 8292 section 3. */
    public static function authorization(string $audience, int $now, string $public, string $private, string $subject): string {
        return 'vapid t=' . self::jwt($audience, $now, $private, $subject) . ', k=' . $public;
    }

    /** ECDSA-Sig-Value (DER SEQUENCE of two INTEGERs) -> 64 bytes R || S; null when it is not that. */
    public static function der_to_raw(string $der): ?string {
        $n = strlen($der);
        if ($n < 8 || ord($der[0]) !== 0x30 || ord($der[1]) !== $n - 2) {
            return null;
        }
        $at = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if ($at + 2 > $n || ord($der[$at]) !== 0x02) {
                return null;
            }
            $len = ord($der[$at + 1]);
            if ($len < 1 || $len > 33 || $at + 2 + $len > $n) {
                return null;
            }
            $int = ltrim(substr($der, $at + 2, $len), "\0");
            if (strlen($int) > 32) {
                return null;
            }
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $at += 2 + $len;
        }
        return $at === $n ? $out : null;
    }
}
