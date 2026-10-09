<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

use Semitexa\LaravelAiVerify\Support\ProcessOutcome;

final class Result
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const SKIPPED = 'skipped';

    /** The check ran but could not reach a trustworthy verdict (timeout, crash, tool missing when required). */
    public const INCOMPLETE = 'incomplete';

    public string $id;

    public string $check;

    public bool $required = true;

    /** @var list<string> */
    public array $triggeredBy = [];

    public int $durationMs = 0;

    /** @var list<string>|null */
    public ?array $command = null;

    /**
     * @param  list<Violation>  $violations
     */
    public function __construct(
        public readonly string $status,
        public readonly string $signal,
        public readonly ?int $exitCode = null,
        public readonly array $violations = [],
        public readonly bool $accepted = false,
    ) {}

    /** @param list<Violation> $violations */
    public static function pass(string $signal, array $violations = []): self
    {
        return new self(self::PASS, $signal, 0, $violations);
    }

    /** @param list<Violation> $violations */
    public static function fail(string $signal, array $violations = [], ?int $exitCode = 1): self
    {
        return new self(self::FAIL, $signal, $exitCode, $violations);
    }

    public static function skipped(string $signal): self
    {
        return new self(self::SKIPPED, $signal);
    }

    public static function incomplete(string $signal, ?int $exitCode = null): self
    {
        return new self(self::INCOMPLETE, $signal, $exitCode);
    }

    /** Uniform handling for "the tool was killed": never a pass, never a fail. */
    public static function aborted(ProcessOutcome $outcome): self
    {
        $why = $outcome->timedOut ? 'timed out after '.round($outcome->durationMs / 1000).'s' : 'output exceeded the cap';

        return self::incomplete($why.' — '.$outcome->lastLine(), $outcome->exitCode);
    }

    public function bindTo(Target $target): self
    {
        $this->id = $target->id;
        $this->check = $target->check;
        $this->required = $target->required;
        $this->triggeredBy = $target->triggeredBy;

        return $this;
    }

    /** Required checks that were skipped (tool missing) leave the change unverified. */
    public function effectiveStatus(): string
    {
        return $this->status === self::SKIPPED && $this->required ? self::INCOMPLETE : $this->status;
    }

    public function reached(): bool
    {
        return $this->status === self::PASS || $this->status === self::FAIL;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'check' => $this->check,
            'status' => $this->effectiveStatus(),
            'exit_code' => $this->exitCode,
            'signal' => $this->signal,
            'required' => $this->required,
            'accepted' => $this->accepted ?: null,
            'duration_ms' => $this->durationMs,
            'violations' => count($this->violations) ?: null,
            'command' => $this->command !== null ? implode(' ', $this->command) : null,
        ], static fn ($value) => $value !== null);
    }
}
