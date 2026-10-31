<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * `php artisan ai:verify` as an MCP tool: the agent asks for a verdict on its
 * change and gets the same JSON envelope the command prints.
 *
 * A failing verdict is a normal answer, not a tool error: the agent is meant
 * to read the failures and act on them. The run writes a receipt, so this
 * tool is not read-only.
 */
final class AiVerify extends Tool
{
    use RunsArtisanJson;

    protected string $description = 'Verify the current change in this Laravel app before saying it is done. Runs only the checks the change needs (PHP syntax, the tests that cover the changed code, Larastan and the project code style tool on changed lines, custom checks from the config, Laravel boot, Blade compile, migrations in pretend mode, test integrity) and returns one verdict: pass, fail or incomplete, with each failure located in a file and line. By default it verifies uncommitted work. Act on every failure, then call it again; report the change as done only after a pass. A pass writes a receipt that the git hook turns into an AI-Verify commit trailer.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'files' => $schema->array()
                ->items($schema->string())
                ->description('Verify only these changed paths, relative to the project root. Leave empty to verify all uncommitted work.'),
            'git_ref' => $schema->string()
                ->description('Verify everything that differs from this git ref instead, e.g. origin/main.'),
            'scope' => $schema->string()
                ->enum(['minimal', 'standard', 'broad'])
                ->description('How wide to test: minimal (fastest), standard (default) or broad.'),
            'timeout' => $schema->integer()
                ->description('Seconds Laravel Boost lets this call run. Defaults to the Boost tool timeout (180); raise it for a broad run on a large suite.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $parameters = [];

        $files = array_values(array_filter((array) $request->get('files', []), 'is_string'));
        if ($files !== []) {
            $parameters['--files'] = $files;
        }
        if (is_string($ref = $request->get('git_ref')) && $ref !== '') {
            $parameters['--git-ref'] = $ref;
        }
        if (is_string($scope = $request->get('scope')) && $scope !== '') {
            $parameters['--scope'] = $scope;
        }

        return $this->artisanJson('ai:verify', $parameters);
    }
}
