<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Graph;

/**
 * Directed project graph. Every edge points from the dependent to what it
 * depends on (route → controller, test → class, model → table, table →
 * migration), so "what does a change to X affect" is a walk over incoming edges.
 */
final class Graph
{
    /** @var array<string, array{id: string, type: string, label: string, path?: string, meta?: array<string, mixed>}> */
    private array $nodes = [];

    /** @var array<string, array{from: string, to: string, type: string, meta?: array<string, mixed>}> */
    private array $edges = [];

    /** @var array<string, list<string>> node id => edge keys pointing at it */
    private array $incoming = [];

    /** @var array<string, list<string>> node id => edge keys leaving it */
    private array $outgoing = [];

    /** @var array<string, list<string>> relative path => node ids */
    private array $byPath = [];

    /** @param array<string, mixed> $meta */
    public function addNode(string $id, string $type, string $label, ?string $path = null, array $meta = []): void
    {
        if (isset($this->nodes[$id])) {
            if ($meta !== []) {
                $this->nodes[$id]['meta'] = array_merge($this->nodes[$id]['meta'] ?? [], $meta);
            }

            if ($path !== null && ! isset($this->nodes[$id]['path'])) {
                $this->nodes[$id]['path'] = $path;
                $this->byPath[$path][] = $id;
            }

            return;
        }

        $node = ['id' => $id, 'type' => $type, 'label' => $label];

        if ($path !== null) {
            $node['path'] = $path;
            $this->byPath[$path][] = $id;
        }

        if ($meta !== []) {
            $node['meta'] = $meta;
        }

        $this->nodes[$id] = $node;
    }

    /** @param array<string, mixed> $meta */
    public function addEdge(string $from, string $to, string $type, array $meta = []): void
    {
        if ($from === $to || ! isset($this->nodes[$from], $this->nodes[$to])) {
            return;
        }

        $key = $from.'|'.$type.'|'.$to;

        if (isset($this->edges[$key])) {
            return;
        }

        $edge = ['from' => $from, 'to' => $to, 'type' => $type];

        if ($meta !== []) {
            $edge['meta'] = $meta;
        }

        $this->edges[$key] = $edge;
        $this->incoming[$to][] = $key;
        $this->outgoing[$from][] = $key;
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** @return array{id: string, type: string, label: string, path?: string, meta?: array<string, mixed>}|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return list<string> */
    public function nodesForPath(string $path): array
    {
        return $this->byPath[ltrim($path, '/')] ?? [];
    }

    /** @return list<array{id: string, type: string, label: string, path?: string, meta?: array<string, mixed>}> */
    public function nodesOfType(string $type): array
    {
        return array_values(array_filter($this->nodes, static fn (array $node) => $node['type'] === $type));
    }

    /** @return list<array{from: string, to: string, type: string, meta?: array<string, mixed>}> */
    public function outgoing(string $id, ?string $type = null): array
    {
        return $this->collect($this->outgoing[$id] ?? [], $type);
    }

    /** @return list<array{from: string, to: string, type: string, meta?: array<string, mixed>}> */
    public function incoming(string $id, ?string $type = null): array
    {
        return $this->collect($this->incoming[$id] ?? [], $type);
    }

    /**
     * Breadth-first walk over incoming edges: everything that transitively depends on `$ids`.
     *
     * @param  list<string>  $ids
     * @return array<string, int> node id => distance (seeds excluded)
     */
    public function dependents(array $ids, int $maxDepth = 5): array
    {
        $seen = array_fill_keys($ids, 0);
        $queue = $ids;
        $distance = [];

        while ($queue !== []) {
            $next = [];

            foreach ($queue as $id) {
                $depth = $seen[$id];

                if ($depth >= $maxDepth) {
                    continue;
                }

                foreach ($this->incoming[$id] ?? [] as $key) {
                    $from = $this->edges[$key]['from'];

                    if (! isset($seen[$from])) {
                        $seen[$from] = $depth + 1;
                        $distance[$from] = $depth + 1;
                        $next[] = $from;
                    }
                }
            }

            $queue = $next;
        }

        return $distance;
    }

    /** Best-effort lookup from what a human or agent would type: FQCN, path, view name, route URI or name, table. */
    public function find(string $needle): ?string
    {
        $needle = trim($needle);
        $class = ltrim($needle, '\\');

        // Explicit ids (view:…, route:…, table:…) and exact classes first.
        foreach ([$needle, "class:{$class}"] as $candidate) {
            if (isset($this->nodes[$candidate])) {
                return $candidate;
            }
        }

        // Route names and URIs beat same-named views: the route chain is the richer answer.
        $uri = '/'.ltrim($needle, '/');

        foreach ($this->nodes as $id => $node) {
            if ($node['type'] === 'route' && (($node['meta']['name'] ?? null) === $needle || ($node['meta']['uri'] ?? null) === $uri)) {
                return $id;
            }
        }

        foreach (["view:{$needle}", "table:{$needle}", "test:{$needle}", "file:{$needle}"] as $candidate) {
            if (isset($this->nodes[$candidate])) {
                return $candidate;
            }
        }

        if ($ids = $this->nodesForPath($needle)) {
            return $ids[0];
        }

        foreach ($this->nodes as $id => $node) {
            if ($node['type'] === 'class' && str_ends_with($id, '\\'.$class)) {
                return $id;
            }
        }

        return null;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $stats = ['nodes' => count($this->nodes), 'edges' => count($this->edges)];

        foreach ($this->nodes as $node) {
            $key = 'nodes.'.$node['type'];
            $stats[$key] = ($stats[$key] ?? 0) + 1;
        }

        foreach ($this->edges as $edge) {
            $key = 'edges.'.$edge['type'];
            $stats[$key] = ($stats[$key] ?? 0) + 1;
        }

        ksort($stats);

        return $stats;
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['nodes' => array_values($this->nodes), 'edges' => array_values($this->edges)];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{from: string, to: string, type: string, meta?: array<string, mixed>}>
     */
    private function collect(array $keys, ?string $type): array
    {
        $edges = [];

        foreach ($keys as $key) {
            if ($type === null || $this->edges[$key]['type'] === $type) {
                $edges[] = $this->edges[$key];
            }
        }

        return $edges;
    }
}
