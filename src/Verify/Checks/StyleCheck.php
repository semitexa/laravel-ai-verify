<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Style\Finding;
use Semitexa\LaravelAiVerify\Verify\Style\StyleSetup;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

/**
 * The project's own code style tool (Pint, PHP-CS-Fixer, PHPCS or ECS) on the
 * changed files only. Each file is also checked as it was at the base ref, and
 * only the rules the change added to a file fail the check: a legacy file the
 * agent touched does not make it responsible for the whole file's style.
 */
final class StyleCheck implements Check
{
    /**
     * @param  array<string, StyleSetup>  $setups  tool id => setup
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
        private readonly array $setups,
        private readonly string $none = 'no code style tool found',
    ) {}

    public function run(Target $target): Result
    {
        $setup = $this->setups[(string) ($target->params['tool'] ?? '')] ?? null;

        if ($setup === null) {
            return Result::skipped($this->none);
        }

        if ($setup->bin === null) {
            return Result::skipped((string) $setup->missing());
        }

        $tool = $setup->tool;
        $files = (array) $target->params['files'];
        $config = $setup->config !== null ? $this->workspace->path($setup->config) : null;
        $outcome = $this->runner->run($tool->argv($this->workspace->phpBinary, $setup->bin, $config, $files));

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        $found = $tool->parse($outcome->stdout, $this->workspace);

        if ($found === null) {
            return $outcome->succeeded()
                ? Result::pass($tool->label().': style ok')
                : Result::incomplete($tool->label().' produced no report: '.$outcome->lastLine(), $outcome->exitCode);
        }

        $ref = (string) ($target->params['ref'] ?? 'HEAD');
        $violations = $preexisting = [];

        foreach ($found as $path => $findings) {
            [$introduced, $old] = $this->split($findings, $this->countsAtRef($setup, $config, $path, $ref));
            $fix = $tool->fixCommand([$path]);

            array_push($violations, ...$this->violations($introduced, $path, $tool->id(), 'error', $fix !== null ? 'Auto-fix: '.$fix : null));
            array_push($preexisting, ...$this->violations($old, $path, $tool->id(), 'warning', "Already present at {$ref}"));
        }

        $failing = array_values(array_filter($violations, static fn (Violation $v) => $v->severity === 'error'));

        if ($failing === []) {
            $notes = [...$violations, ...$preexisting];

            return $notes === []
                ? Result::pass($tool->label().': '.count($files).' file(s) follow the project style')
                : new Result(Result::PASS, $tool->label().': no new style errors; '.count($notes).' warning(s) or pre-existing issue(s)', $outcome->exitCode, $notes, accepted: true);
        }

        $paths = array_values(array_unique(array_map(static fn (Violation $v) => (string) $v->path, $failing)));
        $fix = $tool->fixCommand($paths);

        return Result::fail(
            count($paths).' file(s) break the project style ('.$tool->label().')'.($fix !== null ? ' — run '.$fix : ''),
            [...$violations, ...$preexisting],
            $outcome->exitCode,
        );
    }

    /**
     * A rule counts as introduced when the file now breaks it more often than
     * it did at the base ref. Warnings never fail, wherever they come from.
     *
     * @param  list<Finding>  $findings
     * @param  array<string, int>|null  $before  rule => count at the base ref; null when the file is new
     * @return array{0: list<Finding>, 1: list<Finding>}
     */
    private function split(array $findings, ?array $before): array
    {
        if ($before === null) {
            return [$findings, []];
        }

        $now = [];

        foreach ($findings as $finding) {
            $now[$finding->rule] = ($now[$finding->rule] ?? 0) + 1;
        }

        $introduced = $old = [];

        foreach ($findings as $finding) {
            if ($now[$finding->rule] > ($before[$finding->rule] ?? 0)) {
                $introduced[] = $finding;
            } else {
                $old[] = $finding;
            }
        }

        return [$introduced, $old];
    }

    /**
     * Findings with a line and a message become one violation each; rule-only
     * findings (fixers) are grouped into one violation per file.
     *
     * @param  list<Finding>  $findings
     * @return list<Violation>
     */
    private function violations(array $findings, string $path, string $tool, string $severity, ?string $tip): array
    {
        $out = [];
        $rules = [];

        foreach ($findings as $finding) {
            $level = $finding->warning ? 'warning' : $severity;

            if ($finding->message !== null) {
                $out[] = new Violation($finding->message, $path, $finding->line, $tool.'.'.$finding->rule, severity: $level,
                    tip: $finding->warning && $severity === 'error' ? 'Warning: reported, does not fail the check' : $tip);
            } else {
                $rules[$level][] = $finding->rule;
            }
        }

        foreach ($rules as $level => $names) {
            $prefix = $severity === 'warning' ? 'Pre-existing style issues: ' : 'Style issues: ';
            array_unshift($out, new Violation($prefix.implode(', ', array_unique($names)), $path, null, $tool, severity: $level, tip: $tip));
        }

        return $out;
    }

    /**
     * Rule counts for the file as it was at `$ref`, checked under its own
     * name (sniffs look at file names) in a scratch directory; null when the
     * file is new there.
     *
     * @return array<string, int>|null
     */
    private function countsAtRef(StyleSetup $setup, ?string $config, string $path, string $ref): ?array
    {
        $base = $this->runner->run(['git', 'show', $ref.':'.$path]);

        if (! $base->succeeded()) {
            return null;
        }

        $dir = $this->workspace->tempPath('style-base-'.md5($ref.':'.$path));
        @mkdir($dir, 0o777, true);
        $temp = $dir.'/'.basename($path);
        file_put_contents($temp, $base->stdout);

        $outcome = $this->runner->run($setup->tool->argv($this->workspace->phpBinary, (string) $setup->bin, $config, [$temp]));
        @unlink($temp);
        @rmdir($dir);

        $counts = [];

        foreach ($setup->tool->parse($outcome->stdout, $this->workspace) ?? [] as $findings) {
            foreach ($findings as $finding) {
                $counts[$finding->rule] = ($counts[$finding->rule] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
