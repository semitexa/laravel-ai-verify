<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;
use Symfony\Component\Process\ExecutableFinder;

/**
 * `composer audit --locked` when the change touches composer.json or
 * composer.lock. An advisory on a package the change added or moved to
 * another version fails; one on a package that was already locked at the
 * base ref is a warning, since the change did not bring it in.
 *
 * An audit that gives no readable answer (no network, an old Composer) is
 * "skipped" with the reason, never a pass.
 */
final class AdvisoryCheck implements Check
{
    private const LOCK = 'composer.lock';

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    public function run(Target $target): Result
    {
        if (! $this->workspace->exists(self::LOCK)) {
            return Result::skipped('no composer.lock to audit');
        }

        $composer = (new ExecutableFinder)->find('composer');

        if ($composer === null) {
            return Result::skipped('composer executable not found on PATH');
        }

        // A wide terminal keeps Composer's error box on one line, so the reason below stays readable.
        $outcome = $this->runner->run([$composer, 'audit', '--locked', '--format=json', '--no-interaction'], ['COLUMNS' => '400']);

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        $advisories = self::advisories($outcome->stdout);

        if ($advisories === null) {
            return Result::skipped('composer audit gave no readable result: '.self::reason($outcome->output));
        }

        $locked = self::versions((string) file_get_contents($this->workspace->path(self::LOCK)));
        $ref = (string) ($target->params['ref'] ?? 'HEAD');

        return self::judge($advisories, $locked, $this->versionsAt($ref), $ref);
    }

    /**
     * @param  list<array{package: string, id: string, title: string, link: ?string}>  $advisories
     * @param  array<string, string>  $locked  package => version now
     * @param  array<string, string>|null  $before  package => version at the base ref; null when it cannot be known
     */
    public static function judge(array $advisories, array $locked, ?array $before, string $ref = 'HEAD'): Result
    {
        if ($advisories === []) {
            return Result::pass('composer audit: no known advisory in '.count($locked).' locked package(s)');
        }

        $introduced = $old = [];

        foreach ($advisories as $advisory) {
            $package = $advisory['package'];
            $version = $locked[$package] ?? null;
            $moved = $before !== null && ($before[$package] ?? null) !== $version;
            $message = $package.($version !== null ? ' '.$version : '').': '.$advisory['title'].($advisory['link'] !== null ? ' ('.$advisory['link'].')' : '');

            if ($moved) {
                $introduced[] = new Violation($message, self::LOCK, null, 'composer.advisory',
                    tip: isset($before[$package]) ? "This change moved the package from {$before[$package]}; move it to a fixed version" : 'This change added the package at an affected version');
            } else {
                $old[] = new Violation($message, self::LOCK, null, 'composer.advisory', severity: 'warning',
                    tip: $before === null ? 'The base lock file could not be read, so this is reported, not failed' : "Already locked at {$ref}");
            }
        }

        if ($introduced === []) {
            return new Result(Result::PASS, 'composer audit: no advisory in the packages this change touched; '.count($old).' known in packages it left alone', 0, $old, accepted: true);
        }

        $packages = array_values(array_unique(array_map(static fn (Violation $v) => strtok($v->message, ' :'), $introduced)));

        return Result::fail(
            count($introduced).' known advisory(ies) in packages this change added or moved: '.implode(', ', $packages),
            [...$introduced, ...$old],
        );
    }

    /**
     * The advisories of `composer audit --format=json`, flattened; null when
     * the output is not that report.
     *
     * @return list<array{package: string, id: string, title: string, link: ?string}>|null
     */
    public static function advisories(string $stdout): ?array
    {
        $start = strpos($stdout, '{');
        $report = $start === false ? null : json_decode(substr($stdout, $start), true);

        if (! is_array($report) || ! is_array($report['advisories'] ?? null)) {
            return null;
        }

        $out = [];

        foreach ($report['advisories'] as $package => $list) {
            foreach ((array) $list as $advisory) {
                if (! is_array($advisory)) {
                    continue;
                }

                $out[] = [
                    'package' => (string) ($advisory['packageName'] ?? $package),
                    'id' => (string) ($advisory['advisoryId'] ?? ''),
                    'title' => trim((string) ($advisory['title'] ?? $advisory['cve'] ?? 'security advisory')),
                    'link' => isset($advisory['link']) && $advisory['link'] !== '' ? (string) $advisory['link'] : null,
                ];
            }
        }

        return $out;
    }

    /** What Composer said went wrong: its error text without the "In File.php line N:" header and the usage line. */
    public static function reason(string $output): string
    {
        $lines = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/', ' ', $line));

            if ($line === '' || preg_match('/^In \S+ line \d+:$/', $line) || preg_match('/^audit \[/', $line)) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines === [] ? 'no output' : mb_strimwidth(implode(' ', $lines), 0, 240, '…');
    }

    /** @return array<string, string> package => version, from a composer.lock */
    public static function versions(string $lock): array
    {
        $data = json_decode($lock, true);
        $out = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ((array) (is_array($data) ? $data[$section] ?? [] : []) as $package) {
                if (is_array($package) && isset($package['name'], $package['version'])) {
                    $out[(string) $package['name']] = (string) $package['version'];
                }
            }
        }

        return $out;
    }

    /** Versions at the base ref: [] when the lock file is new there, null when the ref cannot be read. */
    private function versionsAt(string $ref): ?array
    {
        if ($ref === '' || str_starts_with($ref, '-')) {
            return null;
        }

        if (! $this->runner->run(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}'])->succeeded()) {
            return null;
        }

        $base = $this->runner->run(['git', 'show', $ref.':'.self::LOCK]);

        return $base->succeeded() ? self::versions($base->stdout) : [];
    }
}
