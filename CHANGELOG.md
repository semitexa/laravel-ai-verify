# Changelog

## 0.4.2 — 2026-10-09

- The GitHub Action now lives in this repository: `uses: semitexa/laravel-ai-verify@v0.4.2`. `semitexa/laravel-ai-verify-action` is archived. `action.yml` stays in the source archive: GitHub downloads an Action as that archive, so excluding it with `export-ignore` would hide it from GitHub.

## 0.4.1 — 2026-10-09

- Docs and metadata only: `ai:graph` gets its place in the description, keywords and README. The README hero now presents it as a code graph for agents, and two FAQ entries cover mapping a Laravel codebase for an AI agent and impact analysis.

## 0.4.0 — 2026-10-09

- **Receipts** (ported from Semitexa).
  - Every `ai:verify` run writes a receipt to `storage/ai-verify/receipts/`. It holds the verdict, hashes of each check, a sha256 per changed file, files that changed while the checks ran, the git tree id of the working state, and a digest.
  - `ai:verify:receipt` checks whether a receipt still holds. `--unread` lists runs nobody looked at.
- **Commit trailer.** `ai:verify:install --git-hook` adds a `prepare-commit-msg` hook. It adds `AI-Verify: pass rcpt-… tree=…` when a passing receipt covers exactly the committed tree. Otherwise it explains on stderr why there is no trailer, and it drops stale trailers on amend.
- **CI.**
  - `ai:verify:receipt --range=… --require=ai|all|none --github` checks each commit's trailer against its own tree.
  - `ai:verify --github` turns violations into PR diff annotations and writes a job summary.
  - GitHub Action: [semitexa/laravel-ai-verify-action](https://github.com/semitexa/laravel-ai-verify-action).

## 0.3.4 — 2026-10-09

- Route-name check: names a file guards with `Route::has('…')` are no longer reported, as in Laravel's default `welcome.blade.php` (`@if (Route::has('login'))`). An end-to-end agent run flagged these as false positives.

## 0.3.3 — 2026-10-09

- Stop hook: the "already verified this tree" cache now depends on the base commit, the package version and the config, not only on the uncommitted diff. Before, the same diff re-applied on a new commit reused a stale `pass`, and so did a package upgrade that added checks.
- Stop hook: it no longer waits on an open but empty stdin.

## 0.3.2 — 2026-10-09

- New check for references to route names that no longer exist: `route()`, `to_route()`, `redirect()->route()`, `URL::route()`, `redirectToRoute()`, `assertRedirectToRoute()`. It runs with the routes probe and reports `path:line` for each one.
  - After a `routes/*.php` change it scans the whole project, because a rename can break callers anywhere.
  - Otherwise it scans only the changed files. Blade views, mailables, notifications, Livewire and view components now trigger it too.
- Found by an end-to-end run. A fresh Claude Code session renamed `contact.create` to `contact.show`. The Stop hook ran, but no selected test referenced the old name, so the broken references passed unnoticed.

## 0.3.1 — 2026-10-09

- Docs only: README hero, badges and FAQ. The FAQ covers agents that report "done" while tests fail, deleted or skipped tests, AGENTS.md/CLAUDE.md setup for Laravel, and running only affected tests.
- Package description and keywords rewritten around these problems.

## 0.3.0 — 2026-10-09

- `ai:verify:install` writes the verify instructions where agents read them:
  - `AGENTS.md` and `CLAUDE.md`;
  - `GEMINI.md`, Copilot, Junie, Cursor and Windsurf files when the project uses those agents;
  - the `ai-verify` skill for Claude Code and `.agents`.
  It is idempotent, coexists with Laravel Boost's block, and supports `--all`, `--remove` and `--dry-run`.
- `--hook` adds a Claude Code Stop hook (`ai:verify:hook`). It keeps Claude working while `ai:verify` reports `fail`, respects `stop_hook_active`, and skips a working tree it has already verified.
- The human output of `ai:verify` suggests `ai:verify:install` when no agent knows about it yet.
- `php artisan about` gets an "Ai Verify" section.

## 0.2.0 — 2026-10-09

- **Breaking:** the commands are renamed to `ai:verify` and `ai:graph`, the names Semitexa itself uses. The internal migration probe is now `ai:verify:migration`.
- The Boost skill is renamed to `ai-verify`. Re-run `php artisan boost:install` to replace `semitexa-verify`.

## 0.1.0 — 2026-10-09

First release: a Laravel port of Semitexa's `ai:verify`.

- `semitexa:verify`: the change set can come from `--dirty` (the default), `--git-ref`, `--files` or `--diff-stdin`.
  - Changed files are classified into Laravel kinds, and checks are planned at three scopes: minimal, standard and broad.
  - Standard scope widens to broad automatically for providers, contracts, `bootstrap/app.php`, `composer.json` and large change sets.
  - Checks: `php -l`, a Blade compile check, JSON validity, Pint and PHPStan/Larastan on changed files only, Laravel boot probes (routes, config cache, full boot, events), a migration pretend-run, `composer validate`, and tests chosen through the project graph and naming conventions.
  - Only problems the change introduced fail the run: PHPStan errors on changed lines and newly needed Pint fixers.
  - Test-integrity guard: fails when a changed test file loses tests or assertions or gains skip, todo or only markers.
  - Coverage-gap verdict rule.
  - Restart hints for queue workers, Horizon, Octane and a stale `bootstrap/cache`.
  - Output as NDJSON, a JSON envelope (`semitexa.laravel-ai-verify/v1`) or human-readable text.
- `semitexa:graph`: a static and runtime project graph with an overview, route chains, class neighbourhoods and an `--impact` report.
- Laravel Boost guideline and skill, picked up automatically by `boost:install`.
