<?php
/**
 * CI helper: waits (max 120 s) until the disposable test DB server accepts
 * connections, then prints its version. Uses EVC_TEST_DB_* env variables
 * and refuses any non-loopback host.
 */
$host = (string) getenv('EVC_TEST_DB_HOST');
if (!in_array($host, array('127.0.0.1', 'localhost', '::1'), true)) {
    fwrite(STDERR, "Refusing non-loopback test database host.\n");
    exit(2);
}
$port = getenv('EVC_TEST_DB_PORT') === false ? 3306 : (int) getenv('EVC_TEST_DB_PORT');
$deadline = time() + 120;
do {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            (string) getenv('EVC_TEST_DB_USER'),
            (string) getenv('EVC_TEST_DB_PASSWORD'),
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3)
        );
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo 'Test DB server ready: ', $version, ' | PHP ', PHP_VERSION, ' | pdo_mysql ', phpversion('pdo_mysql'), "\n";
        exit(0);
    } catch (PDOException $e) {
        sleep(2);
    }
} while (time() < $deadline);
fwrite(STDERR, "Test DB server did not become ready in time.\n");
exit(1);
