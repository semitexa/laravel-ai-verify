<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

/**
 * What a changed path *is* in Laravel terms. Classification is path-only:
 * it never opens the file, so it is instant and works for deleted files too.
 */
enum FileKind: string
{
    case Controller = 'controller';
    case FormRequest = 'form_request';
    case ApiResource = 'api_resource';
    case Middleware = 'middleware';
    case Model = 'model';
    case Policy = 'policy';
    case Job = 'job';
    case Event = 'event';
    case Listener = 'listener';
    case Observer = 'observer';
    case Mail = 'mail';
    case Notification = 'notification';
    case Command = 'command';
    case Provider = 'provider';
    case ViewComponent = 'view_component';
    case Livewire = 'livewire';
    case Contract = 'contract';
    case AppClass = 'app_class';
    case Route = 'route';
    case Config = 'config';
    case Migration = 'migration';
    case Factory = 'factory';
    case Seeder = 'seeder';
    case Blade = 'blade';
    case Lang = 'lang';
    case Test = 'test';
    case TestFixture = 'test_fixture';
    case Composer = 'composer';
    case Json = 'json';
    case OtherPhp = 'php_other';
    case Frontend = 'frontend';
    case NonPhp = 'non_php';

    public function isPhp(): bool
    {
        return ! in_array($this, [self::Blade, self::Composer, self::Json, self::Frontend, self::NonPhp], true);
    }

    /** Production PHP code under app/ — what static analysis and test mapping are about. */
    public function isAppCode(): bool
    {
        return in_array($this, [
            self::Controller, self::FormRequest, self::ApiResource, self::Middleware, self::Model,
            self::Policy, self::Job, self::Event, self::Listener, self::Observer, self::Mail,
            self::Notification, self::Command, self::Provider, self::ViewComponent, self::Livewire,
            self::Contract, self::AppClass,
        ], true);
    }

    public function isTest(): bool
    {
        return $this === self::Test || $this === self::TestFixture;
    }

    /** Changes that ripple wide enough to bump standard scope to broad. */
    public function widensScope(): bool
    {
        return in_array($this, [self::Contract, self::Provider, self::Composer], true);
    }

    /** Code a long-running worker keeps in memory (queue workers, Octane, Horizon). */
    public function isLongLived(): bool
    {
        return in_array($this, [self::Job, self::Listener, self::Event, self::Mail, self::Notification, self::Observer], true);
    }
}
