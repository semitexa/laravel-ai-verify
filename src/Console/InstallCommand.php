<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use Semitexa\LaravelAiVerify\Agents\AgentInstructions;
use Semitexa\LaravelAiVerify\Agents\ClaudeHook;
use Semitexa\LaravelAiVerify\Agents\GitCommitHook;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Checks\PhpstanCheck;

/**
 * Teaches the project's coding agents to verify their work:
 *
 *   php artisan ai:verify:install             # AGENTS.md, CLAUDE.md, + files of agents already in use, + skills
 *   php artisan ai:verify:install --hook      # also stop Claude Code from finishing while ai:verify fails
 *   php artisan ai:verify:install --git-hook  # also add the AI-Verify trailer to verified commits (any agent, any human)
 *   php artisan ai:verify:install --larastan  # also install Larastan, without which no static analysis runs
 *   php artisan ai:verify:install --all       # create files for every supported agent
 *   php artisan ai:verify:install --remove    # take it all out again
 */
final class InstallCommand extends Command
{
    protected $signature = 'ai:verify:install
        {--hook : Also add a Claude Code Stop hook that runs ai:verify before the agent may finish}
        {--git-hook : Also add a prepare-commit-msg git hook that adds the AI-Verify trailer to verified commits}
        {--larastan : Also install Larastan with composer; without it ai:verify runs no static analysis}
        {--no-larastan : Do not ask about Larastan}
        {--all : Create instruction files for every supported agent, not only the ones the project already uses}
        {--remove : Remove the instructions, skills and hooks}
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
        $gitHook = new GitCommitHook($workspace, new ProcessRunner($workspace->basePath));
        $withGitHook = (bool) $this->option('git-hook') || ($remove && $gitHook->installed());
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();

        if (! $remove && $instructions->managedByBoost() && ! $this->option('force')) {
            $this->line('  <fg=green>✓</> Laravel Boost already ships the ai:verify guideline and skill ("packages" in boost.json).');
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

        if ($withGitHook) {
            $this->line(sprintf(
                '  <fg=%s>%-9s</> %s <fg=gray>— git prepare-commit-msg hook: AI-Verify trailer on verified commits</>',
                $remove ? 'yellow' : 'green',
                $remove ? $gitHook->planRemove() : $gitHook->planInstall(),
                $workspace->relative((string) $gitHook->path()),
            ));
        }

        $withLarastan = ! $remove && $this->wantsLarastan($workspace, $dryRun);

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

        if ($withGitHook) {
            $remove ? $gitHook->remove() : $gitHook->install();
        }

        if ($withLarastan) {
            $this->installLarastan($workspace);
        }

        $this->newLine();

        if (! $remove) {
            $this->line('  Agents will now run <options=bold>php artisan ai:verify</> before calling a change done.');

            if (! $withHook && ! $hook->installed()) {
                $this->line('  <fg=gray>Using Claude Code? Add --hook to make it non-optional: Claude cannot finish while verify fails.</>');
            }

            if (! $withGitHook && ! $gitHook->installed()) {
                $this->line('  <fg=gray>Add --git-hook to stamp verified commits with an AI-Verify trailer that CI can check.</>');
            }

            $this->line('  <fg=gray>Commit these files so every teammate\'s agent gets them.</>');
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * A skipped analysis leaves the verdict at pass, and agents do not act on a
     * hint to add a dependency: 31 of 31 such hints went unused across 33 agent
     * sessions in our benchmark. So the question is asked here, of the person installing.
     */
    private function wantsLarastan(Workspace $workspace, bool $dryRun): bool
    {
        $enabled = (bool) ($this->laravel['config']->get('ai-verify.checks.phpstan') ?? true);

        if ($this->option('no-larastan') || ! PhpstanCheck::unavailable($workspace) || (! $enabled && ! $this->option('larastan'))) {
            return false;
        }

        if ($dryRun) {
            if ($this->option('larastan')) {
                $this->line(sprintf('  <fg=green>%-9s</> larastan/larastan <fg=gray>: composer require --dev</>', 'install'));
            }

            return false;
        }

        if ($this->option('larastan')) {
            return true;
        }

        if ($this->input->isInteractive()) {
            return $this->confirm('Larastan is not installed, so ai:verify would run no static analysis. Install it now?', true);
        }

        $this->line('  <fg=yellow>!</> Larastan is not installed, so ai:verify runs no static analysis. Add --larastan, or --no-larastan to leave it out.');

        return false;
    }

    private function installLarastan(Workspace $workspace): void
    {
        $outcome = (new ProcessRunner($workspace->basePath))->withTimeout(600)
            ->run(['composer', 'require', '--dev', 'larastan/larastan', '--no-interaction']);

        if ($outcome->succeeded()) {
            $this->line(sprintf('  <fg=green>%-9s</> larastan/larastan <fg=gray>: ai:verify now analyses changed code</>', 'installed'));

            return;
        }

        $this->line('  <fg=yellow>!</> composer could not install Larastan: '.$outcome->lastLine());
        $this->line('    <fg=gray>Run composer require --dev larastan/larastan yourself; until then ai:verify runs no static analysis.</>');
    }
}
