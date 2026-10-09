<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Graph;

/**
 * Reverse-dependency walks over the graph: blast radius per changed file,
 * and the tests that (transitively) exercise it.
 */
final class ImpactAnalyzer
{
    public const BAND_HIGH = 'high';

    public const BAND_MEDIUM = 'medium';

    public const BAND_LOW = 'low';

    /** The file has no node in the graph (outside scanned paths, or not code). */
    public const BAND_UNRESOLVED = 'unresolved';

    private const RANK = [self::BAND_UNRESOLVED => 0, self::BAND_LOW => 1, self::BAND_MEDIUM => 2, self::BAND_HIGH => 3];

    public function __construct(
        private readonly Graph $graph,
        private readonly int $depth = 5,
    ) {}

    /**
     * @param  list<string>  $paths
     * @return array{max: string, files: list<array<string, mixed>>, routes_affected: int, dependents: int, hottest: ?string}
     */
    public function report(array $paths): array
    {
        $files = [];
        $max = self::BAND_UNRESOLVED;
        $hottest = null;
        $hottestScore = null;
        $allDependents = [];
        $allRoutes = [];

        foreach ($paths as $path) {
            $seeds = $this->graph->nodesForPath($path);

            if ($seeds === []) {
                $files[] = ['path' => $path, 'band' => self::BAND_UNRESOLVED, 'dependents' => 0, 'routes' => 0, 'tests' => 0];

                continue;
            }

            $dependents = $this->graph->dependents($seeds, $this->depth);
            $byType = ['route' => [], 'test' => [], 'code' => []];

            foreach (array_keys($dependents) as $id) {
                $type = $this->graph->node($id)['type'] ?? 'code';
                $byType[in_array($type, ['route', 'test'], true) ? $type : 'code'][] = $id;
            }

            $count = count($byType['code']) + count($byType['route']);
            $band = match (true) {
                $count >= 20 || count($byType['route']) >= 10 => self::BAND_HIGH,
                $count >= 5 || count($byType['route']) >= 3 => self::BAND_MEDIUM,
                default => self::BAND_LOW,
            };

            $files[] = [
                'path' => $path,
                'band' => $band,
                'dependents' => $count,
                'routes' => count($byType['route']),
                'tests' => count($byType['test']),
                'sample' => array_slice(array_merge($byType['route'], $byType['code']), 0, 8),
            ];

            $score = [self::RANK[$band], $count];

            if ($hottestScore === null || $score > $hottestScore) {
                [$hottestScore, $hottest, $max] = [$score, $path, $band];
            }

            $allDependents += array_flip(array_merge($byType['code'], $byType['route']));
            $allRoutes += array_flip($byType['route']);
        }

        return [
            'max' => $max,
            'hottest' => $hottest,
            'dependents' => count($allDependents),
            'routes_affected' => count($allRoutes),
            'files' => $files,
        ];
    }

    /**
     * Tests reachable from the changed paths within `$depth` reverse hops.
     *
     * @param  list<string>  $paths
     * @return array<string, list<string>> test path => changed paths that led to it
     */
    public function testsFor(array $paths, int $depth): array
    {
        $tests = [];

        foreach ($paths as $path) {
            $seeds = $this->graph->nodesForPath($path);

            if ($seeds === []) {
                continue;
            }

            foreach ($this->graph->dependents($seeds, $depth) as $id => $distance) {
                $node = $this->graph->node($id);

                if (($node['type'] ?? null) === 'test' && isset($node['path'])) {
                    $tests[$node['path']][] = $path;
                }
            }
        }

        ksort($tests);

        return array_map(static fn (array $from) => array_values(array_unique($from)), $tests);
    }

    /**
     * Files that reference the given path's node(s) directly — for a deleted class,
     * the code that would now fail to autoload it. Tests excluded.
     *
     * @return list<string>
     */
    public function directDependents(string $path): array
    {
        $paths = [];

        foreach ($this->graph->dependents($this->graph->nodesForPath($path), 1) as $id => $distance) {
            $node = $this->graph->node($id);

            if ($node !== null && $node['type'] !== 'test' && isset($node['path']) && $node['path'] !== $path) {
                $paths[] = $node['path'];
            }
        }

        return array_values(array_unique($paths));
    }
}
