<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Finds the tests that request a route changed in routes/*.php. The graph links
 * a test to the controller behind a route, so a rename in a route file reaches
 * no test through it: the route file is not a class anyone imports.
 *
 * The route file is compared with its version at the base ref, statement by
 * statement, so a chain spread over several lines counts as one route and both
 * the old and the new name or URI are looked for. Reformatting and comments
 * change nothing. A test that matches by accident only costs one more test run.
 */
final class RouteTests
{
    private const NAMES = [
        '/->name\(\s*[\'"]([\w.\-:]+)[\'"]/',
        '/[\'"]as[\'"]\s*=>\s*[\'"]([\w.\-:]+)[\'"]/',
    ];

    private const URIS = [
        '/(?:::|->)(?:get|post|put|patch|delete|options|any|view|redirect|permanentRedirect|inertia|livewire|route)\(\s*[\'"]([^\'"]*)[\'"]/',
        '/(?:::|->)match\(\s*\[[^\]]*\]\s*,\s*[\'"]([^\'"]*)[\'"]/',
    ];

    /** Each of these stands for a whole family of routes: `posts.*` and `/posts…`. */
    private const RESOURCES = '/(?:::|->)(?:resource|apiResource|singleton|apiSingleton)\(\s*[\'"]([^\'"]+)[\'"]/';

    private const PREFIXES = [
        '/(?:::|->)prefix\(\s*[\'"]([^\'"]+)[\'"]/',
        '/[\'"]prefix[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/',
    ];

    /** `$this->get('/x')`, Pest's `get('/x')`, `->assertRedirect('/x')`. */
    private const REQUESTS = [
        '/(?:->|(?<![\w$>:]))(?:get|post|put|patch|delete|options|head|getJson|postJson|putJson|patchJson|deleteJson|optionsJson|visit|from|assertRedirect|assertRedirectContains)\(\s*([\'"])(.*?)\1/',
        '/->(?:json|call)\(\s*[\'"]\w+[\'"]\s*,\s*([\'"])(.*?)\1/',
    ];

    /** @var array<string, array{names: list<string>, uris: list<string>}> */
    private array $references = [];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
        private readonly TestLocator $tests,
    ) {}

    /**
     * @param  list<ChangedFile>  $routeFiles
     * @return array<string, list<string>>  test path => the route files that sent us to it
     */
    public function testsFor(array $routeFiles, string $ref): array
    {
        $found = [];

        foreach ($routeFiles as $file) {
            $changed = $this->changedRoutes($file, $ref);

            if ($changed['names'] === [] && $changed['uris'] === []) {
                continue;
            }

            foreach ($this->tests->all() as $test) {
                if ($this->requests($test, $changed)) {
                    $found[$test][] = $file->path;
                }
            }
        }

        return $found;
    }

    /** @return array{names: array<string, bool>, uris: list<array{segments: list<string>, family: bool}>} */
    private function changedRoutes(ChangedFile $file, string $ref): array
    {
        $old = $this->runner->run(['git', 'show', $ref.':'.($file->originalPath ?? $file->path)]);
        $before = $this->statements($old->succeeded() ? $old->stdout : '');
        $absolute = $this->workspace->path($file->path);
        $after = $this->statements(is_file($absolute) ? (string) file_get_contents($absolute) : '');

        $names = $uris = [];

        foreach ([...array_diff($before, $after), ...array_diff($after, $before)] as $statement) {
            foreach (self::NAMES as $pattern) {
                preg_match_all($pattern, $statement, $matches);

                foreach ($matches[1] as $name) {
                    $names[$name] = str_ends_with($name, '.');
                }
            }

            foreach (self::URIS as $pattern) {
                preg_match_all($pattern, $statement, $matches);

                foreach ($matches[1] as $uri) {
                    array_push($uris, ...$this->routePatterns($uri, false));
                }
            }

            preg_match_all(self::RESOURCES, $statement, $matches);

            foreach ($matches[1] as $resource) {
                $names[$resource.'.'] = true;
                array_push($uris, ...$this->routePatterns(str_replace('.', '/{id}/', $resource), true));
            }

            foreach (self::PREFIXES as $pattern) {
                preg_match_all($pattern, $statement, $matches);

                foreach ($matches[1] as $prefix) {
                    array_push($uris, ...$this->routePatterns($prefix, true));
                }
            }
        }

        return ['names' => $names, 'uris' => $uris];
    }

    /**
     * Code split at `;`, `{` and `}`, whitespace and comments removed, so a route
     * inside a group is its own statement and does not drag its group along.
     *
     * @return list<string>
     */
    private function statements(string $code): array
    {
        if ($code === '') {
            return [];
        }

        $statements = [];
        $current = '';

        foreach (token_get_all($code) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }

            if (in_array($text, [';', '{', '}'], true)) {
                $statements[] = $current;
                $current = '';

                continue;
            }

            $current .= $text;
        }

        return array_values(array_unique(array_filter([...$statements, $current], static fn (string $s) => $s !== '')));
    }

    /**
     * `/posts/{post?}` matches with and without its optional tail.
     *
     * @return list<array{segments: list<string>, family: bool}>
     */
    private function routePatterns(string $uri, bool $family): array
    {
        $segments = array_map(
            static fn (string $s) => str_starts_with($s, '{') ? '*' : $s,
            $this->segments($uri),
        );
        $patterns = [['segments' => $segments, 'family' => $family]];
        $parts = $this->segments($uri);

        while ($parts !== [] && str_ends_with((string) end($parts), '?}')) {
            array_pop($parts);
            array_pop($segments);
            $patterns[] = ['segments' => $segments, 'family' => $family];
        }

        // `/{slug}` has nothing to look for: under a group prefix it would match the end of any URI.
        return array_values(array_filter(
            $patterns,
            static fn (array $p) => $p['segments'] === [] || array_diff($p['segments'], ['*']) !== [],
        ));
    }

    /** @return list<string> */
    private function segments(string $uri): array
    {
        return array_values(array_filter(explode('/', $uri), static fn (string $s) => $s !== ''));
    }

    /** @param array{names: array<string, bool>, uris: list<array{segments: list<string>, family: bool}>} $changed */
    private function requests(string $test, array $changed): bool
    {
        $references = $this->references[$test] ??= $this->read($test);

        foreach ($references['names'] as $reference) {
            foreach ($changed['names'] as $name => $family) {
                // A route inside a named group is known here by its last part only.
                $hit = $family
                    ? str_starts_with($reference, (string) $name) || str_contains($reference, '.'.$name)
                    : $reference === (string) $name || str_ends_with($reference, '.'.$name);

                if ($hit) {
                    return true;
                }
            }
        }

        foreach ($references['uris'] as $uri) {
            foreach ($changed['uris'] as $route) {
                if ($this->matches($route['segments'], $route['family'], $uri)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{names: list<string>, uris: list<string>} */
    private function read(string $test): array
    {
        $code = (string) file_get_contents($this->workspace->path($test));
        $uris = [];

        foreach (self::REQUESTS as $pattern) {
            preg_match_all($pattern, $code, $matches);
            $uris = [...$uris, ...$matches[2]];
        }

        return ['names' => RouteReferences::names($code), 'uris' => array_values(array_unique($uris))];
    }

    /**
     * The route may sit under a group prefix this file does not show, so it is
     * looked for anywhere in the test's path. A test URI that ends in a slash was
     * cut by a concatenation (`'/posts/'.$id`) and only has to match as far as it goes.
     *
     * @param  list<string>  $route
     */
    private function matches(array $route, bool $family, string $uri): bool
    {
        $path = (string) preg_replace(['#^[a-z]+://[^/]+#i', '/\?.*$/'], '', $uri);
        $test = $this->segments($path);
        $open = str_ends_with($path, '/') && $test !== [];

        if ($route === []) {
            return $test === [] && ! $family;
        }

        for ($start = 0, $count = count($test); $start < $count; $start++) {
            $length = min(count($route), $count - $start);

            for ($i = 0; $i < $length; $i++) {
                if (! $this->sameSegment($route[$i], $test[$start + $i])) {
                    continue 2;
                }
            }

            $whole = $length === count($route);
            $atEnd = $start + $length === $count;

            if (($family && $whole) || ($open && $atEnd) || ($whole && $atEnd)) {
                return true;
            }
        }

        return false;
    }

    private function sameSegment(string $route, string $test): bool
    {
        return $route === '*' || $route === $test || str_contains($test, '{') || str_contains($test, '$');
    }
}
