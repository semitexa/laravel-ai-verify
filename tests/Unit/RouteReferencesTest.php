<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\RouteReferences;

final class RouteReferencesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-routes-'.uniqid();
        mkdir($this->dir.'/app/Http/Controllers', 0o777, true);
        mkdir($this->dir.'/resources/views', 0o777, true);
        mkdir($this->dir.'/tests/Feature', 0o777, true);

        file_put_contents($this->dir.'/app/Http/Controllers/ContactController.php', <<<'PHP'
        <?php
        class ContactController
        {
            public function store($request)
            {
                $post = $request->route('post');      // a route parameter, not a name
                $self = $this->route('contact');      // same

                return to_route('contact.create');
            }
        }
        PHP);
        file_put_contents($this->dir.'/resources/views/contact.blade.php', "<form action=\"{{ route('contact.store') }}\">\n<a href=\"{{ route('home') }}\">\n");
        file_put_contents($this->dir.'/tests/Feature/ContactTest.php', "<?php\n\$this->get(route('contact.create'))->assertOk();\n\$r->assertRedirectToRoute('admin.*');\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function test_finds_references_to_renamed_routes_with_locations(): void
    {
        $violations = (new RouteReferences(new Workspace($this->dir)))->missing(['contact.show', 'contact.send', 'home']);

        $found = array_map(static fn ($v) => $v->path.':'.$v->line.' '.$v->message, $violations);

        $this->assertSame([
            'app/Http/Controllers/ContactController.php:9 Route [contact.create] is not defined',
            'resources/views/contact.blade.php:1 Route [contact.store] is not defined',
            'tests/Feature/ContactTest.php:2 Route [contact.create] is not defined',
        ], $found);
    }

    public function test_known_names_and_explicit_file_lists(): void
    {
        $refs = new RouteReferences(new Workspace($this->dir));

        $this->assertSame([], $refs->missing(['contact.create', 'contact.store', 'home']));
        $this->assertCount(1, $refs->missing(['home'], ['resources/views/contact.blade.php']));
        $this->assertSame([], $refs->missing([], ['README.md']));
    }
}
