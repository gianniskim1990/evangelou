<?php
/**
 * STAGE-05C: one QR token rotation or revocation (job op) in its OWN PHP process and DB connection, for
 * tests/integration/SqlQrTokenStoreTest.php. DB host/user/password come from the inherited
 * EVC_TEST_DB_* environment (never argv); argv carries only the JSON job (database, member row
 * id, expected digest or null, new digest, barrier). Prints one JSON line: rotated | revoked | conflict |
 * duplicate | unknown | failed (op fail: one failed-scan increment, migration 003) | error:<class>. Never a token (the job carries digests only).
 */
define('EVC_STANDALONE_TEST', true);
date_default_timezone_set('Pacific/Kiritimati');

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/support/class-evc-fixed-clock.php';

$job = json_decode($argv[1], true);
$host = (string) getenv('EVC_TEST_DB_HOST');
if (!is_array($job) || !is_string($job['database'] ?? null) || !in_array($host, array('127.0.0.1', 'localhost', '::1'), true) || strpos($job['database'], 'evc_test_') !== 0
    || !in_array($job['op'] ?? null, array('rotate', 'revoke', 'fail'), true)) {
    fwrite(STDERR, "Refusing non-test database.\n");
    exit(2);
}
$password = getenv('EVC_TEST_DB_PASSWORD');
$port = getenv('EVC_TEST_DB_PORT');
try {
    $db = EVC_Club_Db::connect(new EVC_Db_Config($host, $port === false ? 3306 : (int) $port, $job['database'], (string) getenv('EVC_TEST_DB_USER'), $password === false ? '' : (string) $password));
    $db->fetch_one('SELECT 1 AS warm');
} catch (Throwable $e) {
    echo json_encode(array('outcome' => 'worker_connect_failed')), "\n";
    exit(0);
}
$store = new EVC_Sql_Qr_Token_Store($db);

while (microtime(true) < (float) $job['start_at']) {
    usleep(200);
}
if (!empty($job['announce'])) {
    // Lets a test know the next statement is the store call (connected, bootstrapped, nothing pending).
    echo json_encode(array('ready' => true)), "\n";
    fflush(STDOUT);
}
try {
    if ($job['op'] === 'fail') {
        // STAGE-05C D9: one failed scan of one tablet session (fixed clock: one window).
        (new EVC_Sql_Lookup_Rate_Limiter($db, new EVC_Fixed_Clock('2026-11-04T10:00:10Z')))->record_failure(array('session:' . (string) $job['session']), 'qr_lookup');
        $outcome = 'failed';
    } elseif ($job['op'] === 'revoke') {
        $store->revoke((int) $job['member_id'], (string) $job['expected'], '2026-11-03 10:00:00.000000');
        $outcome = 'revoked';
    } else {
        $store->rotate((int) $job['member_id'], $job['expected'], (string) $job['new_hash'], '2026-11-03 10:00:00.000000');
        $outcome = 'rotated';
    }
} catch (EVC_Qr_Rotation_Conflict $e) {
    $outcome = 'conflict';
} catch (EVC_Db_Duplicate_Key_Exception $e) {
    $outcome = 'duplicate';
} catch (EVC_Db_Commit_Unknown_Exception $e) {
    $outcome = 'unknown';
} catch (Throwable $e) {
    $outcome = 'error:' . get_class($e);
}
echo json_encode(array('outcome' => $outcome)), "\n";
