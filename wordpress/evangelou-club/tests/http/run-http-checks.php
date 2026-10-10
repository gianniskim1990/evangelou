<?php
/**
 * CI-ONLY real HTTP checks against a disposable WordPress served by `php -S`
 * with the PACKAGED plugin. Uses real logins (wp-login.php), real cookies,
 * real response headers.
 *
 *   php run-http-checks.php on    (server started with EVC_HTTP_FLAG=on)
 *   php run-http-checks.php off   (server started with EVC_HTTP_FLAG=off)
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

function no_bootstrap(array $r): bool {
    return strpos($r['body'], 'evc-staff-config') === false && strpos($r['body'], 'type="module"') === false;
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
    fwrite(STDERR, "usage: run-http-checks.php on|off\n");
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
