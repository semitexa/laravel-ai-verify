<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

/**
 * Path-only classification into Laravel kinds. First match wins.
 * Directory fragments are matched anywhere in the path, so modular layouts
 * (Modules/Blog/Http/Controllers/…, src/Domain/…/Models/…) classify too.
 */
final class ChangedFileClassifier
{
    private const FRONTEND = ['js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue', 'svelte', 'css', 'scss', 'sass', 'less'];

    /** @var array<string, FileKind> fragment => kind, checked in order */
    private const FRAGMENTS = [
        '/Http/Controllers/' => FileKind::Controller,
        '/Http/Requests/' => FileKind::FormRequest,
        '/Http/Resources/' => FileKind::ApiResource,
        '/Http/Middleware/' => FileKind::Middleware,
        '/Http/Livewire/' => FileKind::Livewire,
        '/Livewire/' => FileKind::Livewire,
        '/Models/' => FileKind::Model,
        '/Policies/' => FileKind::Policy,
        '/Jobs/' => FileKind::Job,
        '/Events/' => FileKind::Event,
        '/Listeners/' => FileKind::Listener,
        '/Observers/' => FileKind::Observer,
        '/Mail/' => FileKind::Mail,
        '/Notifications/' => FileKind::Notification,
        '/Console/Commands/' => FileKind::Command,
        '/Providers/' => FileKind::Provider,
        '/View/Components/' => FileKind::ViewComponent,
        '/Contracts/' => FileKind::Contract,
        '/Interfaces/' => FileKind::Contract,
    ];

    /**
     * @param  array<string, string>  $overrides  path prefix or glob => FileKind value, checked before the built-in rules
     */
    public function __construct(private readonly array $overrides = []) {}

    public function classify(string $path): FileKind
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');

        foreach ($this->overrides as $pattern => $kind) {
            $pattern = '/'.ltrim($pattern, '/');

            if ((str_contains($pattern, '*') ? fnmatch($pattern, $path) : str_starts_with($path, $pattern))
                && ($resolved = FileKind::tryFrom($kind)) !== null) {
                return $resolved;
            }
        }

        $base = basename($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (str_starts_with($path, '/tests/') || str_contains($path, '/tests/')) {
            return $ext === 'php' && str_ends_with($base, 'Test.php') ? FileKind::Test : FileKind::TestFixture;
        }

        if (str_ends_with($base, '.blade.php')) {
            return FileKind::Blade;
        }

        if ($base === 'composer.json' || $base === 'composer.lock') {
            return FileKind::Composer;
        }

        if ($ext === 'json') {
            return str_starts_with($path, '/lang/') ? FileKind::Lang : FileKind::Json;
        }

        if (in_array($ext, self::FRONTEND, true)) {
            return FileKind::Frontend;
        }

        if ($ext !== 'php') {
            return FileKind::NonPhp;
        }

        return match (true) {
            str_starts_with($path, '/routes/') => FileKind::Route,
            str_starts_with($path, '/config/') => FileKind::Config,
            str_contains($path, '/database/migrations/') || str_starts_with($path, '/database/migrations/') => FileKind::Migration,
            str_contains($path, '/database/factories/') || str_starts_with($path, '/database/factories/') => FileKind::Factory,
            str_contains($path, '/database/seeders/') || str_starts_with($path, '/database/seeders/') => FileKind::Seeder,
            str_starts_with($path, '/lang/') || str_starts_with($path, '/resources/lang/') => FileKind::Lang,
            // bootstrap/app.php wires middleware, routing and exceptions — it behaves like a provider.
            $path === '/bootstrap/app.php' || $path === '/bootstrap/providers.php' => FileKind::Provider,
            default => $this->classifyClass($path, $base),
        };
    }

    private function classifyClass(string $path, string $base): FileKind
    {
        foreach (self::FRAGMENTS as $fragment => $kind) {
            if (str_contains($path, $fragment)) {
                return $kind;
            }
        }

        if (str_ends_with($base, 'Interface.php') || str_ends_with($base, 'Contract.php')) {
            return FileKind::Contract;
        }

        return str_starts_with($path, '/app/') || str_starts_with($path, '/src/')
            ? FileKind::AppClass
            : FileKind::OtherPhp;
    }
}
