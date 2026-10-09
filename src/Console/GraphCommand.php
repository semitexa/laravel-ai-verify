<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use Semitexa\LaravelAiVerify\Graph\Graph;
use Semitexa\LaravelAiVerify\Toolkit;

/**
 * Agent orientation over the project graph.
 *
 *   php artisan semitexa:graph                         # overview: counts, routes, hubs
 *   php artisan semitexa:graph /posts/{post}           # a route's full chain
 *   php artisan semitexa:graph "App\Models\Post"       # what a class uses / what uses it
 *   php artisan semitexa:graph --impact=app/Models/Post.php
 *   php artisan semitexa:graph --json --full           # the whole graph
 */
final class GraphCommand extends Command
{
    protected $signature = 'semitexa:graph
        {node? : Class FQCN or basename, file path, route URI or name, view name, or table}
        {--impact=* : Blast radius of these paths, with the tests that cover them}
        {--json : JSON output (default when stdout is not a terminal)}
        {--human : Human-readable output (default in a terminal)}
        {--full : With --json and no node: dump every node and edge}';

    protected $description = 'Project graph for AI agents: routes → controllers → requests/policies/models/views → tests, events → listeners, impact';

    private Graph $graph;

    private bool $json;

    public function handle(): int
    {
        $toolkit = Toolkit::fromApp($this->laravel);
        $this->graph = $toolkit->graph();
        $this->json = (bool) $this->option('json')
            || (! $this->option('human') && ! (function_exists('stream_isatty') && @stream_isatty(STDOUT)));

        if ($impact = (array) $this->option('impact')) {
            $analyzer = $toolkit->impact();
            $report = $analyzer->report($impact);
            $report['tests'] = $analyzer->testsFor($impact, (int) ($toolkit->config['test_depth'] ?? 4));

            return $this->respond($report, fn () => $this->renderImpact($report));
        }

        if ($needle = $this->argument('node')) {
            $id = $this->graph->find((string) $needle);

            if ($id === null) {
                return $this->respond(['error' => "No node matches '{$needle}'"], fn () => $this->error("No node matches '{$needle}'"), self::FAILURE);
            }

            $view = $this->describe($id);

            return $this->respond($view, fn () => $this->renderNode($view));
        }

        if ($this->json && $this->option('full')) {
            return $this->respond(['stats' => $this->graph->stats(), 'warnings' => $toolkit->graphWarnings()] + $this->graph->toArray(), fn () => null);
        }

        $overview = $this->overview();
        $overview['warnings'] = $toolkit->graphWarnings();

        return $this->respond($overview, fn () => $this->renderOverview($overview));
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        $routes = [];

        foreach ($this->graph->nodesOfType('route') as $route) {
            $handler = $this->graph->outgoing($route['id'], 'handled_by')[0] ?? null;
            $routes[] = array_filter([
                'route' => $route['label'],
                'name' => $route['meta']['name'] ?? null,
                'action' => $handler !== null ? $this->short($handler['to']).'@'.($handler['meta']['method'] ?? '__invoke') : ($route['meta']['action'] ?? null),
                'tests' => count($this->graph->incoming($route['id'], 'hits')) ?: null,
            ]);
        }

        $hubs = [];

        foreach ($this->graph->nodesOfType('class') as $node) {
            $in = count(array_filter($this->graph->incoming($node['id']), static fn (array $e) => $e['type'] !== 'tests'));

            if ($in > 0) {
                $hubs[$node['id']] = $in;
            }
        }

        arsort($hubs);

        $untested = [];

        foreach ($this->graph->nodesOfType('class') as $node) {
            $kind = $node['meta']['kind'] ?? '';

            if (in_array($kind, ['controller', 'model', 'job', 'listener', 'policy', 'form_request', 'app_class'], true)
                && ! array_filter(array_keys($this->graph->dependents([$node['id']], 3)), static fn (string $id) => str_starts_with($id, 'test:'))) {
                $untested[] = $node['path'] ?? $node['id'];
            }
        }

        return [
            'stats' => $this->graph->stats(),
            'routes' => $routes,
            'hubs' => array_map(fn ($id, $count) => ['node' => $id, 'dependents' => $count], array_keys(array_slice($hubs, 0, 10, true)), array_slice($hubs, 0, 10, true)),
            'untested' => $untested,
        ];
    }

    /** @return array<string, mixed> */
    private function describe(string $id): array
    {
        $node = $this->graph->node($id);
        $group = function (array $edges, string $side): array {
            $out = [];

            foreach ($edges as $edge) {
                $out[$edge['type']][] = $edge[$side].(isset($edge['meta']['method']) ? '@'.$edge['meta']['method'] : '')
                    .(isset($edge['meta']['type']) ? ' ('.$edge['meta']['type'].')' : '');
            }

            ksort($out);

            return $out;
        };

        $view = [
            'node' => $node,
            'uses' => $group($this->graph->outgoing($id), 'to'),
            'used_by' => $group($this->graph->incoming($id), 'from'),
        ];

        if ($node['type'] === 'route') {
            $view['chain'] = $this->routeChain($id);
        }

        $tests = [];

        foreach ($this->graph->dependents([$id], 3) as $dependent => $distance) {
            if (str_starts_with($dependent, 'test:')) {
                $tests[] = substr($dependent, 5);
            }
        }

        sort($tests);
        $view['tests'] = $tests;

        return $view;
    }

    /**
     * route → controller@method, form request, middleware, views, models (+ table, policy), tests.
     *
     * @return array<string, mixed>
     */
    private function routeChain(string $routeId): array
    {
        $chain = ['route' => $this->graph->node($routeId)['meta'] ?? []];
        $handler = $this->graph->outgoing($routeId, 'handled_by')[0] ?? null;

        $chain['controller'] = $handler !== null ? substr($handler['to'], 6).'@'.($handler['meta']['method'] ?? '__invoke') : null;
        $chain['form_requests'] = array_map(static fn (array $e) => substr($e['to'], 6), $this->graph->outgoing($routeId, 'validates_with'));
        $chain['middleware'] = array_map(static fn (array $e) => substr($e['to'], 6), $this->graph->outgoing($routeId, 'through'));
        $chain['views'] = $handler['meta']['views'] ?? [];
        $chain['models'] = [];

        if ($handler !== null) {
            foreach ($this->graph->outgoing($handler['to']) as $edge) {
                if ($edge['type'] === 'renders' && ! isset($handler['meta']['views'])) {
                    $chain['views'][] = substr($edge['to'], 5);
                }

                $target = $this->graph->node($edge['to']);

                if (($target['meta']['kind'] ?? null) === 'model') {
                    $model = ['class' => substr($edge['to'], 6)];

                    foreach ($this->graph->outgoing($edge['to']) as $modelEdge) {
                        match ($modelEdge['type']) {
                            'maps_to' => $model['table'] = substr($modelEdge['to'], 6),
                            'authorized_by' => $model['policy'] = substr($modelEdge['to'], 6),
                            default => null,
                        };
                    }

                    $chain['models'][] = $model;
                }
            }
        }

        $chain['tests'] = array_map(static fn (array $e) => substr($e['from'], 5), $this->graph->incoming($routeId, 'hits'));

        return $chain;
    }

    // ── rendering ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overview */
    private function renderOverview(array $overview): void
    {
        $s = $overview['stats'];
        $this->newLine();
        $this->line('  <options=bold>semitexa:graph</> · '.$s['nodes'].' nodes · '.$s['edges'].' edges · '
            .($s['nodes.route'] ?? 0).' routes · '.($s['nodes.class'] ?? 0).' classes · '.($s['nodes.view'] ?? 0).' views · '.($s['nodes.test'] ?? 0).' tests');
        $this->newLine();

        if ($overview['routes'] !== []) {
            $this->table(['Route', 'Name', 'Action', 'Tests'], array_map(
                static fn (array $r) => [$r['route'], $r['name'] ?? '', $r['action'] ?? '', $r['tests'] ?? '—'],
                $overview['routes'],
            ));
        }

        if ($overview['hubs'] !== []) {
            $this->line('  <options=bold>Most depended-on</>');

            foreach ($overview['hubs'] as $hub) {
                $this->line("    {$hub['dependents']}  ".$this->short($hub['node']));
            }

            $this->newLine();
        }

        if ($overview['untested'] !== []) {
            $this->line('  <options=bold>No test reaches</> <fg=gray>(within 3 hops)</>');

            foreach (array_slice($overview['untested'], 0, 15) as $path) {
                $this->line("    <fg=yellow>·</> {$path}");
            }

            $this->newLine();
        }

        foreach ($overview['warnings'] as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        $this->line('  <fg=gray>Next: php artisan semitexa:graph <class|route|view> · --impact=<path> · --json --full</>');
        $this->newLine();
    }

    /** @param array<string, mixed> $view */
    private function renderNode(array $view): void
    {
        $node = $view['node'];
        $this->newLine();
        $this->line("  <options=bold>{$node['id']}</>".(isset($node['path']) ? " <fg=gray>{$node['path']}</>" : ''));

        if (isset($view['chain'])) {
            $c = $view['chain'];
            $this->newLine();
            $this->line('  <fg=cyan>controller</>    '.($c['controller'] ?? '—'));

            foreach ([['form_requests', 'request'], ['middleware', 'middleware'], ['views', 'view']] as [$key, $label]) {
                foreach ($c[$key] as $value) {
                    $this->line('  <fg=cyan>'.str_pad($label, 14).'</>'.$value);
                }
            }

            foreach ($c['models'] as $model) {
                $this->line('  <fg=cyan>model</>         '.$model['class']
                    .(isset($model['table']) ? " <fg=gray>table {$model['table']}</>" : '')
                    .(isset($model['policy']) ? " <fg=gray>policy {$model['policy']}</>" : ''));
            }
        }

        foreach (['uses' => '→', 'used_by' => '←'] as $key => $arrow) {
            if ($view[$key] === []) {
                continue;
            }

            $this->newLine();

            foreach ($view[$key] as $type => $targets) {
                foreach ($targets as $target) {
                    $this->line("  {$arrow} <fg=gray>".str_pad($type, 15)."</>{$target}");
                }
            }
        }

        $this->newLine();
        $this->line('  <options=bold>Tests</> '.($view['tests'] === [] ? '<fg=yellow>none within 3 hops</>' : count($view['tests'])));

        foreach ($view['tests'] as $test) {
            $this->line("    {$test}");
        }

        $this->newLine();
    }

    /** @param array<string, mixed> $report */
    private function renderImpact(array $report): void
    {
        $this->newLine();
        $this->line("  <options=bold>impact</> max <options=bold>{$report['max']}</> · {$report['dependents']} dependent(s) · {$report['routes_affected']} route(s)");
        $this->newLine();

        foreach ($report['files'] as $file) {
            $this->line("  <fg=magenta>{$file['band']}</> {$file['path']} <fg=gray>{$file['dependents']} dependents · {$file['routes']} routes · {$file['tests']} tests</>");

            foreach ($file['sample'] ?? [] as $id) {
                $this->line("      <fg=gray>←</> {$id}");
            }
        }

        $this->newLine();
        $this->line('  <options=bold>Tests to run</> '.count($report['tests']));

        foreach ($report['tests'] as $test => $from) {
            $this->line("    {$test}");
        }

        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data, \Closure $human, int $exit = self::SUCCESS): int
    {
        if ($this->json) {
            $this->line((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $human();
        }

        return $exit;
    }

    private function short(string $id): string
    {
        return str_starts_with($id, 'class:') ? substr($id, 6) : $id;
    }
}
