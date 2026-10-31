<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Style;

/**
 * One rule a style tool would apply to a file. Fixer-based tools (Pint,
 * PHP-CS-Fixer) name the rule only; sniff-based ones (PHPCS) add a line and a
 * message. A `warning` finding is reported but never fails the check.
 */
final class Finding
{
    public function __construct(
        public readonly string $rule,
        public readonly ?int $line = null,
        public readonly ?string $message = null,
        public readonly bool $warning = false,
    ) {}
}
