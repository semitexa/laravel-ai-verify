<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

use Semitexa\LaravelAiVerify\Graph\ImpactAnalyzer;
use Semitexa\LaravelAiVerify\Support\PathPattern;
use Semitexa\LaravelAiVerify\Verify\Checks\ArtisanCheck;

/**
 * Turns a classified change set into the smallest set of checks that can
 * still vouch for it.
 *
 *   minimal  — syntax, Blade compile, JSON validity, changed tests, test integrity
 *   standard — + the project's style tool, PHPStan, related tests (graph, naming, changed routes), Laravel probes per kind
 *   broad    — + every Laravel probe and the whole test suite
 *
 * Custom commands from the config join at the scope they ask for, when one
 * of the changed files matches their `when` patterns.
 *
 * Standard is bumped to broad when contracts, providers or composer files
 * change, or when the change set is large.
 */
final class Planner
{
    /** kind => Laravel probes it requires (see ArtisanCheck::PROBES) */
    private const PROBES = [
        'route' => ['routes'],
        'controller' => ['routes'],
        'middleware' => ['routes'],
        'form_request' => ['routes'],
        'blade' => ['routes'],
        'mail' => ['routes'],
        'notification' => ['routes'],
        'livewire' => ['routes'],
        'view_component' => ['routes'],
        'config' => ['config'],
        'provider' => ['boot', 'routes', 'events'],
        'composer' => ['boot'],
        'event' => ['events'],
        'listener' => ['events'],
        'observer' => ['boot'],
        'policy' => ['boot'],
    ];

    /**
     * `style_tools` lists the style tools to run (ids, see StyleDetector); an
     * empty list still plans one `style` target, so the verdict says why no
     * style check ran.
     *
     * @param  array{broad_threshold?: int, max_test_targets?: int, test_depth?: int, style?: bool, style_tools?: list<string>, phpstan?: bool, migrations?: bool, advisories?: bool, test_integrity?: bool, commands?: list<array{name: string, run: string|list<string>, when: list<string>, scope: string, required: bool, timeout: ?int}>}  $options
     */
    public function __construct(
        private readonly TestLocator $tests,
        private readonly ?ImpactAnalyzer $impact = null,
        private readonly array $options = [],
        private readonly ?string $gitRef = null,
        private readonly bool $hasGit = true,
        private readonly ?RouteTests $routeTests = null,
    ) {}

    /** @param list<ChangedFile> $files */
    public function plan(array $files, Scope $scope): Plan
    {
        $plan = new Plan($scope, $scope);
        $this->maybeWiden($plan, $files);
        $effective = $plan->effectiveScope;

        $live = array_values(array_filter($files, static fn (ChangedFile $f) => ! $f->isDeleted()));
        $paths = static fn (array $list) => array_map(static fn (ChangedFile $f) => $f->path, $list);

        // ── always: syntax per PHP file, Blade compile, JSON validity ──────────────
        $blade = $json = [];

        foreach ($live as $file) {
            match (true) {
                $file->kind === FileKind::Blade => $blade[] = $file->path,
                $file->kind === FileKind::Json, $file->kind === FileKind::Composer,
                $file->kind === FileKind::Lang && str_ends_with($file->path, '.json') => $json[] = $file->path,
                $file->kind->isPhp() => $plan->add(new Target('syntax:'.$file->path, 'syntax', 'PHP file changed', [$file->path], ['file' => $file->path])),
                default => null,
            };
        }

        if ($blade !== []) {
            $plan->add(new Target('blade', 'blade', 'Blade view changed — compile with the app\'s Blade compiler', $blade, ['files' => $blade]));
        }

        if ($json !== []) {
            $plan->add(new Target('json', 'json', 'JSON file changed', $json, ['files' => $json]));
        }

        // ── always: test integrity for changed or deleted test files ──────────────
        $changedTests = array_values(array_filter($files, static fn (ChangedFile $f) => $f->kind === FileKind::Test));

        if ($changedTests !== [] && ($this->options['test_integrity'] ?? true)) {
            $plan->add(new Target(
                'test_integrity', 'test_integrity',
                'Test files changed — make sure no test or assertion was removed or skipped',
                $paths($changedTests),
                ['files' => $paths($changedTests), 'ref' => $this->gitRef ?? 'HEAD'],
                required: $this->hasGit,
                readsContent: false,
            ));
        }

        // ── tests: changed tests always run themselves ────────────────────────────
        $testTargets = [];

        foreach ($changedTests as $test) {
            if (! $test->isDeleted()) {
                $testTargets[$test->path] = [$test->path];
            }
        }

        if ($effective->atLeast(Scope::Standard)) {
            $this->planStandard($plan, $files, $live, $testTargets);
        }

        if ($effective === Scope::Broad) {
            foreach (array_keys(ArtisanCheck::PROBES) as $probe) {
                $this->addProbe($plan, $probe, [], 'broad scope runs every Laravel probe');
            }
        }

        $this->addTestTargets($plan, $testTargets, $effective, $paths($live));
        $this->addCommands($plan, $files, $live, $effective);

        return $plan;
    }

    /**
     * @param  list<ChangedFile>  $files
     * @param  list<ChangedFile>  $live
     */
    private function addCommands(Plan $plan, array $files, array $live, Scope $scope): void
    {
        foreach ($this->options['commands'] ?? [] as $command) {
            if (! $scope->atLeast(Scope::fromInput($command['scope']))) {
                continue;
            }

            $matches = static fn (ChangedFile $f) => $command['when'] === [] || PathPattern::any($command['when'], $f->path);
            $triggers = array_values(array_map(static fn (ChangedFile $f) => $f->path, array_filter($files, $matches)));

            if ($triggers === []) {
                continue;
            }

            $plan->add(new Target(
                'command:'.$command['name'], 'command',
                'custom check from ai-verify.commands'.($command['when'] !== [] ? ' (when '.implode(', ', $command['when']).')' : ''),
                $triggers,
                [
                    'name' => $command['name'],
                    'run' => $command['run'],
                    'files' => array_values(array_map(static fn (ChangedFile $f) => $f->path, array_filter($live, $matches))),
                    'timeout' => $command['timeout'],
                ],
                required: $command['required'],
            ));
        }
    }

    /**
     * @param  list<ChangedFile>  $files
     * @param  list<ChangedFile>  $live
     * @param  array<string, list<string>>  $testTargets
     */
    private function planStandard(Plan $plan, array $files, array $live, array &$testTargets): void
    {
        $phpForStyle = [];
        $phpForAnalysis = [];

        foreach ($live as $file) {
            if ($file->kind->isPhp()) {
                $phpForStyle[] = $file->path;
            }

            if ($file->kind->isAppCode() || in_array($file->kind, [FileKind::Factory, FileKind::Seeder, FileKind::Migration], true)) {
                $phpForAnalysis[] = $file->path;
            }

            foreach (self::PROBES[$file->kind->value] ?? [] as $probe) {
                $this->addProbe($plan, $probe, [$file->path], "{$file->kind->value} changed");
            }

            if ($file->kind === FileKind::Migration && ($this->options['migrations'] ?? true)) {
                $plan->add(new Target(
                    'migration:'.$file->path, 'migration',
                    'Migration changed — run up()/down() in pretend mode',
                    [$file->path], ['file' => $file->path],
                ));
            }

            if ($file->kind === FileKind::Composer && str_ends_with($file->path, 'composer.json')) {
                $plan->add(new Target('composer', 'composer', 'composer.json changed — validate schema and lock sync', [$file->path], [], required: false));
            }

            if ($file->kind === FileKind::Composer && ($this->options['advisories'] ?? true)) {
                $plan->add(new Target(
                    'advisories', 'advisories',
                    'Dependencies changed — composer audit on the lock file',
                    [$file->path], ['ref' => $this->gitRef ?? 'HEAD'],
                    required: false, readsContent: false,
                ));
            }
        }

        // Route-name references: the whole project after a routes/*.php change, else just the changed files.
        if ($routes = $plan->get('artisan:routes')) {
            $routeFileChanged = array_filter($files, static fn (ChangedFile $f) => $f->kind === FileKind::Route) !== [];
            $routes->params['refs'] = $routeFileChanged
                ? 'all'
                : array_values(array_map(static fn (ChangedFile $f) => $f->path, array_filter($live, static fn (ChangedFile $f) => $f->kind->isPhp() || $f->kind === FileKind::Blade)));
        }

        // Deleted or renamed classes: whoever still imports them must be re-analysed.
        if ($this->impact !== null) {
            $gone = [];

            foreach ($files as $file) {
                if ($file->isDeleted() && $file->kind->isAppCode()) {
                    $gone[] = $file->path;
                } elseif ($file->status === ChangedFile::RENAMED && $file->originalPath !== null) {
                    $gone[] = $file->originalPath;
                }
            }

            foreach ($gone as $path) {
                $dependents = $this->impact->directDependents($path);

                if ($dependents !== []) {
                    $plan->expansions[] = "{$path} was removed — still referenced by ".count($dependents).' file(s); re-analysing them';
                    $phpForAnalysis = [...$phpForAnalysis, ...$dependents];
                }
            }
        }

        if ($phpForStyle !== [] && ($this->options['style'] ?? true)) {
            $tools = $this->options['style_tools'] ?? ['pint'];

            foreach ($tools === [] ? [null] : $tools as $tool) {
                $plan->add(new Target(
                    $tool ?? 'style', 'style',
                    'Code style of changed PHP files'.($tool !== null ? " ({$tool})" : ''),
                    $phpForStyle,
                    ['tool' => $tool, 'files' => $phpForStyle, 'ref' => $this->gitRef ?? 'HEAD'],
                    required: false,
                ));
            }
        }

        $phpForAnalysis = array_values(array_unique($phpForAnalysis));

        if ($phpForAnalysis !== [] && ($this->options['phpstan'] ?? true)) {
            $plan->add(new Target(
                'phpstan', 'phpstan',
                'Static analysis of changed production code',
                $phpForAnalysis, ['files' => $phpForAnalysis, 'ref' => $this->gitRef ?? 'HEAD'],
                required: false,
            ));
        }

        // Related tests: by graph reachability, then by name.
        $sources = array_values(array_filter($files, static fn (ChangedFile $f) => $f->kind !== FileKind::Test));
        $depth = (int) ($this->options['test_depth'] ?? 4);

        if ($this->impact !== null && $sources !== []) {
            $sourcePaths = array_map(static fn (ChangedFile $f) => $f->originalPath ?? $f->path, $sources);
            $sourcePaths = [...$sourcePaths, ...array_map(static fn (ChangedFile $f) => $f->path, $sources)];

            foreach ($this->impact->testsFor(array_values(array_unique($sourcePaths)), $depth) as $test => $triggers) {
                $testTargets[$test] = [...($testTargets[$test] ?? []), ...$triggers];
            }
        }

        // Tests that request a changed route: the graph cannot get there from a route file.
        $routeFiles = array_values(array_filter($files, static fn (ChangedFile $f) => $f->kind === FileKind::Route));

        if ($this->routeTests !== null && $routeFiles !== []) {
            foreach ($this->routeTests->testsFor($routeFiles, $this->gitRef ?? 'HEAD') as $test => $triggers) {
                $testTargets[$test] = [...($testTargets[$test] ?? []), ...$triggers];
            }
        }

        foreach ($sources as $file) {
            if ($file->kind === FileKind::TestFixture) {
                $dir = $this->tests->directoryFor($file->path);
                $key = $dir ?? '*suite*';
                $testTargets[$key] = [...($testTargets[$key] ?? []), $file->path];

                continue;
            }

            if ($file->kind->isPhp() && ! $file->kind->isTest()) {
                foreach ($this->tests->byName($file->path) as $test) {
                    $testTargets[$test] = [...($testTargets[$test] ?? []), $file->path];
                }
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $testTargets  test path (or dir, or '*suite*') => triggering files
     * @param  list<string>  $live
     */
    private function addTestTargets(Plan $plan, array $testTargets, Scope $scope, array $live): void
    {
        $max = (int) ($this->options['max_test_targets'] ?? 25);
        $runSuite = $scope === Scope::Broad || isset($testTargets['*suite*']) || count($testTargets) > $max;

        if ($runSuite) {
            $reason = match (true) {
                $scope === Scope::Broad => 'broad scope runs the whole suite',
                isset($testTargets['*suite*']) => 'shared test infrastructure changed (TestCase, Pest.php, …)',
                default => count($testTargets).' related test files exceed max_test_targets='.$max,
            };

            if (count($testTargets) > $max && $scope !== Scope::Broad) {
                $plan->expansions[] = $reason.' — running the whole suite once instead';
            }

            $triggers = array_values(array_unique(array_merge($live, ...array_values($testTargets))));
            $plan->add(new Target('tests:suite', 'tests', $reason, $triggers, ['path' => null]));

            return;
        }

        foreach ($testTargets as $path => $triggers) {
            $plan->add(new Target(
                'tests:'.$path, 'tests',
                $triggers === [$path] ? 'test file changed' : 'covers '.implode(', ', array_slice(array_unique($triggers), 0, 3)),
                array_values(array_unique([$path, ...$triggers])),
                ['path' => $path],
            ));
        }
    }

    /** @param list<ChangedFile> $files */
    private function maybeWiden(Plan $plan, array $files): void
    {
        if ($plan->effectiveScope !== Scope::Standard) {
            return;
        }

        $threshold = (int) ($this->options['broad_threshold'] ?? 15);

        foreach ($files as $file) {
            if ($file->kind->widensScope()) {
                $plan->effectiveScope = Scope::Broad;
                $plan->expansions[] = "{$file->kind->value} changed ({$file->path}) — this ripples app-wide, expanding to broad scope";

                return;
            }
        }

        if (count($files) >= $threshold) {
            $plan->effectiveScope = Scope::Broad;
            $plan->expansions[] = count($files)." files changed (≥ {$threshold}) — expanding to broad scope";
        }
    }

    /** @param list<string> $triggers */
    private function addProbe(Plan $plan, string $probe, array $triggers, string $why): void
    {
        $plan->add(new Target(
            'artisan:'.$probe, 'artisan',
            ArtisanCheck::PROBES[$probe]['what'].' — '.$why,
            $triggers,
            ['probe' => $probe],
        ));
    }
}
