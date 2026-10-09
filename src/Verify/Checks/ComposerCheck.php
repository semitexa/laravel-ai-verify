<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;
use Symfony\Component\Process\ExecutableFinder;

/** `composer validate` — schema, and whether composer.lock is in sync with composer.json. */
final class ComposerCheck implements Check
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function run(Target $target): Result
    {
        $composer = (new ExecutableFinder)->find('composer');

        if ($composer === null) {
            return Result::skipped('composer executable not found on PATH');
        }

        $outcome = $this->runner->run([$composer, 'validate', '--no-check-publish', '--no-interaction', '--no-ansi']);

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        if ($outcome->succeeded()) {
            return Result::pass('composer.json valid, lock file in sync');
        }

        return Result::fail('composer validate: '.$outcome->lastLine(), [
            new Violation(trim($outcome->output), 'composer.json', null, 'composer.validate'),
        ], $outcome->exitCode);
    }
}
