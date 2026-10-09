<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Checks\TestIntegrityCheck;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;

final class TestIntegrityCheckTest extends TestCase
{
    private string $dir;

    private const ORIGINAL = <<<'PHP'
    <?php

    it('creates a post', function () {
        expect(1)->toBe(1);
        $this->assertTrue(true);
    });

    test('lists posts', function () {
        expect([])->toBeEmpty();
    });
    PHP;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-integrity-'.uniqid();
        mkdir($this->dir.'/tests', 0o777, true);
        file_put_contents($this->dir.'/tests/PostTest.php', self::ORIGINAL);
        exec('cd '.escapeshellarg($this->dir).' && git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -qm init');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function check(string $newContent): Result
    {
        file_put_contents($this->dir.'/tests/PostTest.php', $newContent);
        $check = new TestIntegrityCheck(new Workspace($this->dir), new ProcessRunner($this->dir));

        return $check->run(new Target('test_integrity', 'test_integrity', 'x', [], ['files' => ['tests/PostTest.php']]));
    }

    public function test_untouched_and_strengthened_tests_pass(): void
    {
        $this->assertSame('pass', $this->check(self::ORIGINAL)->status);
        $this->assertSame('pass', $this->check(self::ORIGINAL."\nit('more', fn () => expect(2)->toBe(2));")->status);
    }

    public function test_removed_assertion_fails(): void
    {
        $result = $this->check(str_replace('$this->assertTrue(true);', '', self::ORIGINAL));

        $this->assertSame('fail', $result->status);
        $this->assertSame('test_integrity.assertions_removed', $result->violations[0]->rule);
    }

    public function test_removed_test_and_added_skip_fail(): void
    {
        $result = $this->check(str_replace("test('lists posts', function () {\n    expect([])->toBeEmpty();\n});", '', self::ORIGINAL));
        $this->assertContains('test_integrity.test_removed', array_map(fn ($v) => $v->rule, $result->violations));

        $result = $this->check(str_replace("});\n\ntest(", "})->skip();\n\ntest(", self::ORIGINAL));
        $this->assertSame('test_integrity.skip_added', $result->violations[0]->rule);
    }

    public function test_commenting_out_an_assertion_counts_as_removing_it(): void
    {
        $this->assertSame('fail', $this->check(str_replace('$this->assertTrue(true);', '// $this->assertTrue(true);', self::ORIGINAL))->status);
    }

    public function test_accept_marker_turns_findings_into_accepted(): void
    {
        $result = $this->check("<?php\n// verify:accept-test-change merged into FeedTest\n");

        $this->assertSame('pass', $result->status);
        $this->assertTrue($result->accepted);
        $this->assertNotEmpty($result->violations);
    }

    public function test_deleting_the_file_fails(): void
    {
        unlink($this->dir.'/tests/PostTest.php');
        $check = new TestIntegrityCheck(new Workspace($this->dir), new ProcessRunner($this->dir));
        $result = $check->run(new Target('test_integrity', 'test_integrity', 'x', [], ['files' => ['tests/PostTest.php']]));

        $this->assertSame('test_integrity.file_removed', $result->violations[0]->rule);
    }
}
