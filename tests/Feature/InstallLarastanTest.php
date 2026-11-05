<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Semitexa\LaravelAiVerify\AiVerifyServiceProvider;

final class InstallLarastanTest extends TestCase
{
    private string $dir;

    private string $originalBase;

    private string $originalPath;

    protected function getPackageProviders($app): array
    {
        return [AiVerifyServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/ai-verify-larastan-'.uniqid();
        mkdir($this->dir.'/bin', 0o777, true);
        file_put_contents($this->dir.'/composer.json', '{}');

        $this->originalBase = $this->app->basePath();
        $this->app->setBasePath($this->dir);
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH='.$this->dir.'/bin:'.$this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        $this->app->setBasePath($this->originalBase);
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    /** A stand-in for composer that records its arguments and exits with `$exit`. */
    private function composer(int $exit = 0): void
    {
        file_put_contents($this->dir.'/bin/composer', "#!/bin/sh\necho \"\$@\" > \"{$this->dir}/composer.args\"\necho 'could not resolve'\nexit {$exit}\n");
        chmod($this->dir.'/bin/composer', 0o755);
    }

    private function composerArgs(): ?string
    {
        $file = $this->dir.'/composer.args';

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }

    private function install(array $args = []): string
    {
        $this->assertSame(0, Artisan::call('ai:verify:install', ['--no-interaction' => true, ...$args]));

        return Artisan::output();
    }

    public function test_the_flag_installs_larastan_with_composer(): void
    {
        $this->composer();

        $output = $this->install(['--larastan' => true]);

        $this->assertSame('require --dev larastan/larastan --no-interaction', $this->composerArgs());
        $this->assertStringContainsString('ai:verify now analyses changed code', $output);
    }

    public function test_without_a_terminal_it_warns_and_changes_nothing(): void
    {
        $this->composer();

        $output = $this->install();

        $this->assertNull($this->composerArgs());
        $this->assertStringContainsString('runs no static analysis', $output);
        $this->assertStringContainsString('--larastan', $output);
    }

    public function test_a_composer_failure_does_not_fail_the_install(): void
    {
        $this->composer(exit: 2);

        $output = $this->install(['--larastan' => true]);

        $this->assertStringContainsString('composer could not install Larastan', $output);
        $this->assertStringContainsString('could not resolve', $output);
        $this->assertFileExists($this->dir.'/AGENTS.md');
    }

    public function test_it_stays_quiet_when_analysis_already_works(): void
    {
        $this->composer();
        mkdir($this->dir.'/vendor/bin', 0o777, true);
        file_put_contents($this->dir.'/vendor/bin/phpstan', '');
        file_put_contents($this->dir.'/phpstan.neon', '');

        $output = $this->install(['--larastan' => true]);

        $this->assertNull($this->composerArgs());
        $this->assertStringNotContainsString('static analysis', $output);
    }

    public function test_no_larastan_silences_the_warning(): void
    {
        $this->assertStringNotContainsString('static analysis', $this->install(['--no-larastan' => true]));
    }

    public function test_dry_run_only_says_what_it_would_do(): void
    {
        $this->composer();

        $output = $this->install(['--larastan' => true, '--dry-run' => true]);

        $this->assertNull($this->composerArgs());
        $this->assertStringContainsString('larastan/larastan', $output);
    }

    public function test_remove_leaves_larastan_alone(): void
    {
        $this->composer();

        $this->install(['--remove' => true, '--larastan' => true]);

        $this->assertNull($this->composerArgs());
    }

    public function test_a_project_that_turned_analysis_off_is_not_asked(): void
    {
        $this->app['config']->set('ai-verify.checks.phpstan', false);

        $this->assertStringNotContainsString('static analysis', $this->install());
    }
}
