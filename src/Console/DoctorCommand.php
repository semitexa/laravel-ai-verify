<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use Semitexa\LaravelAiVerify\Agents\AgentInstructions;
use Semitexa\LaravelAiVerify\Agents\ClaudeHook;
use Semitexa\LaravelAiVerify\Agents\GitCommitHook;
use Semitexa\LaravelAiVerify\Toolkit;
use Semitexa\LaravelAiVerify\Verify\Checks\PhpstanCheck;
use Semitexa\LaravelAiVerify\Verify\Checks\TestCheck;
use Semitexa\LaravelAiVerify\Verify\Style\StyleSetup;

/**
 * What ai:verify found in this project and what it will do with it: the test
 * runner, the code style tool and why it was picked, static analysis, custom
 * commands, ignored paths, hooks. Every "skipped" a verdict could show is
 * explained here first.
 *
 *   php artisan ai:verify:doctor
 *   php artisan ai:verify:doctor --json
 */
final class DoctorCommand extends Command
{
    protected $signature = 'ai:verify:doctor {--json : Print one JSON document}';

    protected $description = 'Show which tests, code style tool, static analysis and custom checks ai:verify will use in this project, and why';

    public function handle(): int
    {
        $toolkit = Toolkit::fromApp($this->laravel);
        $report = $this->inspect($toolkit);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function inspect(Toolkit $toolkit): array
    {
        $w = $toolkit->workspace;
        $config = $toolkit->config;
        $style = $toolkit->style();
        $commands = $toolkit->commands();
        $runner = (new TestCheck($w, $toolkit->runner))->runnerName();
        $testsPath = (string) ($config['tests_path'] ?? 'tests');
        $git = is_dir($w->path('.git'));
        $problems = [...$style['warnings'], ...$commands['warnings']];

        if ($runner === null) {
            $problems[] = 'neither Pest nor PHPUnit is installed: no test can run';
        }

        $phpstanConfig = null;

        foreach (PhpstanCheck::CONFIGS as $name) {
            if ($w->exists($name)) {
                $phpstanConfig = $name;
                break;
            }
        }

        $analysis = match (true) {
            ! ($config['checks']['phpstan'] ?? true) => ['status' => 'off', 'detail' => 'checks.phpstan is false'],
            $w->bin('phpstan') === null => ['status' => 'skipped', 'detail' => 'PHPStan is not installed (composer require --dev larastan/larastan)'],
            $phpstanConfig !== null => ['status' => 'on', 'detail' => "{$phpstanConfig}, with its baseline if it has one"],
            $w->hasPackage('larastan/larastan') => ['status' => 'on', 'detail' => 'no phpstan.neon: Larastan at level '.(int) ($config['phpstan']['fallback_level'] ?? 5)],
            default => ['status' => 'skipped', 'detail' => 'PHPStan without Larastan or a phpstan.neon is too noisy on Laravel code'],
        };

        $agents = new AgentInstructions($w, dirname(__DIR__, 2).'/resources');

        return [
            'tool' => 'semitexa/laravel-ai-verify '.Toolkit::VERSION,
            'scope' => (string) ($config['scope'] ?? 'standard'),
            'git' => $git,
            'tests' => [
                'runner' => $runner,
                'path' => $testsPath,
                'path_exists' => $w->exists($testsPath),
                'args' => $toolkit->testArgs(),
            ],
            'style' => [
                'status' => $style['setups'] === [] ? 'skipped' : 'on',
                'tools' => array_map(static fn (StyleSetup $s) => $s->toArray(), $style['setups']),
                'detail' => $style['none'],
            ],
            'static_analysis' => $analysis,
            'migrations' => (bool) ($config['checks']['migrations'] ?? true),
            'test_integrity' => (bool) ($config['checks']['test_integrity'] ?? true),
            'commands' => $commands['commands'],
            'ignore' => $toolkit->ignore(),
            'timeouts' => ['default' => (int) ($config['timeout'] ?? 120)] + array_map('intval', (array) ($config['timeouts'] ?? [])),
            'agents' => [
                'instructions' => match (true) {
                    $agents->managedByBoost() => 'via Laravel Boost',
                    $agents->installed() => 'installed',
                    default => 'missing',
                },
                'claude_stop_hook' => (new ClaudeHook($w))->installed(),
                'git_commit_hook' => $git && (new GitCommitHook($w, $toolkit->runner))->installed(),
                'boost_mcp_tools' => class_exists(\Laravel\Mcp\Server\Tool::class) && (bool) ($config['mcp']['boost'] ?? true),
            ],
            'problems' => $problems,
        ];
    }

    /** @param array<string, mixed> $r */
    private function render(array $r): void
    {
        $ok = '<fg=green>✓</>';
        $off = '<fg=gray>-</>';
        $bad = '<fg=yellow>!</>';

        $this->newLine();
        $this->line("  <options=bold>{$r['tool']}</>  <fg=gray>default scope: {$r['scope']}</>");
        $this->newLine();

        $t = $r['tests'];
        $this->line('  '.($t['runner'] !== null ? $ok : $bad).' Tests        '.($t['runner'] ?? 'no runner installed')
            ." <fg=gray>in {$t['path']}".($t['path_exists'] ? '' : ' (missing)').($t['args'] !== [] ? ', args: '.implode(' ', $t['args']) : '').'</>');

        if ($r['style']['tools'] === []) {
            $this->line("  {$off} Code style   <fg=gray>{$r['style']['detail']}</>");
        }

        foreach ($r['style']['tools'] as $tool) {
            $this->line('  '.(($tool['installed'] ?? false) ? $ok : $bad).' Code style   '.$tool['label']
                .' <fg=gray>'.($tool['config'] ?? 'default preset').' — '.$tool['reason'].'</>');
        }

        $a = $r['static_analysis'];
        $this->line('  '.($a['status'] === 'on' ? $ok : $off).' Analysis     '.($a['status'] === 'on' ? 'PHPStan' : $a['status'])." <fg=gray>{$a['detail']}</>");
        $this->line("  {$ok} Laravel      <fg=gray>boot probes per changed kind (routes, config, events, providers)</>");
        $this->line('  '.($r['migrations'] ? $ok : $off).' Migrations   <fg=gray>'.($r['migrations'] ? 'up()/down() in pretend mode' : 'off').'</>');
        $this->line('  '.($r['test_integrity'] ? $ok : $off).' Test guard   <fg=gray>'.($r['test_integrity'] ? 'fails on deleted, skipped or weakened tests' : 'off').'</>');

        foreach ($r['commands'] as $c) {
            $run = is_array($c['run']) ? implode(' ', $c['run']) : $c['run'];
            $this->line("  {$ok} Command      {$c['name']} <fg=gray>{$run}  [".($c['when'] === [] ? 'any change' : 'when '.implode(', ', $c['when']))
                .", {$c['scope']}".($c['required'] ? '' : ', optional').']</>');
        }

        if ($r['ignore'] !== []) {
            $this->line("  {$off} Ignored      <fg=gray>".implode(', ', $r['ignore']).'</>');
        }

        $g = $r['agents'];
        $this->newLine();
        $this->line('  '.($g['instructions'] !== 'missing' ? $ok : $bad).' Agent rules  <fg=gray>'.$g['instructions'].'</>');
        $this->line('  '.($g['claude_stop_hook'] ? $ok : $off).' Claude hook  <fg=gray>'.($g['claude_stop_hook'] ? 'Claude cannot finish while verify fails' : 'off — php artisan ai:verify:install --hook').'</>');
        $this->line('  '.($g['git_commit_hook'] ? $ok : $off).' Git hook     <fg=gray>'.($g['git_commit_hook'] ? 'AI-Verify trailer and message vs diff warnings' : 'off — php artisan ai:verify:install --git-hook').'</>');
        $this->line('  '.($g['boost_mcp_tools'] ? $ok : $off).' Boost MCP    <fg=gray>'.($g['boost_mcp_tools'] ? 'ai-verify, ai-graph, ai-verify-receipt offered as tools' : 'off').'</>');

        if ($r['problems'] !== []) {
            $this->newLine();

            foreach ($r['problems'] as $problem) {
                $this->line("  {$bad} {$problem}");
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>Change any of this in config/ai-verify.php (php artisan vendor:publish --tag=ai-verify-config).</>');
        $this->newLine();
    }
}
