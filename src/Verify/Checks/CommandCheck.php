<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

/**
 * A check the project defines in `ai-verify.commands`: Rector, Deptrac,
 * Psalm, a composer script, `npm run lint`. The exit code is the verdict;
 * `{files}` expands to the changed files the command's `when` matched.
 *
 * A string runs through the platform's shell; a list is run as argv, with a
 * `{files}` element replaced by the files.
 */
final class CommandCheck implements Check
{
    private const TAIL_LINES = 20;

    private const NOT_FOUND = 127;

    public function __construct(private readonly ProcessRunner $runner) {}

    public function run(Target $target): Result
    {
        $name = (string) $target->params['name'];
        $run = $target->params['run'] ?? null;
        $files = (array) ($target->params['files'] ?? []);
        $runner = isset($target->params['timeout']) ? $this->runner->withTimeout((int) $target->params['timeout']) : $this->runner;

        if (is_array($run) && $run !== []) {
            $argv = [];

            foreach ($run as $part) {
                array_push($argv, ...($part === '{files}' ? $files : [(string) $part]));
            }

            $display = implode(' ', $argv);
            $outcome = $runner->run($argv);
        } elseif (is_string($run) && trim($run) !== '') {
            $display = str_replace('{files}', implode(' ', $files), $run);
            $outcome = $runner->runShell(str_replace('{files}', implode(' ', array_map('escapeshellarg', $files)), $run));
        } else {
            return Result::incomplete("ai-verify.commands.{$name}: 'run' must be a command line or a list of arguments");
        }

        if ($outcome->aborted()) {
            $result = Result::aborted($outcome);
        } elseif ($outcome->succeeded()) {
            $result = Result::pass("{$name}: passed");
        } elseif ($outcome->exitCode === self::NOT_FOUND) {
            // Skipped, so `required` decides: incomplete when required, a plain skip when not.
            $result = new Result(Result::SKIPPED, "{$name}: command not found — {$outcome->lastLine()}", $outcome->exitCode);
        } else {
            $result = Result::fail(
                "{$name} failed (exit {$outcome->exitCode}): {$outcome->lastLine()}",
                [new Violation($this->tail($outcome->output), null, null, 'command.'.$name, tip: 'Reproduce: '.$display)],
                $outcome->exitCode,
            );
        }

        $result->command = [$display];

        return $result;
    }

    private function tail(string $output): string
    {
        $lines = preg_split('/\R/', rtrim($output)) ?: [];
        $tail = array_slice($lines, -self::TAIL_LINES);

        return (count($lines) > self::TAIL_LINES ? "…\n" : '').implode("\n", $tail);
    }
}
