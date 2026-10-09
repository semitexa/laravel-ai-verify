<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ErrorText;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\RouteReferences;
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
            return $target->params['probe'] === 'routes'
                ? $this->checkRouteReferences($target, $outcome->stdout, ucfirst($probe['what']))
                : Result::pass(ucfirst($probe['what']));
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

    /**
     * The route table loads — now make sure nothing still points at a route
     * name that is gone. After a routes/*.php change the whole project is
     * scanned (a rename breaks callers anywhere); otherwise only changed files.
     */
    private function checkRouteReferences(Target $target, string $stdout, string $passSignal): Result
    {
        $start = strcspn($stdout, '[');
        $routes = json_decode(substr($stdout, $start), true);

        if (! is_array($routes)) {
            return Result::pass($passSignal);
        }

        $names = array_values(array_filter(array_map(static fn ($route) => is_array($route) ? ($route['name'] ?? null) : null, $routes)));
        $refs = $target->params['refs'] ?? 'all';
        $violations = (new RouteReferences($this->workspace))->missing($names, $refs === 'all' ? null : (array) $refs);

        if ($violations === []) {
            return Result::pass($passSignal.'; every route name referenced '.($refs === 'all' ? 'in the project' : 'in changed files').' exists');
        }

        $first = $violations[0];

        return Result::fail(
            count($violations).' reference(s) to undefined route names; first: '.$first->path.':'.$first->line.' '.$first->message,
            $violations,
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
