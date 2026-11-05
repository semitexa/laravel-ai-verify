<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\ChangedFile;
use Semitexa\LaravelAiVerify\Verify\ChangedFileClassifier;
use Semitexa\LaravelAiVerify\Verify\Planner;
use Semitexa\LaravelAiVerify\Verify\RouteTests;
use Semitexa\LaravelAiVerify\Verify\Scope;
use Semitexa\LaravelAiVerify\Verify\TestLocator;

final class RouteTestsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-route-tests-'.uniqid();
        mkdir($this->dir.'/routes', 0o777, true);
        mkdir($this->dir.'/tests/Feature', 0o777, true);
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function git(string ...$args): void
    {
        exec('git -C '.escapeshellarg($this->dir).' '.implode(' ', array_map('escapeshellarg', $args)));
    }

    private function test(string $name, string $body): void
    {
        file_put_contents($this->dir."/tests/Feature/{$name}Test.php", "<?php\n\n{$body}\n");
    }

    private function commitRoutes(string $routes): void
    {
        file_put_contents($this->dir.'/routes/web.php', "<?php\n\n{$routes}\n");
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'routes');
    }

    private function locator(): TestLocator
    {
        return new TestLocator(new Workspace($this->dir));
    }

    private function changed(string $status = ChangedFile::MODIFIED): ChangedFile
    {
        $file = new ChangedFile('routes/web.php', $status);
        $file->kind = (new ChangedFileClassifier)->classify('routes/web.php');

        return $file;
    }

    /** @return list<string> test names found after the route file becomes `$routes` */
    private function found(string $routes): array
    {
        file_put_contents($this->dir.'/routes/web.php', "<?php\n\n{$routes}\n");
        $finder = new RouteTests(new Workspace($this->dir), new ProcessRunner($this->dir), $this->locator());

        return array_map(
            static fn (string $path) => basename($path, 'Test.php'),
            array_keys($finder->testsFor([$this->changed()], 'HEAD')),
        );
    }

    public function test_a_renamed_route_finds_the_tests_that_use_the_old_name(): void
    {
        $this->test('ContactForm', "\$this->get(route('contact.form'));");
        $this->test('Other', "\$this->get(route('about'));");
        $this->commitRoutes("Route::get('/contact', Contact::class)->name('contact.form');\nRoute::get('/about', About::class)->name('about');");

        $this->assertSame(['ContactForm'], $this->found("Route::get('/contact', Contact::class)->name('contact.show');\nRoute::get('/about', About::class)->name('about');"));
    }

    public function test_a_route_renamed_to_a_name_a_test_already_uses_finds_that_test(): void
    {
        $this->test('New', "\$this->get(route('contact.show'));");
        $this->commitRoutes("Route::get('/contact', Contact::class)->name('contact.form');");

        $this->assertSame(['New'], $this->found("Route::get('/contact', Contact::class)->name('contact.show');"));
    }

    public function test_a_changed_uri_finds_the_tests_that_request_it(): void
    {
        $this->test('Contact', "\$this->post('/contact', []);");
        $this->test('Pest', "get('/contact')->assertOk();");
        $this->test('Other', "\$this->get('/contact-us/old');");
        $this->commitRoutes("Route::post('/contact', Contact::class);");

        $this->assertSame(['Contact', 'Pest'], $this->found("Route::post('/contact-form', Contact::class);"));
    }

    public function test_a_chain_over_several_lines_is_one_route(): void
    {
        $this->test('Post', "\$this->get('/posts/1');");
        $this->commitRoutes("Route::get('/posts/{post}', Show::class)\n    ->middleware('auth')\n    ->name('posts.show');");

        $this->assertSame(['Post'], $this->found("Route::get('/posts/{post}', Show::class)\n    ->middleware('verified')\n    ->name('posts.show');"));
    }

    public function test_reformatting_and_comments_find_nothing(): void
    {
        $this->test('Post', "\$this->get('/posts');");
        $this->commitRoutes("Route::get('/posts', Index::class);");

        $this->assertSame([], $this->found("// the list of posts\nRoute::get(\n    '/posts',\n    Index::class\n);"));
    }

    public function test_a_route_in_a_group_does_not_pull_in_the_whole_group(): void
    {
        $this->test('Users', "\$this->get('/admin/users');");
        $this->test('Named', "\$this->get(route('admin.users.index'));");
        $this->test('Reports', "\$this->get('/admin/reports');");
        $group = "Route::prefix('admin')->name('admin.')->group(function () {\n    Route::get('/users', A::class)->name('users.index');\n    Route::get('/reports', B::class)->name('reports');\n});";
        $this->commitRoutes($group);

        $this->assertSame(['Named', 'Users'], $this->found(str_replace('A::class', 'C::class', $group)));
    }

    public function test_a_changed_group_prefix_finds_every_test_under_it(): void
    {
        $this->test('Users', "\$this->get('/admin/users');");
        $this->test('Public', "\$this->get('/users');");
        $this->commitRoutes("Route::prefix('admin')->group(function () {\n    Route::get('/users', A::class);\n});");

        $this->assertSame(['Users'], $this->found("Route::prefix('backend')->group(function () {\n    Route::get('/users', A::class);\n});"));
    }

    public function test_a_resource_stands_for_its_names_and_uris(): void
    {
        $this->test('ByName', "\$this->get(route('photos.edit', 1));");
        $this->test('ByUri', "\$this->delete('/photos/1');");
        $this->test('Other', "\$this->get('/videos');");
        $this->commitRoutes("Route::resource('photos', A::class);");

        $this->assertSame(['ByName', 'ByUri'], $this->found("Route::resource('photos', B::class);"));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function requests(): array
    {
        return [
            'concatenated' => ['/posts/{post}/edit', "\$this->get('/posts/'.\$post->id.'/edit');"],
            'interpolated' => ['/posts/{post}/edit', '$this->get("/posts/{$post->id}/edit");'],
            'query string' => ['/search', "\$this->get('/search?q=a');"],
            'no leading slash' => ['/search', "\$this->get('search');"],
            'json' => ['/api/items', "\$this->postJson('/api/items', []);"],
            'json with method' => ['/api/items', "\$this->json('PATCH', '/api/items');"],
            'optional parameter left out' => ['/posts/{post?}', "\$this->get('/posts');"],
            'optional parameter given' => ['/posts/{post?}', "\$this->get('/posts/7');"],
            'full url' => ['/search', "\$this->get('http://localhost/search');"],
            'redirect target' => ['/search', "\$response->assertRedirect('/search');"],
            'root' => ['/', "\$this->get('/');"],
        ];
    }

    #[DataProvider('requests')]
    public function test_the_ways_a_test_requests_a_uri(string $uri, string $request): void
    {
        $this->test('Request', $request);
        $this->commitRoutes("Route::get('{$uri}', A::class);");

        $this->assertSame(['Request'], $this->found("Route::get('{$uri}', B::class);"));
    }

    public function test_the_root_route_does_not_match_every_uri(): void
    {
        $this->test('Other', "\$this->get('/about');");
        $this->commitRoutes("Route::get('/', A::class);");

        $this->assertSame([], $this->found("Route::get('/', B::class);"));
    }

    public function test_a_route_of_parameters_alone_does_not_match_every_uri(): void
    {
        $this->test('Other', "\$this->get('/api/orders');");
        $this->commitRoutes("Route::get('/{read?}', A::class);");

        $this->assertSame([], $this->found("Route::get('/{read?}', B::class);"));
    }

    public function test_collection_get_and_static_calls_are_not_requests(): void
    {
        $this->test('Cache', "Cache::get('search');");
        $this->commitRoutes("Route::get('/search', A::class);");

        $this->assertSame([], $this->found("Route::get('/search', B::class);"));
    }

    public function test_a_new_route_file_and_a_deleted_one_count_every_route(): void
    {
        $this->test('Post', "\$this->get('/posts');");
        $this->commitRoutes("Route::get('/posts', A::class);");

        unlink($this->dir.'/routes/web.php');
        $finder = new RouteTests(new Workspace($this->dir), new ProcessRunner($this->dir), $this->locator());

        $this->assertSame(['tests/Feature/PostTest.php'], array_keys($finder->testsFor([$this->changed(ChangedFile::DELETED)], 'HEAD')));
    }

    public function test_the_planner_runs_them_with_the_route_file_as_the_reason(): void
    {
        $this->test('ContactForm', "\$this->get(route('contact.form'));");
        $this->commitRoutes("Route::get('/contact', A::class)->name('contact.form');");
        file_put_contents($this->dir.'/routes/web.php', "<?php\n\nRoute::get('/contact', A::class)->name('contact.show');\n");

        $workspace = new Workspace($this->dir);
        $locator = $this->locator();
        $planner = new Planner($locator, null, [], null, true, new RouteTests($workspace, new ProcessRunner($this->dir), $locator));
        $plan = $planner->plan([$this->changed()], Scope::Standard);

        $target = $plan->get('tests:tests/Feature/ContactFormTest.php');
        $this->assertNotNull($target);
        $this->assertContains('routes/web.php', $target->triggeredBy);
    }
}
