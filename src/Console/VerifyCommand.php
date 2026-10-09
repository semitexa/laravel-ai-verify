<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Semitexa\LaravelAiVerify\Agents\AgentInstructions;
use Semitexa\LaravelAiVerify\Receipts\Receipts;
use Semitexa\LaravelAiVerify\Support\GitTree;
use Semitexa\LaravelAiVerify\Toolkit;
use Semitexa\LaravelAiVerify\Verify\ChangeCollector;
use Semitexa\LaravelAiVerify\Verify\ChangedFile;
use Semitexa\LaravelAiVerify\Verify\Planner;
use Semitexa\LaravelAiVerify\Verify\Report;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Scope;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\TestLocator;

/**
 * Agent-facing verifier: takes a change set, plans the precise subset of
 * syntax / Blade / Pint / PHPStan / Laravel boot / test checks it needs,
 * runs them with timeouts, and emits a verdict an agent can act on.
 *
 *   php artisan ai:verify                       # uncommitted work (default)
 *   php artisan ai:verify --git-ref=main        # everything since main
 *   php artisan ai:verify --files=app/Models/Post.php --scope=minimal
 *   git diff --name-status HEAD~3 | php artisan ai:verify --diff-stdin
 *
 * Exit code: 0 for pass/skipped, 1 for fail/incomplete.
 *
 * Laravel port of Semitexa's `ai:verify` — https://semitexa.com
 */
final class VerifyCommand extends Command
{
    protected $signature = 'ai:verify
        {--files=* : Changed paths (repeatable, or comma-separated)}
        {--git-ref= : Verify everything that differs from this ref (plus untracked files)}
        {--diff-stdin : Read `git diff --name-only` / `--name-status` output from stdin}
        {--dirty : Verify uncommitted work: staged, unstaged and untracked (default when no source is given)}
        {--scope= : minimal | standard | broad}
        {--impact : Add a blast-radius report from the project graph}
        {--json : Print one JSON envelope}
        {--ndjson : Stream one JSON object per line (default when stdout is not a terminal)}
        {--human : Human-readable output (default in a terminal)}
        {--github : GitHub Actions: human-readable log, ::error annotations on the diff and a job summary}
        {--no-graph : Skip the project graph (faster; test selection falls back to naming only)}';

    protected $description = 'Plan and run the minimal checks that verify a change set; machine-readable verdict for AI agents';

    private string $mode;

    public function handle(): int
    {
        $this->mode = match (true) {
            (bool) $this->option('json') => 'json',
            (bool) $this->option('ndjson') => 'ndjson',
            (bool) $this->option('human'), (bool) $this->option('github') => 'human',
            default => $this->isTerminal() ? 'human' : 'ndjson',
        };

        $toolkit = Toolkit::fromApp($this->laravel);

        try {
            [$files, $source, $dirtyClean] = $this->collect($toolkit);
        } catch (RuntimeException $e) {
            return $this->reportError($e->getMessage());
        }

        if ($dirtyClean) {
            $this->emit(['kind' => 'verdict', 'verdict' => 'nothing_to_verify', 'source' => $source, 'headline' => 'Working tree is clean — nothing to verify']);

            return self::SUCCESS;
        }

        if ($files === []) {
            return $this->reportError('no changed files supplied — pass --files, --git-ref, --diff-stdin or --dirty');
        }

        foreach ($files as $file) {
            $file->kind = $toolkit->classifier->classify($file->path);
        }

        $scope = Scope::fromInput($this->option('scope') ?: ($toolkit->config['scope'] ?? 'standard'));
        $useGraph = ! $this->option('no-graph') && ($toolkit->config['graph']['enabled'] ?? true)
            && ($scope !== Scope::Minimal || $this->option('impact'));

        $planner = new Planner(
            new TestLocator($toolkit->workspace, (string) ($toolkit->config['tests_path'] ?? 'tests')),
            $useGraph ? $toolkit->impact() : null,
            [
                'broad_threshold' => $toolkit->config['broad_threshold'] ?? 15,
                'max_test_targets' => $toolkit->config['max_test_targets'] ?? 25,
                'test_depth' => $toolkit->config['test_depth'] ?? 4,
                ...((array) ($toolkit->config['checks'] ?? [])),
            ],
            $this->option('git-ref') ?: null,
            is_dir($toolkit->workspace->path('.git')) || $source !== 'files',
        );

        $plan = $planner->plan($files, $scope);
        $paths = array_map(static fn ($f) => $f->path, $files);
        $impact = $this->option('impact') && $useGraph ? $toolkit->impact()->report($paths) : null;

        $this->emit(['kind' => 'summary', 'source' => $source, 'requested_scope' => $scope->value,
            'effective_scope' => $plan->effectiveScope->value, 'changed_files' => count($files),
            'targets' => count($plan->targets()), 'tool' => 'semitexa/laravel-ai-verify '.Toolkit::VERSION]);

        if ($this->mode === 'ndjson') {
            foreach ($files as $file) {
                $this->emit(['kind' => 'file', 'file_kind' => $file->kind->value] + $file->toArray());
            }
        }

        if ($impact !== null) {
            $this->emit(['kind' => 'impact'] + $impact);
        }

        foreach ($plan->expansions as $note) {
            $this->emit(['kind' => 'expansion', 'note' => $note]);
        }

        foreach ($toolkit->graphWarnings() as $warning) {
            $this->emit(['kind' => 'warning', 'message' => 'graph probe failed — '.$warning]);
        }

        if ($this->mode === 'ndjson') {
            foreach ($plan->targets() as $target) {
                $this->emit(['kind' => 'target'] + $target->toArray());
            }
        }

        // What the receipt will vouch for: the state *before* any check ran.
        $receipts = new Receipts($toolkit->workspace);
        $git = new GitTree($toolkit->runner);
        $filesBefore = $receipts->fingerprint(array_map(static fn ($f) => $f->path, $files));
        $treeBefore = $git->working();

        $results = $toolkit->executor()->execute(
            $plan,
            fn (Target $target) => $this->mode === 'human' && $this->isTerminal() ? $this->output->write("  <fg=gray>…</> {$target->id}\r") : null,
            fn (Result $result) => $this->emitResult($result),
        );

        $report = new Report($plan, $files, $results, $impact);
        $restart = $report->restartHints($toolkit->workspace);
        $next = $report->nextCommands();

        $receipt = $receipts->write([
            'verdict' => $report->verdict,
            'scope' => $plan->effectiveScope->value,
            'source' => $source,
            'counts' => $report->counts,
            'results' => array_map(static fn (Result $r) => $r->toArray(), $results),
        ], $filesBefore, $treeBefore, $git->head());
        $receiptRef = $receipt === null ? null : array_filter([
            'id' => $receipt['id'],
            'path' => Receipts::DIR.'/'.$receipt['id'].'.json',
            'tree' => $receipt['tree'],
            'digest' => $receipt['digest'],
            'changed_during_run' => $receipt['changed_during_run'] ?: null,
        ], static fn ($v) => $v !== null);

        if ($this->mode === 'json') {
            $this->line((string) json_encode([
                'schema' => Report::SCHEMA,
                'generated_at' => date(DATE_ATOM),
                'tool' => ['name' => 'semitexa/laravel-ai-verify', 'version' => Toolkit::VERSION, 'homepage' => Toolkit::HOMEPAGE],
                'source' => $source,
                ...$report->summary(),
                'expansions' => $plan->expansions,
                'files' => array_map(static fn ($f) => $f->toArray(), $files),
                'targets' => array_map(static fn (Target $t) => $t->toArray(), $plan->targets()),
                'results' => array_map(static fn (Result $r) => $r->toArray(), $results),
                'violations' => $report->violations(),
                'verdict' => $report->verdict,
                'counts' => $report->counts,
                'headline' => $report->headline,
                'unchecked_files' => $report->uncheckedFiles,
                'impact' => $impact,
                'restart' => $restart,
                'next' => $next,
                'receipt' => $receiptRef,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $report->exitCode();
        }

        foreach ($restart as $hint) {
            $this->emit(['kind' => 'restart'] + $hint);
        }

        foreach ($next as $hint) {
            $this->emit(['kind' => 'next'] + $hint);
        }

        if ($receiptRef !== null) {
            $this->emit(['kind' => 'receipt'] + $receiptRef);
        }

        $this->emit(['kind' => 'verdict', 'verdict' => $report->verdict, 'counts' => $report->counts,
            'headline' => $report->headline, 'unchecked_files' => $report->uncheckedFiles]);

        if ($this->option('github')) {
            $this->github($report, $results, $receiptRef);
        } else {
            $this->suggestInstall($toolkit);
        }

        return $report->exitCode();
    }

    /** @return array{0: list<ChangedFile>, 1: string, 2: bool} */
    private function collect(Toolkit $toolkit): array
    {
        $collector = new ChangeCollector($toolkit->workspace, $toolkit->runner);
        $sources = [];

        if ($files = (array) $this->option('files')) {
            $collector->addFiles($files);
            $sources[] = 'files';
        }

        if ($ref = $this->option('git-ref')) {
            $collector->addGitRef((string) $ref);
            $sources[] = 'git-ref:'.$ref;
        }

        if ($this->option('diff-stdin')) {
            $collector->addDiffText((string) stream_get_contents(STDIN));
            $sources[] = 'stdin';
        }

        $dirtyRequested = (bool) $this->option('dirty');

        if ($dirtyRequested || $sources === []) {
            if (! $collector->addDirty()) {
                throw new RuntimeException('not a git repository (or git is unavailable) — pass --files or --diff-stdin');
            }

            $sources[] = $dirtyRequested ? 'dirty' : 'dirty (default)';

            if (count($sources) === 1 && $collector->files() === []) {
                return [[], $sources[0], true];
            }
        }

        return [$collector->files(), implode('+', $sources), false];
    }

    private function emitResult(Result $result): void
    {
        if ($this->mode === 'json') {
            return;
        }

        if ($this->mode === 'human') {
            $this->renderResult($result);

            return;
        }

        $this->emit(['kind' => 'result'] + $result->toArray());

        foreach ($result->violations as $violation) {
            $this->emit(['kind' => 'violation', 'target' => $result->id, 'check' => $result->check] + $violation->toArray());
        }
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        match ($this->mode) {
            'ndjson' => $this->line((string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'human' => $this->renderEvent($event),
            default => null,
        };
    }

    // ── human rendering ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $event */
    private function renderEvent(array $event): void
    {
        switch ($event['kind']) {
            case 'summary':
                $this->newLine();
                $scope = $event['requested_scope'] === $event['effective_scope']
                    ? $event['effective_scope']
                    : "{$event['requested_scope']} → {$event['effective_scope']}";
                $this->line("  <options=bold>ai:verify</> · {$event['changed_files']} changed file(s) · scope <fg=cyan>{$scope}</> · {$event['targets']} check(s) · source {$event['source']}");
                $this->newLine();
                break;

            case 'impact':
                $this->line("  <fg=magenta>impact</> max <options=bold>{$event['max']}</> · {$event['dependents']} dependent(s) · {$event['routes_affected']} route(s)".($event['hottest'] ? " · hottest {$event['hottest']}" : ''));
                break;

            case 'expansion':
                $this->line("  <fg=yellow>↗</> {$event['note']}");
                break;

            case 'warning':
                $this->line("  <fg=yellow>!</> {$event['message']}");
                break;

            case 'restart':
                $this->line("  <fg=yellow>↻</> <options=bold>{$event['cmd']}</> — {$event['why']}");
                break;

            case 'receipt':
                $tree = isset($event['tree']) ? ' · tree '.substr((string) $event['tree'], 0, 10) : '';
                $this->line("  <fg=gray>receipt {$event['id']}{$tree} — php artisan ai:verify:receipt</>");
                break;

            case 'next':
                $this->line("  <fg=cyan>→</> <options=bold>{$event['cmd']}</> — {$event['why']}");
                break;

            case 'verdict':
                $color = match ($event['verdict']) {
                    'pass', 'nothing_to_verify' => 'green',
                    'fail' => 'red',
                    default => 'yellow',
                };
                $this->newLine();
                $counts = isset($event['counts'])
                    ? ' · '.implode(' · ', array_map(static fn ($k, $v) => "{$v} {$k}", array_keys($event['counts']), $event['counts']))
                    : '';
                $this->line('  <bg='.$color.';fg=black;options=bold> '.strtoupper((string) $event['verdict'])." </>{$counts}");
                $this->line("  <fg=gray>{$event['headline']}</>");
                $this->newLine();
                $this->line('  <fg=gray>Laravel port of Semitexa ai:verify · '.Toolkit::HOMEPAGE.'</>');
                $this->newLine();
                break;

            case 'error':
                $this->line("  <fg=red>error</> {$event['error']}");
                break;
        }
    }

    private function renderResult(Result $result): void
    {
        [$icon, $color] = match ($result->effectiveStatus()) {
            Result::PASS => ['✓', 'green'],
            Result::FAIL => ['✗', 'red'],
            Result::SKIPPED => ['○', 'gray'],
            default => ['!', 'yellow'],
        };

        $time = $result->durationMs >= 1000 ? round($result->durationMs / 1000, 1).'s' : $result->durationMs.'ms';
        if ($this->isTerminal()) {
            $this->output->write("\033[2K");
        }

        $this->line("  <fg={$color}>{$icon}</> {$result->id} <fg=gray>{$time} — {$result->signal}</>");

        foreach (array_slice($result->violations, 0, 10) as $violation) {
            $where = $violation->path !== null ? $violation->path.($violation->line ? ':'.$violation->line : '') : '';
            $this->line("      <fg={$color}>›</> {$where} {$violation->message}");

            if ($violation->tip !== null) {
                $this->line("        <fg=gray>{$violation->tip}</>");
            }
        }

        if (count($result->violations) > 10) {
            $this->line('      <fg=gray>… '.(count($result->violations) - 10).' more (use --json)</>');
        }
    }

    /**
     * Annotations land on the PR diff at path:line; the summary goes on the run page.
     *
     * @param  list<Result>  $results
     * @param  array<string, mixed>|null  $receipt
     */
    private function github(Report $report, array $results, ?array $receipt): void
    {
        $escape = static fn (string $v) => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $v);
        $prop = static fn (string $v) => str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $v);

        foreach ($report->violations() as $v) {
            $level = ($v['severity'] ?? 'error') === 'error' && empty($v['accepted']) ? 'error' : 'warning';
            $props = array_filter([
                'file' => $v['path'] ?? null,
                'line' => isset($v['line']) ? (string) $v['line'] : null,
                'title' => 'ai:verify '.($v['target'] ?? $v['check'] ?? '').(isset($v['rule']) ? ' · '.$v['rule'] : ''),
            ]);
            $propText = implode(',', array_map(static fn ($k, $val) => $k.'='.$prop((string) $val), array_keys($props), $props));
            $this->line("::{$level} {$propText}::".$escape((string) $v['message'].(isset($v['tip']) ? "\n".$v['tip'] : '')));
        }

        foreach ($results as $r) {
            if ($r->effectiveStatus() === Result::INCOMPLETE) {
                $this->line('::warning title=ai:verify '.$prop($r->id).' incomplete::'.$escape($r->signal));
            }
        }

        $file = getenv('GITHUB_STEP_SUMMARY');

        if ($file === false || $file === '') {
            return;
        }

        $icon = ['pass' => '✅', 'fail' => '❌', 'skipped' => '➖', 'incomplete' => '⚠️'];
        $rows = array_map(static fn (Result $r) => sprintf('| %s | `%s` | %s |',
            $icon[$r->effectiveStatus()] ?? '', $r->id, str_replace(['|', "\n"], ['\\|', ' '], mb_strimwidth($r->signal, 0, 160, '…'))), $results);

        $md = '### ai:verify — '.($icon[$report->verdict] ?? '').' '.strtoupper($report->verdict)."\n\n"
            ."{$report->headline}\n\n| | Check | Result |\n|---|---|---|\n".implode("\n", $rows)."\n"
            .($receipt !== null ? "\nReceipt `{$receipt['id']}`\n" : '')
            ."\n<sub>[semitexa/laravel-ai-verify](https://github.com/semitexa/laravel-ai-verify) · Laravel port of Semitexa ai:verify</sub>\n";

        @file_put_contents($file, $md, FILE_APPEND);
    }

    /** People try the command first; make sure their agents learn about it too. */
    private function suggestInstall(Toolkit $toolkit): void
    {
        if ($this->mode !== 'human') {
            return;
        }

        $agents = new AgentInstructions($toolkit->workspace, dirname(__DIR__, 2).'/resources');

        if (! $agents->installed() && ! $agents->managedByBoost()) {
            $this->line('  <fg=yellow>Tip:</> your agents don\'t know about this command yet — <options=bold>php artisan ai:verify:install</> adds it to CLAUDE.md / AGENTS.md (--hook for Claude Code).');
            $this->newLine();
        }
    }

    private function reportError(string $message): int
    {
        if ($this->mode === 'json') {
            $this->line((string) json_encode(['schema' => Report::SCHEMA, 'verdict' => 'fail', 'error' => $message], JSON_UNESCAPED_SLASHES));
        } else {
            $this->emit(['kind' => 'error', 'error' => $message]);
        }

        return self::FAILURE;
    }

    private function isTerminal(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }
}
