<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): the text of one notification. Pure.
 *
 * Title at most 50 characters, body at most 150 (counted in Unicode characters, never cut: a longer text is refused),
 * both required. White space (incl. new lines) is collapsed to single spaces and trimmed; control and format characters
 * (e.g. U+200B, U+202E) are refused, so what the administrator previews is what the phone shows. Invalid UTF-8 is refused.
 *
 * The payload the member's phone decrypts is JSON {"title": ..., "body": ...} and nothing else: no URL, no icon, no
 * action. The service worker always opens the member page itself (app/src/customer/pwa/swPush.ts), never an address
 * from a message.
 */
final class EVC_Push_Message {
    const MAX_TITLE = 50;
    const MAX_BODY = 150;

    const PROBLEM_TITLE_EMPTY = 'title_empty';
    const PROBLEM_TITLE_LONG = 'title_too_long';
    const PROBLEM_BODY_EMPTY = 'body_empty';
    const PROBLEM_BODY_LONG = 'body_too_long';
    const PROBLEM_CHARACTERS = 'bad_characters';

    /** The text as it will be sent (collapsed, trimmed), or null for text that is not valid UTF-8 / has control or format characters. */
    public static function normalise($text): ?string {
        if (!is_string($text) || preg_match('//u', $text) !== 1) {
            return null;
        }
        $text = trim((string) preg_replace('/[\s\p{Zs}\x{2028}\x{2029}]+/u', ' ', $text));
        if (preg_match('/[\p{Cc}\p{Cf}\p{Co}\p{Cs}]/u', $text) === 1) {
            return null;
        }
        return $text;
    }

    public static function length(string $text): int {
        return preg_match_all('/./us', $text);
    }

    /** Null when title and body may be sent; otherwise the first problem code. */
    public static function problem($title, $body): ?string {
        $t = self::normalise($title);
        $b = self::normalise($body);
        if ($t === null || $b === null) {
            return self::PROBLEM_CHARACTERS;
        }
        if ($t === '') {
            return self::PROBLEM_TITLE_EMPTY;
        }
        if (self::length($t) > self::MAX_TITLE) {
            return self::PROBLEM_TITLE_LONG;
        }
        if ($b === '') {
            return self::PROBLEM_BODY_EMPTY;
        }
        return self::length($b) > self::MAX_BODY ? self::PROBLEM_BODY_LONG : null;
    }

    /**
     * The JSON the phone decrypts. The caller has checked problem() === null.
     * @throws InvalidArgumentException for text that problem() refuses
     */
    public static function payload($title, $body): string {
        if (self::problem($title, $body) !== null) {
            throw new InvalidArgumentException('Refused notification text.');
        }
        return (string) json_encode(array('title' => self::normalise($title), 'body' => self::normalise($body)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
