<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Verify\ChangedFileClassifier;
use Semitexa\LaravelAiVerify\Verify\FileKind;

final class ChangedFileClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, FileKind}> */
    public static function paths(): iterable
    {
        yield 'controller' => ['app/Http/Controllers/PostController.php', FileKind::Controller];
        yield 'form request' => ['app/Http/Requests/StorePostRequest.php', FileKind::FormRequest];
        yield 'api resource' => ['app/Http/Resources/PostResource.php', FileKind::ApiResource];
        yield 'middleware' => ['app/Http/Middleware/EnsureAdmin.php', FileKind::Middleware];
        yield 'model' => ['app/Models/Post.php', FileKind::Model];
        yield 'policy' => ['app/Policies/PostPolicy.php', FileKind::Policy];
        yield 'job' => ['app/Jobs/PublishPost.php', FileKind::Job];
        yield 'event' => ['app/Events/PostPublished.php', FileKind::Event];
        yield 'listener' => ['app/Listeners/NotifySubscribers.php', FileKind::Listener];
        yield 'command' => ['app/Console/Commands/Prune.php', FileKind::Command];
        yield 'provider' => ['app/Providers/AppServiceProvider.php', FileKind::Provider];
        yield 'bootstrap/app.php' => ['bootstrap/app.php', FileKind::Provider];
        yield 'view component' => ['app/View/Components/Alert.php', FileKind::ViewComponent];
        yield 'livewire' => ['app/Livewire/Counter.php', FileKind::Livewire];
        yield 'contract dir' => ['app/Contracts/PaymentGateway.php', FileKind::Contract];
        yield 'contract by name' => ['app/Billing/GatewayInterface.php', FileKind::Contract];
        yield 'plain app class' => ['app/Support/Money.php', FileKind::AppClass];
        yield 'route' => ['routes/web.php', FileKind::Route];
        yield 'config' => ['config/app.php', FileKind::Config];
        yield 'migration' => ['database/migrations/2026_01_01_000000_create_posts_table.php', FileKind::Migration];
        yield 'factory' => ['database/factories/PostFactory.php', FileKind::Factory];
        yield 'seeder' => ['database/seeders/DatabaseSeeder.php', FileKind::Seeder];
        yield 'blade' => ['resources/views/posts/index.blade.php', FileKind::Blade];
        yield 'lang php' => ['lang/en/auth.php', FileKind::Lang];
        yield 'lang json' => ['lang/uk.json', FileKind::Lang];
        yield 'test' => ['tests/Feature/PostTest.php', FileKind::Test];
        yield 'test case' => ['tests/TestCase.php', FileKind::TestFixture];
        yield 'pest config' => ['tests/Pest.php', FileKind::TestFixture];
        yield 'composer' => ['composer.json', FileKind::Composer];
        yield 'lock' => ['composer.lock', FileKind::Composer];
        yield 'json' => ['package.json', FileKind::Json];
        yield 'frontend' => ['resources/js/app.ts', FileKind::Frontend];
        yield 'markdown' => ['README.md', FileKind::NonPhp];
        yield 'modular controller' => ['Modules/Blog/Http/Controllers/PostController.php', FileKind::Controller];
        yield 'php outside app' => ['scripts/deploy.php', FileKind::OtherPhp];
    }

    #[DataProvider('paths')]
    public function test_classifies_laravel_paths(string $path, FileKind $expected): void
    {
        $this->assertSame($expected, (new ChangedFileClassifier)->classify($path));
    }

    public function test_overrides_win_over_built_in_rules(): void
    {
        $classifier = new ChangedFileClassifier([
            'app/Billing/' => 'contract',
            'app/Domain/*/Repositories/*' => 'service_typo_is_ignored',
        ]);

        $this->assertSame(FileKind::Contract, $classifier->classify('app/Billing/Stripe.php'));
        $this->assertSame(FileKind::AppClass, $classifier->classify('app/Domain/Blog/Repositories/PostRepo.php'));
    }
}
