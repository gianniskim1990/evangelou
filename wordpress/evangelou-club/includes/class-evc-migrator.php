<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Versioned, checksummed schema migrations for the separate Club database.
 *
 * - Files: database/NNN_snake_name.sql, applied in ascending NNN order.
 * - evc_schema_migrations records version, name, SHA-256 checksum (CRLF
 *   normalised to LF) and UTC apply time.
 * - Drift is fatal: an applied migration whose file changed or disappeared,
 *   or a new file numbered below the latest applied version, aborts before
 *   any DDL runs.
 * - A named lock (GET_LOCK) stops two migrators racing.
 * - MySQL/MariaDB DDL auto-commits, so a migration that fails half-way can
 *   leave partial DDL behind. It is NOT recorded as applied; the operator
 *   must restore the pre-migration backup. Always back up before migrating.
 *
 * During Task 1B this runs ONLY against disposable CI/test databases.
 */
final class EVC_Migrator {
    const TABLE = 'evc_schema_migrations';
    /** Lock name prefix; the lock is scoped per database via SHA1(DATABASE()). */
    const LOCK_PREFIX = 'evc_migrations_';
    const LOCK_TIMEOUT_SECONDS = 10;

    /** @var EVC_Club_Db */
    private $db;
    /** @var string */
    private $directory;

    public function __construct(EVC_Club_Db $db, string $directory) {
        $this->db = $db;
        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * Migration files found on disk, keyed by integer version.
     * @return array<int,array{name:string,path:string,checksum:string}>
     */
    public function discover(): array {
        $found = array();
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql');
        foreach ($files ?: array() as $path) {
            $base = basename($path);
            if (!preg_match('/^(\d{3})_([a-z0-9_]+)\.sql$/D', $base, $m)) {
                throw new EVC_Migration_Exception('Unexpected migration file name: ' . $base);
            }
            $version = (int) $m[1];
            if (isset($found[$version])) {
                throw new EVC_Migration_Exception('Duplicate migration version ' . $m[1] . '.');
            }
            $found[$version] = array(
                'name' => $m[2],
                'path' => $path,
                'checksum' => self::checksum_file($path),
            );
        }
        ksort($found);
        return $found;
    }

    /** @return array<int,array{name:string,checksum:string}> Applied migrations keyed by version. */
    public function applied(): array {
        $this->ensure_bookkeeping_table();
        $rows = $this->db->fetch_all('SELECT version, name, checksum FROM ' . self::TABLE . ' ORDER BY version');
        $applied = array();
        foreach ($rows as $row) {
            $applied[(int) $row['version']] = array('name' => (string) $row['name'], 'checksum' => (string) $row['checksum']);
        }
        return $applied;
    }

    /**
     * Verifies applied migrations against disk and applies pending ones.
     * @return int[] Versions applied by this call (empty when up to date).
     */
    public function migrate(): array {
        $this->ensure_bookkeeping_table();
        $this->acquire_lock();
        try {
            $available = $this->discover();
            $applied = $this->applied();
            $this->verify_no_drift($available, $applied);

            $done = array();
            foreach ($available as $version => $migration) {
                if (isset($applied[$version])) {
                    continue;
                }
                $this->apply($version, $migration);
                $done[] = $version;
            }
            return $done;
        } finally {
            $this->release_lock();
        }
    }

    /** Splits a migration into statements; strips full-line "--" comments. */
    public static function split_statements(string $sql): array {
        $lines = preg_split('/\r\n|\n|\r/', $sql);
        $kept = array();
        foreach ($lines as $line) {
            if (strpos(ltrim($line), '--') === 0) {
                continue;
            }
            $kept[] = $line;
        }
        $statements = array();
        foreach (preg_split('/;\s*(?:\n|$)/', implode("\n", $kept)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
        return $statements;
    }

    public static function checksum_file(string $path): string {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new EVC_Migration_Exception('Cannot read migration file ' . basename($path) . '.');
        }
        return hash('sha256', str_replace("\r\n", "\n", $contents));
    }

    private function verify_no_drift(array $available, array $applied): void {
        $latest_applied = $applied ? max(array_keys($applied)) : 0;
        foreach ($applied as $version => $row) {
            if (!isset($available[$version])) {
                throw new EVC_Migration_Exception(sprintf('Schema drift: applied migration %03d is missing from the code.', $version));
            }
            if (!hash_equals($row['checksum'], $available[$version]['checksum'])) {
                throw new EVC_Migration_Exception(sprintf('Schema drift: applied migration %03d was modified after it ran.', $version));
            }
        }
        foreach (array_keys($available) as $version) {
            if (!isset($applied[$version]) && $version < $latest_applied) {
                throw new EVC_Migration_Exception(sprintf('Out-of-order migration %03d is older than applied %03d.', $version, $latest_applied));
            }
        }
    }

    private function apply(int $version, array $migration): void {
        $sql = file_get_contents($migration['path']);
        if ($sql === false) {
            throw new EVC_Migration_Exception(sprintf('Cannot read migration %03d.', $version));
        }
        try {
            foreach (self::split_statements($sql) as $statement) {
                $this->db->exec_raw($statement);
            }
        } catch (EVC_Db_Exception $e) {
            throw new EVC_Migration_Exception(sprintf(
                'Migration %03d failed (driver code %s). Schema may be partially changed: restore the pre-migration backup.',
                $version,
                $e->driver_code() === null ? 'n/a' : (string) $e->driver_code()
            ));
        }
        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (version, name, checksum, applied_at_utc) VALUES (?, ?, ?, ?)',
            array($version, $migration['name'], $migration['checksum'], gmdate('Y-m-d H:i:s') . '.000000')
        );
    }

    private function ensure_bookkeeping_table(): void {
        // The only IF NOT EXISTS in the schema: the bookkeeping table itself.
        // Its shape is then verified by selecting every expected column.
        $this->db->exec_raw(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . ' version INT UNSIGNED NOT NULL,'
            . ' name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . ' checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . ' applied_at_utc DATETIME(6) NOT NULL,'
            . ' PRIMARY KEY (version)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        try {
            $this->db->fetch_all('SELECT version, name, checksum, applied_at_utc FROM ' . self::TABLE . ' WHERE 1 = 0');
        } catch (EVC_Db_Exception $e) {
            throw new EVC_Migration_Exception('Schema drift: ' . self::TABLE . ' has an unexpected shape.');
        }
    }

    private function acquire_lock(): void {
        $row = $this->db->fetch_one('SELECT GET_LOCK(CONCAT(?, SHA1(DATABASE())), ?) AS acquired', array(self::LOCK_PREFIX, self::LOCK_TIMEOUT_SECONDS));
        if (!$row || (int) $row['acquired'] !== 1) {
            throw new EVC_Migration_Exception('Another migration is running (lock not acquired).');
        }
    }

    private function release_lock(): void {
        try {
            $this->db->fetch_one('SELECT RELEASE_LOCK(CONCAT(?, SHA1(DATABASE()))) AS released', array(self::LOCK_PREFIX));
        } catch (EVC_Db_Exception $ignored) {
            // Lock is released automatically when the session ends.
        }
    }
}
