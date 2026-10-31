<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Support;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Bounded subprocess runner: every check gets a wall-clock timeout and an
 * output cap, so a hung test or a runaway tool can never stall the agent.
 * stderr is merged into stdout, exactly like a terminal would show it.
 */
final class ProcessRunner
{
    public const EXIT_TIMEOUT = 124;

    public const EXIT_OUTPUT_CAP = 125;

    /**
     * Agent-detecting wrappers (laravel/pao) rewrite tool output when they see
     * an agent; we parse the tools' own machine formats instead, so switch them off.
     */
    private const ENV = [
        'PAO_DISABLE' => '1',
        'NO_COLOR' => '1',
        'COLLISION_PRINTER' => false,
    ];

    /**
     * @param  list<string>  $scrub  variables to remove from children's environment (see Workspace::dotenvLeaks)
     */
    public function __construct(
        private readonly string $cwd,
        private readonly int $timeoutSeconds = 120,
        private readonly int $outputCapBytes = 4_194_304,
        private readonly array $scrub = [],
    ) {}

    /** The same runner with another wall-clock limit (per-check `timeouts` in the config). */
    public function withTimeout(int $seconds): self
    {
        return new self($this->cwd, $seconds, $this->outputCapBytes, $this->scrub);
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, string|false>  $env
     */
    public function run(array $argv, array $env = []): ProcessOutcome
    {
        return $this->execute(new Process($argv, $this->cwd, $this->env($env), null, $this->timeoutSeconds), $argv);
    }

    /**
     * A command line for the platform's shell, for user-written commands from
     * the config (`composer lint`, `npm run lint -- {files}`).
     *
     * @param  array<string, string|false>  $env
     */
    public function runShell(string $command, array $env = []): ProcessOutcome
    {
        return $this->execute(Process::fromShellCommandline($command, $this->cwd, $this->env($env), null, $this->timeoutSeconds), [$command]);
    }

    /**
     * @param  array<string, string|false>  $env
     * @return array<string, string|false>
     */
    private function env(array $env): array
    {
        return array_merge(array_fill_keys($this->scrub, false), self::ENV, $env);
    }

    /** @param list<string> $argv */
    private function execute(Process $process, array $argv): ProcessOutcome
    {

        $output = '';
        $stdout = '';
        $truncated = false;
        $started = hrtime(true);

        try {
            $process->start();

            foreach ($process as $type => $chunk) {
                $output .= $chunk;

                if ($type === Process::OUT) {
                    $stdout .= $chunk;
                }

                if (strlen($output) > $this->outputCapBytes) {
                    $truncated = true;
                    $process->stop(0);
                    break;
                }
            }

            $process->wait();
            $exitCode = $truncated ? self::EXIT_OUTPUT_CAP : ($process->getExitCode() ?? 1);
            $timedOut = false;
        } catch (ProcessTimedOutException) {
            $process->stop(0);
            $exitCode = self::EXIT_TIMEOUT;
            $timedOut = true;
        }

        return new ProcessOutcome(
            argv: $argv,
            exitCode: $exitCode,
            output: $output,
            stdout: $stdout,
            timedOut: $timedOut,
            truncated: $truncated,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
        );
    }
}
