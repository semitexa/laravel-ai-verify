<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use Semitexa\LaravelAiVerify\Receipts\Receipts;
use Semitexa\LaravelAiVerify\Receipts\Trailer;
use Semitexa\LaravelAiVerify\Support\GitTree;
use Semitexa\LaravelAiVerify\Toolkit;

/**
 * Checks ai:verify receipts, so a "verified" claim can be checked instead of believed.
 *
 *   php artisan ai:verify:receipt                    # latest receipt: intact, tree unchanged, verdict pass?
 *   php artisan ai:verify:receipt rcpt-…             # a specific one
 *   php artisan ai:verify:receipt --unread           # runs whose outcome nobody looked at
 *   php artisan ai:verify:receipt --trailer          # the AI-Verify trailer for what is staged (used by the git hook)
 *   php artisan ai:verify:receipt --commit=HEAD      # does this commit carry a trailer that matches its tree?
 *   php artisan ai:verify:receipt --range=origin/main..HEAD --require=ai --github   # CI
 */
final class ReceiptCommand extends Command
{
    protected $signature = 'ai:verify:receipt
        {id? : Receipt id (default: the latest)}
        {--unread : List receipts nobody has checked yet}
        {--trailer : Print the AI-Verify trailer for the staged tree, if a passing receipt vouches for it}
        {--message-file= : With --trailer: add the trailer to this commit message file (prepare-commit-msg hook)}
        {--commit= : Check the AI-Verify trailer of one commit against its tree}
        {--range= : Check AI-Verify trailers of every commit in a range, e.g. origin/main..HEAD}
        {--require=ai : With --range/--commit: which commits must be verified — all, ai (written with an AI agent) or none}
        {--github : With --range: GitHub Actions annotations and a job summary}
        {--json : JSON output}';

    protected $description = 'Check ai:verify receipts and the AI-Verify commit trailers that carry them to CI';

    private Toolkit $toolkit;

    private Receipts $receipts;

    private GitTree $git;

    public function handle(): int
    {
        $this->toolkit = Toolkit::fromApp($this->laravel);
        $this->receipts = new Receipts($this->toolkit->workspace);
        $this->git = new GitTree($this->toolkit->runner);

        return match (true) {
            (bool) $this->option('trailer') => $this->trailer(),
            (bool) $this->option('range') => $this->range((string) $this->option('range')),
            (bool) $this->option('commit') => $this->range((string) $this->option('commit'), single: true),
            (bool) $this->option('unread') => $this->unread(),
            default => $this->check($this->argument('id')),
        };
    }

    private function check(?string $id): int
    {
        $receipt = $this->receipts->find($id);

        if ($receipt === null) {
            return $this->out(['found' => false, 'id' => $id], fn () => $this->line('  <fg=yellow>No receipt'.($id ? " {$id}" : '').' — run php artisan ai:verify first.</>'), 1);
        }

        $result = $this->receipts->check($receipt, $this->git->working());
        $this->receipts->markRead($result['id']);

        return $this->out(['found' => true] + $result, function () use ($result, $receipt): void {
            $this->newLine();
            $this->line('  '.($result['holds'] ? '<fg=green>✓ holds</>' : '<fg=red>✗ does not hold</>')." <options=bold>{$result['id']}</> <fg=gray>{$result['generated_at']}</>");
            $this->line('    verdict      '.($result['verdict'] ?? '?').' · scope '.($receipt['scope'] ?? '?').' · '.count((array) ($receipt['checks'] ?? [])).' check(s)');
            $this->line('    intact       '.($result['intact'] ? 'yes' : '<fg=red>no — the receipt was edited</>'));
            $this->line('    tree         '.match ($result['tree_matches']) {
                true => 'unchanged since the run',
                false => '<fg=red>changed since the run</>',
                null => 'unknown (not a git repository)',
            });

            foreach ($result['changed_since'] as $path) {
                $this->line("      <fg=yellow>changed</> {$path}");
            }

            foreach ($result['changed_during_run'] as $path) {
                $this->line("      <fg=red>changed while checks ran</> {$path}");
            }

            $this->newLine();
        }, $result['holds'] ? 0 : 1);
    }

    private function unread(): int
    {
        $unread = $this->receipts->unread();

        return $this->out(['unread' => $unread], function () use ($unread): void {
            if ($unread === []) {
                $this->line('  <fg=green>Every receipt has been checked.</>');

                return;
            }

            foreach ($unread as $r) {
                $color = $r['verdict'] === 'pass' ? 'gray' : 'red';
                $this->line("  <fg={$color}>{$r['verdict']}</> {$r['id']} <fg=gray>{$r['generated_at']} · ".($r['run_by'] ?? 'unknown').'</>');
            }
        });
    }

    /** Hook-safe: never fails, prints or applies the trailer only when a passing receipt matches the staged tree exactly. */
    private function trailer(): int
    {
        $tree = $this->git->staged();
        $receipt = $tree !== null ? $this->receipts->forTree($tree) : null;

        if ($receipt === null) {
            return self::SUCCESS;
        }

        $file = $this->option('message-file');

        if (is_string($file) && $file !== '') {
            $this->toolkit->runner->run(['git', 'interpret-trailers', '--in-place', '--if-exists', 'replace', '--trailer', Trailer::line($receipt), $file]);
            $this->receipts->markRead((string) $receipt['id']);

            return self::SUCCESS;
        }

        $this->line(Trailer::line($receipt));

        return self::SUCCESS;
    }

    private function range(string $range, bool $single = false): int
    {
        $require = in_array($this->option('require'), ['all', 'ai', 'none'], true) ? (string) $this->option('require') : 'ai';
        $args = $single ? ['-1', $range] : [$range];
        $log = $this->toolkit->runner->run(['git', 'log', '--no-merges', '--format=%H%x1f%T%x1f%s%x1f%B%x1e', ...$args, '--']);

        if (! $log->succeeded()) {
            return $this->out(['error' => "git log {$range} failed: ".$log->lastLine()], fn () => $this->error("git log {$range} failed: ".$log->lastLine()), 1);
        }

        $commits = [];

        foreach (array_filter(array_map('trim', explode("\x1e", $log->stdout))) as $entry) {
            [$sha, $tree, $subject, $body] = array_pad(explode("\x1f", $entry, 4), 4, '');
            $trailer = Trailer::parse($body);
            $ai = Trailer::fromAgent($body);
            $status = match (true) {
                $trailer === null => 'missing',
                $trailer['tree'] !== $tree => 'stale',
                $trailer['verdict'] !== 'pass' => 'not_pass',
                default => 'verified',
            };
            $required = $require === 'all' || ($require === 'ai' && $ai);

            $commits[] = [
                'sha' => $sha,
                'subject' => $subject,
                'ai' => $ai,
                'required' => $required,
                'status' => $status,
                'receipt' => $trailer['id'] ?? null,
                'scope' => $trailer['scope'] ?? null,
                'checks' => $trailer['checks'] ?? null,
            ];
        }

        $failing = array_values(array_filter($commits, static fn (array $c) => $c['required'] && $c['status'] !== 'verified'));
        $summary = [
            'range' => $range,
            'require' => $require,
            'commits' => count($commits),
            'verified' => count(array_filter($commits, static fn (array $c) => $c['status'] === 'verified')),
            'failing' => count($failing),
            'ok' => $failing === [],
        ];

        if ($this->option('github')) {
            $this->github($commits, $summary);
        }

        return $this->out($summary + ['details' => $commits], function () use ($commits, $summary): void {
            $this->newLine();

            foreach ($commits as $c) {
                [$icon, $color] = match ($c['status']) {
                    'verified' => ['✓', 'green'],
                    'missing' => [$c['required'] ? '✗' : '·', $c['required'] ? 'red' : 'gray'],
                    default => ['✗', $c['required'] ? 'red' : 'yellow'],
                };
                $why = match ($c['status']) {
                    'verified' => "{$c['receipt']} · {$c['scope']} · {$c['checks']} checks",
                    'stale' => 'trailer tree does not match the commit — it changed after verification',
                    'not_pass' => 'trailer says the run did not pass',
                    default => 'no AI-Verify trailer'.($c['ai'] ? ' (AI-assisted commit)' : ''),
                };
                $this->line("  <fg={$color}>{$icon}</> ".substr($c['sha'], 0, 8)." {$c['subject']} <fg=gray>— {$why}</>");
            }

            $this->newLine();
            $this->line('  '.($summary['ok'] ? '<fg=green>OK</>' : '<fg=red>FAIL</>')." · {$summary['verified']}/{$summary['commits']} commit(s) verified · require={$summary['require']}");
            $this->newLine();
        }, $summary['ok'] ? 0 : 1);
    }

    /**
     * @param  list<array<string, mixed>>  $commits
     * @param  array<string, mixed>  $summary
     */
    private function github(array $commits, array $summary): void
    {
        foreach ($commits as $c) {
            if ($c['required'] && $c['status'] !== 'verified') {
                $this->line(sprintf('::error title=Unverified commit %s::%s — %s', substr($c['sha'], 0, 8), $c['subject'],
                    $c['status'] === 'stale' ? 'changed after ai:verify ran' : ($c['status'] === 'not_pass' ? 'ai:verify did not pass' : 'no AI-Verify trailer; run php artisan ai:verify before committing')));
            }
        }

        $file = getenv('GITHUB_STEP_SUMMARY');

        if ($file === false || $file === '') {
            return;
        }

        $rows = array_map(static fn (array $c) => sprintf('| %s | `%s` %s | %s | %s |',
            ['verified' => '✅', 'missing' => $c['required'] ? '❌' : '➖', 'stale' => '⚠️', 'not_pass' => '❌'][$c['status']],
            substr($c['sha'], 0, 8), str_replace('|', '\\|', $c['subject']),
            $c['ai'] ? 'AI-assisted' : '',
            $c['status'] === 'verified' ? "{$c['receipt']} ({$c['scope']}, {$c['checks']} checks)" : str_replace('_', ' ', $c['status']),
        ), $commits);

        $md = "### ai:verify receipts\n\n".($summary['ok'] ? '✅' : '❌')." {$summary['verified']}/{$summary['commits']} commit(s) verified · require `{$summary['require']}`\n\n"
            ."| | Commit | | Receipt |\n|---|---|---|---|\n".implode("\n", $rows)."\n";

        @file_put_contents($file, $md, FILE_APPEND);
    }

    /** @param array<string, mixed> $data */
    private function out(array $data, \Closure $human, int $exit = 0): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $human();
        }

        return $exit;
    }
}
