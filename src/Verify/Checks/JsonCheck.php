<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use JsonException;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

final class JsonCheck implements Check
{
    public function __construct(private readonly Workspace $workspace) {}

    public function run(Target $target): Result
    {
        $violations = [];
        $files = (array) $target->params['files'];

        foreach ($files as $file) {
            try {
                json_decode((string) file_get_contents($this->workspace->path($file)), flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $violations[] = new Violation('Invalid JSON: '.$e->getMessage(), $file, null, 'json.syntax');
            }
        }

        return $violations === []
            ? Result::pass(count($files).' JSON file(s) valid')
            : Result::fail($violations[0]->path.': '.$violations[0]->message, $violations);
    }
}
