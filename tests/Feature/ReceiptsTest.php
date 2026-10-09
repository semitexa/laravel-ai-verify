<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Semitexa\LaravelAiVerify\AiVerifyServiceProvider;
use Semitexa\LaravelAiVerify\Receipts\Receipts;
use Semitexa\LaravelAiVerify\Support\GitTree;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;

final class ReceiptsTest extends TestCase
{
    private string $dir;

    private string $originalBase;

    protected function getPackageProviders($app): array
    {
        return [AiVerifyServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/ai-verify-receipts-'.uniqid();
        mkdir($this->dir.'/app', 0o777, true);
        file_put_contents($this->dir.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money\n{\n}\n");
        $this->git('init -q && git add -A && git commit -qm init');

        $this->originalBase = $this->app->basePath();
        $this->app->setBasePath($this->dir);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    private function git(string $args): string
    {
        return (string) shell_exec('cd '.escapeshellarg($this->dir).' && git -c user.name=t -c user.email=t@t '.$args.' 2>&1');
    }

    private function verify(): array
    {
        Artisan::call('ai:verify', ['--scope' => 'minimal', '--json' => true]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_working_tree_id_is_the_tree_the_commit_gets(): void
    {
        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money\n{\n    public int \$cents = 0;\n}\n");
        file_put_contents($this->dir.'/app/New.php', "<?php\n\nnamespace App;\n\nclass NewOne\n{\n}\n");

        $tree = (new GitTree(new ProcessRunner($this->dir)))->working();
        $this->assertSame('', trim($this->git('diff --cached --name-only')), 'the real index is untouched');

        $this->git('add -A && git commit -qm change');
        $this->assertSame(trim($this->git('rev-parse HEAD^{tree}')), $tree);
    }

    public function test_verify_writes_a_receipt_that_holds_until_the_tree_changes(): void
    {
        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money\n{\n    public int \$cents = 0;\n}\n");

        $report = $this->verify();
        $this->assertSame('pass', $report['verdict']);
        $this->assertMatchesRegularExpression('/^rcpt-\d{8}-\d{6}-[0-9a-f]{6}$/', $report['receipt']['id']);
        $this->assertFileExists($this->dir.'/'.$report['receipt']['path']);
        $this->assertSame('', trim($this->git('status --short --ignored=no -- storage')), 'receipts never show up as changes');

        $this->assertSame(0, Artisan::call('ai:verify:receipt', ['--json' => true]));
        $check = json_decode(Artisan::output(), true);
        $this->assertTrue($check['holds']);
        $this->assertTrue($check['tree_matches']);

        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money {}\n");
        $this->assertSame(1, Artisan::call('ai:verify:receipt', ['--json' => true]));
        $check = json_decode(Artisan::output(), true);
        $this->assertFalse($check['holds']);
        $this->assertSame(['app/Money.php'], $check['changed_since']);
    }

    public function test_an_edited_receipt_is_not_intact(): void
    {
        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money\n{\n    public int \$x = 1;\n}\n");
        $report = $this->verify();

        $path = $this->dir.'/'.$report['receipt']['path'];
        file_put_contents($path, str_replace('"pass"', '"PASS"', (string) file_get_contents($path)));

        Artisan::call('ai:verify:receipt', ['id' => $report['receipt']['id'], '--json' => true]);
        $this->assertFalse(json_decode(Artisan::output(), true)['intact']);
    }

    public function test_trailer_travels_with_the_commit_and_ci_checks_it_against_the_tree(): void
    {
        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money\n{\n    public int \$cents = 0;\n}\n");
        $this->verify();
        $this->git('add -A');

        Artisan::call('ai:verify:receipt', ['--trailer' => true]);
        $trailer = trim(Artisan::output());
        $this->assertStringStartsWith('AI-Verify: pass rcpt-', $trailer);

        file_put_contents($this->dir.'/msg', "Add cents\n\nCo-Authored-By: Claude <noreply@anthropic.com>\n");
        Artisan::call('ai:verify:receipt', ['--trailer' => true, '--message-file' => $this->dir.'/msg']);
        $this->assertStringContainsString($trailer, (string) file_get_contents($this->dir.'/msg'));
        $this->git('commit -q -F msg');

        // An AI commit without a trailer, made after the verified one.
        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money {}\n");
        $this->git('commit -qam "Shrink" -m "Co-Authored-By: Claude <noreply@anthropic.com>"');
        // A human commit without a trailer.
        file_put_contents($this->dir.'/README.md', 'docs');
        $this->git('add -A && git commit -qm Docs');

        $this->assertSame(1, Artisan::call('ai:verify:receipt', ['--range' => 'HEAD~3..HEAD', '--json' => true]));
        $range = json_decode(Artisan::output(), true);
        $this->assertSame(['missing', 'missing', 'verified'], array_column($range['details'], 'status'));
        $this->assertSame([false, true, true], array_column($range['details'], 'required'), 'newest first: human docs commit, AI commit, AI commit');
        $this->assertSame(1, $range['failing']);

        $this->assertSame(0, Artisan::call('ai:verify:receipt', ['--commit' => 'HEAD~2', '--json' => true]));
        $this->assertSame(0, Artisan::call('ai:verify:receipt', ['--range' => 'HEAD~3..HEAD', '--require' => 'none']));
    }

    public function test_amending_after_verification_makes_the_trailer_stale(): void
    {
        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money\n{\n    public int \$cents = 0;\n}\n");
        $this->verify();
        $this->git('add -A');
        Artisan::call('ai:verify:receipt', ['--trailer' => true]);
        $trailer = trim(Artisan::output());
        $this->git('commit -qm '.escapeshellarg("Add cents\n\n{$trailer}"));

        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money\n{\n    public int \$cents = 1;\n}\n");
        $this->git('commit -q --amend -a --no-edit');

        Artisan::call('ai:verify:receipt', ['--commit' => 'HEAD', '--require' => 'all', '--json' => true]);
        $this->assertSame('stale', json_decode(Artisan::output(), true)['details'][0]['status']);
    }

    public function test_unread_lists_receipts_nobody_checked(): void
    {
        file_put_contents($this->dir.'/app/Money.php', "<?php\n\nnamespace App;\n\nclass Money\n{\n    public int \$a = 1;\n}\n");
        $report = $this->verify();
        $receipts = new Receipts(new Workspace($this->dir));

        $this->assertSame([$report['receipt']['id']], array_column($receipts->unread(), 'id'));

        Artisan::call('ai:verify:receipt');
        $this->assertSame([], $receipts->unread());
    }
}
