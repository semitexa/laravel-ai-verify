<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

/** `pint --test` on the changed files only, using the project's pint.json. */
final class PintCheck implements Check
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    public function run(Target $target): Result
    {
        $pint = $this->workspace->bin('pint');

        if ($pint === null) {
            return Result::skipped('laravel/pint is not installed');
        }

        $files = (array) $target->params['files'];
        $outcome = $this->runner->run([$this->workspace->phpBinary, $pint, '--test', '--format=json', ...$files]);

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        $json = json_decode(trim($outcome->stdout), true);

        if (! is_array($json)) {
            return $outcome->succeeded()
                ? Result::pass('Pint: style ok')
                : Result::incomplete('Pint produced unreadable output: '.$outcome->lastLine(), $outcome->exitCode);
        }

        $violations = [];
        $preexisting = [];
        $ref = (string) ($target->params['ref'] ?? 'HEAD');

        foreach ($json['files'] ?? [] as $file) {
            $path = (string) ($file['path'] ?? '');
            $fixers = (array) ($file['fixers'] ?? []);
            $before = $this->fixersAtRef($pint, $path, $ref);
            $introduced = $before === null ? $fixers : array_values(array_diff($fixers, $before));

            if ($introduced === []) {
                $preexisting[] = new Violation(
                    'Pre-existing style issues: '.implode(', ', $fixers), $path, null, 'pint',
                    severity: 'warning', tip: "Already present at {$ref}; optional: vendor/bin/pint {$path}",
                );

                continue;
            }

            $violations[] = new Violation(
                'Style issues: '.implode(', ', $introduced), $path, null, 'pint',
                tip: 'Auto-fix: vendor/bin/pint '.$path,
            );
        }

        if ($violations === [] && ($outcome->succeeded() || $preexisting !== [])) {
            return $preexisting === []
                ? Result::pass('Pint: '.count($files).' file(s) follow the project style')
                : new Result(Result::PASS, 'Pint: no new style issues; '.count($preexisting).' file(s) had them before', $outcome->exitCode, $preexisting, accepted: true);
        }

        $paths = array_map(static fn (Violation $v) => (string) $v->path, $violations);

        return Result::fail(count($violations).' file(s) need formatting — run vendor/bin/pint '.implode(' ', $paths), [...$violations, ...$preexisting], $outcome->exitCode);
    }

    /**
     * Fixers Pint would have applied to the file as it was at `$ref`; null when
     * the file is new there. Lets the verdict blame only style the change introduced.
     *
     * @return list<string>|null
     */
    private function fixersAtRef(string $pint, string $path, string $ref): ?array
    {
        $base = $this->runner->run(['git', 'show', $ref.':'.$path]);

        if (! $base->succeeded()) {
            return null;
        }

        $temp = $this->workspace->tempPath('pint-base-'.md5($path).'-'.basename($path));
        file_put_contents($temp, $base->stdout);

        $argv = [$this->workspace->phpBinary, $pint, '--test', '--format=json'];

        if ($this->workspace->exists('pint.json')) {
            $argv[] = '--config='.$this->workspace->path('pint.json');
        }

        $outcome = $this->runner->run([...$argv, $temp]);
        @unlink($temp);
        $json = json_decode(trim($outcome->stdout), true);

        return array_values(array_merge([], ...array_map(
            static fn (array $file) => (array) ($file['fixers'] ?? []),
            (array) ($json['files'] ?? []),
        )));
    }
}
