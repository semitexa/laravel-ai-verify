<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Checks\ArtisanCheck;

/**
 * The verdict and everything an agent needs to act on it.
 *
 * Verdict rollup:
 *   no results                          → incomplete
 *   any fail                            → fail
 *   any required check not pass/fail    → incomplete
 *   everything skipped                  → skipped
 *   otherwise                           → pass
 * then the coverage gap: if no content-reading check reached a verdict on
 * *any* changed file, pass/skipped is downgraded to incomplete. "Green" must
 * mean "something actually looked at this change".
 */
final class Report
{
    public const SCHEMA = 'semitexa.laravel-ai-verify/v1';

    /** Checks that run but do not read the changed files' content. */
    private const NON_READING = ['test_integrity'];

    public string $verdict;

    /** @var array<string, int> */
    public array $counts = ['pass' => 0, 'fail' => 0, 'skipped' => 0, 'incomplete' => 0];

    /** @var list<array{path: string, kind: string}> */
    public array $uncheckedFiles = [];

    public string $headline;

    /**
     * @param  list<ChangedFile>  $files
     * @param  list<Result>  $results
     * @param  array<string, mixed>|null  $impact
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly array $files,
        public readonly array $results,
        public readonly ?array $impact = null,
    ) {
        foreach ($results as $result) {
            $this->counts[$result->effectiveStatus()]++;
        }

        $this->verdict = $this->rollup();
        $this->applyCoverageGap();
    }

    public function exitCode(): int
    {
        return in_array($this->verdict, [Result::PASS, Result::SKIPPED], true) ? 0 : 1;
    }

    /** @return list<array{path: string, line: ?int, rule: ?string, message: string, check: string, target: string, tip?: string}> */
    public function violations(): array
    {
        $all = [];

        foreach ($this->results as $result) {
            foreach ($result->violations as $violation) {
                $all[] = ['target' => $result->id, 'check' => $result->check, 'accepted' => $result->accepted ?: null] + $violation->toArray();
            }
        }

        return array_map(static fn (array $v) => array_filter($v, static fn ($x) => $x !== null), $all);
    }

    /**
     * Long-lived processes keep old code in memory; a green verify says nothing about them.
     *
     * @return list<array{cmd: string, why: string}>
     */
    public function restartHints(Workspace $workspace): array
    {
        $hints = [];
        $kinds = array_map(static fn (ChangedFile $f) => $f->kind, $this->files);
        $has = static fn (callable $test) => array_filter($kinds, $test) !== [];

        if ($workspace->exists('bootstrap/cache/config.php') && $has(static fn (FileKind $k) => $k === FileKind::Config)) {
            $hints[] = ['cmd' => 'php artisan config:clear', 'why' => 'config is cached in bootstrap/cache/config.php — the running app ignores your change'];
        }

        foreach (glob($workspace->path('bootstrap/cache/routes-*.php')) ?: [] as $_) {
            if ($has(static fn (FileKind $k) => in_array($k, [FileKind::Route, FileKind::Controller], true))) {
                $hints[] = ['cmd' => 'php artisan route:clear', 'why' => 'routes are cached in bootstrap/cache — the running app ignores your change'];
            }

            break;
        }

        if ($workspace->exists('bootstrap/cache/events.php') && $has(static fn (FileKind $k) => in_array($k, [FileKind::Event, FileKind::Listener], true))) {
            $hints[] = ['cmd' => 'php artisan event:clear', 'why' => 'events are cached in bootstrap/cache/events.php'];
        }

        if ($has(static fn (FileKind $k) => $k->isLongLived() || $k === FileKind::Provider || $k === FileKind::Config)) {
            $hints[] = $workspace->hasPackage('laravel/horizon')
                ? ['cmd' => 'php artisan horizon:terminate', 'why' => 'queued code changed — Horizon workers still run the old version']
                : ['cmd' => 'php artisan queue:restart', 'why' => 'queued code changed — running queue workers still have the old version in memory'];
        }

        if ($workspace->hasPackage('laravel/octane') && $has(static fn (FileKind $k) => $k->isPhp())) {
            $hints[] = ['cmd' => 'php artisan octane:reload', 'why' => 'Octane workers keep the old code until reloaded'];
        }

        return $hints;
    }

    /** @return list<array{cmd: string, why: string}> */
    public function nextCommands(): array
    {
        $next = [];

        foreach ($this->results as $result) {
            if ($result->effectiveStatus() === Result::PASS) {
                continue;
            }

            $files = array_values(array_filter(array_map(static fn (Violation $v) => $v->path, $result->violations)));

            $cmd = match (true) {
                $result->check === 'pint' && $result->status === Result::FAIL => ['cmd' => 'vendor/bin/pint '.implode(' ', array_unique($files)), 'why' => 'auto-fix code style, then re-verify'],
                $result->check === 'tests' && $result->status === Result::FAIL => ['cmd' => 'php artisan test '.(str_starts_with($result->id, 'tests:suite') ? '' : substr($result->id, 6)), 'why' => 'reproduce the failing test with full output'],
                $result->check === 'phpstan' && $result->status === Result::SKIPPED => ['cmd' => 'composer require --dev larastan/larastan', 'why' => 'enable static analysis of changed code'],
                $result->check === 'test_integrity' && $result->status === Result::FAIL => ['cmd' => 'git diff '.implode(' ', array_unique($files)), 'why' => 'restore the removed tests/assertions, or mark the change with // verify:accept-test-change <reason>'],
                $result->check === 'artisan' && $result->status === Result::FAIL => ['cmd' => 'php artisan '.implode(' ', ArtisanCheck::PROBES[substr($result->id, 8)]['args'] ?? []), 'why' => 'reproduce the boot failure with the full stack trace'],
                default => null,
            };

            if ($cmd !== null && ! in_array($cmd, $next, true)) {
                $next[] = $cmd;
            }
        }

        if ($this->verdict !== Result::FAIL && $next === []) {
            $next[] = $this->verdict === Result::PASS
                ? ['cmd' => 'git add -A && git commit', 'why' => 'the change set is verified']
                : ['cmd' => 'php artisan ai:verify --dirty --scope=broad', 'why' => 'nothing conclusive ran at this scope'];
        }

        return $next;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'requested_scope' => $this->plan->requestedScope->value,
            'effective_scope' => $this->plan->effectiveScope->value,
            'changed_files' => count($this->files),
            'targets' => count($this->results),
        ];
    }

    private function rollup(): string
    {
        if ($this->results === []) {
            return Result::INCOMPLETE;
        }

        if ($this->counts['fail'] > 0) {
            return Result::FAIL;
        }

        if ($this->counts['incomplete'] > 0) {
            return Result::INCOMPLETE;
        }

        return $this->counts['pass'] === 0 ? Result::SKIPPED : Result::PASS;
    }

    private function applyCoverageGap(): void
    {
        $checked = [];

        foreach ($this->results as $result) {
            if ($result->reached() && ! in_array($result->check, self::NON_READING, true)) {
                foreach ($result->triggeredBy as $path) {
                    $checked[$path] = true;
                }
            }
        }

        $candidates = array_filter($this->files, static fn (ChangedFile $f) => ! $f->isDeleted());

        foreach ($candidates as $file) {
            if (! isset($checked[$file->path])) {
                $this->uncheckedFiles[] = ['path' => $file->path, 'kind' => $file->kind->value];
            }
        }

        $total = count($this->results);
        $fileCount = count($this->files);

        if ($this->uncheckedFiles === []) {
            $this->headline = "{$total} check(s) over {$fileCount} changed file(s); every file was read by at least one check";
        } else {
            $names = array_map(static fn (array $f) => $f['path'], array_slice($this->uncheckedFiles, 0, 5));
            $more = count($this->uncheckedFiles) > 5 ? ' …' : '';
            $this->headline = "{$total} check(s) over {$fileCount} changed file(s); no check read ".count($this->uncheckedFiles).' of them: '.implode(', ', $names).$more;
        }

        if ($candidates !== [] && count($this->uncheckedFiles) === count($candidates)
            && in_array($this->verdict, [Result::PASS, Result::SKIPPED], true)) {
            $this->verdict = Result::INCOMPLETE;
        }
    }
}
