<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Agents;

use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Manages one Claude Code Stop hook entry in the project's shared
 * `.claude/settings.json`, merged with whatever settings and hooks are
 * already there. Instructions can be ignored; a Stop hook cannot: Claude
 * is sent back to work while `ai:verify` reports a failure.
 */
final class ClaudeHook
{
    public const SETTINGS = '.claude/settings.json';

    public const COMMAND = 'php "${CLAUDE_PROJECT_DIR}/artisan" ai:verify:hook';

    /** Seconds Claude Code waits for the hook; a standard-scope verify is well inside this. */
    public const TIMEOUT = 600;

    public function __construct(private readonly Workspace $workspace) {}

    public function installed(): bool
    {
        return $this->find($this->settings()) !== null;
    }

    public function planInstall(): string
    {
        $settings = $this->settings();
        $at = $this->find($settings);

        if ($at === null) {
            return $this->workspace->exists(self::SETTINGS) ? 'update' : 'create';
        }

        return $settings['hooks']['Stop'][$at[0]]['hooks'][$at[1]] === $this->entry() ? 'unchanged' : 'update';
    }

    public function planRemove(): string
    {
        return $this->installed() ? 'remove' : 'unchanged';
    }

    public function install(): void
    {
        $settings = $this->settings();
        $at = $this->find($settings);

        if ($at !== null) {
            $settings['hooks']['Stop'][$at[0]]['hooks'][$at[1]] = $this->entry();
        } else {
            $settings['hooks']['Stop'][] = ['hooks' => [$this->entry()]];
        }

        $this->write($settings);
    }

    public function remove(): void
    {
        $settings = $this->settings();

        while (($at = $this->find($settings)) !== null) {
            array_splice($settings['hooks']['Stop'][$at[0]]['hooks'], $at[1], 1);

            if ($settings['hooks']['Stop'][$at[0]]['hooks'] === []) {
                array_splice($settings['hooks']['Stop'], $at[0], 1);
            }
        }

        if (($settings['hooks']['Stop'] ?? null) === []) {
            unset($settings['hooks']['Stop']);
        }

        if (($settings['hooks'] ?? null) === []) {
            unset($settings['hooks']);
        }

        if ($settings === []) {
            @unlink($this->workspace->path(self::SETTINGS));

            return;
        }

        $this->write($settings);
    }

    /** @return array{type: string, command: string, timeout: int} */
    private function entry(): array
    {
        return ['type' => 'command', 'command' => self::COMMAND, 'timeout' => self::TIMEOUT];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: int, 1: int}|null [matcher group index, hook index]
     */
    private function find(array $settings): ?array
    {
        foreach ((array) ($settings['hooks']['Stop'] ?? []) as $g => $group) {
            foreach ((array) ($group['hooks'] ?? []) as $h => $hook) {
                if (str_contains((string) ($hook['command'] ?? ''), 'ai:verify:hook')) {
                    return [$g, $h];
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $json = json_decode((string) @file_get_contents($this->workspace->path(self::SETTINGS)), true);

        return is_array($json) ? $json : [];
    }

    /** @param array<string, mixed> $settings */
    private function write(array $settings): void
    {
        $path = $this->workspace->path(self::SETTINGS);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }
}
