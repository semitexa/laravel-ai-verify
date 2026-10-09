<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Graph;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\ChangedFileClassifier;
use Semitexa\LaravelAiVerify\Verify\FileKind;
use Throwable;

/**
 * Builds the project graph in two passes:
 *  1. static — tokenises PHP under the configured paths and parses Blade, so it
 *     works on a half-broken app and never executes project code;
 *  2. runtime — asks the booted application for routes, listeners, model
 *     tables, policies and middleware. Every runtime probe is isolated: a
 *     failure drops that fact, never the graph.
 */
final class GraphBuilder
{
    private const RELATIONS = 'hasOne|hasMany|belongsTo|belongsToMany|hasOneThrough|hasManyThrough|morphOne|morphMany|morphToMany|morphedByMany';

    private Graph $graph;

    /** @var array<string, FileFacts> relative path => facts */
    private array $facts = [];

    /** @var array<string, string> FQCN => relative path */
    private array $classes = [];

    /** @var array<string, string> view name => relative path */
    private array $views = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  list<string>  $paths  directories / files to scan, relative to the project
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ChangedFileClassifier $classifier,
        private readonly array $paths = ['app', 'routes', 'database', 'tests', 'bootstrap/app.php'],
        private readonly string $viewsPath = 'resources/views',
        private readonly ?Application $app = null,
        private readonly PhpFileInspector $inspector = new PhpFileInspector,
    ) {}

    public function build(): Graph
    {
        $this->graph = new Graph;
        $this->facts = $this->classes = $this->views = $this->warnings = [];

        $this->scanPhp();
        $this->scanViews();
        $this->linkReferences();
        $this->linkViews();
        $this->linkMigrations();

        if ($this->app !== null) {
            $this->probe('routes', fn () => $this->linkRoutes());
            $this->probe('events', fn () => $this->linkEvents());
            $this->probe('models', fn () => $this->linkModels());
        }

        $this->linkTestsToRoutes();

        return $this->graph;
    }

    /** @return list<string> runtime probes that failed, for transparency */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function scanPhp(): void
    {
        foreach ($this->phpFiles() as $relative) {
            if (str_ends_with($relative, '.blade.php')) {
                continue;
            }

            $facts = $this->inspector->inspect($this->workspace->path($relative), $relative);
            $this->facts[$relative] = $facts;
            $kind = $this->classifier->classify($relative);

            if ($kind->isTest()) {
                $this->graph->addNode('test:'.$relative, 'test', $facts->class ?? $relative, $relative, ['kind' => $kind->value]);
            } elseif ($facts->class !== null) {
                $this->classes[$facts->class] = $relative;
                $this->graph->addNode('class:'.$facts->class, 'class', class_basename($facts->class), $relative, [
                    'kind' => $kind->value,
                    'type' => $facts->classType,
                ]);
            } else {
                $this->graph->addNode('file:'.$relative, 'file', $relative, $relative, ['kind' => $kind->value]);
            }
        }
    }

    private function scanViews(): void
    {
        $root = $this->workspace->path($this->viewsPath);

        if (! is_dir($root)) {
            return;
        }

        foreach ($this->walk($root) as $absolute) {
            if (! str_ends_with($absolute, '.blade.php')) {
                continue;
            }

            $relative = $this->workspace->relative($absolute);
            $name = str_replace('/', '.', substr($absolute, strlen($root) + 1, -strlen('.blade.php')));
            $this->views[$name] = $relative;
            $this->graph->addNode('view:'.$name, 'view', $name, $relative);
        }
    }

    /** Class/file/test → every project class it names. */
    private function linkReferences(): void
    {
        foreach ($this->facts as $relative => $facts) {
            $from = $this->nodeIdFor($relative);
            $imported = array_flip($facts->imports);

            foreach ($facts->references as $name) {
                if (! isset($this->classes[$name])) {
                    // An explicit import of a project-namespace class that no longer exists:
                    // keep it as a "missing" node so deleting/renaming a class still finds its users.
                    if (isset($imported[$name]) && $this->workspace->isProjectNamespace($name)) {
                        $this->graph->addNode('class:'.$name, 'missing', class_basename($name), $this->workspace->pathForClass($name));
                        $this->graph->addEdge($from, 'class:'.$name, str_starts_with($from, 'test:') ? 'tests' : 'references');
                    }

                    continue;
                }

                $type = match (true) {
                    str_starts_with($from, 'test:') => 'tests',
                    $name === $facts->extends => 'extends',
                    in_array($name, $facts->implements, true) => 'implements',
                    default => 'references',
                };

                $this->graph->addEdge($from, 'class:'.$name, $type);
            }

            // Model ⇄ factory by convention: tests reach a factory through Model::factory().
            if ($facts->class !== null && str_starts_with($facts->class, 'Database\\Factories\\')) {
                $model = 'App\\Models\\'.Str::beforeLast(class_basename($facts->class), 'Factory');

                if (isset($this->classes[$model])) {
                    $this->graph->addEdge('class:'.$model, 'class:'.$facts->class, 'has_factory');
                }
            }
        }
    }

    /** Class → view it renders (string literal naming an existing view), view → view / component. */
    private function linkViews(): void
    {
        foreach ($this->facts as $relative => $facts) {
            $from = $this->nodeIdFor($relative);

            foreach ($facts->strings as $string) {
                if (isset($this->views[$string])) {
                    $this->graph->addEdge($from, 'view:'.$string, 'renders');
                }
            }
        }

        $componentNamespace = 'App\\View\\Components\\';

        foreach ($this->views as $name => $relative) {
            $blade = (string) @file_get_contents($this->workspace->path($relative));

            preg_match_all("/@(?:extends|include|includeIf|includeWhen|includeUnless|includeFirst|each|component|livewire)\(\s*['\"]([\w.\-:]+)['\"]/", $blade, $includes);

            foreach ($includes[1] as $target) {
                $this->graph->addEdge('view:'.$name, 'view:'.$target, 'includes');
            }

            preg_match_all('/<x-([\w\-.:]+)/', $blade, $components);

            foreach (array_unique($components[1]) as $tag) {
                if (str_contains($tag, '::')) {
                    continue; // vendor-namespaced component
                }

                $segments = array_map(static fn (string $s) => Str::studly($s), explode('.', $tag));
                $class = $componentNamespace.implode('\\', $segments);

                if (isset($this->classes[$class])) {
                    $this->graph->addEdge('view:'.$name, 'class:'.$class, 'uses_component');
                } else {
                    $this->graph->addEdge('view:'.$name, 'view:components.'.$tag, 'uses_component');
                    $this->graph->addEdge('view:'.$name, 'view:components.'.$tag.'.index', 'uses_component');
                }
            }
        }
    }

    /** table → migration that creates or alters it. */
    private function linkMigrations(): void
    {
        foreach ($this->facts as $relative => $facts) {
            if ($this->classifier->classify($relative) !== FileKind::Migration) {
                continue;
            }

            $code = (string) @file_get_contents($this->workspace->path($relative));
            preg_match_all("/Schema::(?:connection\([^)]*\)->)?(create|table)\(\s*['\"]([\w.]+)['\"]/", $code, $m, PREG_SET_ORDER);

            foreach ($m as [, $verb, $table]) {
                $this->graph->addNode('table:'.$table, 'table', $table);
                $this->graph->addEdge('table:'.$table, $this->nodeIdFor($relative), $verb === 'create' ? 'created_by' : 'altered_by');
            }
        }
    }

    private function linkRoutes(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $aliases = $router->getMiddleware();
        $groups = $router->getMiddlewareGroups();

        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $uri = '/'.ltrim($route->uri(), '/');
            $id = 'route:'.implode('|', $methods).' '.$uri;

            $action = $route->getActionName();
            $this->graph->addNode($id, 'route', implode('|', $methods).' '.$uri, null, array_filter([
                'uri' => $uri,
                'methods' => $methods,
                'name' => $route->getName(),
                'action' => $action,
                'domain' => $route->getDomain(),
            ]));

            if ($action !== 'Closure' && $action !== '') {
                [$controller, $method] = array_pad(explode('@', $action, 2), 2, '__invoke');

                if (isset($this->classes[$controller])) {
                    $this->graph->addEdge($id, 'class:'.$controller, 'handled_by', array_filter([
                        'method' => $method,
                        'views' => $this->viewsInMethod($controller, $method),
                    ]));
                    $this->linkFormRequests($id, $controller, $method);
                }
            }

            foreach ($this->expandMiddleware($route->gatherMiddleware(), $aliases, $groups) as $class) {
                if (isset($this->classes[$class])) {
                    $this->graph->addEdge($id, 'class:'.$class, 'through');
                }
            }
        }
    }

    private function linkFormRequests(string $routeId, string $controller, string $method): void
    {
        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (Throwable) {
            return;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
                && isset($this->classes[$type->getName()])
                && is_subclass_of($type->getName(), FormRequest::class)) {
                $this->graph->addEdge($routeId, 'class:'.$type->getName(), 'validates_with');
            }
        }
    }

    /** @return list<string> views named inside one controller method's body */
    private function viewsInMethod(string $class, string $method): array
    {
        try {
            $reflection = new ReflectionMethod($class, $method);
            $lines = file((string) $reflection->getFileName()) ?: [];
            $body = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        } catch (Throwable) {
            return [];
        }

        preg_match_all('/[\'"]([\w.\-:]+)[\'"]/', $body, $m);

        return array_values(array_unique(array_filter($m[1], fn (string $name) => isset($this->views[$name]))));
    }

    /**
     * @param  array<int, mixed>  $middleware
     * @param  array<string, string>  $aliases
     * @param  array<string, array<int, string>>  $groups
     * @return list<string>
     */
    private function expandMiddleware(array $middleware, array $aliases, array $groups, int $depth = 0): array
    {
        $classes = [];

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $name = strstr($entry, ':', true) ?: $entry;

            if (isset($groups[$name]) && $depth < 3) {
                array_push($classes, ...$this->expandMiddleware($groups[$name], $aliases, $groups, $depth + 1));
            } else {
                $classes[] = $aliases[$name] ?? $name;
            }
        }

        return $classes;
    }

    private function linkEvents(): void
    {
        $events = $this->app->make('events');

        foreach ($events->getRawListeners() as $event => $listeners) {
            if (! class_exists($event) && ! interface_exists($event)) {
                continue;
            }

            $eventId = 'class:'.$event;

            if (! $this->graph->has($eventId)) {
                $this->graph->addNode($eventId, 'class', class_basename($event), null, ['kind' => 'event', 'external' => true]);
            }

            foreach ((array) $listeners as $listener) {
                $class = match (true) {
                    is_string($listener) => strstr($listener, '@', true) ?: $listener,
                    is_array($listener) && is_string($listener[0] ?? null) => $listener[0],
                    default => null,
                };

                if ($class !== null && isset($this->classes[$class])) {
                    $this->graph->addEdge($eventId, 'class:'.$class, 'dispatches_to');
                }
            }
        }
    }

    private function linkModels(): void
    {
        $gate = $this->app->bound(Gate::class)
            ? $this->app->make(Gate::class)
            : null;

        foreach ($this->classes as $class => $relative) {
            if ($this->facts[$relative]->extends === null) {
                continue;
            }

            try {
                if (! is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                $table = (new $class)->getTable();
                $this->graph->addNode('table:'.$table, 'table', $table);
                $this->graph->addEdge('class:'.$class, 'table:'.$table, 'maps_to');
            } catch (Throwable) {
                continue;
            }

            $code = (string) @file_get_contents($this->workspace->path($relative));
            preg_match_all('/\$this->('.self::RELATIONS.')\(\s*\\\\?([\w\\\\]+)::class/', $code, $m, PREG_SET_ORDER);

            foreach ($m as [, $relation, $target]) {
                $related = $this->facts[$relative]->resolveShort($target);

                if (isset($this->classes[$related])) {
                    $this->graph->addEdge('class:'.$class, 'class:'.$related, 'relation', ['type' => $relation]);
                }
            }

            try {
                $policy = $gate?->getPolicyFor($class);

                if ($policy !== null && isset($this->classes[$policy::class])) {
                    $this->graph->addEdge('class:'.$class, 'class:'.$policy::class, 'authorized_by');
                }
            } catch (Throwable) {
                // policy resolution needs nothing but the container; a failure just means "unknown"
            }
        }
    }

    /** test → route it requests, matched by literal URI or route name. */
    private function linkTestsToRoutes(): void
    {
        $patterns = [];
        $names = [];

        foreach ($this->graph->nodesOfType('route') as $route) {
            $uri = $route['meta']['uri'] ?? null;

            if ($uri !== null && ! str_starts_with($uri, '/_') && $uri !== '/up') {
                $regex = preg_quote($uri, '#');
                $regex = (string) preg_replace('#\\\\\{[^/]+?\\\\\?\\\\\}#', '[^/]*', $regex);
                $regex = (string) preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', $regex);
                $patterns[$route['id']] = '#^'.$regex.'/?$#';
            }

            if (isset($route['meta']['name'])) {
                $names[$route['meta']['name']] = $route['id'];
            }
        }

        if ($patterns === [] && $names === []) {
            return;
        }

        foreach ($this->facts as $relative => $facts) {
            if (! $this->classifier->classify($relative)->isTest()) {
                continue;
            }

            foreach (array_unique($facts->strings) as $string) {
                if (isset($names[$string])) {
                    $this->graph->addEdge('test:'.$relative, $names[$string], 'hits');

                    continue;
                }

                if (! str_starts_with($string, '/')) {
                    continue;
                }

                $path = strstr($string, '?', true) ?: $string;

                foreach ($patterns as $id => $regex) {
                    if (preg_match($regex, $path)) {
                        $this->graph->addEdge('test:'.$relative, $id, 'hits');
                    }
                }
            }
        }
    }

    private function nodeIdFor(string $relative): string
    {
        $facts = $this->facts[$relative] ?? null;

        if ($this->classifier->classify($relative)->isTest()) {
            return 'test:'.$relative;
        }

        return $facts?->class !== null ? 'class:'.$facts->class : 'file:'.$relative;
    }

    /** @return list<string> relative paths */
    private function phpFiles(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            $absolute = $this->workspace->path($path);

            if (is_file($absolute) && str_ends_with($absolute, '.php')) {
                $files[] = $this->workspace->relative($absolute);
            } elseif (is_dir($absolute)) {
                foreach ($this->walk($absolute) as $file) {
                    if (str_ends_with($file, '.php')) {
                        $files[] = $this->workspace->relative($file);
                    }
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /** @return iterable<string> */
    private function walk(string $dir): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file) => ! in_array($file->getFilename(), ['vendor', 'node_modules', '.git'], true),
            ),
        );

        foreach ($iterator as $file) {
            yield $file->getPathname();
        }
    }

    private function probe(string $name, Closure $probe): void
    {
        try {
            $probe();
        } catch (Throwable $e) {
            $this->warnings[] = "{$name}: ".$e->getMessage();
        }
    }
}
