---
name: ai-verify
description: Verify a Laravel change before calling it done, and orient in an unfamiliar Laravel codebase. Use after editing PHP, Blade, routes, config or migrations; when asked to "check", "verify" or "make sure it works"; before committing; or before changing code you have not read (what calls it, which tests cover it).
---

# Verify Laravel changes with ai:verify

## The loop

1. Make a coherent change. That can be one fix or one feature slice; it doesn't have to be every file you will eventually touch.
2. Run `php artisan ai:verify`. With no options it verifies uncommitted work: staged, unstaged and untracked files.
3. Read the final line, `{"kind":"verdict","verdict":...}`.
4. On `fail`, fix the `violation` events in order and go back to step 2. Each violation has `path`, `line`, `rule`, `message` and sometimes a `tip`, such as `vendor/bin/pint app/Foo.php`.
5. On `incomplete`, don't claim success. Check `unchecked_files` (nothing read those files) and any `result` with `status: incomplete` (timeout, crash, or a required tool missing). Fix the cause, or tell the user exactly what could not be verified.
6. On `pass`, follow any `restart` hints (`queue:restart`, `octane:reload`, `config:clear`) before testing in a browser.

## Choosing the input

| Situation | Command |
|---|---|
| Normal work | `php artisan ai:verify` |
| Whole branch before a PR | `php artisan ai:verify --git-ref=main` |
| Specific files | `php artisan ai:verify --files=app/Models/Post.php,routes/web.php` |
| Fast inner loop | `php artisan ai:verify --scope=minimal` |
| Risky or cross-cutting change | `php artisan ai:verify --scope=broad --impact` |

Scope widens to `broad` automatically for providers, contracts, `bootstrap/app.php`, `composer.json` and large change sets. Read the `expansion` events to see why.

## Rules

- Never delete, skip (`->skip()`, `markTestSkipped`), `->only()`, or hollow out tests to get a pass. The `test_integrity` check compares changed test files with git HEAD and fails on any of these.
- If removing a test is genuinely right, for example because it moved elsewhere, add `// verify:accept-test-change <reason>` to the test file and say so to the user.
- `pre-existing` / `severity: warning` violations were already there before your change. You may mention them, but don't fix unrelated code unless asked.

## Orientation with ai:graph

- `php artisan ai:graph`: routes and their actions, the most-depended-on classes, and code that no test reaches.
- `php artisan ai:graph posts.show` or `/posts/{post}`: controller@method, FormRequest, middleware, views, models (with table and policy), and the tests that hit the route.
- `php artisan ai:graph "App\Models\Post"`: what the class uses, what uses it, and which tests cover it.
- `php artisan ai:graph --impact=app/Models/Post.php`: blast radius (low/medium/high) and the tests to run.

Add `--json` for machine-readable output. It is the default when stdout is not a terminal.
