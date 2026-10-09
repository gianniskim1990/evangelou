<?php
/**
 * One concurrent redemption attempt in its OWN PHP process and DB connection.
 * Spawned by tests/integration/ConcurrencyTest.php. DB host/user/password are
 * read from the inherited EVC_TEST_DB_* environment (never from argv); argv
 * carries only the JSON job (database name, member, request id, barrier).
 * Prints one JSON line with the outcome.
 */
define('EVC_STANDALONE_TEST', true);
date_default_timezone_set('Pacific/Kiritimati');

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/support/class-evc-fixed-clock.php';
require dirname(__DIR__) . '/support/class-evc-mock-membership-adapter.php';

$job = json_decode($argv[1], true);
$host = (string) getenv('EVC_TEST_DB_HOST');
if (!in_array($host, array('127.0.0.1', 'localhost', '::1'), true) || strpos($job['database'], 'evc_test_') !== 0) {
    fwrite(STDERR, "Refusing non-test database.\n");
    exit(2);
}
$password = getenv('EVC_TEST_DB_PASSWORD');
$port = getenv('EVC_TEST_DB_PORT');

try {
    $db = EVC_Club_Db::connect(new EVC_Db_Config(
        $host,
        $port === false ? 3306 : (int) $port,
        $job['database'],
        (string) getenv('EVC_TEST_DB_USER'),
        $password === false ? '' : (string) $password
    ));
    $db->fetch_one('SELECT 1 AS warm');
} catch (Throwable $e) {
    echo json_encode(array('outcome' => 'worker_connect_failed')), "\n";
    exit(0);
}

$adapter = new EVC_Mock_Membership_Adapter();
$adapter->set((int) $job['wp_user_id'], EVC_Mock_Membership_Adapter::active_until('2030-01-01T00:00:00Z'));
$service = new EVC_Redemption_Service(
    new EVC_Redemption_Store($db),
    new EVC_Db_Audit_Log(),
    $adapter,
    new EVC_Coffee_Catalog(require dirname(__DIR__) . '/fixtures/coffee-catalog.php'),
    new EVC_Fixed_Clock($job['now'])
);

// Barrier: every worker is connected and warm; all fire at the same instant.
while (microtime(true) < (float) $job['start_at']) {
    usleep(200);
}

$result = $service->redeem(new EVC_Redemption_Request(
    $job['member'],
    'free_coffee',
    $job['coffee'],
    $job['request_id'],
    (int) $job['staff']
));

echo json_encode(array(
    'outcome' => $result->outcome(),
    'redemption' => $result->redemption(),
    'details' => $result->details(),
)), "\n";
