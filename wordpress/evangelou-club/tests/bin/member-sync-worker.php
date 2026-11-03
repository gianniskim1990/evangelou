<?php
/**
 * STAGE-05B: one member sync in its OWN PHP process and DB connection, for
 * tests/integration/SqlMemberIdentityStoreTest.php. DB host/user/password come from
 * the inherited EVC_TEST_DB_* environment (never argv); argv carries only the JSON
 * job (database, WordPress user id, barrier). Prints one JSON line.
 */
define('EVC_STANDALONE_TEST', true);
date_default_timezone_set('Pacific/Kiritimati');

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/support/class-evc-fixed-clock.php';
require dirname(__DIR__) . '/support/class-evc-mock-membership-adapter.php';
require dirname(__DIR__) . '/support/class-evc-fake-member-sync.php';

$job = json_decode($argv[1], true);
$host = (string) getenv('EVC_TEST_DB_HOST');
if (!in_array($host, array('127.0.0.1', 'localhost', '::1'), true) || strpos($job['database'], 'evc_test_') !== 0) {
    fwrite(STDERR, "Refusing non-test database.\n");
    exit(2);
}
$password = getenv('EVC_TEST_DB_PASSWORD');
$port = getenv('EVC_TEST_DB_PORT');
try {
    $db = EVC_Club_Db::connect(new EVC_Db_Config($host, $port === false ? 3306 : (int) $port, $job['database'], (string) getenv('EVC_TEST_DB_USER'), $password === false ? '' : (string) $password));
    $db->fetch_one('SELECT 1 AS warm');
} catch (Throwable $e) {
    echo json_encode(array('kind' => 'worker_connect_failed', 'member_public_id' => null)), "\n";
    exit(0);
}

$wp_user_id = (int) $job['wp_user_id'];
$adapter = new EVC_Mock_Membership_Adapter();
$adapter->set($wp_user_id, EVC_Mock_Membership_Adapter::active_until('2030-01-01T00:00:00Z'));
$accounts = new EVC_Fake_Wp_Account_Directory();
$accounts->kinds = array($wp_user_id => EVC_Wp_Account_Directory::CUSTOMER);
$service = new EVC_Member_Sync_Service(new EVC_Sql_Member_Identity_Store($db), $accounts, $adapter, new EVC_Fixed_Clock('2026-11-03T10:00:00Z'));

while (microtime(true) < (float) $job['start_at']) {
    usleep(200);
}
$result = $service->sync(EVC_Member_Sync_Signal::of('woocommerce', 'payment_changed', $wp_user_id));
echo json_encode(array('kind' => $result->kind(), 'member_public_id' => $result->member_public_id())), "\n";
