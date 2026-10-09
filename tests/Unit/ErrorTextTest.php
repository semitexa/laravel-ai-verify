<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\ErrorText;
use Semitexa\LaravelAiVerify\Support\Workspace;

final class ErrorTextTest extends TestCase
{
    public function test_reads_a_laravel_console_exception_block(): void
    {
        $output = "\n   LogicException \n\n  Your configuration files could not be serialized because\n  the value at \"app.name\" is non-serializable.\n\n  at vendor/laravel/framework/src/X.php:77\n";

        $this->assertSame([
            'class' => 'LogicException',
            'message' => 'Your configuration files could not be serialized because the value at "app.name" is non-serializable.',
        ], ErrorText::consoleException($output));
    }

    public function test_message_drops_trace_paragraphs_and_base_path(): void
    {
        $text = "BadMethodCallException: Method strin does not exist in /srv/app/database/m.php:14\n\n/srv/app/vendor/x.php:117\n/srv/app/database/m.php:14";

        $this->assertSame(
            'BadMethodCallException: Method strin does not exist in database/m.php:14',
            ErrorText::message($text, new Workspace('/srv/app')),
        );
    }

    public function test_location_skips_vendor_storage_and_foreign_paths(): void
    {
        $text = '/srv/app/vendor/a.php:1 /srv/app/storage/framework/views/x.php:46 /elsewhere/b.php:3 /srv/app/app/Http/Controllers/C.php:24';

        $this->assertSame(['app/Http/Controllers/C.php', 24], ErrorText::location($text, new Workspace('/srv/app')));
        $this->assertSame([null, null], ErrorText::location('no paths here', new Workspace('/srv/app')));
    }
}
