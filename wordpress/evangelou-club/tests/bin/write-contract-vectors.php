<?php
/**
 * Regenerates tests/fixtures/contract/vectors.json from the REAL PHP services
 * and UI-04G response mappers (deterministic). The PHP unit test fails if the
 * committed file drifts; the TypeScript tests parse the same file.
 *
 * Usage (from the plugin directory): php tests/bin/write-contract-vectors.php
 */
require dirname(__DIR__) . '/bootstrap.php';
$path = dirname(__DIR__) . '/fixtures/contract/vectors.json';
file_put_contents($path, EVC_Contract_Vector_Builder::encode());
echo 'wrote ' . $path . "\n";
