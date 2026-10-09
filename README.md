# Laravel AI Verify: stop AI agents from faking "done"

[![Tests](https://github.com/semitexa/laravel-ai-verify/actions/workflows/tests.yml/badge.svg)](https://github.com/semitexa/laravel-ai-verify/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/semitexa/laravel-ai-verify.svg)](https://packagist.org/packages/semitexa/laravel-ai-verify)
[![PHP](https://img.shields.io/packagist/php-v/semitexa/laravel-ai-verify.svg)](https://packagist.org/packages/semitexa/laravel-ai-verify)
[![License](https://img.shields.io/packagist/l/semitexa/laravel-ai-verify.svg)](LICENSE)

**AI code verification for Laravel.** Claude Code, Cursor, Codex, Copilot or Gemini says the
change is done. `php artisan ai:verify` checks whether it is.

It runs only the tests, Larastan, Pint and Laravel boot checks the diff actually needs. It fails
the change if the agent deleted, skipped or weakened a test to get green. It returns one verdict the
agent must act on: `pass`, `fail` or `incomplete`. One more command writes the instructions into
`AGENTS.md` and `CLAUDE.md`. For Claude Code it also adds a Stop hook, so the agent cannot finish
while verification fails.

```bash
composer require --dev semitexa/laravel-ai-verify
php artisan ai:verify:install --hook
```

How it works: `ai:verify` reads what changed (uncommitted work, a git ref, or a file list). It
works out the smallest set of checks that can vouch for that change and runs them with timeouts.
The verdict comes as streamed NDJSON for agents, a JSON envelope or terminal text.

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

### Tell your agents

An agent only uses a tool it knows about. One command puts the instructions where each agent looks:

```bash
php artisan ai:verify:install          # AGENTS.md + CLAUDE.md, the files of agents you already use, and skills
php artisan ai:verify:install --hook   # also: Claude Code may not finish while ai:verify fails
```

```text
  create    AGENTS.md — Codex, Cursor, Copilot, Amp, Jules, Zed and other AGENTS.md readers
  append    CLAUDE.md — Claude Code
  create    .claude/skills/ai-verify/SKILL.md — agent skill
  create    .claude/settings.json — Claude Code Stop hook: php "${CLAUDE_PROJECT_DIR}/artisan" ai:verify:hook
```

| Agent | Where the instructions go |
|---|---|
| Codex, Cursor, Copilot, Amp, Jules, Zed, … | `AGENTS.md` (always) |
| Claude Code | `CLAUDE.md` (always), `.claude/skills/ai-verify/`, and with `--hook` a Stop hook |
| Gemini CLI | `GEMINI.md`, if it exists |
| GitHub Copilot | `.github/copilot-instructions.md`, if it exists |
| JetBrains Junie | `.junie/guidelines.md`, if it exists |
| Cursor | `.cursor/rules/ai-verify.mdc`, if `.cursor/` exists |
| Windsurf | `.windsurf/rules/ai-verify.md`, if `.windsurf/` exists |
| Agents that read `.agents/skills` | `.agents/skills/ai-verify/`, if `.agents/` exists |

- **Idempotent.** Each file gets one `<!-- ai-verify:start -->…<!-- ai-verify:end -->` block,
  which is updated in place on re-run. The rest of the file is left alone, including Laravel
  Boost's `<laravel-boost-guidelines>` block. Boost rewrites only its own block, so the two coexist.
- **More agents.** `--all` also creates the files for agents the project doesn't use yet.
- **Undo.** `--remove` takes everything out again, and `--dry-run` shows the plan first.
- **Commit the files.** Every teammate's agent then gets the same instructions.

**Why the hook.** An agent can ignore an instruction, but it can't ignore a Stop hook. With
`--hook`, every time Claude Code is about to finish, `ai:verify` checks the uncommitted work. If
the verdict is `fail`, Claude is sent back with the violations and fixing commands instead of
stopping. To keep it from getting in the way:

- It never blocks twice in a row (Claude Code's `stop_hook_active`).
- It doesn't re-run on a working tree it has already checked, so answering a question in a dirty
  repo costs nothing.
- `AI_VERIFY_HOOK_SCOPE=minimal` keeps it to a few seconds.

**With [Laravel Boost](https://github.com/laravel/boost)**, you can instead tick
`semitexa/laravel-ai-verify` in `boost:install`'s third-party list, or add it to `"packages"` in
`boost.json`. Boost then ships the same guideline and the `ai-verify` skill, and
`ai:verify:install` steps aside to avoid duplicates. `--hook` still applies.

`php artisan about` shows whether the instructions and the hook are in place.

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
| controller, middleware, form request, `routes/*` | `route:list` in a fresh process, then every `route('…')` / `to_route('…')` / `redirect()->route('…')` must name an existing route (the whole project after a routes change) |
| Blade view, mailable, notification, Livewire, view component | the same route-name check on the changed files |
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
{"kind":"summary","source":"dirty (default)","requested_scope":"standard","effective_scope":"standard","changed_files":1,"targets":4,"tool":"semitexa/laravel-ai-verify 0.3.2"}
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

## FAQ

### How do I stop Claude Code (or Cursor, Codex) from saying "done" when the tests fail?

Rules in `CLAUDE.md` or `AGENTS.md` help, but agents don't always follow them. Run
`php artisan ai:verify:install --hook`. The rules go into the instruction files, and Claude Code
gets a Stop hook: when the agent tries to finish, `ai:verify` checks the uncommitted work, and on
`fail` Claude is sent back with the violations. Other agents get the same rules and the same
command to run.

### How do I stop an AI agent from deleting, skipping or weakening tests to make them pass?

Agents sometimes make a red suite green by editing the tests instead of the code. Researchers call
this "reward hacking". Every changed test file is compared with the base ref. The run fails when it
loses tests or assertions, or gains `skip()`, `todo()`, `only()` or `markTestSkipped`. Commenting
out an assertion counts as removing it. If a test change is genuinely intended, the agent has to
say so explicitly with `// verify:accept-test-change <reason>`, which shows up in review.

### What should go in AGENTS.md / CLAUDE.md for a Laravel project?

At minimum, the agent needs to know how to verify its work and what not to do to pass. See
[the guideline this package installs](resources/boost/guidelines/core.blade.php). Run
`php artisan ai:verify:install` and it is written into `AGENTS.md` and `CLAUDE.md`, and into
`GEMINI.md`, Copilot, Junie, Cursor and Windsurf files if you use them, as an idempotent block next
to your own rules and Laravel Boost's.

### How do I run only the tests affected by a change in Laravel?

`php artisan ai:verify --git-ref=main` (or `php artisan ai:graph --impact=app/Models/Post.php`
to just list them). Tests are selected through a project graph: tests that reference a changed
class, request a route its controller handles, render a view it touches, or use its factory. It
needs no coverage driver and no recorded baseline, unlike coverage-based test impact analysis.

### Does it replace AI code review?

No. It checks things a reviewer shouldn't have to: the code parses, the views compile, the app
boots, migrations run, static analysis and style are clean on the changed lines, the related tests
pass, and the tests weren't tampered with. Review time then goes on design and intent, and
"it doesn't even run" is off the table.

### Larastan and Pint fail on my legacy code. Will the agent drown in errors?

No. Only problems on the lines this change touched fail the run. PHPStan errors elsewhere in the
touched files, and Pint fixers the file already needed, are reported as `pre-existing` warnings.

### Does it work with Laravel Boost, Pest 4/5, PHPUnit, Octane, Horizon?

Yes. It ships a Boost guideline and skill. It runs Pest or PHPUnit and reads their JUnit output.
After a change it tells the agent when to run `queue:restart`, `horizon:terminate` or
`octane:reload`.

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
