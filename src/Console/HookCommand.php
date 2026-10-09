<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use Semitexa\LaravelAiVerify\Agents\ClaudeHook;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Toolkit;

/**
 * Claude Code Stop hook (installed by `ai:verify:install --hook`).
 *
 * Runs `ai:verify` on the uncommitted work when Claude is about to finish.
 * On `fail` it prints a block decision with the violations, so Claude keeps
 * working. It never blocks twice in a row (`stop_hook_active`), and it skips
 * a working tree it has already verified, so answering a question in a dirty
 * repo does not re-run the checks or nag about the same failure.
 */
final class HookCommand extends Command
{
    protected $signature = 'ai:verify:hook';

    protected $description = 'Claude Code Stop hook: block finishing while ai:verify fails';

    protected $hidden = true;

    public function handle(): int
    {
        $input = $this->stdinJson();

        if (($input['stop_hook_active'] ?? false) === true) {
            return self::SUCCESS;
        }

        $toolkit = Toolkit::fromApp($this->laravel);
        $fingerprint = $this->fingerprint($toolkit);

        if ($fingerprint === null) {
            return self::SUCCESS; // not a git repo, or nothing uncommitted
        }

        $stateFile = $toolkit->workspace->tempPath('hook-state.json');
        $state = json_decode((string) @file_get_contents($stateFile), true);

        if (is_array($state) && ($state['fingerprint'] ?? null) === $fingerprint) {
            return self::SUCCESS;
        }

        $scope = (string) ($toolkit->config['hook']['scope'] ?? 'standard');
        // The whole run must fit inside Claude Code's hook timeout; each check inside has its own limit.
        $runner = new ProcessRunner($toolkit->workspace->basePath, ClaudeHook::TIMEOUT - 30, 16_777_216, $toolkit->workspace->dotenvLeaks());
        $outcome = $runner->run($toolkit->workspace->artisan('ai:verify', '--json', '--scope='.$scope, '--no-interaction'));
        $report = json_decode($outcome->stdout, true);
        $verdict = is_array($report) ? (string) ($report['verdict'] ?? 'unknown') : 'unknown';

        file_put_contents($stateFile, (string) json_encode(['fingerprint' => $fingerprint, 'verdict' => $verdict, 'at' => date(DATE_ATOM)]));

        if ($verdict !== 'fail' || ! is_array($report)) {
            return self::SUCCESS;
        }

        $this->line((string) json_encode([
            'decision' => 'block',
            'reason' => $this->reason($report),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function reason(array $report): string
    {
        $lines = ['php artisan ai:verify failed on your uncommitted changes — fix these before finishing:'];

        foreach ((array) ($report['results'] ?? []) as $result) {
            if (($result['status'] ?? null) === 'fail') {
                $lines[] = "✗ {$result['id']}: {$result['signal']}";
            }
        }

        $violations = array_values(array_filter(
            (array) ($report['violations'] ?? []),
            static fn (array $v) => ($v['severity'] ?? 'error') === 'error' && empty($v['accepted']),
        ));

        foreach (array_slice($violations, 0, 10) as $v) {
            $where = isset($v['path']) ? $v['path'].(isset($v['line']) ? ':'.$v['line'] : '').' ' : '';
            $lines[] = "  › {$where}{$v['message']}".(isset($v['tip']) ? " ({$v['tip']})" : '');
        }

        if (count($violations) > 10) {
            $lines[] = '  … '.(count($violations) - 10).' more — run php artisan ai:verify for the full list';
        }

        foreach ((array) ($report['next'] ?? []) as $next) {
            $lines[] = "→ {$next['cmd']} — {$next['why']}";
        }

        $lines[] = 'Re-run php artisan ai:verify after fixing. Do not delete or weaken tests to make it pass.';

        return implode("\n", $lines);
    }

    /**
     * Hash of what a verdict depends on: base commit, package version, config,
     * and the uncommitted paths, statuses and content. Null when there is nothing to verify.
     */
    private function fingerprint(Toolkit $toolkit): ?string
    {
        $status = $toolkit->runner->run(['git', 'status', '--porcelain=v1', '-z', '--untracked-files=all']);

        if (! $status->succeeded() || trim($status->stdout) === '') {
            return null;
        }

        $diff = $toolkit->runner->run(['git', 'diff', 'HEAD', '--no-color', '--no-ext-diff']);
        $head = $toolkit->runner->run(['git', 'rev-parse', 'HEAD']);
        $hash = hash_init('sha256');
        // A cached verdict is only valid for the same base commit, the same checks and the same config.
        hash_update($hash, trim($head->stdout).'|'.Toolkit::VERSION.'|'.json_encode($toolkit->config));
        hash_update($hash, $status->stdout);
        hash_update($hash, $diff->stdout);

        // Untracked files have no diff; hash their content too.
        foreach (explode("\0", $status->stdout) as $entry) {
            if (str_starts_with($entry, '?? ')) {
                $path = $toolkit->workspace->path(substr($entry, 3));

                if (is_file($path) && filesize($path) < 2_000_000) {
                    hash_update($hash, (string) file_get_contents($path));
                }
            }
        }

        return hash_final($hash);
    }

    /** @return array<string, mixed> */
    private function stdinJson(): array
    {
        if (function_exists('stream_isatty') && @stream_isatty(STDIN)) {
            return [];
        }

        // Claude Code writes the payload and closes stdin at once; never hang on an open, silent stdin.
        $read = [STDIN];
        $none = null;

        if (@stream_select($read, $none, $none, 2) !== 1) {
            return [];
        }

        $json = json_decode((string) stream_get_contents(STDIN), true);

        return is_array($json) ? $json : [];
    }
}
