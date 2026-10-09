<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Receipts;

use Semitexa\LaravelAiVerify\Support\Workspace;
use Throwable;

/**
 * A receipt for an ai:verify run, so "all tests pass" can be checked instead
 * of believed. Ported from Semitexa's ai:verify receipts.
 *
 * It records what ran (argv, each check's status, exit code and a hash of its
 * signal) and the exact state it ran against, in two forms:
 *  - `files`: a sha256 of every changed file, taken before the checks ran;
 *  - `tree`:  the git tree id of the whole working tree, i.e. the tree a
 *             commit would get if this state were committed as it is.
 *
 * check() answers: is the receipt as it was written (digest), does the tree
 * still look like that, and was the verdict a pass. The digest catches an
 * edited receipt, not a forged one — whoever can write storage/ can rewrite
 * both. What it buys is that a claim names something that can be looked at,
 * compared with a commit, and re-run.
 */
final class Receipts
{
    public const DIR = 'storage/ai-verify/receipts';

    public const SCHEMA = 'semitexa.laravel-ai-verify.receipt/v1';

    private const KEEP = 200;

    private const READS = 'reads.ndjson';

    public const DELETED = 'deleted';

    public function __construct(private readonly Workspace $workspace) {}

    /** sha256 per path, `deleted` for paths that do not exist. */
    public function fingerprint(array $paths): array
    {
        $hashes = [];

        foreach ($paths as $path) {
            $absolute = $this->workspace->path($path);
            $hashes[$path] = is_file($absolute) ? (hash_file('sha256', $absolute) ?: 'unreadable') : self::DELETED;
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * @param  array<string, mixed>  $run  verdict, scope, results, source, …
     * @param  array<string, string>  $filesBefore  fingerprint taken before the checks ran
     * @return array<string, mixed>|null the written receipt, or null when it could not be written
     */
    public function write(array $run, array $filesBefore, ?string $tree, ?string $head): ?array
    {
        $now = $this->fingerprint(array_keys($filesBefore));
        $changedDuringRun = array_keys(array_filter($filesBefore, static fn (string $hash, string $path) => ($now[$path] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));

        $receipt = [
            'schema' => self::SCHEMA,
            'id' => 'rcpt-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)),
            'generated_at' => gmdate(DATE_ATOM),
            'argv' => array_values(array_map('strval', (array) ($_SERVER['argv'] ?? []))),
            'run_by' => self::agent(),
            'verdict' => $run['verdict'] ?? null,
            'scope' => $run['scope'] ?? null,
            'source' => $run['source'] ?? null,
            'counts' => $run['counts'] ?? null,
            'checks' => array_values(array_map(static fn (array $r) => [
                'id' => $r['id'] ?? null,
                'status' => $r['status'] ?? null,
                'exit_code' => $r['exit_code'] ?? null,
                'signal_sha256' => hash('sha256', (string) ($r['signal'] ?? '')),
            ], (array) ($run['results'] ?? []))),
            'head' => $head,
            'tree' => $tree,
            'files' => $filesBefore,
            'changed_during_run' => array_values(array_map('strval', $changedDuringRun)),
        ];
        $receipt['digest'] = self::digest($receipt);

        try {
            $dir = $this->dir();
            file_put_contents($dir.'/'.$receipt['id'].'.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->prune($dir);
        } catch (Throwable) {
            return null; // a receipt that could not be written is absent, never a failed run
        }

        return $receipt;
    }

    /** @return array<string, mixed>|null */
    public function find(?string $id = null): ?array
    {
        $file = $id === null ? ($this->all()[0] ?? null) : $this->workspace->path(self::DIR.'/'.basename($id).'.json');

        return $file !== null ? $this->load($file) : null;
    }

    /**
     * Newest passing, intact receipt that vouches for exactly this tree.
     *
     * @return array<string, mixed>|null
     */
    public function forTree(string $tree): ?array
    {
        foreach ($this->all() as $file) {
            $receipt = $this->load($file);

            if ($receipt !== null && ($receipt['tree'] ?? null) === $tree && ($receipt['verdict'] ?? null) === 'pass'
                && ($receipt['changed_during_run'] ?? []) === [] && self::intact($receipt)) {
                return $receipt;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array{id: string, intact: bool, verdict: ?string, generated_at: ?string, tree_matches: ?bool, changed_since: list<string>, changed_during_run: list<string>, holds: bool}
     */
    public function check(array $receipt, ?string $currentTree): array
    {
        $files = (array) ($receipt['files'] ?? []);
        $now = $this->fingerprint(array_map('strval', array_keys($files)));
        $changedSince = array_values(array_map('strval', array_keys(array_filter($files, static fn ($hash, $path) => ($now[$path] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH))));
        $treeMatches = $currentTree !== null && isset($receipt['tree']) ? $receipt['tree'] === $currentTree : null;
        $intact = self::intact($receipt);
        $changedDuringRun = array_values(array_filter((array) ($receipt['changed_during_run'] ?? []), 'is_string'));

        return [
            'id' => (string) ($receipt['id'] ?? ''),
            'intact' => $intact,
            'verdict' => is_string($receipt['verdict'] ?? null) ? $receipt['verdict'] : null,
            'generated_at' => is_string($receipt['generated_at'] ?? null) ? $receipt['generated_at'] : null,
            'tree_matches' => $treeMatches,
            'changed_since' => $changedSince,
            'changed_during_run' => $changedDuringRun,
            'holds' => $intact && ($receipt['verdict'] ?? null) === 'pass' && $changedDuringRun === []
                && $changedSince === [] && $treeMatches !== false,
        ];
    }

    /** Remember that someone looked: a receipt nobody checked is a claim nobody verified. */
    public function markRead(string $id): void
    {
        try {
            file_put_contents($this->dir().'/'.self::READS, json_encode(['id' => basename($id), 'at' => gmdate(DATE_ATOM), 'by' => self::agent()], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
        }
    }

    /**
     * Receipts nobody checked with ai:verify:receipt, newest first. A subagent
     * that saw red and reported green leaves exactly this behind.
     *
     * @return list<array{id: string, generated_at: ?string, verdict: ?string, run_by: ?string}>
     */
    public function unread(?int $withinSeconds = null): array
    {
        $read = [];
        $reads = $this->workspace->path(self::DIR.'/'.self::READS);

        foreach (is_file($reads) ? (file($reads, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $read[$entry['id']] = true;
            }
        }

        $cutoff = $withinSeconds === null ? null : time() - $withinSeconds;
        $unread = [];

        foreach ($this->all() as $file) {
            $receipt = $this->load($file);

            if ($receipt === null || isset($read[$receipt['id'] ?? ''])) {
                continue;
            }

            if ($cutoff !== null && strtotime((string) ($receipt['generated_at'] ?? '')) < $cutoff) {
                break;
            }

            $unread[] = [
                'id' => (string) $receipt['id'],
                'generated_at' => $receipt['generated_at'] ?? null,
                'verdict' => $receipt['verdict'] ?? null,
                'run_by' => $receipt['run_by'] ?? null,
            ];
        }

        return $unread;
    }

    /** @param array<string, mixed> $receipt */
    public static function intact(array $receipt): bool
    {
        $recorded = is_string($receipt['digest'] ?? null) ? $receipt['digest'] : '';
        unset($receipt['digest']);

        return $recorded !== '' && hash_equals($recorded, self::digest($receipt));
    }

    /** @param array<string, mixed> $receipt */
    private static function digest(array $receipt): string
    {
        return 'sha256:'.hash('sha256', (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Which agent ran it, as far as the environment says. */
    private static function agent(): ?string
    {
        foreach (['CLAUDECODE' => 'claude-code', 'CURSOR_AGENT' => 'cursor', 'CODEX_SANDBOX' => 'codex', 'CODEX_THREAD_ID' => 'codex',
            'GEMINI_CLI' => 'gemini-cli', 'COPILOT_CLI' => 'copilot', 'OPENCODE' => 'opencode', 'JUNIE_DATA' => 'junie',
            'GITHUB_ACTIONS' => 'github-actions', 'CI' => 'ci'] as $env => $name) {
            if (getenv($env) !== false && getenv($env) !== '') {
                return $name;
            }
        }

        return null;
    }

    /** @return list<string> receipt files, newest first */
    private function all(): array
    {
        $files = glob($this->workspace->path(self::DIR.'/rcpt-*.json')) ?: [];
        rsort($files);

        return $files;
    }

    /** @return array<string, mixed>|null */
    private function load(string $file): ?array
    {
        $receipt = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($receipt) && is_string($receipt['id'] ?? null) ? $receipt : null;
    }

    private function dir(): string
    {
        $dir = $this->workspace->path(self::DIR);

        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        // Receipts are local evidence; the commit trailer is what travels. The ignore file ignores
        // itself too: an untracked .gitignore would change the very tree the receipt vouches for.
        $ignore = dirname($dir).'/.gitignore';

        if (! is_file($ignore)) {
            file_put_contents($ignore, "*\n");
        }

        return $dir;
    }

    private function prune(string $dir): void
    {
        $files = glob($dir.'/rcpt-*.json') ?: [];
        rsort($files);

        foreach (array_slice($files, self::KEEP) as $old) {
            @unlink($old);
        }
    }
}
