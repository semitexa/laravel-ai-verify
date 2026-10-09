<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

/**
 * Runs up() and down() of one migration in pretend mode, in a fresh process
 * (see MigrationProbeCommand). Nothing touches the database schema; the SQL
 * is only compiled. A missing database connection is "skipped", not "fail".
 */
final class MigrationCheck implements Check
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    public function run(Target $target): Result
    {
        $file = (string) $target->params['file'];
        $outcome = $this->runner->run($this->workspace->artisan('ai:verify:migration', $file, '--no-interaction'));

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        $start = strpos($outcome->stdout, '{"migration"');
        $report = $start === false ? null : json_decode(trim(substr($outcome->stdout, $start)), true);

        if (! is_array($report)) {
            return Result::fail('migration probe crashed: '.$outcome->lastLine(), [
                new Violation($outcome->lastLine(), $file, null, 'laravel.migration'),
            ], $outcome->exitCode);
        }

        if ($report['status'] === 'skipped') {
            return Result::skipped($report['message']);
        }

        if ($report['status'] === 'pass') {
            return Result::pass($report['message']);
        }

        return Result::fail($report['message'], [
            new Violation($report['message'], $report['file'] ?? $file, $report['line'] ?? null, 'laravel.migration'),
        ], $outcome->exitCode);
    }
}
