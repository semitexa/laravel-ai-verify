<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\ChangedFile;
use Semitexa\LaravelAiVerify\Verify\ChangedFileClassifier;
use Semitexa\LaravelAiVerify\Verify\Plan;
use Semitexa\LaravelAiVerify\Verify\Planner;
use Semitexa\LaravelAiVerify\Verify\Scope;
use Semitexa\LaravelAiVerify\Verify\Target;
use Semitexa\LaravelAiVerify\Verify\TestLocator;

final class PlannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-planner-'.uniqid();

        foreach (['tests/Feature/PostTest.php', 'tests/Unit/PostSlugTest.php', 'tests/Unit/UserTest.php'] as $test) {
            @mkdir(dirname($this->dir.'/'.$test), 0o777, true);
            file_put_contents($this->dir.'/'.$test, '<?php');
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @param array<string, string> $files path => status */
    private function plan(array $files, Scope $scope = Scope::Standard, array $options = []): Plan
    {
        $classifier = new ChangedFileClassifier;
        $changed = [];

        foreach ($files as $path => $status) {
            $file = new ChangedFile($path, $status);
            $file->kind = $classifier->classify($path);
            $changed[] = $file;
        }

        return (new Planner(new TestLocator(new Workspace($this->dir)), null, $options))->plan($changed, $scope);
    }

    /** @return list<string> */
    private function ids(Plan $plan): array
    {
        return array_map(static fn (Target $t) => $t->id, $plan->targets());
    }

    public function test_minimal_scope_is_syntax_and_changed_tests_only(): void
    {
        $plan = $this->plan(['app/Models/Post.php' => 'M', 'tests/Feature/PostTest.php' => 'M'], Scope::Minimal);

        $this->assertSame([
            'syntax:app/Models/Post.php',
            'syntax:tests/Feature/PostTest.php',
            'test_integrity',
            'tests:tests/Feature/PostTest.php',
        ], $this->ids($plan));
    }

    public function test_standard_scope_maps_a_model_to_style_analysis_and_named_tests(): void
    {
        $plan = $this->plan(['app/Models/Post.php' => 'M']);

        $this->assertSame([
            'syntax:app/Models/Post.php',
            'pint',
            'phpstan',
            'tests:tests/Feature/PostTest.php',
            'tests:tests/Unit/PostSlugTest.php',
        ], $this->ids($plan));
        $this->assertSame(['tests/Unit/PostSlugTest.php', 'app/Models/Post.php'], $plan->get('tests:tests/Unit/PostSlugTest.php')?->triggeredBy);
    }

    public function test_laravel_kinds_schedule_their_boot_probes(): void
    {
        $plan = $this->plan([
            'routes/web.php' => 'M',
            'config/app.php' => 'M',
            'app/Listeners/Notify.php' => 'M',
            'database/migrations/2026_01_01_000000_x.php' => 'A',
            'resources/views/a.blade.php' => 'M',
            'lang/uk.json' => 'M',
        ]);

        foreach (['artisan:routes', 'artisan:config', 'artisan:events', 'migration:database/migrations/2026_01_01_000000_x.php', 'blade', 'json'] as $id) {
            $this->assertContains($id, $this->ids($plan));
        }

        $this->assertNotContains('artisan:boot', $this->ids($plan));
    }

    public function test_contracts_providers_and_large_change_sets_widen_to_broad(): void
    {
        $provider = $this->plan(['app/Providers/AppServiceProvider.php' => 'M']);
        $this->assertSame(Scope::Broad, $provider->effectiveScope);
        $this->assertStringContainsString('provider changed', $provider->expansions[0]);
        $this->assertContains('tests:suite', $this->ids($provider));
        $this->assertContains('artisan:boot', $this->ids($provider));

        $files = [];

        for ($i = 0; $i < 3; $i++) {
            $files["app/Support/C{$i}.php"] = 'M';
        }

        $this->assertSame(Scope::Broad, $this->plan($files, Scope::Standard, ['broad_threshold' => 3])->effectiveScope);
        $this->assertSame(Scope::Minimal, $this->plan(['app/Contracts/X.php' => 'M'], Scope::Minimal)->effectiveScope, 'explicit minimal is respected');
    }

    public function test_shared_test_infrastructure_runs_the_suite(): void
    {
        $plan = $this->plan(['tests/TestCase.php' => 'M']);

        $this->assertContains('tests:suite', $this->ids($plan));
    }

    public function test_too_many_related_tests_collapse_into_one_suite_run(): void
    {
        $plan = $this->plan(['app/Models/Post.php' => 'M'], Scope::Standard, ['max_test_targets' => 1]);

        $this->assertContains('tests:suite', $this->ids($plan));
        $this->assertNotContains('tests:tests/Feature/PostTest.php', $this->ids($plan));
        $this->assertStringContainsString('max_test_targets=1', implode(' ', $plan->expansions));
    }

    public function test_deleted_files_get_no_syntax_check(): void
    {
        $this->assertNotContains('syntax:app/Models/Post.php', $this->ids($this->plan(['app/Models/Post.php' => 'D'])));
    }

    public function test_each_detected_style_tool_gets_its_own_target(): void
    {
        $plan = $this->plan(['app/Models/Post.php' => 'M'], Scope::Standard, ['style_tools' => ['php-cs-fixer', 'phpcs']]);

        $this->assertSame('style', $plan->get('php-cs-fixer')?->check);
        $this->assertSame('phpcs', $plan->get('phpcs')?->params['tool']);
        $this->assertNull($plan->get('pint'));
    }

    public function test_no_style_tool_still_plans_a_style_target_that_explains_itself(): void
    {
        $plan = $this->plan(['app/Models/Post.php' => 'M'], Scope::Standard, ['style_tools' => []]);

        $this->assertNull($plan->get('style')?->params['tool']);
        $this->assertNull($this->plan(['app/Models/Post.php' => 'M'], Scope::Standard, ['style' => false])->get('pint'));
    }

    public function test_custom_commands_follow_when_and_scope(): void
    {
        $command = static fn (array $over) => ['name' => 'rector', 'run' => 'vendor/bin/rector {files}', 'when' => ['app/'], 'scope' => 'standard', 'required' => true, 'timeout' => null, ...$over];
        $files = ['app/Models/Post.php' => 'M', 'routes/web.php' => 'M', 'app/Gone.php' => 'D'];

        $target = $this->plan($files, Scope::Standard, ['commands' => [$command([])]])->get('command:rector');
        $this->assertSame(['app/Models/Post.php', 'app/Gone.php'], $target?->triggeredBy);
        $this->assertSame(['app/Models/Post.php'], $target?->params['files'], 'deleted files are not passed to the command');

        $this->assertNull($this->plan(['routes/web.php' => 'M'], Scope::Standard, ['commands' => [$command([])]])->get('command:rector'));
        $this->assertNull($this->plan($files, Scope::Minimal, ['commands' => [$command([])]])->get('command:rector'));
        $this->assertNotNull($this->plan($files, Scope::Minimal, ['commands' => [$command(['scope' => 'minimal'])]])->get('command:rector'));
        $this->assertNotNull($this->plan(['routes/web.php' => 'M'], Scope::Standard, ['commands' => [$command(['when' => []])]])->get('command:rector'));
    }

    public function test_custom_commands_run_before_tests(): void
    {
        $command = ['name' => 'lint', 'run' => 'true', 'when' => [], 'scope' => 'standard', 'required' => true, 'timeout' => null];
        $ids = $this->ids($this->plan(['app/Models/Post.php' => 'M'], Scope::Standard, ['commands' => [$command]]));

        $this->assertLessThan(array_search('tests:tests/Feature/PostTest.php', $ids, true), array_search('command:lint', $ids, true));
    }
}
