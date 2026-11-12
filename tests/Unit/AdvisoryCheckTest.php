<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Checks\AdvisoryCheck;
use Semitexa\LaravelAiVerify\Verify\Result;
use Semitexa\LaravelAiVerify\Verify\Target;

final class AdvisoryCheckTest extends TestCase
{
    private const ROUTING = ['package' => 'symfony/routing', 'id' => 'PKSA-1', 'title' => 'CVE-1: host placeholders', 'link' => 'https://example.test/cve-1'];

    private const KERNEL = ['package' => 'symfony/http-kernel', 'id' => 'PKSA-2', 'title' => 'CVE-2: forwarded headers', 'link' => null];

    private const LOCKED = ['symfony/routing' => 'v8.1.10', 'symfony/http-kernel' => 'v8.1.10', 'laravel/framework' => 'v13.39.0'];

    public function test_no_advisory_passes(): void
    {
        $result = AdvisoryCheck::judge([], self::LOCKED, self::LOCKED);

        $this->assertSame(Result::PASS, $result->status);
        $this->assertSame([], $result->violations);
        $this->assertStringContainsString('3 locked package(s)', $result->signal);
    }

    public function test_an_advisory_on_a_package_the_change_left_alone_is_a_warning(): void
    {
        $result = AdvisoryCheck::judge([self::ROUTING], self::LOCKED, self::LOCKED, 'main');

        $this->assertSame(Result::PASS, $result->status);
        $this->assertTrue($result->accepted);
        $this->assertSame('warning', $result->violations[0]->severity);
        $this->assertSame('Already locked at main', $result->violations[0]->tip);
        $this->assertSame('symfony/routing v8.1.10: CVE-1: host placeholders (https://example.test/cve-1)', $result->violations[0]->message);
    }

    public function test_an_advisory_on_a_package_the_change_moved_fails(): void
    {
        $before = ['symfony/routing' => 'v8.1.9'] + self::LOCKED;
        $result = AdvisoryCheck::judge([self::ROUTING, self::KERNEL], self::LOCKED, $before);

        $this->assertSame(Result::FAIL, $result->status);
        $this->assertSame('1 known advisory(ies) in packages this change added or moved: symfony/routing', $result->signal);
        $this->assertSame(['error', 'warning'], array_map(static fn ($v) => $v->severity, $result->violations));
        $this->assertStringContainsString('from v8.1.9', (string) $result->violations[0]->tip);
        $this->assertSame('composer.advisory', $result->violations[0]->rule);
    }

    public function test_an_advisory_on_a_package_the_change_added_fails(): void
    {
        $before = self::LOCKED;
        unset($before['symfony/http-kernel']);
        $result = AdvisoryCheck::judge([self::KERNEL], self::LOCKED, $before);

        $this->assertSame(Result::FAIL, $result->status);
        $this->assertStringContainsString('added the package', (string) $result->violations[0]->tip);
    }

    public function test_a_new_lock_file_makes_every_advisory_introduced(): void
    {
        $this->assertSame(Result::FAIL, AdvisoryCheck::judge([self::ROUTING], self::LOCKED, [])->status);
    }

    public function test_an_unreadable_base_reports_without_failing(): void
    {
        $result = AdvisoryCheck::judge([self::ROUTING], self::LOCKED, null);

        $this->assertSame(Result::PASS, $result->status);
        $this->assertStringContainsString('could not be read', (string) $result->violations[0]->tip);
    }

    public function test_it_reads_the_composer_audit_report(): void
    {
        $json = '{"advisories":{"symfony/routing":[{"advisoryId":"PKSA-1","packageName":"symfony/routing","title":"CVE-1: host placeholders","cve":"CVE-1","link":"https://example.test/cve-1"}],'
            .'"symfony/http-kernel":{"7":{"advisoryId":"PKSA-2","title":"CVE-2: forwarded headers","link":""}}},"abandoned":[]}';

        $this->assertSame([self::ROUTING, self::KERNEL], AdvisoryCheck::advisories("Warning: something on stdout\n".$json));
        $this->assertSame([], AdvisoryCheck::advisories('{"advisories":[],"abandoned":[]}'));
    }

    public function test_output_that_is_not_a_report_is_not_a_clean_result(): void
    {
        $this->assertNull(AdvisoryCheck::advisories(''));
        $this->assertNull(AdvisoryCheck::advisories('The "https://packagist.org/api/security-advisories/" file could not be downloaded'));
        $this->assertNull(AdvisoryCheck::advisories('{"error":"no"}'));
    }

    public function test_the_skip_reason_is_composers_error_not_its_usage_line(): void
    {
        $output = "In HttpDownloader.php line 329:\n                 \n  Network disabled, request canceled: https://packagist.org/api/security-advisories/  \n                 \n\naudit [--no-dev] [-f|--format FORMAT] [--locked]\n\n";

        $this->assertSame('Network disabled, request canceled: https://packagist.org/api/security-advisories/', AdvisoryCheck::reason($output));
        $this->assertSame('no output', AdvisoryCheck::reason("\n"));
    }

    public function test_it_reads_versions_from_both_lock_sections(): void
    {
        $lock = '{"packages":[{"name":"a/b","version":"1.0.0"}],"packages-dev":[{"name":"c/d","version":"v2.1.0"},{"name":"broken"}]}';

        $this->assertSame(['a/b' => '1.0.0', 'c/d' => 'v2.1.0'], AdvisoryCheck::versions($lock));
        $this->assertSame([], AdvisoryCheck::versions('not json'));
    }

    public function test_a_project_without_a_lock_file_is_skipped(): void
    {
        $dir = sys_get_temp_dir().'/ai-verify-advisory-'.uniqid();
        mkdir($dir);
        $target = new Target('advisories', 'advisories', 'test', ['composer.json'], [], required: false);
        $result = (new AdvisoryCheck(new Workspace($dir), new ProcessRunner($dir)))->run($target)->bindTo($target);
        rmdir($dir);

        $this->assertSame(Result::SKIPPED, $result->effectiveStatus());
        $this->assertSame('no composer.lock to audit', $result->signal);
    }
}
