<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Illuminate\View\Compilers\BladeCompiler;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;
use Throwable;

/**
 * Compiles each changed Blade view with the app's own compiler (so custom
 * directives count) and runs `php -l` on the compiled PHP. This catches
 * unclosed `@if` / `@foreach`, broken `@php` blocks and bad echo expressions
 * without rendering anything.
 */
final class BladeCheck implements Check
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
        private readonly ?BladeCompiler $compiler,
    ) {}

    public function run(Target $target): Result
    {
        if ($this->compiler === null) {
            return Result::skipped('Blade compiler is not bound in this application');
        }

        $violations = [];
        $files = (array) $target->params['files'];

        foreach ($files as $file) {
            try {
                $compiled = $this->compiler->compileString((string) file_get_contents($this->workspace->path($file)));
            } catch (Throwable $e) {
                $violations[] = new Violation('Blade compile error: '.$e->getMessage(), $file, null, 'blade.compile');

                continue;
            }

            $temp = $this->workspace->tempPath('blade-'.md5($file).'.php');
            file_put_contents($temp, $compiled);
            $outcome = $this->runner->run([$this->workspace->phpBinary, '-d', 'display_errors=1', '-l', $temp]);
            @unlink($temp);

            if ($outcome->aborted()) {
                return Result::aborted($outcome);
            }

            if (! $outcome->succeeded()) {
                $parsed = SyntaxCheck::parse($outcome->output, $file);
                // Line numbers refer to compiled PHP; Blade keeps them close but not exact.
                $violations[] = new Violation(
                    $parsed->message,
                    $file,
                    $parsed->line,
                    'blade.compiled_syntax',
                    tip: 'Line is approximate (compiled PHP). Look for an unclosed @if/@foreach/@php or a broken {{ }} expression.',
                );
            }
        }

        $count = count($files);

        return $violations === []
            ? Result::pass("{$count} view(s) compiled to valid PHP")
            : Result::fail(count($violations)." of {$count} view(s) failed to compile: ".$violations[0]->message, $violations);
    }
}
