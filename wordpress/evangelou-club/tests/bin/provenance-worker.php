<?php
/**
 * One concurrent provenance write in its OWN PHP process and DB connection
 * (Task 1D-G). Spawned by tests/integration/SqlProvenanceConcurrencyTest.php.
 * DB host/user/password come from the inherited EVC_TEST_DB_* environment
 * (never argv); argv carries only the JSON job. Synthetic data only.
 * Prints one JSON line: {"outcome": ..., "reason": ...}.
 */
define('EVC_STANDALONE_TEST', true);
date_default_timezone_set('Pacific/Kiritimati');

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/support/class-evc-fake-pmpro-reader.php';
require dirname(__DIR__) . '/support/class-evc-provenance-test-fixture.php';

$job = json_decode($argv[1], true);
$host = (string) getenv('EVC_TEST_DB_HOST');
if (!in_array($host, array('127.0.0.1', 'localhost', '::1'), true) || strpos($job['database'], 'evc_test_') !== 0) {
    fwrite(STDERR, "Refusing non-test database.\n");
    exit(2);
}
$password = getenv('EVC_TEST_DB_PASSWORD');
$port = getenv('EVC_TEST_DB_PORT');
$config = new EVC_Db_Config($host, $port === false ? 3306 : (int) $port, $job['database'], (string) getenv('EVC_TEST_DB_USER'), $password === false ? '' : (string) $password);

try {
    $store = EVC_Provenance_Test_Fixture::sql_store($config);
    $store->movement(EVC_Provenance_Test_Fixture::ref('warm-up')); // connect + schema check before the barrier
} catch (Throwable $e) {
    echo json_encode(array('outcome' => 'worker_connect_failed')), "\n";
    exit(0);
}
$recorder = EVC_Provenance_Test_Fixture::recorder($store);

$movement = function (array $j): EVC_Verified_Movement {
    $o = array('user' => (int) ($j['user'] ?? EVC_Provenance_Test_Fixture::USER));
    if (isset($j['aliases'])) {
        $o['aliases'] = array();
        foreach ($j['aliases'] as $a) {
            $o['aliases'][] = new EVC_Movement_Alias($a[0], EVC_Provenance_Test_Fixture::ref($a[1]));
        }
    }
    return EVC_Provenance_Test_Fixture::movement($j['label'], $j['at'], $o);
};

// Barrier: every worker is connected and warm; all fire at the same instant.
while (microtime(true) < (float) $job['start_at']) {
    usleep(200);
}
if (!empty($job['delay_ms'])) {
    usleep((int) $job['delay_ms'] * 1000); // lets the test alternate which side of a race starts first
}

if ($job['op'] === 'record') {
    $o = $recorder->record($movement($job));
} elseif ($job['op'] === 'correct') {
    $o = $recorder->correct(EVC_Provenance_Test_Fixture::correction($job['label'], $job['movement'], $job['kind']));
} else {
    $o = $recorder->reconcile(new EVC_Movement_Reconciliation($movement($job), array_map(array('EVC_Provenance_Test_Fixture', 'ref'), $job['ack'])));
}
echo json_encode(array('outcome' => $o->outcome(), 'reason' => $o->reason())), "\n";
