<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/mutation/lib-mutation-journal.php';

/**
 * STAGE-03B.2: a mutant left behind by a KILLED runner is restored by the next
 * run, and a file edited in the meantime is never overwritten.
 */
final class MutationJournalTest extends TestCase {
    /** @var string */
    private $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/evc-journal-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: array() as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function source(string $content): string {
        $path = $this->dir . '/source.php';
        file_put_contents($path, $content);
        return $path;
    }

    public function test_a_killed_run_is_undone_by_the_next_run(): void {
        $path = $this->source("original\r\n");
        $journal = $this->dir . '/journal.json';
        evc_mutation_journal_begin($journal, $path, "original\r\n", "mutant\n");
        file_put_contents($path, "mutant\n");
        // ... the process dies here: no finally, no journal_end.
        $this->assertSame('restored', evc_mutation_journal_recover($journal));
        $this->assertSame("original\r\n", file_get_contents($path));
        $this->assertFileDoesNotExist($journal);
        $this->assertSame('clean', evc_mutation_journal_recover($journal));
    }

    public function test_a_run_killed_after_its_finally_needs_nothing(): void {
        $path = $this->source('original');
        $journal = $this->dir . '/journal.json';
        evc_mutation_journal_begin($journal, $path, 'original', 'mutant');
        $this->assertSame('already_original', evc_mutation_journal_recover($journal));
        $this->assertSame('original', file_get_contents($path));
    }

    public function test_a_file_edited_since_is_never_overwritten(): void {
        $path = $this->source('original');
        $journal = $this->dir . '/journal.json';
        evc_mutation_journal_begin($journal, $path, 'original', 'mutant');
        file_put_contents($path, 'a developer edit');
        try {
            evc_mutation_journal_recover($journal);
            $this->fail('overwrote an unknown file state');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be undone automatically', $e->getMessage());
        }
        $this->assertSame('a developer edit', file_get_contents($path));
        $this->assertFileExists($journal);
    }

    public function test_a_corrupt_journal_stops_the_run(): void {
        $journal = $this->dir . '/journal.json';
        file_put_contents($journal, '{"path":');
        $this->expectException(RuntimeException::class);
        evc_mutation_journal_recover($journal);
    }

    public function test_end_requires_the_original_back_in_place(): void {
        $path = $this->source('original');
        $journal = $this->dir . '/journal.json';
        evc_mutation_journal_begin($journal, $path, 'original', 'mutant');
        file_put_contents($path, 'mutant');
        try {
            evc_mutation_journal_end($journal, $path);
            $this->fail('cleared the journal while the mutant was still in place');
        } catch (RuntimeException $e) {
            $this->assertFileExists($journal);
        }
        file_put_contents($path, 'original');
        evc_mutation_journal_end($journal, $path);
        $this->assertFileDoesNotExist($journal);
    }

    public function test_journal_lives_outside_the_repository(): void {
        $root = dirname(__DIR__, 2);
        $this->assertStringStartsNotWith(str_replace('\\', '/', (string) realpath($root)), str_replace('\\', '/', evc_mutation_journal_path($root)));
    }
}
