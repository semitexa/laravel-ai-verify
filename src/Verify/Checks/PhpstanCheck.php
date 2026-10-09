<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

/**
 * PHPStan (Larastan) over the changed production files only. Uses the
 * project's config — and therefore its baseline — when one exists; otherwise
 * falls back to Larastan at the configured level. A pass requires the exit
 * code *and* the JSON diagnostics to agree.
 */
final class PhpstanCheck implements Check
{
    private const CONFIGS = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
        private readonly int $fallbackLevel = 5,
        private readonly string $memoryLimit = '1G',
    ) {}

    public function run(Target $target): Result
    {
        $phpstan = $this->workspace->bin('phpstan');

        if ($phpstan === null) {
            return Result::skipped('PHPStan is not installed — composer require --dev larastan/larastan');
        }

        $config = $this->config();

        if ($config === null) {
            return Result::skipped('No phpstan.neon and Larastan is not installed; plain PHPStan on Laravel code is too noisy to trust');
        }

        $files = (array) $target->params['files'];
        $outcome = $this->runner->run([
            $this->workspace->phpBinary, $phpstan, 'analyse',
            '--no-progress', '--no-interaction', '--error-format=json',
            '--memory-limit='.$this->memoryLimit,
            '-c', $config,
            ...$files,
        ]);

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        // PHPStan may print warnings before the JSON document.
        $start = strpos($outcome->stdout, '{"totals"');
        $json = $start === false ? null : json_decode(substr($outcome->stdout, $start), true);

        if (! is_array($json)) {
            return Result::incomplete('PHPStan did not return a report: '.$outcome->lastLine(), $outcome->exitCode);
        }

        $violations = [];

        foreach ($json['files'] ?? [] as $path => $file) {
            foreach ($file['messages'] ?? [] as $message) {
                $violations[] = new Violation(
                    (string) $message['message'],
                    $this->workspace->relative((string) $path),
                    isset($message['line']) ? (int) $message['line'] : null,
                    $message['identifier'] ?? 'phpstan',
                    tip: $message['tip'] ?? null,
                );
            }
        }

        foreach ($json['errors'] ?? [] as $error) {
            $error = (string) $error;
            $file = preg_match('/while analysing file (\S+\.php)/', $error, $m) ? $this->workspace->relative($m[1]) : null;
            $message = trim((string) preg_replace(['/\s*while analysing file \S+/', '/\s*Run PHPStan with -v.*$/s', '/^Internal error:\s*/'], '', $error));

            $violations[] = new Violation($message, $file, null, 'phpstan.internal');
        }

        if ($violations === [] && $outcome->succeeded()) {
            return Result::pass('PHPStan: no errors in '.count($files).' file(s)');
        }

        if ($violations === []) {
            return Result::incomplete('PHPStan exited '.$outcome->exitCode.' without diagnostics: '.$outcome->lastLine(), $outcome->exitCode);
        }

        [$introduced, $preexisting] = $this->splitByChangedLines($violations, (string) ($target->params['ref'] ?? 'HEAD'));

        if ($introduced === []) {
            return new Result(
                Result::PASS,
                'PHPStan: no errors on changed lines; '.count($preexisting).' pre-existing error(s) elsewhere in the touched files',
                $outcome->exitCode,
                $preexisting,
                accepted: true,
            );
        }

        $violations = [...$introduced, ...$preexisting];
        $first = $introduced[0];

        return Result::fail(
            count($introduced).' error(s) introduced by this change'.($preexisting ? ' (+'.count($preexisting).' pre-existing)' : '').'; first: '.$first->path.':'.$first->line.' '.$first->message,
            $violations,
            $outcome->exitCode,
        );
    }

    /**
     * Diagnostics on lines this change touched are the agent's; the rest were
     * already there. Pre-existing ones are reported as warnings and do not fail
     * the check — the question verify answers is "did this change break anything".
     *
     * @param  list<Violation>  $violations
     * @return array{0: list<Violation>, 1: list<Violation>}
     */
    private function splitByChangedLines(array $violations, string $ref): array
    {
        $introduced = $preexisting = [];
        $changed = [];

        foreach ($violations as $violation) {
            $path = $violation->path;

            if ($path === null || $violation->line === null) {
                $introduced[] = $violation;

                continue;
            }

            $changed[$path] ??= $this->changedLines($path, $ref);
            $lines = $changed[$path];

            if ($lines === true || isset($lines[$violation->line])) {
                $introduced[] = $violation;
            } else {
                $preexisting[] = new Violation(
                    $violation->message, $violation->path, $violation->line, $violation->rule,
                    severity: 'warning',
                    tip: 'Pre-existing: this line was not changed. '.($violation->tip ?? ''),
                );
            }
        }

        return [$introduced, $preexisting];
    }

    /** @return array<int, true>|true  changed line numbers, or true when the whole file is new/unknown */
    private function changedLines(string $path, string $ref): array|bool
    {
        $tracked = $this->runner->run(['git', 'cat-file', '-e', $ref.':'.$path]);

        if (! $tracked->succeeded()) {
            return true;
        }

        $diff = $this->runner->run(['git', 'diff', '--no-color', '-U0', $ref, '--', $path]);

        if (! $diff->succeeded()) {
            return true;
        }

        $lines = [];

        preg_match_all('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/m', $diff->output, $hunks, PREG_SET_ORDER);

        foreach ($hunks as $hunk) {
            $start = (int) $hunk[1];
            $count = isset($hunk[2]) ? (int) $hunk[2] : 1;

            // A pure deletion (count 0, `start` = line before it) can break its neighbours.
            $end = $count === 0 ? $start + 1 : $start + $count - 1;

            for ($line = max(1, $start); $line <= $end; $line++) {
                $lines[$line] = true;
            }
        }

        return $lines;
    }

    private function config(): ?string
    {
        foreach (self::CONFIGS as $name) {
            if ($this->workspace->exists($name)) {
                return $this->workspace->path($name);
            }
        }

        $extension = $this->workspace->path('vendor/larastan/larastan/extension.neon');

        if (! is_file($extension)) {
            return null;
        }

        $path = $this->workspace->tempPath('phpstan-fallback.neon');
        file_put_contents($path, "includes:\n    - {$extension}\nparameters:\n    level: {$this->fallbackLevel}\n");

        return $path;
    }
}
