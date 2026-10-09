<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Semitexa\LaravelAiVerify\Agents\AgentInstructions;
use Semitexa\LaravelAiVerify\Agents\ClaudeHook;
use Semitexa\LaravelAiVerify\Console\GraphCommand;
use Semitexa\LaravelAiVerify\Console\HookCommand;
use Semitexa\LaravelAiVerify\Console\InstallCommand;
use Semitexa\LaravelAiVerify\Console\MigrationProbeCommand;
use Semitexa\LaravelAiVerify\Console\VerifyCommand;
use Semitexa\LaravelAiVerify\Support\Workspace;

final class AiVerifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-verify.php', 'ai-verify');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/ai-verify.php' => config_path('ai-verify.php'),
        ], 'ai-verify-config');

        $this->commands([
            VerifyCommand::class,
            GraphCommand::class,
            InstallCommand::class,
            HookCommand::class,
            MigrationProbeCommand::class,
        ]);

        AboutCommand::add('Ai Verify', function (): array {
            $workspace = new Workspace($this->app->basePath());
            $agents = new AgentInstructions($workspace, __DIR__.'/../resources');

            return [
                'Version' => Toolkit::VERSION,
                'Agent instructions' => match (true) {
                    $agents->managedByBoost() => 'via Laravel Boost',
                    $agents->installed() => 'installed',
                    default => 'missing — php artisan ai:verify:install',
                },
                'Claude Code hook' => (new ClaudeHook($workspace))->installed() ? 'installed' : 'off',
                'Verify' => 'php artisan ai:verify',
            ];
        });
    }
}
