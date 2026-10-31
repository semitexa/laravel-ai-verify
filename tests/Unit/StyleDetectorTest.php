<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Style\StyleDetector;
use Semitexa\LaravelAiVerify\Verify\Style\StyleSetup;

final class StyleDetectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-style-'.uniqid();
        mkdir($this->dir.'/vendor/bin', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function touch(string ...$paths): void
    {
        foreach ($paths as $path) {
            file_put_contents($this->dir.'/'.$path, '');
        }
    }

    /** @return array{setups: list<StyleSetup>, none: ?string, warnings: list<string>} */
    private function detect(mixed $setting = 'auto'): array
    {
        return (new StyleDetector(new Workspace($this->dir)))->detect($setting);
    }

    /** @param array{setups: list<StyleSetup>} $found */
    private function ids(array $found): array
    {
        return array_map(static fn (StyleSetup $s) => $s->id(), $found['setups']);
    }

    public function test_nothing_installed_and_no_config_means_no_style_check(): void
    {
        $found = $this->detect();

        $this->assertSame([], $found['setups']);
        $this->assertStringContainsString('no code style tool found', (string) $found['none']);
    }

    public function test_pint_alone_falls_back_to_its_laravel_preset(): void
    {
        $this->touch('vendor/bin/pint');
        $found = $this->detect();

        $this->assertSame(['pint'], $this->ids($found));
        $this->assertNull($found['setups'][0]->config);
        $this->assertStringContainsString('laravel preset', $found['setups'][0]->reason);
    }

    public function test_a_php_cs_fixer_config_wins_over_an_installed_pint(): void
    {
        $this->touch('vendor/bin/pint', 'vendor/bin/php-cs-fixer', '.php-cs-fixer.dist.php');
        $found = $this->detect();

        $this->assertSame(['php-cs-fixer'], $this->ids($found));
        $this->assertSame('.php-cs-fixer.dist.php', $found['setups'][0]->config);
        $this->assertSame([], $found['warnings']);
    }

    public function test_a_config_without_its_tool_is_reported_not_replaced_by_pint(): void
    {
        $this->touch('vendor/bin/pint', 'phpcs.xml.dist');
        $found = $this->detect();

        $this->assertSame(['phpcs'], $this->ids($found));
        $this->assertNull($found['setups'][0]->bin);
        $this->assertSame(["phpcs.xml.dist is the project's standard, but vendor/bin/phpcs is not installed"], $found['warnings']);
    }

    public function test_every_configured_tool_runs(): void
    {
        $this->touch('pint.json', 'ecs.php', 'vendor/bin/pint', 'vendor/bin/ecs');

        $this->assertSame(['pint', 'ecs'], $this->ids($this->detect()));
    }

    public function test_explicit_tools_and_unknown_names(): void
    {
        $this->touch('vendor/bin/phpcs', 'vendor/bin/pint', '.phpcs.xml');

        $this->assertSame(['phpcs', 'pint'], $this->ids($this->detect('phpcs, pint')));
        $this->assertSame(['phpcs'], $this->ids($this->detect(['phpcs'])));
        $this->assertSame('.phpcs.xml', $this->detect('phpcs')['setups'][0]->config);

        $unknown = $this->detect('rubocop');
        $this->assertSame([], $unknown['setups']);
        $this->assertStringContainsString("unknown style tool 'rubocop'", $unknown['warnings'][0]);
    }

    public function test_style_can_be_switched_off(): void
    {
        $this->touch('vendor/bin/pint');

        $this->assertSame([], $this->detect(false)['setups']);
        $this->assertSame([], $this->detect('none')['setups']);
    }
}
