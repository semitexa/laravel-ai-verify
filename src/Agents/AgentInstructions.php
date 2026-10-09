<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Agents;

use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Puts the "verify your changes" instructions where coding agents read them:
 * AGENTS.md and CLAUDE.md always, and the other agents' instruction files when
 * the project already uses them. Each file gets one marked block, replaced in
 * place on re-run, so the command is idempotent and leaves everything else in
 * the file alone, including Laravel Boost's own `<laravel-boost-guidelines>` block.
 */
final class AgentInstructions
{
    public const START = '<!-- ai-verify:start -->';

    public const END = '<!-- ai-verify:end -->';

    public const PACKAGE = 'semitexa/laravel-ai-verify';

    /**
     * Instruction files: path => [always create?, reader].
     * Files that are not created by default are only updated when they already exist.
     */
    private const FILES = [
        'AGENTS.md' => [true, 'Codex, Cursor, Copilot, Amp, Jules, Zed and other AGENTS.md readers'],
        'CLAUDE.md' => [true, 'Claude Code'],
        'GEMINI.md' => [false, 'Gemini CLI'],
        '.github/copilot-instructions.md' => [false, 'GitHub Copilot'],
        '.junie/guidelines.md' => [false, 'JetBrains Junie'],
    ];

    /** Rule files that live in their own file, created when the agent's directory exists. */
    private const RULE_FILES = [
        '.cursor/rules/ai-verify.mdc' => ['.cursor', 'Cursor', "---\ndescription: Verify every change with php artisan ai:verify\nalwaysApply: true\n---\n\n"],
        '.windsurf/rules/ai-verify.md' => ['.windsurf', 'Windsurf', "---\ntrigger: always_on\n---\n\n"],
    ];

    /** Skill directories, written when the agent's directory exists (Claude's also when CLAUDE.md is written). */
    private const SKILL_DIRS = [
        '.claude/skills/ai-verify/SKILL.md' => '.claude',
        '.agents/skills/ai-verify/SKILL.md' => '.agents',
    ];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $resources,
    ) {}

    /**
     * @return list<array{path: string, action: string, for: string}>
     */
    public function plan(bool $all = false, bool $remove = false): array
    {
        $actions = [];
        $guideline = $this->guideline();

        foreach (self::FILES as $path => [$always, $for]) {
            $exists = $this->workspace->exists($path);

            if (! $exists && ! ($always || $all) || ($remove && ! $exists)) {
                continue;
            }

            $current = $exists ? (string) file_get_contents($this->workspace->path($path)) : '';
            $next = $remove ? $this->withoutBlock($current) : $this->withBlock($current, $guideline);
            $actions[] = ['path' => $path, 'action' => $this->verb($exists, $current, $next, $remove), 'for' => $for, 'content' => $next];
        }

        foreach (self::RULE_FILES as $path => [$dir, $for, $frontmatter]) {
            $exists = $this->workspace->exists($path);

            if ($remove ? ! $exists : ! ($all || $this->workspace->exists($dir))) {
                continue;
            }

            $current = $exists ? (string) file_get_contents($this->workspace->path($path)) : '';
            $next = $remove ? '' : $frontmatter.$guideline."\n";
            $actions[] = ['path' => $path, 'action' => $remove ? 'delete' : $this->verb($exists, $current, $next, false), 'for' => $for, 'content' => $next];
        }

        foreach (self::SKILL_DIRS as $path => $dir) {
            $exists = $this->workspace->exists($path);
            $wanted = $all || $this->workspace->exists($dir) || ($dir === '.claude' && $actions !== []);

            if ($remove ? ! $exists : ! $wanted) {
                continue;
            }

            $current = $exists ? (string) file_get_contents($this->workspace->path($path)) : '';
            $next = $remove ? '' : $this->skill();
            $actions[] = ['path' => $path, 'action' => $remove ? 'delete' : $this->verb($exists, $current, $next, false), 'for' => 'agent skill', 'content' => $next];
        }

        return $actions;
    }

    /** @param list<array{path: string, action: string, for: string, content?: string}> $actions */
    public function apply(array $actions): void
    {
        foreach ($actions as $action) {
            $absolute = $this->workspace->path($action['path']);

            if ($action['action'] === 'unchanged') {
                continue;
            }

            if ($action['action'] === 'delete' || ($action['action'] === 'remove' && trim((string) $action['content']) === '')) {
                @unlink($absolute);
                $this->pruneEmptyDirs(dirname($absolute));

                continue;
            }

            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0o755, true);
            }

            file_put_contents($absolute, (string) $action['content']);
        }
    }

    /** True when Laravel Boost already ships this package's guideline into the agent files. */
    public function managedByBoost(): bool
    {
        $config = json_decode((string) @file_get_contents($this->workspace->path('boost.json')), true);

        return is_array($config) && in_array(self::PACKAGE, (array) ($config['packages'] ?? []), true);
    }

    /** Whether any instruction file an agent reads already tells it about ai:verify. */
    public function installed(): bool
    {
        foreach ([...array_keys(self::FILES), ...array_keys(self::RULE_FILES)] as $path) {
            if ($this->workspace->exists($path) && str_contains((string) file_get_contents($this->workspace->path($path)), 'ai:verify')) {
                return true;
            }
        }

        return false;
    }

    public function guideline(): string
    {
        return trim((string) file_get_contents($this->resources.'/boost/guidelines/core.blade.php'));
    }

    private function skill(): string
    {
        return (string) file_get_contents($this->resources.'/boost/skills/ai-verify/SKILL.md');
    }

    private function withBlock(string $content, string $guideline): string
    {
        $block = self::START."\n".$guideline."\n".self::END;

        if (str_contains($content, self::START) && str_contains($content, self::END)) {
            return (string) preg_replace(
                '/'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'/s',
                str_replace(['\\', '$'], ['\\\\', '\\$'], $block),
                $content,
                1,
            );
        }

        return trim($content) === '' ? $block."\n" : rtrim($content)."\n\n".$block."\n";
    }

    private function withoutBlock(string $content): string
    {
        $stripped = (string) preg_replace('/\n*'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'\n?/s', "\n", $content);

        return trim($stripped) === '' ? '' : rtrim($stripped)."\n";
    }

    private function verb(bool $exists, string $current, string $next, bool $remove): string
    {
        return match (true) {
            $current === $next => 'unchanged',
            $remove => 'remove',
            ! $exists => 'create',
            str_contains($current, self::START) => 'update',
            default => 'append',
        };
    }

    private function pruneEmptyDirs(string $dir): void
    {
        $base = rtrim($this->workspace->basePath, '/');

        while ($dir !== $base && str_starts_with($dir, $base.'/') && is_dir($dir) && count(scandir($dir) ?: []) === 2) {
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
