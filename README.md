# Laravel AI Verify

**One Artisan command that tells an AI coding agent whether its change actually works.**

`ai:verify` reads what changed (uncommitted work, a git ref, or a file list), works out the
smallest set of checks that can vouch for that change, runs them with timeouts, and returns one
verdict: `pass`, `fail` or `incomplete`. The verdict comes as streamed NDJSON, a JSON envelope or terminal text.

```text
$ php artisan ai:verify

  ai:verify · 1 changed file(s) · scope standard · 5 check(s) · source dirty (default)

  ✓ syntax:app/Http/Controllers/PostController.php 39ms — No syntax errors
  ✓ pint 515ms — Pint: 1 file(s) follow the project style
  ✓ artisan:routes 250ms — Route table loaded (routes, controllers, middleware aliases)
  ✗ phpstan 3.2s — 1 error(s) introduced by this change; first: app/Http/Controllers/PostController.php:24 Relation 'comments' is not found in App\Models\Post model.
      › app/Http/Controllers/PostController.php:24 Relation 'comments' is not found in App\Models\Post model.
  ✗ tests:tests/Feature/PostTest.php 1.1s — Tests\Feature\PostTest::test_show_renders_a_post — Expected response status code [200] but received 500. …
      › app/Http/Controllers/PostController.php:24 … RelationNotFoundException: Call to undefined relationship [comments] on model [App\Models\Post].
  → php artisan test tests/Feature/PostTest.php — reproduce the failing test with full output

   FAIL  · 3 pass · 2 fail · 0 skipped · 0 incomplete
  5 check(s) over 1 changed file(s); every file was read by at least one check
```

Note that this run did **not** execute the whole suite and did **not** run PHPStan on the whole
app. It followed the change. The edited controller handles `GET /posts/{post}`, and
`tests/Feature/PostTest.php` requests that URI, so that is the test that runs.

### A real catch

While the demo app for this README was being written, the RSS view started with
`<?xml version="1.0"?>`. That is valid XML, but Blade compiles it into PHP that doesn't parse.
`ai:verify` flagged the view, the failing `FeedTest`, and the controller line that
rendered it. The obvious "fix" was `{!! '<?xml … ?>' !!}`. It still failed, because the `?>`
inside the string closes PHP mode in the compiled template. The run went green only after the
real fix (`'<'.'?xml … ?'.'>'`). Without the check, an agent would most likely have reported that
first fix as done.

This package is a Laravel port of `ai:verify` from **[Semitexa](https://semitexa.com)**, a PHP
framework built so that AI agents can work on it safely. Semitexa describes its own structure and
checks every change. This package brings the "checks every change" half of that to Laravel.

---

## Why

Coding agents say "done" when the code looks right. Giving them a way to *check* that is harder
than it sounds:

- **The full test suite** is slow, so agents skip it or run one test by hand.
- **`pest --dirty`** runs only the test files that changed, not the tests for the code that changed.
- **Pest's `--tia`** is precise but needs a coverage driver and a recorded baseline.
- **PHPStan and Pint on a whole legacy app** bury the agent in errors it didn't cause.
- **Many Laravel failures never show up in a unit test.** A closure in `config/`, a broken
  `@forelse`, a typo in a migration, or a provider that no longer boots all slip past them.

`ai:verify` handles all of this with one command and a fixed contract:

| It does | So that |
|---|---|
| Classifies each changed file into a Laravel kind (controller, model, migration, Blade view, route file, config, provider, listener, …) | Each kind gets the checks that can actually catch its failures |
| Picks tests through a **project graph** (tests that reference the class, request a route it handles, render a view it touches, use its factory), plus naming conventions | It runs the tests that matter, not the whole suite |
| Boots Laravel in a fresh process for the subsystem you touched (`route:list`, `config:cache`, `about`, `event:list`) | Boot-time breakage is caught before a browser hits it |
| Runs migrations' `up()`/`down()` in **pretend** mode | A typo in a migration fails here, not in production |
| Compiles changed Blade views with your app's compiler and lints the result | An unclosed `@if` or `@forelse` is reported against the `.blade.php` file |
| Blames only what **you** introduced: PHPStan errors on unchanged lines and Pint fixers the file already needed are reported as `pre-existing` and don't fail the run | Legacy debt doesn't block an agent's correct change |
| Guards **test integrity**: it fails if a changed test file lost tests or assertions, or gained `skip()`/`todo()`/`only()` | "Make the suite green by deleting the failing test" doesn't pass |
| Applies a **coverage gap** rule: `pass` requires at least one check to have read at least one changed file | "Green" never means "nothing looked at it" |
| Gives restart hints: `queue:restart`, `octane:reload`, stale `bootstrap/cache` | The agent knows that a passing verify doesn't mean running workers have the new code |

## Install

```bash
composer require --dev semitexa/laravel-ai-verify
```

Requires PHP 8.3+ and Laravel 12 or 13. Larastan and Pint are used when the project has them.
Pest or PHPUnit is detected automatically.

### Tell your agent

If you use **[Laravel Boost](https://github.com/laravel/boost)**, re-run `php artisan boost:install`
and tick `semitexa/laravel-ai-verify` in the third-party list. In non-interactive runs, add it to
`"packages"` in `boost.json`. The guideline goes into `CLAUDE.md`/`AGENTS.md`, and the
`ai-verify` skill is installed for every agent that supports skills.

Otherwise, add this to `CLAUDE.md`, `AGENTS.md` or `.cursor/rules`:

```markdown
## Verifying changes
After every coherent change, run `php artisan ai:verify` and read the last line (`"kind":"verdict"`).
- `pass`: done. `fail`: fix the reported violations (path:line) and re-run.
- `incomplete`: something could not be checked; read `unchecked_files` and the `incomplete` results. Never report this as done.
- Never delete, skip or weaken a test to get a pass; if a test change is intentional, add `// verify:accept-test-change <reason>` to the file.
Use `php artisan ai:graph <class|route|view>` to see what a class touches and which tests cover it before editing.
```

## Usage

```bash
php artisan ai:verify                          # uncommitted work: staged, unstaged, untracked (default)
php artisan ai:verify --git-ref=main           # everything since main, plus untracked files
php artisan ai:verify --files=app/Models/Post.php,routes/web.php
git diff --name-status HEAD~3 | php artisan ai:verify --diff-stdin

php artisan ai:verify --scope=minimal          # seconds: syntax, Blade, JSON, changed tests
php artisan ai:verify --scope=broad            # every boot probe + the whole suite
php artisan ai:verify --impact                 # add a blast-radius report from the graph
```

Exit code: `0` for `pass`/`skipped`, `1` for `fail`/`incomplete`.

### Scopes

| Scope | Runs |
|---|---|
| `minimal` | `php -l` per PHP file, Blade compile, JSON validity, changed test files, test integrity |
| `standard` (default) | + Pint and PHPStan on the changed files, related tests from the graph and naming, Laravel probes per kind, migration pretend-run |
| `broad` | + every Laravel probe and the whole test suite |

`standard` widens to `broad` on its own when a contract, a service provider, `bootstrap/app.php` or
`composer.json` changes, or when 15 or more files change. Each widening is reported as an
`expansion` event, with the reason.

### What runs for what

| Changed | Checks (standard scope) |
|---|---|
| any PHP file | `php -l`, Pint (only fixers new to this change fail the run) |
| app code, factories, seeders, migrations | PHPStan/Larastan on those files (only errors on changed lines fail the run) |
| controller, middleware, form request, `routes/*` | `route:list` in a fresh process |
| `config/*` | `config:cache` into a temp file (catches closures and unserialisable values) |
| provider, `bootstrap/app.php` | `about` (full boot), routes and events probes, then broad scope |
| event, listener | `event:list` |
| migration | `up()` + `down()` inside `DB::pretend()` |
| `*.blade.php` | compile with the app's Blade compiler, then `php -l` on the output |
| `*.json`, `lang/*.json` | JSON validity |
| `composer.json` | `composer validate`, then broad scope |
| `tests/**/*Test.php` | runs itself, plus a test-integrity check against the base ref |
| `tests/TestCase.php`, `tests/Pest.php`, fixtures | the nearest test directory, or the whole suite |
| any source file | tests reachable within 4 graph hops, plus tests whose name contains the class name |

Above `max_test_targets` (25) related test files, the whole suite runs once instead.

## Output for agents

When stdout is not a terminal, which is how agents run commands, the output is NDJSON: one event
per line, results streamed as each check finishes, and the verdict always last.

```json
{"kind":"summary","source":"dirty (default)","requested_scope":"standard","effective_scope":"standard","changed_files":1,"targets":4,"tool":"semitexa/laravel-ai-verify 0.2.0"}
{"kind":"file","file_kind":"listener","path":"app/Listeners/NotifySubscribers.php","status":"M"}
{"kind":"target","id":"artisan:events","check":"artisan","reason":"event → listener map resolved — listener changed","triggered_by":["app/Listeners/NotifySubscribers.php"],"required":true}
{"kind":"result","id":"artisan:events","check":"artisan","status":"pass","exit_code":0,"signal":"Event → listener map resolved","required":true,"duration_ms":230}
{"kind":"result","id":"phpstan","check":"phpstan","status":"pass","exit_code":0,"signal":"PHPStan: no errors in 1 file(s)","required":false,"duration_ms":2632}
{"kind":"restart","cmd":"php artisan queue:restart","why":"queued code changed — running queue workers still have the old version in memory"}
{"kind":"next","cmd":"git add -A && git commit","why":"the change set is verified"}
{"kind":"verdict","verdict":"pass","counts":{"pass":4,"fail":0,"skipped":0,"incomplete":0},"headline":"4 check(s) over 1 changed file(s); every file was read by at least one check","unchecked_files":[]}
```

Event kinds, in order: `summary`, `file`, `impact`, `expansion`, `warning`, `target`, then
`result` and its `violation` lines, then `restart`, `next` and `verdict`. A violation carries
`path`, `line`, `rule`, `message`, `severity` and, when available, a `tip` with the command that fixes it.

`--json` prints one envelope (schema `semitexa.laravel-ai-verify/v1`) with the same data plus
`files`, `targets`, `results`, `violations`, `counts` and `unchecked_files`. `--human` forces the
terminal view.

### Verdict rules

1. No checks ran → `incomplete`.
2. Any check failed → `fail`.
3. A required check could not finish (timeout, crash, tool missing) → `incomplete`.
4. Every check was skipped → `skipped`.
5. Otherwise → `pass`. If no content-reading check reached a result on **any** changed file
   (for example, only a README changed), `pass` is downgraded to `incomplete`.

Files that no check read are always listed in `unchecked_files` and in the headline.

## `ai:graph`: orientation before editing

The same graph that selects tests is available directly:

```bash
php artisan ai:graph                                   # routes → actions, most-depended-on classes, code no test reaches
php artisan ai:graph posts.show                        # a route's chain: controller@method, request, middleware, views, models, tables, policies, tests
php artisan ai:graph "App\Models\Post"                 # what it uses, what uses it, which tests cover it
php artisan ai:graph --impact=app/Models/Post.php      # blast radius + the tests to run
php artisan ai:graph --json --full                     # every node and edge
```

```text
  route:GET /posts/{post}

  controller    App\Http\Controllers\PostController@show
  view          posts.show
  model         App\Models\Post table posts policy App\Policies\PostPolicy

  Tests 1
    tests/Feature/PostTest.php
```

The graph has two layers:

- **Static.** A tokenizer reads PHP and Blade. It never runs project code, so it works even when
  the app is half broken. It records which classes reference each other, extends/implements,
  views rendered (`view('posts.show')`), `@include`/`@extends`/`<x-component>`, tables created by
  migrations, model → factory, and tests → the classes they use.
- **Runtime.** It reads facts from the booted app: routes → controller@method, FormRequests,
  middleware; event → listeners; model → table, relations, policy. Tests are linked to the
  routes they request, by literal URI or route name.

Edges point from the thing that depends to the thing it depends on, so "what does this change
affect" is a walk backwards along them.

## Safety

- **Nothing is written to `bootstrap/cache`.** Config, route and event cache paths are redirected
  to temp files.
- **Child processes don't inherit `.env`.** Laravel copies `.env` into the process environment,
  and `phpunit.xml` `<env>` entries don't override variables that already exist. Without this
  step, tests started from inside Artisan would run against your `.env` database. Variables you
  set in the shell or in CI with a different value are kept.
- **Migrations run only in pretend mode.** The SQL is compiled, nothing is executed. If schema
  introspection needs a live database, the check is skipped, not failed.
- **Every check has a 120 s timeout and a 4 MB output cap.** Hitting either makes the result
  `incomplete`, never `pass`.
- **Agent output wrappers are bypassed.** `laravel/pao` is switched off for child processes.
  Results are read from JUnit XML and the tools' JSON formats, never from console text.

## Configuration

```bash
php artisan vendor:publish --tag=ai-verify-config
```

```php
return [
    'scope' => env('AI_VERIFY_SCOPE', 'standard'),
    'broad_threshold' => 15,
    'timeout' => 120,
    'test_depth' => 4,              // graph hops when looking for related tests
    'max_test_targets' => 25,       // above this, run the suite once
    'checks' => ['pint' => true, 'phpstan' => true, 'migrations' => true, 'test_integrity' => true],
    'phpstan' => ['fallback_level' => 5, 'memory_limit' => '1G'],   // fallback: Larastan without a phpstan.neon
    'graph' => ['paths' => ['app', 'routes', 'database', 'tests', 'bootstrap/app.php'], 'views' => 'resources/views'],
    'kinds' => ['src/Billing/Gateways/' => 'contract'],             // your own path → kind rules
];
```

## How it relates to other tools

| Tool | What it does | How this package uses it |
|---|---|---|
| **Laravel Boost** | Gives agents docs, schema and logs through MCP, plus guidelines | Complementary. This package ships a Boost guideline and skill, so `boost:install` teaches agents to verify. |
| **Pest / PHPUnit** | Run tests | Used as the runner. Results are read from JUnit XML. |
| **Pest `--tia`** | Coverage-based test selection | A different trade-off: the graph needs no coverage driver and no baseline. |
| **laravel/pao** | Compresses tool output for agents | Bypassed for child processes, so the structured reports stay parseable. |
| **Larastan, Pint** | Static analysis and code style | Run on the changed files only. Only problems introduced by the change fail the run. |

## Where it comes from

[Semitexa](https://semitexa.com) is a modular PHP framework with a Swoole-first runtime, designed
so that an AI coding agent can work on a codebase safely. It has a **project graph** to ask
"what does this change affect" instead of grepping, and **`ai:verify`**, which checks every
change and records what it checked.

This package ports that verification loop to Laravel: the same change classification, scoped
planning, coverage gap, test-integrity guard and verdict contract, mapped onto Laravel's
conventions and tooling. If you like this way of working, have a look at how far it goes when a
framework is designed around it: **[semitexa.com](https://semitexa.com)**.

## Contributing

```bash
composer install
composer test
```

Issues and pull requests are welcome at [github.com/semitexa/laravel-ai-verify](https://github.com/semitexa/laravel-ai-verify).

## License

MIT. See [LICENSE](LICENSE).
