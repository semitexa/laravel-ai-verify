<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify;

use Illuminate\Contracts\Foundation\Application;
use Semitexa\LaravelAiVerify\Graph\Graph;
use Semitexa\LaravelAiVerify\Graph\GraphBuilder;
use Semitexa\LaravelAiVerify\Graph\ImpactAnalyzer;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\ChangedFileClassifier;
use Semitexa\LaravelAiVerify\Verify\Checks;
use Semitexa\LaravelAiVerify\Verify\Executor;
use Semitexa\LaravelAiVerify\Verify\Scope;
use Semitexa\LaravelAiVerify\Verify\Style\StyleDetector;

/**
 * Wires the verifier and the graph from the app's config. One place that
 * knows how the pieces fit, so both commands (and tests) stay thin.
 */
final class Toolkit
{
    public const VERSION = '0.7.1';

    public const HOMEPAGE = 'https://semitexa.com';

    public readonly Workspace $workspace;

    public readonly ProcessRunner $runner;

    public readonly ChangedFileClassifier $classifier;

    private ?GraphBuilder $builder = null;

    private ?Graph $graph = null;

    /** @var array{setups: list<Verify\Style\StyleSetup>, none: ?string, warnings: list<string>}|null */
    private ?array $style = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Application $app,
        public readonly array $config,
    ) {
        $this->workspace = new Workspace($app->basePath());
        $this->runner = new ProcessRunner(
            $app->basePath(),
            (int) ($config['timeout'] ?? 120),
            (int) ($config['output_cap'] ?? 4_194_304),
            $this->workspace->dotenvLeaks(),
        );
        $this->classifier = new ChangedFileClassifier((array) ($config['kinds'] ?? []));
    }

    public static function fromApp(Application $app): self
    {
        return new self($app, (array) $app['config']->get('ai-verify', []));
    }

    public function graph(): Graph
    {
        if ($this->graph === null) {
            $this->builder = new GraphBuilder(
                $this->workspace,
                $this->classifier,
                (array) ($this->config['graph']['paths'] ?? ['app', 'routes', 'database', 'tests']),
                (string) ($this->config['graph']['views'] ?? 'resources/views'),
                $this->app,
            );
            $this->graph = $this->builder->build();
        }

        return $this->graph;
    }

    /** @return list<string> */
    public function graphWarnings(): array
    {
        return $this->builder?->warnings() ?? [];
    }

    public function impact(): ImpactAnalyzer
    {
        return new ImpactAnalyzer($this->graph(), (int) ($this->config['graph']['impact_depth'] ?? 5));
    }

    /**
     * The project's code style tools, found once per run.
     *
     * @return array{setups: list<Verify\Style\StyleSetup>, none: ?string, warnings: list<string>}
     */
    public function style(): array
    {
        $enabled = (bool) ($this->config['checks']['style'] ?? $this->config['checks']['pint'] ?? true);

        return $this->style ??= (new StyleDetector($this->workspace))->detect($enabled ? ($this->config['style'] ?? 'auto') : false);
    }

    /**
     * `ai-verify.commands`, normalised. A bare string is shorthand for ['run' => it].
     *
     * @return array{commands: list<array{name: string, run: string|list<string>, when: list<string>, scope: string, required: bool, timeout: ?int}>, warnings: list<string>}
     */
    public function commands(): array
    {
        $commands = $warnings = [];

        foreach ((array) ($this->config['commands'] ?? []) as $name => $command) {
            $name = (string) $name;
            $command = is_array($command) && ! array_is_list($command) ? $command : ['run' => $command];
            $run = $command['run'] ?? null;

            if (! (is_string($run) && trim($run) !== '') && ! (is_array($run) && $run !== [])) {
                $warnings[] = "ai-verify.commands.{$name} has no 'run' command; skipped";

                continue;
            }

            $scope = Scope::tryFrom((string) ($command['scope'] ?? 'standard'));

            if ($scope === null) {
                $warnings[] = "ai-verify.commands.{$name}: unknown scope '{$command['scope']}', using standard";
            }

            $commands[] = [
                'name' => $name,
                'run' => is_array($run) ? array_values(array_map('strval', $run)) : $run,
                'when' => array_values(array_map('strval', (array) ($command['when'] ?? []))),
                'scope' => ($scope ?? Scope::Standard)->value,
                'required' => (bool) ($command['required'] ?? true),
                'timeout' => isset($command['timeout']) ? (int) $command['timeout'] : null,
            ];
        }

        return ['commands' => $commands, 'warnings' => $warnings];
    }

    /** @return list<string> */
    public function ignore(): array
    {
        return array_values(array_map('strval', (array) ($this->config['ignore'] ?? [])));
    }

    /** @return array<string, mixed> what the Planner needs from the config */
    public function plannerOptions(): array
    {
        $checks = (array) ($this->config['checks'] ?? []);

        return [
            'broad_threshold' => $this->config['broad_threshold'] ?? 15,
            'max_test_targets' => $this->config['max_test_targets'] ?? 25,
            'test_depth' => $this->config['test_depth'] ?? 4,
            ...$checks,
            'style' => (bool) ($checks['style'] ?? $checks['pint'] ?? true),
            'style_tools' => array_map(static fn ($s) => $s->id(), $this->style()['setups']),
            'commands' => $this->commands()['commands'],
        ];
    }

    /** @return list<string> */
    public function testArgs(): array
    {
        return array_values(array_map('strval', (array) ($this->config['test_args'] ?? [])));
    }

    /** A runner with the check's own wall-clock limit from `ai-verify.timeouts`, if it has one. */
    public function runnerFor(string $check): ProcessRunner
    {
        $timeout = $this->config['timeouts'][$check] ?? null;

        return $timeout === null ? $this->runner : $this->runner->withTimeout((int) $timeout);
    }

    public function executor(): Executor
    {
        $w = $this->workspace;
        $r = $this->runner;
        $style = $this->style();
        $setups = [];

        foreach ($style['setups'] as $setup) {
            $setups[$setup->id()] = $setup;
        }

        return new Executor([
            'syntax' => new Checks\SyntaxCheck($w, $r),
            'blade' => new Checks\BladeCheck($w, $r, $this->app->bound('blade.compiler') ? $this->app->make('blade.compiler') : null),
            'json' => new Checks\JsonCheck($w),
            'style' => new Checks\StyleCheck($w, $this->runnerFor('style'), $setups, $style['none'] ?? 'no code style tool found'),
            'phpstan' => new Checks\PhpstanCheck(
                $w, $this->runnerFor('phpstan'),
                (int) ($this->config['phpstan']['fallback_level'] ?? 5),
                (string) ($this->config['phpstan']['memory_limit'] ?? '1G'),
            ),
            'tests' => new Checks\TestCheck($w, $this->runnerFor('tests'), $this->testArgs()),
            'artisan' => new Checks\ArtisanCheck($w, $this->runnerFor('artisan')),
            'migration' => new Checks\MigrationCheck($w, $this->runnerFor('migration')),
            'composer' => new Checks\ComposerCheck($this->runnerFor('composer')),
            'test_integrity' => new Checks\TestIntegrityCheck($w, $r),
            'command' => new Checks\CommandCheck($this->runnerFor('command')),
        ]);
    }
}
