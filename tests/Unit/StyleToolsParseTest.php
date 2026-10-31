<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\Style\EcsTool;
use Semitexa\LaravelAiVerify\Verify\Style\Finding;
use Semitexa\LaravelAiVerify\Verify\Style\PhpcsTool;
use Semitexa\LaravelAiVerify\Verify\Style\PhpCsFixerTool;
use Semitexa\LaravelAiVerify\Verify\Style\PintTool;

/** Report formats as the tools print them (trimmed to the fields we read). */
final class StyleToolsParseTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new Workspace('/srv/app');
    }

    /**
     * @param  array<string, list<Finding>>|null  $found
     * @return array<string, list<string>>
     */
    private function rules(?array $found): array
    {
        $this->assertNotNull($found);

        return array_map(static fn (array $list) => array_map(static fn (Finding $f) => $f->rule, $list), $found);
    }

    public function test_pint(): void
    {
        $stdout = '{"result":"fail","files":[{"path":"/srv/app/app/A.php","name":"app/A.php","fixers":["no_unused_imports","braces_position"]}]}';

        $this->assertSame(['app/A.php' => ['no_unused_imports', 'braces_position']], $this->rules((new PintTool)->parse($stdout, $this->workspace)));
        $this->assertNull((new PintTool)->parse('PHP Fatal error: ...', $this->workspace));
    }

    public function test_php_cs_fixer_after_a_warning_line(): void
    {
        $stdout = "PHP needs to be a minimum version of PHP 7.4.0 and maximum version of PHP 8.4.*.\n"
            .'{"about":"PHP CS Fixer 3.64.0","files":[{"name":"app/A.php","appliedFixers":["single_quote","no_extra_blank_lines"]}],"time":{"total":0.1},"memory":12}';

        $this->assertSame(['app/A.php' => ['single_quote', 'no_extra_blank_lines']], $this->rules((new PhpCsFixerTool)->parse($stdout, $this->workspace)));
        $this->assertSame([], (new PhpCsFixerTool)->parse('{"about":"x","files":[]}', $this->workspace));
        $this->assertSame(['app/B.php' => ['php-cs-fixer']], $this->rules((new PhpCsFixerTool)->parse('{"about":"x","files":[{"name":"app/B.php"}]}', $this->workspace)), 'listed without -v: still needs fixing');
    }

    public function test_phpcs_keeps_lines_messages_and_warning_type(): void
    {
        $stdout = '{"totals":{"errors":1,"warnings":1,"fixable":1},"files":{"/srv/app/app/A.php":{"errors":1,"warnings":1,"messages":['
            .'{"message":"Expected 1 space after IF keyword; 0 found","source":"Squiz.ControlStructures.ControlSignature.SpaceAfterKeyword","severity":5,"fixable":true,"type":"ERROR","line":7,"column":9},'
            .'{"message":"Line exceeds 120 characters","source":"Generic.Files.LineLength.TooLong","severity":5,"fixable":false,"type":"WARNING","line":9,"column":121}]}}}';

        $found = (new PhpcsTool)->parse($stdout, $this->workspace);

        $this->assertSame(['app/A.php' => ['Squiz.ControlStructures.ControlSignature.SpaceAfterKeyword', 'Generic.Files.LineLength.TooLong']], $this->rules($found));
        $this->assertSame(7, $found['app/A.php'][0]->line);
        $this->assertFalse($found['app/A.php'][0]->warning);
        $this->assertTrue($found['app/A.php'][1]->warning);
    }

    public function test_ecs_fixers_and_sniff_errors(): void
    {
        $stdout = '{"meta":{"version":"12.3"},"totals":{"errors":1,"diffs":1},"files":{"app/A.php":{'
            .'"diffs":[{"diff":"--- Original","applied_checkers":["PhpCsFixer\\\\Fixer\\\\Import\\\\NoUnusedImportsFixer"]}],'
            .'"errors":[{"line":4,"file_path":"app/A.php","message":"Class name is not valid","source_class":"PHP_CodeSniffer\\\\Standards\\\\Squiz\\\\Sniffs\\\\Classes\\\\ValidClassNameSniff"}]}}}';

        $found = (new EcsTool)->parse($stdout, $this->workspace);

        $this->assertSame(['app/A.php' => ['NoUnusedImportsFixer', 'ValidClassNameSniff']], $this->rules($found));
        $this->assertSame(4, $found['app/A.php'][1]->line);
    }
}
