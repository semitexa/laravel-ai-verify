<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use Semitexa\LaravelAiVerify\Agents\AgentInstructions;
use Semitexa\LaravelAiVerify\Agents\ClaudeHook;
use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Teaches the project's coding agents to verify their work:
 *
 *   php artisan ai:verify:install             # AGENTS.md, CLAUDE.md, + files of agents already in use, + skills
 *   php artisan ai:verify:install --hook      # also stop Claude Code from finishing while ai:verify fails
 *   php artisan ai:verify:install --all       # create files for every supported agent
 *   php artisan ai:verify:install --remove    # take it all out again
 */
final class InstallCommand extends Command
{
    protected $signature = 'ai:verify:install
        {--hook : Also add a Claude Code Stop hook that runs ai:verify before the agent may finish}
        {--all : Create instruction files for every supported agent, not only the ones the project already uses}
        {--remove : Remove the instructions, skills and hook}
        {--force : Write the instructions even when Laravel Boost already ships them}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Add ai:verify instructions to CLAUDE.md, AGENTS.md and other agent instruction files';

    public function handle(): int
    {
        $workspace = new Workspace($this->laravel->basePath());
        $instructions = new AgentInstructions($workspace, dirname(__DIR__, 2).'/resources');
        $hook = new ClaudeHook($workspace);
        $remove = (bool) $this->option('remove');
        $withHook = (bool) $this->option('hook') || ($remove && $hook->installed());
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();

        if (! $remove && $instructions->managedByBoost() && ! $this->option('force')) {
            $this->line('  <fg=green>✓</> Laravel Boost already ships the ai:verify guideline and skill (boost.json → packages).');
            $this->line('    <fg=gray>Re-run php artisan boost:install to refresh them; use --force to write our own block as well.</>');
            $actions = [];
        } else {
            $actions = $instructions->plan((bool) $this->option('all'), $remove);
        }

        foreach ($actions as $action) {
            $color = $action['action'] === 'unchanged' ? 'gray' : ($remove ? 'yellow' : 'green');
            $this->line(sprintf('  <fg=%s>%-9s</> %s <fg=gray>— %s</>', $color, $action['action'], $action['path'], $action['for']));
        }

        if ($withHook) {
            $this->line(sprintf(
                '  <fg=%s>%-9s</> %s <fg=gray>— Claude Code Stop hook: %s</>',
                $remove ? 'yellow' : 'green',
                $remove ? $hook->planRemove() : $hook->planInstall(),
                ClaudeHook::SETTINGS,
                ClaudeHook::COMMAND,
            ));
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('  <fg=gray>Dry run — nothing written.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $instructions->apply($actions);

        if ($withHook) {
            $remove ? $hook->remove() : $hook->install();
        }

        $this->newLine();

        if (! $remove) {
            $this->line('  Agents will now run <options=bold>php artisan ai:verify</> before calling a change done.');

            if (! $withHook) {
                $this->line('  <fg=gray>Using Claude Code? Add --hook to make it non-optional: Claude cannot finish while verify fails.</>');
            }

            $this->line('  <fg=gray>Commit these files so every teammate\'s agent gets them.</>');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
