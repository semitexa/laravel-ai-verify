<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Checks\StyleCheck;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Style\PhpcsTool;
use Semitexa\LaravelAiVerify\Verify\Style\StyleSetup;
use Semitexa\LaravelAiVerify\Verify\Target;

/**
 * StyleCheck against a stand-in `phpcs` that reports every line containing
 * BAD as an error and MEH as a warning, in a real git repository, so the
 * base-ref comparison runs end to end.
 */
final class StyleCheckTest extends TestCase
{
    private const FAKE_PHPCS = <<<'PHP'
        <?php
        $files = [];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg[0] !== '-') {
                $files[realpath($arg) ?: $arg] = [];
            }
        }
        foreach (array_keys($files) as $file) {
            foreach (file($file) as $i => $line) {
                if (str_contains($line, 'BAD')) {
                    $files[$file][] = ['message' => 'bad line', 'source' => 'Fake.Bad', 'type' => 'ERROR', 'line' => $i + 1];
                }
                if (str_contains($line, 'MEH')) {
                    $files[$file][] = ['message' => 'meh line', 'source' => 'Fake.Meh', 'type' => 'WARNING', 'line' => $i + 1];
                }
            }
        }
        echo json_encode(['totals' => [], 'files' => array_map(fn ($m) => ['messages' => $m], $files)]);
        exit(array_filter($files) === [] ? 0 : 2);
        PHP;

    private string $dir;

    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-stylecheck-'.uniqid();
        mkdir($this->dir.'/vendor/bin', 0o777, true);
        mkdir($this->dir.'/app');
        file_put_contents($this->dir.'/vendor/bin/phpcs', self::FAKE_PHPCS);
        $this->workspace = new Workspace($this->dir);

        $this->git('init -q');
        $this->write('app/Old.php', "<?php\n// BAD legacy\n");
        $this->git('add -A');
        $this->git('-c user.email=t@t -c user.name=t commit -q -m base');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function git(string $args): void
    {
        exec('git -C '.escapeshellarg($this->dir).' '.$args);
    }

    private function write(string $path, string $content): void
    {
        file_put_contents($this->dir.'/'.$path, $content);
    }

    /** @param list<string> $files */
    private function check(array $files): Result
    {
        $setup = new StyleSetup(new PhpcsTool, $this->workspace->bin('phpcs'), null, 'test');
        $check = new StyleCheck($this->workspace, new ProcessRunner($this->dir), ['phpcs' => $setup]);

        return $check->run(new Target('phpcs', 'style', 'test', $files, ['tool' => 'phpcs', 'files' => $files, 'ref' => 'HEAD']));
    }

    public function test_untouched_legacy_style_does_not_fail_the_change(): void
    {
        $this->write('app/Old.php', "<?php\n// BAD legacy\necho 1;\n");
        $result = $this->check(['app/Old.php']);

        $this->assertSame(Result::PASS, $result->status);
        $this->assertTrue($result->accepted);
        $this->assertSame('warning', $result->violations[0]->severity);
        $this->assertStringContainsString('Already present at HEAD', (string) $result->violations[0]->tip);
    }

    public function test_one_more_broken_rule_in_a_legacy_file_fails(): void
    {
        $this->write('app/Old.php', "<?php\n// BAD legacy\n// BAD new\n");
        $result = $this->check(['app/Old.php']);

        $this->assertSame(Result::FAIL, $result->status);
        $this->assertStringContainsString('vendor/bin/phpcbf app/Old.php', $result->signal);
        $this->assertSame('phpcs.Fake.Bad', $result->violations[0]->rule);
    }

    public function test_a_new_file_owns_all_of_its_errors_but_not_its_warnings(): void
    {
        $this->write('app/New.php', "<?php\n// MEH\n");
        $this->assertSame(Result::PASS, $this->check(['app/New.php'])->status);

        $this->write('app/New.php', "<?php\n// BAD\n");
        $result = $this->check(['app/New.php']);
        $this->assertSame(Result::FAIL, $result->status);
        $this->assertSame(2, $result->violations[0]->line);
    }

    public function test_a_missing_tool_is_a_skip_with_the_reason(): void
    {
        $setup = new StyleSetup(new PhpcsTool, null, 'phpcs.xml', 'found phpcs.xml');
        $check = new StyleCheck($this->workspace, new ProcessRunner($this->dir), ['phpcs' => $setup]);
        $result = $check->run(new Target('phpcs', 'style', 'test', [], ['tool' => 'phpcs', 'files' => ['app/Old.php']]));

        $this->assertSame(Result::SKIPPED, $result->status);
        $this->assertStringContainsString('vendor/bin/phpcs is not installed', $result->signal);

        $none = (new StyleCheck($this->workspace, new ProcessRunner($this->dir), [], 'no code style tool found'))
            ->run(new Target('style', 'style', 'test', [], ['tool' => null, 'files' => ['app/Old.php']]));
        $this->assertSame('no code style tool found', $none->signal);
    }
}
