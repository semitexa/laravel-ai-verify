<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ErrorText;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

/**
 * Boots the application in a fresh process and exercises one subsystem.
 * A fresh process matters: this command's own process booted before the
 * agent's edit may have been saved, and a fatal must not take the verifier down.
 * Nothing is written to bootstrap/cache — cache paths are redirected to temp files.
 */
final class ArtisanCheck implements Check
{
    public const PROBES = [
        'routes' => [
            'args' => ['route:list', '--json'],
            'json' => true,
            'what' => 'route table loaded (routes, controllers, middleware aliases)',
        ],
        'config' => [
            'args' => ['config:cache'],
            'json' => false,
            'what' => 'configuration is cacheable (no closures, no env() outside config, all files return arrays)',
        ],
        'boot' => [
            'args' => ['about', '--json'],
            'json' => true,
            'what' => 'application boots (providers registered and booted)',
        ],
        'events' => [
            'args' => ['event:list', '--json'],
            'json' => true,
            'what' => 'event → listener map resolved',
        ],
    ];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    public function run(Target $target): Result
    {
        $probe = self::PROBES[$target->params['probe']] ?? null;

        if ($probe === null || ! $this->workspace->exists('artisan')) {
            return Result::skipped('artisan not found');
        }

        $configCache = $this->workspace->tempPath('config-cache.php');
        $outcome = $this->runner->run($this->workspace->artisan(...$probe['args'], ...['--no-interaction']), [
            'APP_CONFIG_CACHE' => $configCache,
            'APP_ROUTES_CACHE' => $this->workspace->tempPath('routes-cache.php'),
            'APP_EVENTS_CACHE' => $this->workspace->tempPath('events-cache.php'),
        ]);
        @unlink($configCache);

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        if ($outcome->succeeded() && (! $probe['json'] || $this->containsJson($outcome->stdout))) {
            return Result::pass(ucfirst($probe['what']));
        }

        $exception = ErrorText::consoleException($outcome->output);
        $message = $exception !== null ? $exception['class'].': '.ErrorText::message($exception['message'], $this->workspace) : $this->errorLine($outcome->output);
        [$file, $line] = ErrorText::location($outcome->output, $this->workspace);

        return Result::fail(
            "{$target->params['probe']}: {$message}",
            [new Violation($message, $file, $line, 'laravel.'.$target->params['probe'])],
            $outcome->exitCode,
        );
    }

    private function containsJson(string $output): bool
    {
        $start = strcspn($output, '[{');

        return $start < strlen($output) && json_decode(substr($output, $start)) !== null;
    }

    private function errorLine(string $output): string
    {
        foreach (preg_split('/\R/', ErrorText::stripAnsi($output)) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && preg_match('/(Exception|Error|error|ERROR|failed|Unable|not found|Undefined)/', $line)) {
                return mb_strimwidth((string) preg_replace('/\s+/', ' ', $line), 0, 400, '…');
            }
        }

        return mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $output)), 0, 400, '…') ?: 'failed without output';
    }
}
