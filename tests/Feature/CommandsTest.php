<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Semitexa\LaravelAiVerify\AiVerifyServiceProvider;

final class CommandsTest extends TestCase
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

        $this->dir = sys_get_temp_dir().'/ai-verify-feature-'.uniqid();
        mkdir($this->dir.'/app/Models', 0o777, true);
        mkdir($this->dir.'/tests/Unit', 0o777, true);
        file_put_contents($this->dir.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->dir.'/app/Models/Invoice.php', "<?php\n\nnamespace App\\Models;\n\nclass Invoice\n{\n}\n");
        file_put_contents($this->dir.'/app/Billing.php', "<?php\n\nnamespace App;\n\nuse App\\Models\\Invoice;\n\nclass Billing\n{\n    public function make(): Invoice\n    {\n        return new Invoice;\n    }\n}\n");
        file_put_contents($this->dir.'/app/Broken.php', "<?php\n\nclass Broken {\n");
        file_put_contents($this->dir.'/tests/Unit/BillingTest.php', "<?php\n\nuse App\\Billing;\n");

        $this->originalBase = $this->app->basePath();
        $this->app->setBasePath($this->dir);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function ndjson(string $command, array $args): array
    {
        $exit = Artisan::call($command, $args);
        $lines = array_values(array_filter(explode("\n", Artisan::output())));
        $events = array_map(static fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
        $events[] = ['kind' => 'exit', 'code' => $exit];

        return $events;
    }

    /** @param list<array<string, mixed>> $events */
    private function first(array $events, string $kind): array
    {
        foreach ($events as $event) {
            if ($event['kind'] === $kind) {
                return $event;
            }
        }

        $this->fail("no '{$kind}' event");
    }

    public function test_a_syntax_error_fails_with_a_located_violation(): void
    {
        $events = $this->ndjson('ai:verify', ['--files' => ['app/Broken.php'], '--scope' => 'minimal', '--ndjson' => true]);

        $this->assertSame('fail', $this->first($events, 'verdict')['verdict']);
        $this->assertSame(1, $this->first($events, 'exit')['code']);

        $violation = $this->first($events, 'violation');
        $this->assertSame('app/Broken.php', $violation['path']);
        $this->assertSame('php.syntax', $violation['rule']);
        $this->assertIsInt($violation['line']);
    }

    public function test_a_clean_file_passes_and_streams_the_documented_event_order(): void
    {
        $events = $this->ndjson('ai:verify', ['--files' => ['app/Models/Invoice.php'], '--scope' => 'minimal', '--ndjson' => true]);
        $kinds = array_values(array_unique(array_column($events, 'kind')));

        $this->assertSame(['summary', 'file', 'target', 'result', 'next', 'verdict', 'exit'], $kinds);
        $this->assertSame('pass', $this->first($events, 'verdict')['verdict']);
        $this->assertSame('model', $this->first($events, 'file')['file_kind']);
    }

    public function test_json_envelope_carries_schema_and_coverage_gap(): void
    {
        file_put_contents($this->dir.'/NOTES.md', 'notes');

        Artisan::call('ai:verify', ['--files' => ['NOTES.md'], '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('semitexa.laravel-ai-verify/v1', $report['schema']);
        $this->assertSame('https://semitexa.com', $report['tool']['homepage']);
        $this->assertSame('incomplete', $report['verdict'], 'nothing read the changed file');
        $this->assertSame([['path' => 'NOTES.md', 'kind' => 'non_php']], $report['unchecked_files']);
    }

    public function test_no_source_outside_git_is_an_error(): void
    {
        $events = $this->ndjson('ai:verify', ['--ndjson' => true]);

        $this->assertStringContainsString('not a git repository', $this->first($events, 'error')['error']);
        $this->assertSame(1, $this->first($events, 'exit')['code']);
    }

    public function test_graph_links_tests_to_the_classes_they_use_and_reports_impact(): void
    {
        unlink($this->dir.'/app/Broken.php');

        Artisan::call('ai:graph', ['--impact' => ['app/Models/Invoice.php'], '--json' => true]);
        $impact = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['tests/Unit/BillingTest.php' => ['app/Models/Invoice.php']], $impact['tests']);
        $this->assertContains('class:App\Billing', $impact['files'][0]['sample']);

        Artisan::call('ai:graph', ['node' => 'Invoice', '--json' => true]);
        $node = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('class:App\Models\Invoice', $node['node']['id']);
        $this->assertSame(['class:App\Billing'], $node['used_by']['references']);
    }
}
