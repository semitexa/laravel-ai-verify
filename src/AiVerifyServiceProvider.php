<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Semitexa\LaravelAiVerify\Agents\AgentInstructions;
use Semitexa\LaravelAiVerify\Agents\ClaudeHook;
use Semitexa\LaravelAiVerify\Console\DoctorCommand;
use Semitexa\LaravelAiVerify\Console\GraphCommand;
use Semitexa\LaravelAiVerify\Console\HookCommand;
use Semitexa\LaravelAiVerify\Console\InstallCommand;
use Semitexa\LaravelAiVerify\Console\MigrationProbeCommand;
use Semitexa\LaravelAiVerify\Console\ReceiptCommand;
use Semitexa\LaravelAiVerify\Console\VerifyCommand;
use Semitexa\LaravelAiVerify\Mcp\Tools\AiGraph;
use Semitexa\LaravelAiVerify\Mcp\Tools\AiVerify;
use Semitexa\LaravelAiVerify\Mcp\Tools\AiVerifyReceipt;
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

        $this->registerBoostTools();

        $this->commands([
            VerifyCommand::class,
            GraphCommand::class,
            InstallCommand::class,
            HookCommand::class,
            ReceiptCommand::class,
            MigrationProbeCommand::class,
            DoctorCommand::class,
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
                'Code style' => implode(', ', array_map(static fn ($s) => $s->id(), Toolkit::fromApp($this->app)->style()['setups'])) ?: 'none',
                'Verify' => 'php artisan ai:verify',
            ];
        });
    }

    /**
     * Adds ai-verify, ai-graph and ai-verify-receipt to Laravel Boost's MCP
     * server, the same way Boost takes extra tools from an app's config.
     * Without laravel/mcp (Boost's MCP layer) the tool classes could not even
     * load, so nothing is registered.
     */
    private function registerBoostTools(): void
    {
        if (! class_exists(\Laravel\Mcp\Server\Tool::class) || ! config('ai-verify.mcp.boost', true)) {
            return;
        }

        $include = (array) config('boost.mcp.tools.include', []);

        foreach ([AiVerify::class, AiGraph::class, AiVerifyReceipt::class] as $tool) {
            if (! in_array($tool, $include, true)) {
                $include[] = $tool;
            }
        }

        config()->set('boost.mcp.tools.include', $include);
    }
}
