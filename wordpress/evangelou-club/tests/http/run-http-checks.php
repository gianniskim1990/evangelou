<?php
/**
 * CI-ONLY real HTTP checks against a disposable WordPress served by `php -S`
 * with the PACKAGED plugin. Uses real logins (wp-login.php), real cookies,
 * real response headers.
 *
 *   php run-http-checks.php on    (server started with EVC_HTTP_FLAG=on)
 *   php run-http-checks.php off   (server started with EVC_HTTP_FLAG=off)
 *   php run-http-checks.php harness (flag on + the CI-only v2 harness mu-plugin; UI-04H)
 */
$mode = isset($argv[1]) ? $argv[1] : '';
$base = rtrim(getenv('EVC_HTTP_BASE') ?: 'http://127.0.0.1:8089', '/');
$password = (string) getenv('EVC_HTTP_PASSWORD');
$jars = sys_get_temp_dir() . '/evc-http-jars-' . bin2hex(random_bytes(4));
mkdir($jars);
$results = array();

function check(string $name, bool $ok, string $detail = ''): void {
    global $results;
    $results[] = array($name, $ok);
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' :: ' . $detail) . "\n";
}

/** @return array{status:int,headers:array<string,string[]>,body:string} */
function req(string $method, string $url, ?string $jar = null, array $headers = array(), ?array $form = null): array {
    global $base;
    $ch = curl_init(strpos($url, 'http') === 0 ? $url : $base . $url);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    );
    if ($jar !== null) {
        $opts[CURLOPT_COOKIEFILE] = $jar;
        $opts[CURLOPT_COOKIEJAR] = $jar;
    }
    if ($form !== null) {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    unset($ch); // flush the cookie jar
    $parsed = array();
    foreach (preg_split('/\r\n/', substr($raw, 0, $size)) as $line) {
        if (strpos($line, ':') !== false) {
            list($k, $v) = explode(':', $line, 2);
            $parsed[strtolower(trim($k))][] = trim($v);
        }
    }
    return array('status' => $status, 'headers' => $parsed, 'body' => substr($raw, $size));
}

function header_value(array $r, string $name): string {
    $n = strtolower($name);
    return isset($r['headers'][$n]) ? implode(', ', $r['headers'][$n]) : '';
}

function login(string $user, string $jar): array {
    global $password, $base;
    req('GET', '/wp-login.php', $jar);
    return req('POST', '/wp-login.php', $jar, array('Content-Type: application/x-www-form-urlencoded'), array(
        'log' => $user,
        'pwd' => $password,
        'wp-submit' => 'Log In',
        'redirect_to' => $base . '/club-admin/',
    ));
}

function config_of(string $body): ?array {
    if (!preg_match('#<script type="application/json" id="evc-staff-config">(.*?)</script>#s', $body, $m)) {
        return null;
    }
    $c = json_decode($m[1], true);
    return is_array($c) ? $c : null;
}

/** No staff bootstrap and no staff bundle (themes may legitimately load their own module scripts). */
function no_bootstrap(array $r): bool {
    return strpos($r['body'], 'evc-staff-config') === false && strpos($r['body'], 'staff-app/assets/') === false;
}

if ($mode === 'on') {
    // ---- anonymous ------------------------------------------------------
    $anon = req('GET', '/club-admin/');
    check('anonymous /club-admin/ -> 302 to wp-login', $anon['status'] === 302 && strpos(header_value($anon, 'location'), 'wp-login.php') !== false, (string) $anon['status']);
    check('anonymous response has no bootstrap and is no-store', no_bootstrap($anon) && strpos(header_value($anon, 'cache-control'), 'no-store') !== false);

    // ---- restricted staff ----------------------------------------------
    $jar = "$jars/staff.txt";
    $login = login('evc_http_staff', $jar);
    check('staff login redirects to /club-admin/', $login['status'] === 302 && rtrim(header_value($login, 'location'), '/') === $base . '/club-admin', header_value($login, 'location'));
    $cookies = implode("\n", isset($login['headers']['set-cookie']) ? $login['headers']['set-cookie'] : array());
    check('logged_in auth cookie is HttpOnly', (bool) preg_match('/wordpress_logged_in_[^=]+=[^;]+;[^\n]*HttpOnly/i', $cookies));

    $shell = req('GET', '/club-admin/', $jar);
    $csp = header_value($shell, 'content-security-policy');
    check('staff /club-admin/ -> 200 shell', $shell['status'] === 200, (string) $shell['status']);
    check('shell CSP: self-only scripts, no unsafe-inline/eval, no framing', strpos($csp, "script-src 'self'") !== false && strpos($csp, 'unsafe-') === false && strpos($csp, "frame-ancestors 'none'") !== false, $csp);
    check('shell headers: no-store, nosniff, no-referrer, DENY',
        strpos(header_value($shell, 'cache-control'), 'no-store') !== false
        && header_value($shell, 'x-content-type-options') === 'nosniff'
        && header_value($shell, 'referrer-policy') === 'no-referrer'
        && header_value($shell, 'x-frame-options') === 'DENY');
    $config = config_of($shell['body']);
    check('bootstrap JSON present with a wp_rest nonce and same-origin REST base',
        is_array($config) && preg_match('/^[0-9a-f]{10}$/', (string) $config['nonce']) === 1 && strpos((string) $config['restBase'], $base . '/') === 0);
    $nonce = is_array($config) ? (string) $config['nonce'] : '';

    preg_match('#<script type="module" src="([^"]+)"#', $shell['body'], $js);
    preg_match('#<link rel="stylesheet" href="([^"]+)"#', $shell['body'], $css);
    $jsr = isset($js[1]) ? req('GET', html_entity_decode($js[1])) : array('status' => 0, 'headers' => array(), 'body' => '');
    $cssr = isset($css[1]) ? req('GET', html_entity_decode($css[1])) : array('status' => 0, 'headers' => array(), 'body' => '');
    check('hashed JS asset served by WordPress origin', $jsr['status'] === 200 && strpos(header_value($jsr, 'content-type'), 'javascript') !== false, (string) $jsr['status']);
    check('hashed CSS asset served', $cssr['status'] === 200 && strpos(header_value($cssr, 'content-type'), 'css') !== false, (string) $cssr['status']);
    check('static bundle contains no nonce', $nonce !== '' && strpos($jsr['body'], $nonce) === false);

    // ---- REST with real cookies + nonce --------------------------------
    $status = req('GET', '/wp-json/evangelou-club/v1/session', $jar, array('X-WP-Nonce: ' . $nonce));
    $sd = json_decode($status['body'], true);
    check('GET /session with nonce -> 200 authenticated, features off', $status['status'] === 200 && !empty($sd['authenticated']) && $sd['features'] === array('qr_lookup' => false, 'phone_lookup' => false, 'coffee_redemption' => false, 'history' => false), $status['body']);
    check('WordPress refreshes the nonce via X-WP-Nonce header', preg_match('/^[0-9a-f]{10}$/', header_value($status, 'x-wp-nonce')) === 1);

    $foreign = req('GET', '/wp-json/evangelou-club/v1/session', $jar, array('X-WP-Nonce: ' . $nonce, 'Origin: https://evil.example'));
    check('REAL CORS: Club namespace sends no Access-Control-Allow-Origin', header_value($foreign, 'access-control-allow-origin') === '' && header_value($foreign, 'access-control-allow-credentials') === '', header_value($foreign, 'access-control-allow-origin'));
    $core = req('GET', '/wp-json/', null, array('Origin: https://evil.example'));
    check('core REST outside the namespace keeps its CORS behaviour (scoped change)', header_value($core, 'access-control-allow-origin') !== '', 'core sent no ACAO');

    $nononce = req('GET', '/wp-json/evangelou-club/v1/session', $jar);
    $nd = json_decode($nononce['body'], true);
    check('cookie without nonce -> 401 envelope', $nononce['status'] === 401 && isset($nd['error']['code']) && $nd['error']['code'] === 'unauthorized', $nononce['body']);

    // Redeem with a real JSON body: must stay fail-closed (no production backend).
    $ch = curl_init($base . '/wp-json/evangelou-club/v1/members/mem_0123456789abcdef0123456789abcdef/benefits/free_coffee/redeem');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => array('X-WP-Nonce: ' . $nonce, 'Content-Type: application/json'),
        CURLOPT_POSTFIELDS => json_encode(array('request_id' => '3fa85f64-5717-4562-b3fc-2c963f66afa6', 'coffee_code' => 'espresso'))));
    $rb = (string) curl_exec($ch);
    $rs = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $rd = json_decode($rb, true);
    check('redeem stays fail-closed: 503 server_error', $rs === 503 && isset($rd['error']['code']) && $rd['error']['code'] === 'server_error', "$rs $rb");

    // ---- logout via /session/end; old cookie replay must fail -----------
    $saved = "$jars/staff-before-end.txt";
    copy($jar, $saved);
    $end = req('POST', '/wp-json/evangelou-club/v1/session/end', $jar, array('X-WP-Nonce: ' . $nonce));
    check('POST /session/end -> 200', $end['status'] === 200, $end['body']);
    $replay = req('GET', '/club-admin/', $saved);
    check('replaying the pre-logout cookie -> redirected to login (session destroyed server-side)', $replay['status'] === 302 && strpos(header_value($replay, 'location'), 'wp-login.php') !== false && no_bootstrap($replay), (string) $replay['status']);
    $after = req('GET', '/wp-json/evangelou-club/v1/session', $saved, array('X-WP-Nonce: ' . $nonce));
    check('old cookie + old nonce on REST -> 401', $after['status'] === 401, (string) $after['status']);

    // ---- reauth login URL cannot be bypassed by a still-valid cookie -----
    $jar2 = "$jars/staff2.txt";
    login('evc_http_staff', $jar2);
    $shell2 = config_of(req('GET', '/club-admin/', $jar2)['body']);
    $reauth = req('GET', is_array($shell2) ? $shell2['loginUrl'] : '/wp-login.php?reauth=1', $jar2);
    check('wp-login.php?reauth=1 shows the login form even with a valid cookie', $reauth['status'] === 200 && strpos($reauth['body'], 'user_login') !== false, (string) $reauth['status']);

    // ---- other accounts are denied without bootstrap ---------------------
    foreach (array('evc_http_admin' => 'administrator', 'evc_http_editor' => 'editor', 'evc_http_dual' => 'staff + administrator', 'evc_http_direct' => 'staff + direct manage_options') as $user => $label) {
        $j = "$jars/$user.txt";
        login($user, $j);
        $r = req('GET', '/club-admin/', $j);
        check("$label -> 403 without bootstrap", $r['status'] === 403 && no_bootstrap($r), (string) $r['status']);
    }

    // ---- no global header regression ------------------------------------
    $home = req('GET', '/');
    check('ordinary pages do not get the staff CSP/headers', strpos(header_value($home, 'content-security-policy'), "script-src 'self'") === false && header_value($home, 'x-robots-tag') === '');
} elseif ($mode === 'harness') {
    // UI-04H: the TEST-ONLY v2 harness mu-plugin is active on this CI site only.
    // Every raw reply is captured for the TypeScript classifier/parsers
    // (app/scripts/check-http-captures.mjs): real WordPress bytes, real TS code.
    $captures = array();
    $capture = function (string $name, string $surface, array $r, array $expect, array $sent = array()) use (&$captures): void {
        $captures[] = array('name' => $name, 'surface' => $surface, 'status' => $r['status'], 'content_type' => header_value($r, 'content-type'), 'body' => $r['body'], 'sent' => (object) $sent, 'expect' => $expect);
    };
    $v2 = '/wp-json/evangelou-club/v2';
    $member = 'mem_0123456789abcdef0123456789abcdef';
    $qr = 'evc_abababababababababababababababababababababababab';
    $uuid = function (): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    };
    $jreq = function (string $method, string $path, ?string $jar, ?string $nonce, ?array $body = null, array $extra = array()) use ($base): array {
        $headers = $extra;
        if ($nonce !== null) {
            $headers[] = 'X-WP-Nonce: ' . $nonce;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($base . $path);
        $opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30);
        if ($jar !== null) {
            $opts[CURLOPT_COOKIEFILE] = $jar;
            $opts[CURLOPT_COOKIEJAR] = $jar;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        unset($ch);
        $parsed = array();
        foreach (preg_split('/\r\n/', substr($raw, 0, $size)) as $line) {
            if (strpos($line, ':') !== false) {
                list($k, $v) = explode(':', $line, 2);
                $parsed[strtolower(trim($k))][] = trim($v);
            }
        }
        return array('status' => $status, 'headers' => $parsed, 'body' => substr($raw, $size));
    };
    $json = function (array $r) {
        return json_decode($r['body'], true);
    };
    $is_envelope = function (array $r, int $status, string $code) use ($json): bool {
        $d = $json($r);
        return $r['status'] === $status && is_array($d) && array_keys($d) === array('error') && isset($d['error']['code']) && $d['error']['code'] === $code && !isset($d['error']['details']);
    };
    $field = function (array $r, string $key) use ($json) {
        $d = $json($r);
        return is_array($d) && array_key_exists($key, $d) ? $d[$key] : null;
    };
    $order = function (string $rid, ?string $ref): array {
        $o = array('request_id' => $rid, 'catalogue_version' => 1, 'coffee_code' => 'espresso', 'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array()));
        if ($ref !== null) {
            $o['lookup_ref'] = $ref;
        }
        return $o;
    };

    $jar = "$jars/harness-staff.txt";
    login('evc_http_staff', $jar);
    $config = config_of(req('GET', '/club-admin/', $jar)['body']);
    $nonce = is_array($config) ? (string) $config['nonce'] : '';
    check('harness: staff session with a wp_rest nonce', $nonce !== '');

    // ---- valid domain replies ------------------------------------------------
    $l = $jreq('POST', "$v2/lookup/qr", $jar, $nonce, array('qr_token' => $qr));
    $ld = $json($l);
    check('harness: QR lookup -> 200 identity-only domain body', $l['status'] === 200 && is_array($ld) && $ld['result'] === 'found' && array_keys($ld['member']) === array('member_public_id', 'lookup_ref', 'lookup_ref_expires_at_utc'), $l['body']);
    check('harness: domain reply carries the security headers', strpos(header_value($l, 'cache-control'), 'no-store') !== false && header_value($l, 'x-content-type-options') === 'nosniff' && header_value($l, 'access-control-allow-origin') === '', header_value($l, 'cache-control'));
    $capture('lookup qr found', 'lookup', $l, array('kind' => 'domain', 'parsed' => 'found'));
    $ref = is_array($ld) && isset($ld['member']['lookup_ref']) ? $ld['member']['lookup_ref'] : '';

    $rid = $uuid();
    $sent = array('request_id' => $rid, 'member_id' => $member);
    $u = $jreq('POST', "$v2/members/$member/redemptions", $jar, $nonce, $order($rid, $ref), array('X-EVC-Harness-Fault: commit_unknown'));
    check('harness: unknown commit -> 503 outcome_unknown with the SAME id to retry', $u['status'] === 503 && $field($u, 'outcome') === 'outcome_unknown' && $field($u, 'retry_request_id') === $rid && $field($u, 'error') === null, $u['body']);
    $capture('redemption outcome_unknown 503', 'redemption', $u, array('kind' => 'domain', 'parsed' => 'unresolved'), $sent);

    $r1 = $jreq('POST', "$v2/members/$member/redemptions", $jar, $nonce, $order($rid, $ref));
    check('harness: same-id retry after the unknown commit -> 200 replayed, never prepare', $r1['status'] === 200 && $field($r1, 'outcome') === 'replayed' && $field($r1, 'instruction') === 'verify_handoff', $r1['body']);
    $capture('redemption replay after unknown commit', 'redemption', $r1, array('kind' => 'domain', 'parsed' => 'replayed'), $sent);
    $r2 = $jreq('POST', "$v2/members/$member/redemptions", $jar, $nonce, $order($rid, $ref));
    check('harness: duplicate redemption -> 200 replayed again', $r2['status'] === 200 && $field($r2, 'outcome') === 'replayed', $r2['body']);
    $capture('redemption duplicate', 'redemption', $r2, array('kind' => 'domain', 'parsed' => 'replayed'), $sent);

    $rid2 = $uuid();
    $second = $jreq('POST', "$v2/members/$member/redemptions", $jar, $nonce, $order($rid2, $ref));
    check('harness: second coffee the same day -> 409 benefit_already_redeemed', $second['status'] === 409 && $field($second, 'outcome') === 'benefit_already_redeemed', $second['body']);
    $capture('redemption second coffee', 'redemption', $second, array('kind' => 'domain', 'parsed' => 'refused'), array('request_id' => $rid2, 'member_id' => $member));

    $bad = $order($uuid(), $ref);
    unset($bad['coffee_code']);
    $invalid = $jreq('POST', "$v2/members/$member/redemptions", $jar, $nonce, $bad);
    check('harness: server-side validation failure -> 400 domain invalid_request', $invalid['status'] === 400 && $field($invalid, 'outcome') === 'invalid_request', $invalid['body']);
    $capture('redemption invalid request', 'redemption', $invalid, array('kind' => 'domain', 'parsed' => 'refused'), array('request_id' => $bad['request_id'], 'member_id' => $member));

    $hid = $uuid();
    $h = $jreq('POST', "$v2/members/$member/redemptions/$rid/handoff", $jar, $nonce, array('handoff_request_id' => $hid, 'claim' => 'handed_now'));
    check('harness: recorded handoff -> 201 handoff_confirmed', $h['status'] === 201 && $field($h, 'outcome') === 'handoff_confirmed', $h['body']);
    $capture('handoff confirmed', 'handoff', $h, array('kind' => 'domain', 'parsed' => 'recorded'), array('handoff_request_id' => $hid, 'redemption_request_id' => $rid, 'claim' => 'handed_now'));

    $s = $jreq('GET', "$v2/members/$member/redemptions/$rid", $jar, $nonce);
    $sd = $json($s);
    check('harness: status -> 200 found, confirmed', $s['status'] === 200 && is_array($sd) && $sd['data']['fulfillment'] === 'confirmed', $s['body']);
    $capture('status found', 'status', $s, array('kind' => 'domain', 'parsed' => 'found'), array('redemption_request_id' => $rid, 'member_id' => $member));

    $usual = $jreq('POST', "$v2/members/$member/usual-coffee", $jar, $nonce, array('lookup_ref' => $ref));
    check('harness: usual coffee -> 200, never a confirmed order', $usual['status'] === 200 && $field($usual, 'confirmed_order') === false, $usual['body']);
    $capture('usual coffee', 'usual', $usual, array('kind' => 'domain', 'parsed' => 'no_suggestion'));

    // ---- transport failures (v1 envelope) -----------------------------------
    $tamper = $jreq('POST', "$v2/members/$member/redemptions", $jar, $nonce, $order($rid, $ref), array('X-EVC-Harness-Fault: tamper'));
    check('harness: body changed after sealing -> 500 envelope, no "prepare"', $is_envelope($tamper, 500, 'server_error') && strpos($tamper['body'], 'prepare') === false, $tamper['body']);
    $capture('tampered replay', 'redemption', $tamper, array('kind' => 'transport', 'failure' => 'unavailable'), $sent);

    $noproof = $jreq('POST', "$v2/members/$member/redemptions", $jar, $nonce, $order($uuid(), null));
    check('harness: redemption without lookup proof -> 403 envelope', $is_envelope($noproof, 403, 'forbidden'), $noproof['body']);
    $capture('redemption without proof', 'redemption', $noproof, array('kind' => 'transport', 'failure' => 'forbidden'));

    $nn = $jreq('POST', "$v2/lookup/qr", $jar, null, array('qr_token' => $qr));
    check('harness: cookie without nonce -> 401 envelope', $is_envelope($nn, 401, 'unauthorized'), $nn['body']);
    $capture('no nonce', 'lookup', $nn, array('kind' => 'transport', 'failure' => 'unauthorized'));
    $bn = $jreq('POST', "$v2/lookup/qr", $jar, 'not-a-valid-nonce', array('qr_token' => $qr));
    check('harness: invalid nonce -> 401 envelope (core rest_cookie_invalid_nonce never leaks)', $is_envelope($bn, 401, 'unauthorized') && strpos($bn['body'], 'rest_cookie') === false, $bn['body']);
    $capture('invalid nonce', 'lookup', $bn, array('kind' => 'transport', 'failure' => 'unauthorized'));
    $anon = $jreq('POST', "$v2/members/$member/redemptions", null, null, $order($uuid(), $ref));
    check('harness: anonymous -> 401 envelope', $is_envelope($anon, 401, 'unauthorized'), $anon['body']);
    $capture('anonymous redemption', 'redemption', $anon, array('kind' => 'transport', 'failure' => 'unauthorized'));

    $rl = $jreq('POST', "$v2/lookup/qr", $jar, $nonce, array('qr_token' => $qr), array('X-EVC-Harness-Fault: rate_limited'));
    check('harness: rate-limit denial -> 429 envelope', $is_envelope($rl, 429, 'rate_limited'), $rl['body']);
    $capture('rate limited', 'lookup', $rl, array('kind' => 'transport', 'failure' => 'rate_limited'));

    $err = $jreq('GET', "$v2/harness/wp-error", $jar, $nonce);
    check('harness: unexpected WP_Error -> 500 envelope without internals', $is_envelope($err, 500, 'server_error') && strpos($err['body'], 'SQLSTATE') === false && strpos($err['body'], 'hunter2') === false && strpos($err['body'], 'evc_internal') === false, $err['body']);
    $capture('unexpected WP_Error', 'redemption', $err, array('kind' => 'transport', 'failure' => 'unavailable'));
    $forged = $jreq('GET', "$v2/harness/forged", $jar, $nonce);
    check('harness: forged domain-shaped body (key set only) -> 500 envelope', $is_envelope($forged, 500, 'server_error'), $forged['body']);
    $capture('forged key set', 'redemption', $forged, array('kind' => 'transport', 'failure' => 'unavailable'));
    $fu = $jreq('GET', "$v2/harness/forged-unknown", $jar, $nonce);
    check('harness: forged 503 outcome_unknown -> 503 envelope (no domain retry claim)', $is_envelope($fu, 503, 'server_error'), $fu['body']);
    $capture('forged outcome_unknown', 'redemption', $fu, array('kind' => 'transport', 'failure' => 'unavailable'));
    $und = $jreq('GET', "$v2/harness/undesignated", $jar, $nonce);
    check('harness: sealed body on an undesignated v2 route -> 500 envelope', $is_envelope($und, 500, 'server_error'), $und['body']);
    $capture('undesignated route', 'lookup', $und, array('kind' => 'transport', 'failure' => 'unavailable'));
    $shadow = $jreq('POST', "/wp-json/evangelou-club/v2-shadow/members/$member/redemptions", $jar, $nonce, array());
    check('harness: wrong namespace never gains the exemption -> 500 envelope', $is_envelope($shadow, 500, 'server_error'), $shadow['status'] . ' ' . $shadow['body']);
    $capture('wrong namespace', 'lookup', $shadow, array('kind' => 'transport', 'failure' => 'unavailable'));
    $v1 = $jreq('GET', '/wp-json/evangelou-club/v1/harness/sealed', $jar, $nonce);
    check('harness: sealed v2 body on a v1 route -> 500 envelope', $is_envelope($v1, 500, 'server_error'), $v1['body']);
    $nr = $jreq('GET', "$v2/nothing/here", $jar, $nonce);
    check('harness: unknown v2 route -> 404 envelope', $is_envelope($nr, 404, 'invalid_request'), $nr['body']);
    $capture('unknown route', 'status', $nr, array('kind' => 'transport', 'failure' => 'unavailable'));

    // ---- wrapped / non-JSON replies: the client must treat them as transport ----
    $env = $jreq('POST', "$v2/lookup/qr?_envelope=1", $jar, $nonce, array('qr_token' => $qr));
    $ed = $json($env);
    check('harness: _envelope wraps a refusal in HTTP 200 (never a domain body)', $env['status'] === 200 && is_array($ed) && isset($ed['body']['error']['code']) && $ed['body']['error']['code'] === 'invalid_request' && $ed['status'] === 400, $env['body']);
    $capture('_envelope wrapper', 'lookup', $env, array('kind' => 'transport', 'failure' => 'unavailable'));
    $jp = $jreq('POST', "$v2/lookup/qr?_jsonp=evcCallback", $jar, $nonce, array('qr_token' => $qr));
    check('harness: _jsonp reply is JavaScript, never a domain body', strpos(header_value($jp, 'content-type'), 'json') === false && strpos($jp['body'], '"found"') === false, header_value($jp, 'content-type') . ' ' . $jp['body']);
    $capture('_jsonp reply', 'lookup', $jp, array('kind' => 'transport', 'failure' => 'unavailable'));
    $px = $jreq('GET', "$v2/harness/proxy", $jar, $nonce);
    check('harness: proxy-style HTML 502', $px['status'] === 502 && strpos(header_value($px, 'content-type'), 'text/html') !== false, $px['status'] . ' ' . header_value($px, 'content-type'));
    $capture('proxy html', 'redemption', $px, array('kind' => 'transport', 'failure' => 'unavailable'));

    // ---- wrong capability (real nonces from core's rest-nonce action) and revoked session ----
    foreach (array('evc_http_editor' => 'editor', 'evc_http_admin' => 'administrator', 'evc_http_dual' => 'staff + administrator') as $user => $label) {
        $j = "$jars/harness-$user.txt";
        login($user, $j);
        $n = trim(req('GET', '/wp-admin/admin-ajax.php?action=rest-nonce', $j)['body']);
        $r = $jreq('POST', "$v2/lookup/qr", $j, $n, array('qr_token' => $qr));
        check("harness: $label with a valid nonce -> 403 envelope", preg_match('/^[0-9a-f]{10}$/', $n) === 1 && $is_envelope($r, 403, 'forbidden'), $n . ' ' . $r['status'] . ' ' . $r['body']);
        $capture("wrong capability ($label)", 'lookup', $r, array('kind' => 'transport', 'failure' => 'forbidden'));
    }
    $saved = "$jars/harness-before-end.txt";
    copy($jar, $saved);
    req('POST', '/wp-json/evangelou-club/v1/session/end', $jar, array('X-WP-Nonce: ' . $nonce));
    $rev = $jreq('POST', "$v2/lookup/qr", $saved, $nonce, array('qr_token' => $qr));
    check('harness: revoked session (old cookie + nonce) -> 401 envelope', $is_envelope($rev, 401, 'unauthorized'), $rev['body']);
    $capture('revoked session', 'lookup', $rev, array('kind' => 'transport', 'failure' => 'unauthorized'));

    $out = (string) getenv('EVC_HTTP_CAPTURES');
    check('harness: captures destination configured', $out !== '');
    if ($out !== '') {
        file_put_contents($out, json_encode(array('schema' => 'evc-http-captures-v1', 'captures' => $captures), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo 'captures written: ' . count($captures) . "\n";
    }
} elseif ($mode === 'off') {
    $anon = req('GET', '/club-admin/');
    check('flag off: anonymous /club-admin/ is not the shell', $anon['status'] !== 200 || no_bootstrap($anon), (string) $anon['status']);
    check('flag off: no bootstrap for anonymous', no_bootstrap($anon));
    $jar = "$jars/staff-off.txt";
    login('evc_http_staff', $jar);
    $r = req('GET', '/club-admin/', $jar);
    check('flag off: logged-in staff gets no shell/bootstrap', no_bootstrap($r) && config_of($r['body']) === null, (string) $r['status']);
    $s = req('GET', '/wp-json/evangelou-club/v1/session', $jar);
    check('flag off: Club REST namespace absent (404)', $s['status'] === 404, (string) $s['status']);
    $index = json_decode(req('GET', '/wp-json/')['body'], true);
    check('flag off: namespace not advertised', is_array($index) && !in_array('evangelou-club/v1', (array) $index['namespaces'], true));
} else {
    fwrite(STDERR, "usage: run-http-checks.php on|off|harness\n");
    exit(2);
}

foreach (glob("$jars/*") ?: array() as $f) {
    unlink($f);
}
rmdir($jars);
$failed = count(array_filter($results, function ($r) {
    return !$r[1];
}));
printf("[evidence] http-%s: checks=%d passed=%d failed=%d\n", $mode, count($results), count($results) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
