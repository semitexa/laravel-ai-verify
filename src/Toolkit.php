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

/**
 * Wires the verifier and the graph from the app's config. One place that
 * knows how the pieces fit, so both commands (and tests) stay thin.
 */
final class Toolkit
{
    public const VERSION = '0.4.2';

    public const HOMEPAGE = 'https://semitexa.com';

    public readonly Workspace $workspace;

    public readonly ProcessRunner $runner;

    public readonly ChangedFileClassifier $classifier;

    private ?GraphBuilder $builder = null;

    private ?Graph $graph = null;

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

    public function executor(): Executor
    {
        $w = $this->workspace;
        $r = $this->runner;

        return new Executor([
            'syntax' => new Checks\SyntaxCheck($w, $r),
            'blade' => new Checks\BladeCheck($w, $r, $this->app->bound('blade.compiler') ? $this->app->make('blade.compiler') : null),
            'json' => new Checks\JsonCheck($w),
            'pint' => new Checks\PintCheck($w, $r),
            'phpstan' => new Checks\PhpstanCheck(
                $w, $r,
                (int) ($this->config['phpstan']['fallback_level'] ?? 5),
                (string) ($this->config['phpstan']['memory_limit'] ?? '1G'),
            ),
            'tests' => new Checks\TestCheck($w, $r),
            'artisan' => new Checks\ArtisanCheck($w, $r),
            'migration' => new Checks\MigrationCheck($w, $r),
            'composer' => new Checks\ComposerCheck($r),
            'test_integrity' => new Checks\TestIntegrityCheck($w, $r),
        ]);
    }
}
