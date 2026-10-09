<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

enum Scope: string
{
    /** Syntax, Blade compile, JSON validity and the changed tests themselves. Seconds. */
    case Minimal = 'minimal';

    /** Adds style, static analysis, related tests and Laravel boot checks for what changed. */
    case Standard = 'standard';

    /** Adds every Laravel boot check, dependents from the graph and the whole test suite. */
    case Broad = 'broad';

    public static function fromInput(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Standard;
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Minimal => 0,
            self::Standard => 1,
            self::Broad => 2,
        };
    }
}
