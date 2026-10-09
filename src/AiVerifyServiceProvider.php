<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify;

use Illuminate\Support\ServiceProvider;
use Semitexa\LaravelAiVerify\Console\GraphCommand;
use Semitexa\LaravelAiVerify\Console\InstallCommand;
use Semitexa\LaravelAiVerify\Console\MigrationProbeCommand;
use Semitexa\LaravelAiVerify\Console\VerifyCommand;

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
            MigrationProbeCommand::class,
        ]);
    }
}
