<?php

use PHPUnit\Framework\TestCase;

/**
 * STAGE-08 round 12 (owner decision D26 part 2): the subscription values the server accepts (SSRF allowlist of push
 * services, https only), the coarse labels it keeps, and the notification text rules.
 */
final class PushEndpointTest extends TestCase {
    const FCM = 'https://fcm.googleapis.com/fcm/send/dXJ1a2V5OmFiYzEyMw:APA91bHPRgkF3JUikC4ENAHEeMrd41Zxv3hVZjC9KtT8OvPVGJ-hQMRKRrZuJAEcl7B338qju59zJMjw2DELjzEvxwYv7hH5Ynpc1ODQ0aT4U4OFEeco8ohsN5PjL1iC2dNtk2BAokeMCg2ZXKqpc8FXKmhX94kIxQ';
    const APPLE = 'https://web.push.apple.com/QGuQyavXutnMH5NhCzQHvOpY8p2vY-q1C3vTyXK-cY6PoUL3gmd8-L1zaqwqsNbmlj3dCqlB7aaVIZx3cQJ0AA';
    const MOZILLA = 'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABgL1xyz_-0123456789';
    const WINDOWS = 'https://wns2-par02p.notify.windows.com/w/?token=BQYAAABcdef%2bghi%3d';

    public function test_the_four_push_services_are_accepted_with_their_labels(): void {
        foreach (array(self::FCM => 'fcm', self::APPLE => 'apple', self::MOZILLA => 'mozilla', self::WINDOWS => 'windows', 'https://fcm.googleapis.com:443/wp/abc' => 'fcm') as $url => $service) {
            $this->assertNull(EVC_Push_Endpoint::problem($url), $url);
            $this->assertSame($service, EVC_Push_Endpoint::service($url), $url);
        }
        $this->assertSame('https://fcm.googleapis.com', EVC_Push_Endpoint::audience(self::FCM));
        $this->assertSame('https://wns2-par02p.notify.windows.com', EVC_Push_Endpoint::audience(self::WINDOWS));
        $this->assertSame(hash('sha256', self::APPLE), EVC_Push_Endpoint::hash(self::APPLE));
    }

    public function test_anything_else_is_refused_so_the_server_never_calls_arbitrary_addresses(): void {
        $refused = array(
            'http://fcm.googleapis.com/fcm/send/abc' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://evil.example/fcm/send/abc' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://fcm.googleapis.com.evil.example/x' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://evilfcm.googleapis.com/x' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://a.fcm.googleapis.com/x' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://googleapis.com/x' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://push.apple.com/x' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://a.b.notify.windows.com/w/?token=1' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://notify.windows.com/w/?token=1' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://-x.notify.windows.com/w/?token=1' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://127.0.0.1/x' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://localhost/fcm/send/x' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://169.254.169.254/latest' => EVC_Push_Endpoint::PROBLEM_HOST,
            'https://FCM.googleapis.com/fcm/send/abc' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://fcm.googleapis.com:8443/fcm/send/abc' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://user:pw@fcm.googleapis.com/fcm/send/abc' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://evil.example@fcm.googleapis.com/x' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://fcm.googleapis.com@evil.example/x' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://fcm.googleapis.com/' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://fcm.googleapis.com' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://fcm.googleapis.com/x#frag' => EVC_Push_Endpoint::PROBLEM_CHARACTERS,
            "https://fcm.googleapis.com/x\\y" => EVC_Push_Endpoint::PROBLEM_CHARACTERS,
            'https://fcm.googleapis.com/a b' => EVC_Push_Endpoint::PROBLEM_CHARACTERS,
            "https://fcm.googleapis.com/a\nb" => EVC_Push_Endpoint::PROBLEM_CHARACTERS,
            "https://fcm.googleapis.com/\u{3b1}" => EVC_Push_Endpoint::PROBLEM_CHARACTERS,
            'ftp://fcm.googleapis.com/x/y/z' => EVC_Push_Endpoint::PROBLEM_FORM,
            'https://x' => EVC_Push_Endpoint::PROBLEM_LENGTH,
            'https://fcm.googleapis.com/' . str_repeat('a', 1000) => EVC_Push_Endpoint::PROBLEM_LENGTH,
        );
        foreach ($refused as $url => $problem) {
            $this->assertSame($problem, EVC_Push_Endpoint::problem($url), $url);
            $this->assertNull(EVC_Push_Endpoint::service($url), $url);
        }
        $this->assertSame(EVC_Push_Endpoint::PROBLEM_TYPE, EVC_Push_Endpoint::problem(array(self::FCM)));
        $this->assertSame(EVC_Push_Endpoint::PROBLEM_TYPE, EVC_Push_Endpoint::problem(null));
        $this->assertNull(EVC_Push_Endpoint::problem('https://fcm.googleapis.com/' . str_repeat('a', 1024 - 27)), 'exactly 1024 characters');
    }

    public function test_subscription_keys_must_be_a_curve_point_and_a_16_byte_secret(): void {
        $point = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
        $this->assertSame(65, strlen((string) EVC_Push_Endpoint::p256dh($point)));
        $this->assertSame(65, strlen((string) EVC_Push_Endpoint::p256dh($point . '=')), 'padding tolerated');
        $off = EVC_Push_Crypto::b64u_decode($point);
        $off[40] = chr(ord($off[40]) ^ 1);
        $this->assertNull(EVC_Push_Endpoint::p256dh(EVC_Push_Crypto::b64u_encode($off)));
        $this->assertNull(EVC_Push_Endpoint::p256dh(substr($point, 0, 43)));
        $this->assertNull(EVC_Push_Endpoint::p256dh(str_repeat('A', 101)));
        $this->assertNull(EVC_Push_Endpoint::p256dh(null));
        $this->assertSame(16, strlen((string) EVC_Push_Endpoint::auth('BTBZMqHH6r4Tts7J_aSIgg')));
        $this->assertNull(EVC_Push_Endpoint::auth('BTBZMqHH6r4Tts7J_aSI'));
        $this->assertNull(EVC_Push_Endpoint::auth(EVC_Push_Crypto::b64u_encode(random_bytes(17))));
        $this->assertNull(EVC_Push_Endpoint::auth(12));
    }

    public function test_only_a_coarse_browser_family_is_kept(): void {
        $cases = array(
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36' => 'chrome',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1' => 'safari',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0' => 'edge',
            'Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0' => 'firefox',
            'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36' => 'samsung',
            'curl/8.5' => 'other',
            '' => 'other',
        );
        foreach ($cases as $ua => $family) {
            $this->assertSame($family, EVC_Push_Endpoint::agent_family($ua), $ua);
        }
        $this->assertSame('other', EVC_Push_Endpoint::agent_family(null));
        $this->assertSame(EVC_Push_Endpoint::AGENTS, array('chrome', 'safari', 'firefox', 'edge', 'samsung', 'other'));
    }

    public function test_notification_text_limits_count_characters_and_refuse_hidden_ones(): void {
        $this->assertNull(EVC_Push_Message::problem(str_repeat('α', 50), str_repeat('β', 150)));
        $this->assertSame(EVC_Push_Message::PROBLEM_TITLE_LONG, EVC_Push_Message::problem(str_repeat('α', 51), 'x'));
        $this->assertSame(EVC_Push_Message::PROBLEM_BODY_LONG, EVC_Push_Message::problem('x', str_repeat('β', 151)));
        $this->assertSame(EVC_Push_Message::PROBLEM_TITLE_EMPTY, EVC_Push_Message::problem("  \n ", 'x'));
        $this->assertSame(EVC_Push_Message::PROBLEM_BODY_EMPTY, EVC_Push_Message::problem('x', "\t"));
        foreach (array("a\u{200B}b", "a\u{202E}b", "a\u{0007}b", "a\u{FEFF}b", "\xff\xfe", array('x')) as $bad) {
            $this->assertSame(EVC_Push_Message::PROBLEM_CHARACTERS, EVC_Push_Message::problem($bad, 'x'));
            $this->assertSame(EVC_Push_Message::PROBLEM_CHARACTERS, EVC_Push_Message::problem('x', $bad));
        }
        $this->assertSame('Καλή σας μέρα', EVC_Push_Message::normalise("  Καλή\n\u{00A0}σας \u{2003} μέρα "));
        $this->assertNull(EVC_Push_Message::problem(str_repeat('α', 48) . "\n\n\n", 'x'), 'collapsed before counting');
        $this->assertSame('{"title":"Νέο","body":"Δοκιμή & <b>"}', EVC_Push_Message::payload(' Νέο ', 'Δοκιμή & <b>'));
        try {
            EVC_Push_Message::payload('', 'x');
            $this->fail('empty title accepted');
        } catch (InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
    }
}
