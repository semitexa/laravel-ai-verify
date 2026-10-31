<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\PathPattern;

final class PathPatternTest extends TestCase
{
    public function test_prefixes_and_globs(): void
    {
        $this->assertTrue(PathPattern::matches('app/', 'app/Models/Post.php'));
        $this->assertTrue(PathPattern::matches('/app/', 'app/Models/Post.php'));
        $this->assertFalse(PathPattern::matches('app/', 'tests/app/Post.php'));
        $this->assertTrue(PathPattern::matches('_ide_helper', '_ide_helper_models.php'));

        $this->assertTrue(PathPattern::matches('resources/js/*.vue', 'resources/js/Pages/Home.vue'), '* crosses directories');
        $this->assertTrue(PathPattern::matches('*.blade.php', 'resources/views/home.blade.php'));
        $this->assertFalse(PathPattern::matches('*.js', 'resources/js/app.ts'));

        $this->assertTrue(PathPattern::any(['public/build/', '*.min.js'], 'public/build/app.js'));
        $this->assertFalse(PathPattern::any([], 'app/A.php'));
    }
}
