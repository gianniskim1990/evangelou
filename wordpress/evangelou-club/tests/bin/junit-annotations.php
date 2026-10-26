<?php
/**
 * TEST-ONLY CI diagnostics (STAGE-03B.3b): prints the first failing / erroring
 * test cases of a PHPUnit JUnit log as GitHub "::error" annotations, so the
 * reason is readable through the check-runs annotations API. Never shipped.
 *
 * Usage: php tests/bin/junit-annotations.php <junit.xml> [max]
 */
$file = $argv[1] ?? '';
$max = (int) ($argv[2] ?? 10);
if (!is_file($file)) {
    fwrite(STDERR, "No JUnit log at $file\n");
    exit(0);
}
$xml = @simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Unreadable JUnit log\n");
    exit(0);
}
$n = 0;
foreach ($xml->xpath('//testcase') as $case) {
    foreach (array('failure', 'error') as $kind) {
        foreach ($case->{$kind} as $node) {
            if ($n++ >= $max) {
                exit(0);
            }
            $title = (string) $case['class'] . '::' . (string) $case['name'] . ' (' . $kind . ')';
            $message = substr(trim((string) $node), 0, 1800);
            $escape = function (string $s): string {
                return str_replace(array('%', "\r", "\n", ':', ','), array('%25', '%0D', '%0A', '%3A', '%2C'), $s);
            };
            echo '::error title=' . $escape($title) . '::' . str_replace(array('%', "\r", "\n"), array('%25', '%0D', '%0A'), $message) . "\n";
        }
    }
}
