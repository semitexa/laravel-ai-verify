<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

final readonly class Violation
{
    public function __construct(
        public string $message,
        public ?string $path = null,
        public ?int $line = null,
        public ?string $rule = null,
        public string $severity = 'error',
        public ?string $tip = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'severity' => $this->severity,
            'rule' => $this->rule,
            'path' => $this->path,
            'line' => $this->line,
            'message' => $this->message,
            'tip' => $this->tip,
        ], static fn ($value) => $value !== null);
    }
}
