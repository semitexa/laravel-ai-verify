<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use Semitexa\LaravelAiVerify\Agents\AgentInstructions;
use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Teaches the project's coding agents to verify their work:
 *
 *   php artisan ai:verify:install             # AGENTS.md, CLAUDE.md, + files of agents already in use, + skills
 *   php artisan ai:verify:install --all       # create files for every supported agent
 *   php artisan ai:verify:install --remove    # take it all out again
 */
final class InstallCommand extends Command
{
    protected $signature = 'ai:verify:install
        {--all : Create instruction files for every supported agent, not only the ones the project already uses}
        {--remove : Remove the instructions and skills}
        {--force : Write the instructions even when Laravel Boost already ships them}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Add ai:verify instructions to CLAUDE.md, AGENTS.md and other agent instruction files';

    public function handle(): int
    {
        $workspace = new Workspace($this->laravel->basePath());
        $instructions = new AgentInstructions($workspace, dirname(__DIR__, 2).'/resources');
        $remove = (bool) $this->option('remove');
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

        if ($dryRun) {
            $this->newLine();
            $this->line('  <fg=gray>Dry run — nothing written.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $instructions->apply($actions);

        $this->newLine();

        if (! $remove) {
            $this->line('  Agents will now run <options=bold>php artisan ai:verify</> before calling a change done.');
            $this->line('  <fg=gray>Commit these files so every teammate\'s agent gets them.</>');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
