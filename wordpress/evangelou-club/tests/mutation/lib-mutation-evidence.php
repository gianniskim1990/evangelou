<?php
/**
 * STAGE-03B.1: evidence that a mutant was killed for the RIGHT reason.
 *
 * A mutation runner normally counts any non-zero PHPUnit exit as "killed".
 * That cannot tell a meaningful detection (a test's assertion failed) from a
 * broken run (fatal error, failing setUp, exception from an unrelated path).
 * A mutant may therefore name KILLER tests ("Class::method"); the run then
 * writes a JUnit log and the mutant counts as killed only when EVERY named
 * killer is present and FAILED AN ASSERTION (a <failure> element, no
 * <error>). Mutants without killers keep the established exit-code rule.
 *
 * Pure function, no PHPUnit dependency, so it is unit-tested directly
 * (tests/unit/MutationEvidenceTest.php).
 */

/**
 * @param string   $junit_xml PHPUnit --log-junit output ('' when none was written)
 * @param string[] $killers   "Class::method"
 * @return array{verdict:string,detail:string} verdict: assertion | error | passed | missing | unreadable
 */
function evc_mutation_killer_verdict(string $junit_xml, array $killers): array {
    if ($killers === array()) {
        throw new InvalidArgumentException('No killer tests named.');
    }
    if (trim($junit_xml) === '') {
        return array('verdict' => 'unreadable', 'detail' => 'no JUnit log was written');
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($junit_xml);
    libxml_use_internal_errors($previous);
    if ($xml === false) {
        return array('verdict' => 'unreadable', 'detail' => 'JUnit log is not valid XML');
    }
    foreach ($killers as $killer) {
        $parts = explode('::', $killer);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Killer must be "Class::method": ' . $killer);
        }
        list($class, $method) = $parts;
        $cases = $xml->xpath(sprintf('//testcase[@class=%s and @name=%s]', evc_xpath_literal($class), evc_xpath_literal($method)));
        if ($cases === false || count($cases) === 0) {
            return array('verdict' => 'missing', 'detail' => $killer . ' did not run');
        }
        foreach ($cases as $case) {
            if (isset($case->error)) {
                return array('verdict' => 'error', 'detail' => $killer . ' errored: ' . evc_first_line((string) $case->error));
            }
            if (!isset($case->failure)) {
                return array('verdict' => 'passed', 'detail' => $killer . ' passed');
            }
        }
    }
    return array('verdict' => 'assertion', 'detail' => implode(', ', $killers) . ' failed an assertion');
}

/**
 * STAGE-03D: a PHPUnit --filter that runs EXACTLY the named killer tests (and nothing else), so a
 * mutant with killers no longer pays for the whole suite. The verdict is unchanged: every named killer
 * must still have failed an assertion (evc_mutation_killer_verdict), else the mutant is SURVIVED
 * (all killers passed) or INVALID.
 *
 * @param string[] $killers "Class::method"
 */
function evc_mutation_killer_filter(array $killers): string {
    if ($killers === array()) {
        throw new InvalidArgumentException('No killer tests named.');
    }
    $names = array();
    foreach ($killers as $killer) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*::[A-Za-z_][A-Za-z0-9_]*$/D', $killer)) {
            throw new InvalidArgumentException('Killer must be "Class::method": ' . $killer);
        }
        $names[] = $killer; // validated above: letters, digits, _ and one ::
    }
    return '/^(?:' . implode('|', array_values(array_unique($names))) . ')(?: with data set .*)?$/';
}

function evc_xpath_literal(string $value): string {
    if (strpos($value, "'") === false) {
        return "'" . $value . "'";
    }
    return 'concat(\'' . str_replace("'", "', \"'\", '", $value) . '\')';
}

function evc_first_line(string $text): string {
    $text = trim($text);
    $eol = strpos($text, "\n");
    return $eol === false ? $text : substr($text, 0, $eol);
}
