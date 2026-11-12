<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default scope
    |--------------------------------------------------------------------------
    |
    | minimal  — syntax, Blade compile, JSON, changed tests, test integrity
    | standard — + code style, PHPStan, related tests, Laravel boot probes per kind
    | broad    — + every boot probe and the whole test suite
    |
    */

    'scope' => env('AI_VERIFY_SCOPE', 'standard'),

    // Standard scope widens to broad when this many files changed.
    'broad_threshold' => 15,

    // Per-check wall-clock limit (seconds) and output cap (bytes).
    'timeout' => (int) env('AI_VERIFY_TIMEOUT', 120),
    'output_cap' => 4 * 1024 * 1024,

    // Longer limits for slow checks: style, phpstan, tests, artisan,
    // migration, composer, advisories, command. Example: ['tests' => 600]
    'timeouts' => [],

    /*
    |--------------------------------------------------------------------------
    | Ignored paths
    |--------------------------------------------------------------------------
    |
    | Changed files matching these are left out of every check: generated
    | files, built assets. Path prefix or glob, `*` crosses directories.
    |
    */

    'ignore' => [
        // '_ide_helper.php',
        // '_ide_helper_models.php',
        // 'public/build/',
    ],

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

    // Extra arguments for Pest / PHPUnit, e.g. ['--parallel'] or ['--testsuite=Unit,Feature'].
    'test_args' => [],

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    */

    'checks' => [
        'style' => true,
        'phpstan' => true,
        'migrations' => true,
        'advisories' => true, // composer audit when composer.json or composer.lock changes (needs network)
        'test_integrity' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Code style
    |--------------------------------------------------------------------------
    |
    | auto — the tools whose config the project has: pint.json (Pint),
    |        .php-cs-fixer(.dist).php (PHP-CS-Fixer), phpcs.xml(.dist)
    |        (PHP_CodeSniffer), ecs.php (Easy Coding Standard). With none of
    |        them, Pint's laravel preset when Pint is installed.
    | 'phpcs', 'pint,phpcs' or ['php-cs-fixer'] — exactly these tools.
    |
    | Only style the change introduced fails; what a file already had is
    | reported as a warning. `php artisan ai:verify:doctor` shows the choice.
    |
    */

    'style' => env('AI_VERIFY_STYLE', 'auto'),

    // Used only when the project has no phpstan.neon but has Larastan installed.
    'phpstan' => [
        'fallback_level' => 5,
        'memory_limit' => '1G',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom checks
    |--------------------------------------------------------------------------
    |
    | Your own commands, run when a changed file matches `when` (path prefix
    | or glob; empty = any change). The exit code is the verdict. `{files}`
    | becomes the matched changed files. A string runs through the shell, a
    | list is run as arguments. Make sure the command passes on your main
    | branch (or uses its baseline): unlike style and PHPStan, ai:verify cannot
    | tell an old failure from a new one here.
    |
    |   run      — command line or argv list (required)
    |   when     — patterns that trigger it, default: any change
    |   scope    — lowest scope that runs it: minimal | standard | broad (default standard)
    |   required — false makes "command not found" a skip instead of incomplete (default true)
    |   timeout  — seconds, default `timeout` above
    |
    */

    'commands' => [
        // 'rector' => [
        //     'run' => 'vendor/bin/rector process --dry-run --no-progress-bar {files}',
        //     'when' => ['app/', 'database/'],
        // ],
        // 'deptrac' => [
        //     'run' => 'vendor/bin/deptrac analyse --no-progress',
        //     'when' => ['app/'],
        //     'scope' => 'broad',
        // ],
        // 'eslint' => [
        //     'run' => 'npx eslint {files}',
        //     'when' => ['resources/js/*.js', 'resources/js/*.ts', 'resources/js/*.vue'],
        // ],
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
    | Claude Code Stop hook (php artisan ai:verify:install --hook)
    |--------------------------------------------------------------------------
    |
    | Scope used when Claude is about to finish. `minimal` keeps the hook to a
    | few seconds; `standard` also runs static analysis and related tests.
    |
    */

    'hook' => [
        'scope' => env('AI_VERIFY_HOOK_SCOPE', 'standard'),
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

    /*
    |--------------------------------------------------------------------------
    | MCP tools
    |--------------------------------------------------------------------------
    |
    | With Laravel Boost installed, ai-verify, ai-graph and ai-verify-receipt
    | are added to Boost's MCP server, so agents can call them as tools.
    | Set to false to keep them out of Boost.
    |
    */

    'mcp' => [
        'boost' => env('AI_VERIFY_MCP_BOOST', true),
    ],

];
