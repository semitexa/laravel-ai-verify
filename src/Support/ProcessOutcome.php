<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Support;

final readonly class ProcessOutcome
{
    /**
     * @param  list<string>  $argv
     */
    public function __construct(
        public array $argv,
        public int $exitCode,
        /** stdout and stderr interleaved, as a terminal shows them */
        public string $output,
        /** stdout alone — where tools print their machine-readable formats */
        public string $stdout,
        public bool $timedOut,
        public bool $truncated,
        public int $durationMs,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /** True when the tool was cut off and its verdict cannot be trusted either way. */
    public function aborted(): bool
    {
        return $this->timedOut || $this->truncated;
    }

    /** The last non-empty output line, whitespace-collapsed and capped — the "signal" an agent reads first. */
    public function lastLine(int $max = 240): string
    {
        $lines = preg_split('/\R/', trim($this->output)) ?: [];

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string) preg_replace('/\s+/', ' ', $lines[$i]));

            if ($line !== '') {
                return mb_strimwidth($line, 0, $max, '…');
            }
        }

        return '';
    }
}
