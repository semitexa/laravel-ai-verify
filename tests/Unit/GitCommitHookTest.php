<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Agents\GitCommitHook;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;

final class GitCommitHookTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-githook-'.uniqid();
        mkdir($this->dir, 0o777, true);
        exec('cd '.escapeshellarg($this->dir).' && git init -q');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function hook(): GitCommitHook
    {
        return new GitCommitHook(new Workspace($this->dir), new ProcessRunner($this->dir));
    }

    public function test_creates_an_executable_hook_and_is_idempotent(): void
    {
        $hook = $this->hook();
        $this->assertSame('create', $hook->planInstall());

        $hook->install();
        $path = $this->dir.'/.git/hooks/prepare-commit-msg';
        $content = (string) file_get_contents($path);

        $this->assertStringStartsWith("#!/bin/sh\n", $content);
        $this->assertStringContainsString('ai:verify:receipt --trailer --message-file="$1"', $content);
        $this->assertTrue(is_executable($path));
        $this->assertSame('unchanged', $hook->planInstall());

        $hook->install();
        $this->assertSame($content, file_get_contents($path));
    }

    public function test_goes_before_an_existing_hooks_exit_and_removes_cleanly(): void
    {
        $path = $this->dir.'/.git/hooks/prepare-commit-msg';
        @mkdir(dirname($path), 0o777, true);
        $existing = "#!/usr/bin/env bash\necho 'ticket prefix' >> \"\$1\"\nexit 0\n";
        file_put_contents($path, $existing);

        $hook = $this->hook();
        $this->assertSame('append', $hook->planInstall());
        $hook->install();

        $content = (string) file_get_contents($path);
        $this->assertLessThan(strpos($content, 'exit 0'), strpos($content, GitCommitHook::START));
        $this->assertStringStartsWith('#!/usr/bin/env bash', $content);

        $hook->remove();
        $this->assertSame("#!/usr/bin/env bash\necho 'ticket prefix' >> \"\$1\"\nexit 0\n", str_replace("\n\n", "\n", (string) file_get_contents($path)));
    }

    public function test_respects_core_hooks_path_and_deletes_a_file_it_alone_created(): void
    {
        exec('cd '.escapeshellarg($this->dir).' && git config core.hooksPath .husky');

        $hook = $this->hook();
        $hook->install();
        $this->assertFileExists($this->dir.'/.husky/prepare-commit-msg');
        $this->assertTrue($hook->installed());

        $hook->remove();
        $this->assertFileDoesNotExist($this->dir.'/.husky/prepare-commit-msg');
    }
}
