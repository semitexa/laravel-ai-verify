<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

final class Plan
{
    /** @var list<string> */
    public array $expansions = [];

    /** @var array<string, Target> */
    private array $targets = [];

    public function __construct(
        public readonly Scope $requestedScope,
        public Scope $effectiveScope,
    ) {}

    /**
     * Adds a target, or merges triggers into the existing target with the same id.
     */
    public function add(Target $target): Target
    {
        if (isset($this->targets[$target->id])) {
            $this->targets[$target->id]->addTriggers($target->triggeredBy);

            return $this->targets[$target->id];
        }

        return $this->targets[$target->id] = $target;
    }

    public function get(string $id): ?Target
    {
        return $this->targets[$id] ?? null;
    }

    public function remove(string $id): void
    {
        unset($this->targets[$id]);
    }

    /** @return list<Target> in execution order: cheap and decisive first, slow last */
    public function targets(): array
    {
        $order = ['syntax' => 0, 'json' => 1, 'blade' => 2, 'test_integrity' => 3, 'composer' => 4, 'pint' => 5,
            'artisan' => 6, 'migration' => 7, 'phpstan' => 8, 'tests' => 9];

        $targets = array_values($this->targets);
        usort($targets, static fn (Target $a, Target $b) => ($order[$a->check] ?? 99) <=> ($order[$b->check] ?? 99));

        return $targets;
    }
}
