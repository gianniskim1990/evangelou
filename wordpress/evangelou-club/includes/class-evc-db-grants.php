<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;
/**
 * STAGE-08 round 10 (LOW-1 of the round-9 review): PURE reading of the lines of `SHOW GRANTS` (the connected user's
 * own privileges; MySQL 8.0 / 8.4 and MariaDB 10.11 formats) to tell whether that user holds the given table
 * privileges on one table of the current database. It never runs a statement and never writes anything: the
 * readiness page passes it the lines it read. Nothing of a line (user, host, database name, password hash) is
 * kept or returned: only a state and the privilege names that are missing.
 *
 * The answer is "ok" only when every privilege is found explicitly on a scope that covers the table (*.*, db.*, a
 * matching db pattern, db.table) and no partial revoke exists. It is "missing" only when the whole list was
 * understood and nothing could still grant the privilege. Anything that could change the answer without
 * appearing here (a role, a db pattern that matches only without letter case, column privileges on that table, a
 * REVOKE line of partial revokes, a line that is not understood) makes it "unknown": the caller then asks for a
 * check by hand.
 */
final class EVC_Db_Grants {
    const OK = 'ok';
    const MISSING = 'missing';
    const UNKNOWN = 'unknown';
    /** What the till needs for a prepared redemption (migration 004): it writes and reads this table. */
    const PREPARATIONS_TABLE = 'evc_redemption_preparations';
    const PREPARATIONS_PRIVILEGES = array('INSERT', 'SELECT');
    /** Round 12 (D26 part 2): what push needs on the subscriptions table of migration 005 (never DELETE). */
    const PUSH_TABLE = 'evc_push_subscriptions';
    const PUSH_PRIVILEGES = array('SELECT', 'INSERT', 'UPDATE');

    /**
     * @param string[] $lines    the rows of SHOW GRANTS, one statement each
     * @param string   $database the current database (SELECT DATABASE())
     * @param string   $table    the table the privileges are needed on
     * @param string[] $needed   upper-case privilege names, e.g. the ones the till needs
     * @return array{state:string,missing:string[]}
     */
    public static function table_privileges(array $lines, string $database, string $table, array $needed): array {
        $found = array();
        $uncertain = false;
        $revoked = false;
        if ($lines === array() || $database === '') {
            return self::result(self::UNKNOWN, $needed);
        }
        foreach ($lines as $line) {
            if (!is_string($line)) {
                $uncertain = true;
                continue;
            }
            $line = trim($line);
            if (preg_match('/^REVOKE\s/i', $line) === 1) {
                $revoked = true;
                continue;
            }
            if (preg_match('/^SET\s+DEFAULT\s+ROLE\s/i', $line) === 1) {
                $uncertain = true;
                continue;
            }
            $parsed = self::parse_line($line);
            if ($parsed === null) {
                // A role granted to the user (no "ON"), or a line this reader does not understand.
                $uncertain = true;
                continue;
            }
            if ($parsed['kind'] !== 'table') {
                continue; // PROXY, FUNCTION, PROCEDURE: nothing about table privileges.
            }
            $applies = self::scope_applies($parsed['db'], $parsed['table'], $database, $table);
            if ($applies === null) {
                $uncertain = true;
                continue;
            }
            if (!$applies) {
                continue;
            }
            foreach ($parsed['privileges'] as $privilege) {
                if ($privilege['columns']) {
                    $uncertain = true; // column privileges are not table privileges, but they make the answer unsure
                    continue;
                }
                $name = $privilege['name'];
                if ($name === 'ALL' || $name === 'ALL PRIVILEGES') {
                    foreach ($needed as $n) {
                        $found[$n] = true;
                    }
                } else {
                    $found[$name] = true;
                }
            }
        }
        $missing = array_values(array_filter($needed, function ($n) use ($found) {
            return !isset($found[$n]);
        }));
        if ($missing === array() && !$revoked) {
            return self::result(self::OK, array());
        }
        if ($revoked || $uncertain) {
            return self::result(self::UNKNOWN, $missing);
        }
        return self::result(self::MISSING, $missing);
    }

    /** @return array{state:string,missing:string[]} */
    private static function result(string $state, array $missing): array {
        return array('state' => $state, 'missing' => array_values($missing));
    }

    /**
     * One GRANT line: its privileges and object, or null when it is not "GRANT <privileges> ON <object> TO ...".
     * @return array{kind:string,db:string,table:string,privileges:array<int,array{name:string,columns:bool}>}|null
     */
    private static function parse_line(string $line): ?array {
        $ident = '(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|[^\s.`\'"]+)';
        $pattern = '/^GRANT\s+(.+?)\s+ON\s+(?:(TABLE|FUNCTION|PROCEDURE)\s+)?(' . $ident . '|\*)\.(' . $ident . '|\*)\s+TO\s/is';
        if (preg_match($pattern, $line, $m) !== 1) {
            // MySQL / MariaDB proxy line: GRANT PROXY ON ''@'%' TO ...
            if (preg_match('/^GRANT\s+PROXY\s+ON\s/i', $line) === 1) {
                return array('kind' => 'proxy', 'db' => '', 'table' => '', 'privileges' => array());
            }
            return null;
        }
        $kind = strtoupper((string) $m[2]);
        $privileges = self::split_privileges($m[1]);
        if ($privileges === null) {
            return null;
        }
        if ($kind === 'FUNCTION' || $kind === 'PROCEDURE') {
            return array('kind' => 'routine', 'db' => '', 'table' => '', 'privileges' => array());
        }
        if (count($privileges) === 1 && $privileges[0]['name'] === 'PROXY') {
            return array('kind' => 'proxy', 'db' => '', 'table' => '', 'privileges' => array());
        }
        return array('kind' => 'table', 'db' => $m[3], 'table' => $m[4], 'privileges' => $privileges);
    }

    /** @return array<int,array{name:string,columns:bool}>|null */
    private static function split_privileges(string $list): ?array {
        $out = array();
        $depth = 0;
        $current = '';
        $length = strlen($list);
        for ($i = 0; $i < $length; $i++) {
            $c = $list[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth < 0) {
                    return null;
                }
            }
            if ($c === ',' && $depth === 0) {
                $out[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        if ($depth !== 0) {
            return null;
        }
        $out[] = $current;
        $privileges = array();
        foreach ($out as $raw) {
            $columns = strpos($raw, '(') !== false;
            $name = strtoupper(trim(preg_replace('/\(.*\)/s', '', $raw)));
            $name = (string) preg_replace('/\s+/', ' ', $name);
            if ($name === '') {
                return null;
            }
            $privileges[] = array('name' => $name, 'columns' => $columns);
        }
        return $privileges;
    }

    /** The bare name of a quoted or unquoted identifier. */
    private static function unquote(string $ident): string {
        $q = $ident[0] ?? '';
        if (($q === '`' || $q === '\'' || $q === '"') && strlen($ident) >= 2 && substr($ident, -1) === $q) {
            return str_replace($q . $q, $q, substr($ident, 1, -1));
        }
        return $ident;
    }

    /**
     * Whether a grant on db.table covers $table in $database: true / false, or null when it cannot be told
     * (a name that matches only when letter case is ignored).
     *
     * Round 11 (C3 of the round-10 review): only a DATABASE-level grant (db.*) reads its database name as a pattern
     * (unescaped % and _ are wildcards, \_ and \% are the plain characters). In a TABLE-level grant (db.table) the
     * server stores and compares the database name literally: no wildcard, and a backslash is part of the name
     * (checked on MariaDB 10.11: GRANT … ON `a\_b`.`t` needs a database really named «a\_b», and
     * GRANT … ON `a_b`.`t` does not cover a database «aXb»). Table names are always literal.
     */
    private static function scope_applies(string $db, string $tbl, string $database, string $table): ?bool {
        if ($db === '*') {
            return $tbl === '*';
        }
        $db_name = self::unquote($db);
        if ($tbl === '*') {
            if (self::db_matches($db_name, $database)) {
                return true;
            }
            return self::db_matches(strtolower($db_name), strtolower($database)) ? null : false;
        }
        if ($db_name !== $database) {
            return strtolower($db_name) === strtolower($database) ? null : false;
        }
        $table_name = self::unquote($tbl);
        if ($table_name === $table) {
            return true;
        }
        return strtolower($table_name) === strtolower($table) ? null : false;
    }

    /**
     * A database-level grant's database name, where an unescaped % or _ is a wildcard and a backslash makes the next
     * character plain (as MySQL / MariaDB read db-level grants). Round 11: no "equal strings" shortcut: a database
     * really named «a\_b» is NOT covered by the pattern a\_b (that pattern means «a_b»).
     */
    private static function db_matches(string $pattern, string $database): bool {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $c = $pattern[$i];
            if ($c === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }
        return preg_match('/^' . $regex . '$/s', $database) === 1;
    }
}
