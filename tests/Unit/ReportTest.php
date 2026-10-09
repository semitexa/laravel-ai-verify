<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Verify\ChangedFile;
use Semitexa\LaravelAiVerify\Verify\FileKind;
use Semitexa\LaravelAiVerify\Verify\Plan;
use Semitexa\LaravelAiVerify\Verify\Report;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Scope;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

final class ReportTest extends TestCase
{
    private function file(string $path, FileKind $kind = FileKind::Model): ChangedFile
    {
        $file = new ChangedFile($path);
        $file->kind = $kind;

        return $file;
    }

    /** @param list<string> $triggers */
    private function bound(Result $result, array $triggers = ['app/A.php'], string $check = 'syntax', bool $required = true): Result
    {
        return $result->bindTo(new Target($check.':'.uniqid(), $check, 'test', $triggers, required: $required));
    }

    /** @param list<Result> $results */
    private function report(array $results, array $files = []): Report
    {
        return new Report(new Plan(Scope::Standard, Scope::Standard), $files ?: [$this->file('app/A.php')], $results);
    }

    public function test_verdict_rollup(): void
    {
        $this->assertSame('incomplete', $this->report([])->verdict);
        $this->assertSame('pass', $this->report([$this->bound(Result::pass('ok'))])->verdict);
        $this->assertSame('fail', $this->report([$this->bound(Result::pass('ok')), $this->bound(Result::fail('no'))])->verdict);
        $this->assertSame('incomplete', $this->report([$this->bound(Result::pass('ok')), $this->bound(Result::incomplete('timeout'))])->verdict);
        $this->assertSame('incomplete', $this->report([$this->bound(Result::pass('ok')), $this->bound(Result::skipped('no tool'))])->verdict, 'a skipped required check is incomplete');
        $this->assertSame('pass', $this->report([$this->bound(Result::pass('ok')), $this->bound(Result::skipped('no tool'), required: false)])->verdict);
    }

    public function test_exit_code(): void
    {
        $this->assertSame(0, $this->report([$this->bound(Result::pass('ok'))])->exitCode());
        $this->assertSame(1, $this->report([$this->bound(Result::fail('no'))])->exitCode());
        $this->assertSame(1, $this->report([])->exitCode());
    }

    public function test_coverage_gap_lists_unread_files_and_downgrades_when_nothing_was_read(): void
    {
        $files = [$this->file('app/A.php'), $this->file('README.md', FileKind::NonPhp)];

        $partial = $this->report([$this->bound(Result::pass('ok'))], $files);
        $this->assertSame('pass', $partial->verdict);
        $this->assertSame([['path' => 'README.md', 'kind' => 'non_php']], $partial->uncheckedFiles);
        $this->assertStringContainsString('no check read 1 of them: README.md', $partial->headline);

        $none = $this->report([$this->bound(Result::pass('ok'), ['other.php'], 'test_integrity')], $files);
        $this->assertSame('incomplete', $none->verdict, 'pass with zero files actually read is not a pass');
    }

    public function test_next_commands_point_at_the_fix(): void
    {
        $pint = $this->bound(Result::fail('style', [new Violation('x', 'app/A.php', null, 'pint')]), check: 'pint');
        $next = $this->report([$pint])->nextCommands();

        $this->assertSame('vendor/bin/pint app/A.php', $next[0]['cmd']);
    }
}
