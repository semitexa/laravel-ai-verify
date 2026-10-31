<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Semitexa\LaravelAiVerify\AiVerifyServiceProvider;
use Semitexa\LaravelAiVerify\Console\HookCommand;
use Semitexa\LaravelAiVerify\Toolkit;

final class HookCommandTest extends TestCase
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

        $this->dir = sys_get_temp_dir().'/ai-verify-hookcmd-'.uniqid();
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir.'/a.txt', 'one');
        exec('cd '.escapeshellarg($this->dir).' && git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -qm one');

        $this->originalBase = $this->app->basePath();
        $this->app->setBasePath($this->dir);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    private function fingerprint(): ?string
    {
        $method = new \ReflectionMethod(HookCommand::class, 'fingerprint');

        return $method->invoke(new HookCommand, Toolkit::fromApp($this->app));
    }

    public function test_clean_tree_has_nothing_to_verify(): void
    {
        $this->assertNull($this->fingerprint());
    }

    public function test_the_same_diff_on_a_new_base_commit_is_verified_again(): void
    {
        file_put_contents($this->dir.'/a.txt', 'two');
        $before = $this->fingerprint();
        $this->assertSame($before, $this->fingerprint(), 'stable for an unchanged tree');

        // Commit something unrelated, then re-apply the identical uncommitted diff.
        exec('cd '.escapeshellarg($this->dir).' && git stash -q && echo x > b.txt && git add b.txt && git -c user.name=t -c user.email=t@t commit -qm two && git stash pop -q');

        $this->assertNotNull($this->fingerprint());
        $this->assertNotSame($before, $this->fingerprint(), 'a cached verdict must not survive a new base commit');
    }

    public function test_a_clean_tree_exits_silently(): void
    {
        $this->assertSame(0, Artisan::call('ai:verify:hook'));
        $this->assertSame('', trim(Artisan::output()));
    }

    public function test_after_a_block_a_changed_tree_is_verified_again(): void
    {
        $failed = ['fingerprint' => 'tree-1', 'verdict' => 'fail', 'blocks' => 1];

        $this->assertTrue(HookCommand::shouldVerify(true, $failed, 'tree-2'), 'the agent edited after the block: check the fix');
        $this->assertFalse(HookCommand::shouldVerify(true, $failed, 'tree-1'), 'nothing changed since the block: let Claude stop');
        $this->assertFalse(HookCommand::shouldVerify(false, $failed, 'tree-1'), 'same tree in a new turn: already judged');
    }

    public function test_blocks_stop_below_claude_codes_own_cap(): void
    {
        $this->assertTrue(HookCommand::shouldVerify(true, ['fingerprint' => 'a', 'blocks' => HookCommand::MAX_BLOCKS - 1], 'b'));
        $this->assertFalse(HookCommand::shouldVerify(true, ['fingerprint' => 'a', 'blocks' => HookCommand::MAX_BLOCKS], 'b'));
        $this->assertTrue(HookCommand::shouldVerify(false, ['fingerprint' => 'a', 'blocks' => HookCommand::MAX_BLOCKS], 'b'), 'a new turn starts the count again');
        $this->assertTrue(HookCommand::shouldVerify(false, null, 'a'));
    }
}
