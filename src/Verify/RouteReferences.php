<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Finds references to route names that no longer exist: `route('x')`,
 * `to_route('x')`, `redirect()->route('x')`, `URL::route('x')`,
 * `assertRedirectToRoute('x')`. Renaming a route in routes/*.php leaves these
 * behind, and nothing fails until a request or test actually reaches them.
 */
final class RouteReferences
{
    /**
     * `route(` only as a free function: `$request->route('post')` and
     * `$this->route('post')` read a route *parameter*, not a route name.
     */
    private const PATTERN = '/(?:(?<![\w>$:])route|\bto_route|redirect\(\)\s*->\s*route|(?:Redirect|URL)::route|\bredirectToRoute|assertRedirectToRoute|(?:Url|URL)::(?:temporarySignedRoute|signedRoute))\(\s*([\'"])([\w.\-:]+)\1/';

    /** Where references live when the whole project is scanned. */
    private const ROOTS = ['app', 'resources/views', 'routes', 'tests', 'database'];

    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  list<string>  $knownNames  route names that exist
     * @param  list<string>|null  $files  relative paths to scan; null = the whole project
     * @return list<Violation>
     */
    public function missing(array $knownNames, ?array $files = null): array
    {
        $known = array_fill_keys($knownNames, true);
        $violations = [];

        foreach ($files ?? $this->projectFiles() as $file) {
            $absolute = $this->workspace->path($file);

            if (! is_file($absolute) || ! str_ends_with($file, '.php')) {
                continue;
            }

            $code = (string) file_get_contents($absolute);

            if (! preg_match_all(self::PATTERN, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $guarded = $this->guardedNames($code);

            foreach ($matches as $match) {
                $name = $match[2][0];

                if (isset($known[$name]) || isset($guarded[$name]) || str_contains($name, '*')) {
                    continue;
                }

                $violations[] = new Violation(
                    "Route [{$name}] is not defined",
                    $file,
                    substr_count($code, "\n", 0, $match[0][1]) + 1,
                    'laravel.route_name_missing',
                    tip: 'The route was renamed or removed. Update this reference, or restore the name with ->name(\''.$name.'\').',
                );
            }
        }

        return $violations;
    }

    /**
     * Names the file itself checks with `Route::has(...)` before using them, as in Laravel's
     * default welcome view: `@if (Route::has('login')) … route('login')`. Those are optional on purpose.
     *
     * @return array<string, true>
     */
    private function guardedNames(string $code): array
    {
        $guarded = [];

        if (preg_match_all('/Route::has\(\s*(\[[^\]]*\]|([\'"])[\w.\-:]+\2)/', $code, $calls)) {
            foreach ($calls[1] as $argument) {
                preg_match_all('/[\'"]([\w.\-:]+)[\'"]/', $argument, $names);

                foreach ($names[1] as $name) {
                    $guarded[$name] = true;
                }
            }
        }

        return $guarded;
    }

    /** @return list<string> */
    private function projectFiles(): array
    {
        $files = [];

        foreach (self::ROOTS as $root) {
            $dir = $this->workspace->path($root);

            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $this->workspace->relative($file->getPathname());
                }
            }
        }

        sort($files);

        return $files;
    }
}
