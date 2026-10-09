<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

/** `php -l` on one file. */
final class SyntaxCheck implements Check
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    public function run(Target $target): Result
    {
        $file = (string) $target->params['file'];
        $outcome = $this->runner->run([$this->workspace->phpBinary, '-d', 'display_errors=1', '-l', $this->workspace->path($file)]);

        if ($outcome->aborted()) {
            return Result::aborted($outcome);
        }

        if ($outcome->succeeded()) {
            return Result::pass('No syntax errors');
        }

        return Result::fail($outcome->lastLine(), [self::parse($outcome->output, $file)], $outcome->exitCode);
    }

    /** "PHP Parse error:  syntax error, unexpected … in /abs/path.php on line 12" */
    public static function parse(string $output, string $file): Violation
    {
        if (preg_match('/(?:PHP )?(Parse error|Fatal error):\s+(.+?) in .+? on line (\d+)/', $output, $m)) {
            return new Violation($m[2], $file, (int) $m[3], 'php.syntax');
        }

        return new Violation(trim($output) ?: 'php -l failed', $file, null, 'php.syntax');
    }
}
