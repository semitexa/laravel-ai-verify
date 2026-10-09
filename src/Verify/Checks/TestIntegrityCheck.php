<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Checks;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\Violation;

/**
 * Guards against the classic agent shortcut: making the suite green by
 * deleting, skipping or hollowing out tests. Compares each changed test file
 * with its version at the base ref. A line `// verify:accept-test-change <reason>`
 * in the new file accepts the change on purpose.
 */
final class TestIntegrityCheck implements Check
{
    public const ACCEPT_MARKER = 'verify:accept-test-change';

    private const TEST = '/(?:public\s+function\s+test\w*\s*\(|#\[Test\]|@test\b|^\s*(?:it|test)\s*\(\s*[\'"])/m';

    private const ASSERTION = '/(?:->assert\w+\s*\(|\$this->assert\w+\s*\(|self::assert\w+\s*\(|static::assert\w+\s*\(|\bexpect\s*\(|->expect\w*\s*\(|->toBe\w*\s*\(|->to[A-Z]\w*\s*\()/';

    private const SKIP = '/(?:markTestSkipped|markTestIncomplete|->skip\s*\(|->todo\s*\(|#\[(?:Skip|Ignore)\b|->only\s*\()/';

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    public function run(Target $target): Result
    {
        $ref = (string) ($target->params['ref'] ?? 'HEAD');
        $violations = [];
        $accepted = [];
        $compared = 0;

        foreach ((array) $target->params['files'] as $file) {
            $before = $this->runner->run(['git', 'show', $ref.':'.$file]);

            if (! $before->succeeded()) {
                continue; // new file — nothing to weaken
            }

            $compared++;
            $after = $this->workspace->exists($file) ? (string) file_get_contents($this->workspace->path($file)) : null;
            $found = $this->compare($file, $before->output, $after);

            if ($found !== [] && $after !== null && str_contains($after, self::ACCEPT_MARKER)) {
                $accepted = [...$accepted, ...$found];

                continue;
            }

            $violations = [...$violations, ...$found];
        }

        if ($compared === 0) {
            return Result::pass('Only new test files — nothing to compare');
        }

        if ($violations !== []) {
            return Result::fail(count($violations).' weakening(s): '.$violations[0]->message, $violations);
        }

        return $accepted !== []
            ? new Result(Result::PASS, count($accepted).' test weakening(s) accepted via '.self::ACCEPT_MARKER, 0, $accepted, accepted: true)
            : Result::pass("{$compared} test file(s) kept their tests and assertions");
    }

    /** @return list<Violation> */
    private function compare(string $file, string $before, ?string $after): array
    {
        $tip = 'If intentional, add `// '.self::ACCEPT_MARKER.' <reason>` to the test file.';

        if ($after === null) {
            return [new Violation('Test file removed ('.$this->count(self::TEST, $before).' test(s))', $file, null, 'test_integrity.file_removed', tip: $tip)];
        }

        $found = [];
        $pairs = [
            [self::TEST, 'test_integrity.test_removed', 'Test count dropped'],
            [self::ASSERTION, 'test_integrity.assertions_removed', 'Assertion count dropped'],
        ];

        foreach ($pairs as [$pattern, $rule, $label]) {
            $was = $this->count($pattern, $before);
            $now = $this->count($pattern, $after);

            if ($now < $was) {
                $found[] = new Violation("{$label}: {$was} → {$now}", $file, null, $rule, tip: $tip);
            }
        }

        $skipsWas = $this->count(self::SKIP, $before);
        $skipsNow = $this->count(self::SKIP, $after);

        if ($skipsNow > $skipsWas) {
            $found[] = new Violation("Skip/todo/only markers added: {$skipsWas} → {$skipsNow}", $file, null, 'test_integrity.skip_added', tip: $tip);
        }

        return $found;
    }

    private function count(string $pattern, string $code): int
    {
        // Comments do not count as tests or assertions.
        $code = (string) preg_replace('#/\*.*?\*/|^\s*//.*$#ms', '', $code);

        return (int) preg_match_all($pattern, $code);
    }
}
