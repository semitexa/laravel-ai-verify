<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Verify\Checks\CommandCheck;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;

final class CommandCheckTest extends TestCase
{
    private function execute(string|array $run, array $files = [], bool $required = true, ?int $timeout = null): Result
    {
        $target = new Target('command:lint', 'command', 'test', $files, ['name' => 'lint', 'run' => $run, 'files' => $files, 'timeout' => $timeout], required: $required);

        return (new CommandCheck(new ProcessRunner(sys_get_temp_dir())))->run($target)->bindTo($target);
    }

    public function test_exit_zero_passes(): void
    {
        $result = $this->execute('exit 0');

        $this->assertSame(Result::PASS, $result->status);
        $this->assertSame(['exit 0'], $result->command);
    }

    public function test_files_expand_quoted_in_a_shell_command(): void
    {
        $result = $this->execute('printf "%s|" {files}; exit 3', ['app/A.php', 'app/Has Space.php']);

        $this->assertSame(Result::FAIL, $result->status);
        $this->assertSame(3, $result->exitCode);
        $this->assertSame('app/A.php|app/Has Space.php|', $result->violations[0]->message);
        $this->assertSame(['printf "%s|" app/A.php app/Has Space.php; exit 3'], $result->command);
    }

    public function test_an_argv_list_replaces_the_files_element(): void
    {
        $result = $this->execute([PHP_BINARY, '-r', 'echo implode(",", array_slice($argv, 1)); exit(1);', '{files}'], ['a.php', 'b.php']);

        $this->assertSame(Result::FAIL, $result->status);
        $this->assertSame('a.php,b.php', $result->violations[0]->message);
        $this->assertSame('command.lint', $result->violations[0]->rule);
    }

    public function test_only_the_tail_of_a_long_output_is_kept(): void
    {
        $result = $this->execute('seq 1 100; exit 1');

        $this->assertStringStartsWith("…\n81\n", $result->violations[0]->message);
        $this->assertStringEndsWith("\n100", $result->violations[0]->message);
    }

    public function test_a_missing_command_is_incomplete_only_when_required(): void
    {
        $this->assertSame(Result::INCOMPLETE, $this->execute('definitely-not-a-command-xyz')->effectiveStatus());
        $this->assertSame(Result::SKIPPED, $this->execute('definitely-not-a-command-xyz', required: false)->effectiveStatus());
    }

    public function test_its_own_timeout_applies(): void
    {
        $result = $this->execute('sleep 5', timeout: 1);

        $this->assertSame(Result::INCOMPLETE, $result->status);
        $this->assertStringContainsString('timed out', $result->signal);
    }
}
