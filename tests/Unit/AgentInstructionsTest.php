<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Agents\AgentInstructions;
use Semitexa\LaravelAiVerify\Support\Workspace;

final class AgentInstructionsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-agents-'.uniqid();
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function agents(): AgentInstructions
    {
        return new AgentInstructions(new Workspace($this->dir), dirname(__DIR__, 2).'/resources');
    }

    private function install(bool $all = false, bool $remove = false): array
    {
        $agents = $this->agents();
        $actions = $agents->plan($all, $remove);
        $agents->apply($actions);

        return array_column($actions, 'action', 'path');
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->dir.'/'.$path);
    }

    public function test_fresh_project_gets_agents_md_claude_md_and_the_claude_skill(): void
    {
        $this->assertSame([
            'AGENTS.md' => 'create',
            'CLAUDE.md' => 'create',
            '.claude/skills/ai-verify/SKILL.md' => 'create',
        ], $this->install());

        $this->assertStringStartsWith(AgentInstructions::START, $this->read('AGENTS.md'));
        $this->assertStringContainsString('php artisan ai:verify', $this->read('CLAUDE.md'));
        $this->assertStringContainsString('name: ai-verify', $this->read('.claude/skills/ai-verify/SKILL.md'));
        $this->assertTrue($this->agents()->installed());
    }

    public function test_existing_content_and_the_boost_block_are_preserved_and_rerun_is_idempotent(): void
    {
        $original = "# Project notes\n\n<laravel-boost-guidelines>\nboost stuff\n</laravel-boost-guidelines>\n";
        file_put_contents($this->dir.'/CLAUDE.md', $original);

        $this->assertSame('append', $this->install()['CLAUDE.md']);
        $after = $this->read('CLAUDE.md');
        $this->assertStringStartsWith(rtrim($original), $after);
        $this->assertStringContainsString("<laravel-boost-guidelines>\nboost stuff\n</laravel-boost-guidelines>", $after);

        $this->assertSame(['AGENTS.md' => 'unchanged', 'CLAUDE.md' => 'unchanged', '.claude/skills/ai-verify/SKILL.md' => 'unchanged'], $this->install());
        $this->assertSame($after, $this->read('CLAUDE.md'));
        $this->assertSame(1, substr_count($after, AgentInstructions::START));
    }

    public function test_a_stale_block_is_updated_in_place(): void
    {
        file_put_contents($this->dir.'/AGENTS.md', "intro\n\n".AgentInstructions::START."\nold text\n".AgentInstructions::END."\n\noutro\n");

        $this->assertSame('update', $this->install()['AGENTS.md']);
        $agents = $this->read('AGENTS.md');
        $this->assertStringNotContainsString('old text', $agents);
        $this->assertStringStartsWith("intro\n\n".AgentInstructions::START, $agents);
        $this->assertStringEndsWith(AgentInstructions::END."\n\noutro\n", $agents);
    }

    public function test_other_agents_are_covered_only_when_the_project_uses_them_or_with_all(): void
    {
        mkdir($this->dir.'/.cursor');
        file_put_contents($this->dir.'/GEMINI.md', '# Gemini');

        $actions = $this->install();
        $this->assertSame('append', $actions['GEMINI.md']);
        $this->assertSame('create', $actions['.cursor/rules/ai-verify.mdc']);
        $this->assertStringStartsWith("---\ndescription:", $this->read('.cursor/rules/ai-verify.mdc'));
        $this->assertArrayNotHasKey('.github/copilot-instructions.md', $actions);

        $all = $this->install(all: true);
        $this->assertSame('create', $all['.github/copilot-instructions.md']);
        $this->assertSame('create', $all['.agents/skills/ai-verify/SKILL.md']);
    }

    public function test_remove_takes_out_the_block_and_deletes_files_it_alone_created(): void
    {
        file_put_contents($this->dir.'/CLAUDE.md', "# Mine\n");
        $this->install();
        $this->install(remove: true);

        $this->assertSame("# Mine\n", $this->read('CLAUDE.md'));
        $this->assertFileDoesNotExist($this->dir.'/AGENTS.md');
        $this->assertDirectoryDoesNotExist($this->dir.'/.claude');
        $this->assertFalse($this->agents()->installed());
    }

    public function test_detects_boost_management(): void
    {
        $this->assertFalse($this->agents()->managedByBoost());

        file_put_contents($this->dir.'/boost.json', json_encode(['packages' => [AgentInstructions::PACKAGE]]));
        $this->assertTrue($this->agents()->managedByBoost());
    }
}
