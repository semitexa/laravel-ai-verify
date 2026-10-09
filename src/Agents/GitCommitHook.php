<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Agents;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * A `prepare-commit-msg` git hook that adds the AI-Verify trailer to a commit
 * when a passing ai:verify receipt vouches for exactly the tree being
 * committed. It works for every agent and every human that commits, and git
 * runs prepare-commit-msg even with --no-verify. Honours core.hooksPath
 * (Husky, lefthook), and lives in its own marked block next to any hook
 * code that is already there.
 */
final class GitCommitHook
{
    public const START = '# ai-verify:start';

    public const END = '# ai-verify:end';

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    /** Absolute path of the prepare-commit-msg hook, or null outside a git repository. */
    public function path(): ?string
    {
        $out = $this->runner->run(['git', 'rev-parse', '--git-path', 'hooks/prepare-commit-msg']);

        if (! $out->succeeded()) {
            return null;
        }

        $path = trim($out->stdout);

        return str_starts_with($path, '/') ? $path : $this->workspace->path($path);
    }

    public function installed(): bool
    {
        $path = $this->path();

        return $path !== null && is_file($path) && str_contains((string) file_get_contents($path), self::START);
    }

    public function planInstall(): string
    {
        $path = $this->path();

        return match (true) {
            $path === null => 'skipped (not a git repository)',
            ! is_file($path) => 'create',
            str_contains((string) file_get_contents($path), self::START) => (string) file_get_contents($path) === $this->withBlock((string) file_get_contents($path)) ? 'unchanged' : 'update',
            default => 'append',
        };
    }

    public function planRemove(): string
    {
        return $this->installed() ? 'remove' : 'unchanged';
    }

    public function install(): void
    {
        $path = $this->path();

        if ($path === null) {
            return;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, $this->withBlock(is_file($path) ? (string) file_get_contents($path) : ''));
        chmod($path, 0o755);
    }

    public function remove(): void
    {
        $path = $this->path();

        if ($path === null || ! is_file($path)) {
            return;
        }

        $rest = (string) preg_replace('/\n?'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'\n?/s', "\n", (string) file_get_contents($path));

        if (trim((string) preg_replace('/^#!.*$/m', '', $rest)) === '') {
            unlink($path);
        } else {
            file_put_contents($path, rtrim($rest)."\n");
        }
    }

    private function block(): string
    {
        $prefix = trim($this->runner->run(['git', 'rev-parse', '--show-prefix'])->stdout);
        $artisan = '"$(git rev-parse --show-toplevel)/'.$prefix.'artisan"';

        return self::START." — adds 'AI-Verify: pass …' when a passing ai:verify receipt matches what is committed\n"
            .'case "$2" in merge) ;; *) php '.$artisan.' ai:verify:receipt --trailer --message-file="$1" >/dev/null || true ;; esac'."\n"
            .self::END;
    }

    /** Insert right after the shebang: an `exit 0` at the end of an existing hook must not skip us. */
    private function withBlock(string $content): string
    {
        $block = $this->block();

        if (str_contains($content, self::START)) {
            return (string) preg_replace('/'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'/s', str_replace(['\\', '$'], ['\\\\', '\\$'], $block), $content, 1);
        }

        if (trim($content) === '') {
            return "#!/bin/sh\n\n{$block}\n";
        }

        if (str_starts_with($content, '#!')) {
            [$shebang, $rest] = array_pad(explode("\n", $content, 2), 2, '');

            return $shebang."\n\n".$block."\n".$rest;
        }

        return "#!/bin/sh\n\n{$block}\n".$content;
    }
}
