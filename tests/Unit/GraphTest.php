<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Graph\Graph;
use Semitexa\LaravelAiVerify\Graph\ImpactAnalyzer;

final class GraphTest extends TestCase
{
    private function blog(): Graph
    {
        $g = new Graph;
        $g->addNode('class:App\Models\Post', 'class', 'Post', 'app/Models/Post.php', ['kind' => 'model']);
        $g->addNode('class:App\Http\Controllers\PostController', 'class', 'PostController', 'app/Http/Controllers/PostController.php');
        $g->addNode('route:GET /posts', 'route', 'GET /posts', null, ['uri' => '/posts', 'name' => 'posts.index']);
        $g->addNode('view:posts.index', 'view', 'posts.index', 'resources/views/posts/index.blade.php');
        $g->addNode('test:tests/Feature/PostTest.php', 'test', 'PostTest', 'tests/Feature/PostTest.php');
        $g->addNode('test:tests/Unit/OtherTest.php', 'test', 'OtherTest', 'tests/Unit/OtherTest.php');

        $g->addEdge('class:App\Http\Controllers\PostController', 'class:App\Models\Post', 'references');
        $g->addEdge('class:App\Http\Controllers\PostController', 'view:posts.index', 'renders');
        $g->addEdge('route:GET /posts', 'class:App\Http\Controllers\PostController', 'handled_by');
        $g->addEdge('test:tests/Feature/PostTest.php', 'route:GET /posts', 'hits');

        return $g;
    }

    public function test_edges_to_unknown_nodes_and_self_loops_are_dropped(): void
    {
        $g = $this->blog();
        $g->addEdge('class:App\Models\Post', 'class:Nope', 'references');
        $g->addEdge('class:App\Models\Post', 'class:App\Models\Post', 'references');
        $g->addEdge('route:GET /posts', 'class:App\Http\Controllers\PostController', 'handled_by');

        $this->assertSame(4, $g->stats()['edges']);
    }

    public function test_dependents_walk_incoming_edges_with_distance(): void
    {
        $this->assertSame([
            'class:App\Http\Controllers\PostController' => 1,
            'route:GET /posts' => 2,
            'test:tests/Feature/PostTest.php' => 3,
        ], $this->blog()->dependents(['class:App\Models\Post']));

        $this->assertSame(['class:App\Http\Controllers\PostController' => 1], $this->blog()->dependents(['class:App\Models\Post'], 1));
    }

    public function test_find_prefers_routes_over_same_named_views(): void
    {
        $g = $this->blog();
        $g->addNode('view:posts.index', 'view', 'posts.index');

        $this->assertSame('route:GET /posts', $g->find('posts.index'));
        $this->assertSame('route:GET /posts', $g->find('/posts'));
        $this->assertSame('view:posts.index', $g->find('view:posts.index'));
        $this->assertSame('class:App\Models\Post', $g->find('Post'));
        $this->assertSame('class:App\Models\Post', $g->find('app/Models/Post.php'));
        $this->assertNull($g->find('Nothing'));
    }

    public function test_impact_finds_tests_through_views_routes_and_controllers(): void
    {
        $impact = new ImpactAnalyzer($this->blog());

        $this->assertSame(
            ['tests/Feature/PostTest.php' => ['resources/views/posts/index.blade.php']],
            $impact->testsFor(['resources/views/posts/index.blade.php'], 4),
        );
        $this->assertSame([], $impact->testsFor(['resources/views/posts/index.blade.php'], 2), 'depth limits the walk');

        $report = $impact->report(['app/Models/Post.php', 'README.md']);
        $this->assertSame('low', $report['files'][0]['band']);
        $this->assertSame(1, $report['files'][0]['routes']);
        $this->assertSame('unresolved', $report['files'][1]['band']);
    }
}
