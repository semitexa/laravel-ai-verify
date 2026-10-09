<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

/**
 * One planned check. `triggeredBy` lists the changed files that caused it;
 * `readsContent` says whether running it actually reads those files — only
 * such checks count towards coverage.
 */
final class Target
{
    /** @var list<string> */
    public array $triggeredBy = [];

    /**
     * @param  list<string>  $triggeredBy
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $id,
        public readonly string $check,
        public readonly string $reason,
        array $triggeredBy = [],
        public array $params = [],
        public readonly bool $required = true,
        public readonly bool $readsContent = true,
    ) {
        $this->addTriggers($triggeredBy);
    }

    /** @param list<string> $paths */
    public function addTriggers(array $paths): void
    {
        foreach ($paths as $path) {
            if (! in_array($path, $this->triggeredBy, true)) {
                $this->triggeredBy[] = $path;
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'check' => $this->check,
            'reason' => $this->reason,
            'triggered_by' => $this->triggeredBy,
            'required' => $this->required,
        ];
    }
}
