<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default scope
    |--------------------------------------------------------------------------
    |
    | minimal  — syntax, Blade compile, JSON, changed tests, test integrity
    | standard — + Pint, PHPStan, related tests, Laravel boot probes per kind
    | broad    — + every boot probe and the whole test suite
    |
    */

    'scope' => env('AI_VERIFY_SCOPE', 'standard'),

    // Standard scope widens to broad when this many files changed.
    'broad_threshold' => 15,

    // Per-check wall-clock limit (seconds) and output cap (bytes).
    'timeout' => (int) env('AI_VERIFY_TIMEOUT', 120),
    'output_cap' => 4 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Test selection
    |--------------------------------------------------------------------------
    |
    | Related tests are found through the project graph (tests that reference
    | a changed class, request a route it handles, render a view it touches…)
    | up to `test_depth` hops away, plus by naming convention. Above
    | `max_test_targets` files, the whole suite runs once instead.
    |
    */

    'tests_path' => 'tests',
    'test_depth' => 4,
    'max_test_targets' => 25,

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    */

    'checks' => [
        'pint' => true,
        'phpstan' => true,
        'migrations' => true,
        'test_integrity' => true,
    ],

    // Used only when the project has no phpstan.neon but has Larastan installed.
    'phpstan' => [
        'fallback_level' => 5,
        'memory_limit' => '1G',
    ],

    /*
    |--------------------------------------------------------------------------
    | Project graph
    |--------------------------------------------------------------------------
    */

    'graph' => [
        'enabled' => true,
        'paths' => ['app', 'routes', 'database', 'tests', 'bootstrap/app.php'],
        'views' => 'resources/views',
        'impact_depth' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Kind overrides
    |--------------------------------------------------------------------------
    |
    | Path prefix or glob => kind, checked before the built-in Laravel rules.
    | Example: 'src/Billing/Gateways/' => 'contract'
    |
    */

    'kinds' => [],

];
