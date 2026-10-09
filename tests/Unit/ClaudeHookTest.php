<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Agents\ClaudeHook;
use Semitexa\LaravelAiVerify\Support\Workspace;

final class ClaudeHookTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-hook-'.uniqid();
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function settings(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/.claude/settings.json'), true);
    }

    public function test_installs_into_a_fresh_project(): void
    {
        $hook = new ClaudeHook(new Workspace($this->dir));
        $this->assertSame('create', $hook->planInstall());

        $hook->install();

        $this->assertSame(['hooks' => ['Stop' => [['hooks' => [[
            'type' => 'command',
            'command' => 'php "${CLAUDE_PROJECT_DIR}/artisan" ai:verify:hook',
            'timeout' => 600,
        ]]]]]], $this->settings());
        $this->assertTrue($hook->installed());
        $this->assertSame('unchanged', $hook->planInstall());
    }

    public function test_merges_with_existing_settings_and_hooks_and_is_idempotent(): void
    {
        mkdir($this->dir.'/.claude');
        $existing = [
            'permissions' => ['allow' => ['Bash(php artisan test:*)']],
            'hooks' => [
                'Stop' => [['hooks' => [['type' => 'command', 'command' => 'notify-send done']]]],
                'PostToolUse' => [['matcher' => 'Edit', 'hooks' => [['type' => 'command', 'command' => 'pint']]]],
            ],
        ];
        file_put_contents($this->dir.'/.claude/settings.json', json_encode($existing));

        $hook = new ClaudeHook(new Workspace($this->dir));
        $hook->install();
        $hook->install();

        $settings = $this->settings();
        $this->assertSame($existing['permissions'], $settings['permissions']);
        $this->assertSame($existing['hooks']['PostToolUse'], $settings['hooks']['PostToolUse']);
        $this->assertCount(2, $settings['hooks']['Stop']);
        $this->assertSame('notify-send done', $settings['hooks']['Stop'][0]['hooks'][0]['command']);

        $hook->remove();
        $this->assertSame($existing, $this->settings());
    }

    public function test_remove_deletes_a_settings_file_that_only_held_the_hook(): void
    {
        $hook = new ClaudeHook(new Workspace($this->dir));
        $hook->install();
        $hook->remove();

        $this->assertFileDoesNotExist($this->dir.'/.claude/settings.json');
        $this->assertSame('unchanged', $hook->planRemove());
    }
}
