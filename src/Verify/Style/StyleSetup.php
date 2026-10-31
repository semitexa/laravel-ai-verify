<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Style;

/**
 * A style tool as this project has it: the executable (null when the config
 * names a tool that is not installed), its config file and why it was picked.
 */
final class StyleSetup
{
    public function __construct(
        public readonly StyleTool $tool,
        public readonly ?string $bin,
        public readonly ?string $config,
        public readonly string $reason,
    ) {}

    public function id(): string
    {
        return $this->tool->id();
    }

    public function missing(): ?string
    {
        if ($this->bin !== null) {
            return null;
        }

        return ($this->config !== null ? "{$this->config} is the project's standard, but " : '')
            .'vendor/bin/'.$this->tool->binary().' is not installed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'tool' => $this->id(),
            'label' => $this->tool->label(),
            'config' => $this->config,
            'installed' => $this->bin !== null,
            'reason' => $this->reason,
            'problem' => $this->missing(),
        ], static fn ($v) => $v !== null);
    }
}
