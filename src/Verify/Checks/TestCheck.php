<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ErrorText;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;
use SimpleXMLElement;

/**
 * Runs Pest (preferred) or PHPUnit on a test file, a directory or the whole
 * suite and reads the JUnit log — never the console output, which agents'
 * output wrappers and printers are free to reshape.
 */
final class TestCheck implements Check
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    public function runnerName(): ?string
    {
        return $this->workspace->bin('pest') !== null ? 'pest' : ($this->workspace->bin('phpunit') !== null ? 'phpunit' : null);
    }

    public function run(Target $target): Result
    {
        $runner = $this->runnerName();

        if ($runner === null) {
            return Result::skipped('Neither Pest nor PHPUnit is installed');
        }

        $junit = $this->workspace->tempPath('junit-'.md5($target->id).'.xml');
        @unlink($junit);

        $path = $target->params['path'] ?? null;
        $argv = [$this->workspace->phpBinary, (string) $this->workspace->bin($runner), '--log-junit', $junit, '--do-not-cache-result'];

        if ($path !== null) {
            $argv[] = (string) $path;
        }

        $outcome = $this->runner->run($argv, ['APP_ENV' => 'testing']);

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        $report = is_file($junit) ? @simplexml_load_file($junit) : false;
        @unlink($junit);

        if (! $report instanceof SimpleXMLElement) {
            // The runner died before writing a log: a fatal in bootstrap or a test file.
            return $outcome->succeeded()
                ? Result::incomplete("{$runner} exited 0 but wrote no JUnit log: ".$outcome->lastLine(), 0)
                : Result::fail("{$runner} crashed: ".$this->crashLine($outcome->output), [
                    new Violation($this->crashLine($outcome->output), is_string($path) ? $path : null, null, 'tests.crash'),
                ], $outcome->exitCode);
        }

        $suite = isset($report->testsuite[0]) ? $report->testsuite[0] : $report;
        $tests = (int) ($suite['tests'] ?? 0);
        $failures = (int) ($suite['failures'] ?? 0) + (int) ($suite['errors'] ?? 0);
        $skipped = (int) ($suite['skipped'] ?? 0);

        if ($tests === 0) {
            return Result::incomplete('No tests executed'.($path ? " in {$path}" : ''), $outcome->exitCode);
        }

        $violations = $this->failures($report);
        $label = "{$tests} test(s), {$failures} failed".($skipped ? ", {$skipped} skipped" : '');

        if ($failures === 0 && $outcome->succeeded()) {
            return Result::pass("{$runner}: {$label}");
        }

        if ($failures === 0) {
            return Result::fail("{$runner} exited {$outcome->exitCode} ({$label}): ".$outcome->lastLine(), [], $outcome->exitCode);
        }

        $headline = $violations !== [] ? $violations[0]->rule.' — '.$violations[0]->message.' · ' : '';

        return Result::fail(mb_strimwidth($headline.$label, 0, 400, '…'), $violations, $outcome->exitCode);
    }

    /** @return list<Violation> */
    private function failures(SimpleXMLElement $report): array
    {
        $violations = [];

        foreach ($report->xpath('//testcase[failure or error]') ?: [] as $case) {
            $node = isset($case->failure[0]) ? $case->failure[0] : $case->error[0];
            $text = trim((string) ($node['message'] ?? '') ?: (string) $node);
            $name = trim(($case['class'] ?? '').'::'.($case['name'] ?? ''), ':');
            // PHPUnit prefixes the failure body with the test's own name; the rule already carries it.
            $text = str_starts_with($text, $name) ? ltrim(substr($text, strlen($name))) : $text;
            [$file, $line] = $this->location((string) $node, (string) ($case['file'] ?? ''), (int) ($case['line'] ?? 0));

            $violations[] = new Violation(
                ErrorText::message($text, $this->workspace),
                $file,
                $line ?: null,
                $name,
            );

            if (count($violations) >= 50) {
                break;
            }
        }

        return $this->collapse($violations);
    }

    /**
     * Prefer the first project frame (not vendor/, not compiled views) from the failure text.
     *
     * @return array{0: ?string, 1: int}
     */
    private function location(string $trace, string $file, int $line): array
    {
        [$found, $at] = ErrorText::location($trace, $this->workspace);

        if ($found !== null) {
            return [$found, (int) $at];
        }

        return [$file !== '' ? $this->workspace->relative($file) : null, $line];
    }

    /**
     * One broken migration or view fails every test that touches it; report the cause once.
     *
     * @param  list<Violation>  $violations
     * @return list<Violation>
     */
    private function collapse(array $violations): array
    {
        $groups = [];

        foreach ($violations as $violation) {
            $groups[$violation->message.'|'.$violation->path.'|'.$violation->line][] = $violation;
        }

        return array_values(array_map(static function (array $group): Violation {
            $first = $group[0];

            return count($group) === 1 ? $first : new Violation(
                $first->message, $first->path, $first->line,
                $first->rule.' (+'.(count($group) - 1).' more test(s) failing the same way)',
            );
        }, $groups));
    }

    private function crashLine(string $output): string
    {
        if (preg_match('/(PHP )?(Fatal error|Parse error|Error|Exception)[^\n]{0,300}/', $output, $m)) {
            return trim($m[0]);
        }

        return mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $output)), 0, 300, '…') ?: 'no output';
    }
}
