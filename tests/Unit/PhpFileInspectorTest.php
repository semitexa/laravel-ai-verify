<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Graph\PhpFileInspector;

final class PhpFileInspectorTest extends TestCase
{
    public function test_reads_class_imports_references_and_strings(): void
    {
        $code = <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Models\{Post, User as Author};
        use App\Http\Requests\StorePostRequest;
        use Illuminate\Support\Facades\Route;

        final class PostController extends Controller implements \Countable
        {
            use Concerns\Paginates;

            public function store(StorePostRequest $request)
            {
                $fn = function () use ($request) { return Author::first(); };

                return view('posts.show', ['post' => Post::create([])]);
            }

            public function count(): int { return 0; }
        }
        PHP;

        $file = tempnam(sys_get_temp_dir(), 'insp');
        file_put_contents($file, $code);
        $facts = (new PhpFileInspector)->inspect($file, 'app/Http/Controllers/PostController.php');
        unlink($file);

        $this->assertSame('App\Http\Controllers\PostController', $facts->class);
        $this->assertSame('class', $facts->classType);
        $this->assertSame('App\Http\Controllers\Controller', $facts->extends);
        $this->assertSame(['Countable'], $facts->implements);
        $this->assertSame([
            'Post' => 'App\Models\Post',
            'Author' => 'App\Models\User',
            'StorePostRequest' => 'App\Http\Requests\StorePostRequest',
            'Route' => 'Illuminate\Support\Facades\Route',
        ], $facts->imports, 'group use, aliases; a closure `use ($x)` is not an import');

        foreach (['App\Models\Post', 'App\Models\User', 'App\Http\Requests\StorePostRequest', 'App\Http\Controllers\Concerns\Paginates'] as $reference) {
            $this->assertContains($reference, $facts->references);
        }

        $this->assertNotContains('App\Http\Controllers\PostController', $facts->references);
        $this->assertContains('posts.show', $facts->strings);
    }

    public function test_class_constant_and_anonymous_class_are_not_declarations(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'insp');
        file_put_contents($file, "<?php\nnamespace App;\n\$x = Foo::class;\n\$y = new class {};\nclass Real {}\n");
        $facts = (new PhpFileInspector)->inspect($file, 'app/Real.php');
        unlink($file);

        $this->assertSame('App\Real', $facts->class);
    }
}
