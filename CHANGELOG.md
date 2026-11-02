# Changelog

## 0.8.0 - 2026-11-02

- **Message vs diff catches the collateral slip.** Until now the check stopped looking once the line the subject names had changed, so a find-and-replace of `max:255` that hit both `'name'` and its neighbour `'email'` passed under "Limit contact name to 250 chars", with a green receipt. Now the same short swap on a line of the same shape within 12 lines that the subject does not mention is flagged too (`kind: twin`): "The subject names `name` (line ...:10), but the same edit also went to the similar line 11, which it does not mention". A twin the subject also names is part of the claim; an added parameter or a wrapping call carried to sibling lines is not a swap. Reported by a reader on dev.to.
  - Measured before shipping: on 886 recent commits from five of our PHP repositories it added no flags (19 before and after); on their whole history, 2,332 commits, it added one (46 to 47), a variable rename in a refactor.

## 0.7.1 - 2026-10-31

- **The Stop hook checks the fix, not only the first attempt.** Until now it let Claude stop whenever Claude Code reported `stop_hook_active`, that is, on any stop after a first block, so a half-done fix (the controller updated, the tests still calling the old route name) went through unchecked. Now it verifies again whenever the tree changed since the last verdict and blocks again on `fail`. It still lets Claude stop when nothing changed since the block, and after 5 blocks in one continuation, below Claude Code's own cap of 8 consecutive continuations.

## 0.7.0 - 2026-10-31

- **Your code style, not a preset.** The style check now runs the tool the project has a config for: `pint.json` (Pint), `.php-cs-fixer.php` / `.php-cs-fixer.dist.php` (PHP-CS-Fixer), `phpcs.xml` and its variants (PHP_CodeSniffer) or `ecs.php` (Easy Coding Standard), several if there are several. Pint's `laravel` preset is used only when the project has no style config at all. Until now Pint ran whenever it was installed, which every new Laravel app is, so a project that keeps its rules in `.php-cs-fixer.php` got new files failed against someone else's style.
  - A config without its tool (`phpcs.xml` but no `vendor/bin/phpcs`) is a skip with that reason, never a fallback to Pint.
  - As before, only rules the change made a file break more often fail the run; PHP_CodeSniffer warnings never do. PHPCS and ECS violations carry their line and message.
  - The next step names the tool's own fixer: `vendor/bin/php-cs-fixer fix`, `vendor/bin/phpcbf`, `vendor/bin/ecs check --fix`.
  - New `style` option: `'auto'` (default), a tool name, a list or `false`.
- **Custom checks.** `commands` in the config adds the project's own tools to the verdict: Rector, Deptrac, Psalm, ESLint, a composer script. Each has `run` (a shell command line or an argument list, with `{files}` for the matched changed files), `when` (path prefixes or globs), `scope`, `required` and `timeout`. The exit code is the verdict; a failure brings the last 20 lines of output and the command that reproduces it.
- **`php artisan ai:verify:doctor`** shows what `ai:verify` found in the project and why: test runner, code style tool and the file that chose it, PHPStan config or Larastan fallback, custom commands, ignored paths, hooks, Boost MCP tools, and any problem in the setup. `--json` for agents. `php artisan about` lists the style tool too.
- **More options:** `ignore` leaves generated files out of every check (`_ide_helper.php`, `public/build/`); `test_args` passes arguments to Pest or PHPUnit (`--parallel`); `timeouts` gives a slow check its own limit (`['tests' => 600]`).
- Config warnings (an unknown style tool, a command without `run`) appear as `warning` events and in a new `warnings` list of the JSON envelope.
- Compatibility: the style target keeps the tool name as its id (`pint`), but its `check` is now `style` instead of `pint`. `checks.pint => false` still turns the style check off; `checks.style` is the new name. In `kinds`, a pattern with `?` or `[` is now a glob too, not only one with `*`.
- `SECURITY.md`: how to report a vulnerability and what the package runs. The test workflow pins its actions to commit SHAs and has read-only permissions.

## 0.6.0 - 2026-10-17

- **MCP tools in Laravel Boost.** With Boost installed, its MCP server now offers `ai-verify`, `ai-graph` and `ai-verify-receipt` next to its own tools, so agents can ask for a verdict, a graph node or an impact report as a tool call instead of a shell command. They return the same JSON as the commands with `--json`; a `fail` verdict is an answer, not a tool error. Boost runs each call in a fresh process, so the tools always see the agent's latest edits. `ai-verify` takes `timeout` for long suites (Boost's default is 180 seconds).
- Registered through Boost's own `boost.mcp.tools.include` list, only when `laravel/mcp` is installed; nothing changes without Boost. Opt out with `AI_VERIFY_MCP_BOOST=false` or `ai-verify.mcp.boost`.
- The guideline and the agent skill name the tools.

## 0.5.2 - 2026-10-15

- **Readable under laravel/pao.** New Laravel 13 apps ship pao, which strips arrows from what an agent reads, so "Assertion count dropped: 2 → 1" reached agents as "2 1". Messages that carry data now use ASCII ("Assertion count dropped from 2 to 1", "standard -> broad"), as do the hook's next-step lines and the `ai:graph` uses / used-by markers.
- `ai:verify:install` no longer suggests `--hook` or `--git-hook` when that hook is already installed.
- README: the quick start installs both hooks (`--hook --git-hook`), so the message vs diff check is on from the start; requirements sit above the first command; Larastan is listed as the optional install that enables the PHPStan check; each clone needs `--git-hook` once, since `.git/hooks` is not versioned; the `receipt` event is in the event list; a short GIF of the message vs diff warning.

## 0.5.1 - 2026-10-14

- Docs only: the message vs diff notes now say where the mismatched commit came from (Claude Code, while we tested the Action in a test app), that Blade views are searched, that the hook sees messages given with `-m` or `-F` (an editor message is checked in CI), and the exact sample (937 commits, 23 flagged).

## 0.5.0 - 2026-10-14

- **Message vs diff.** A receipt proves the checks passed, not that the commit does what its message says. While we tested the GitHub Action, Claude Code committed "Limit contact name to 250 chars" in our test app, and the diff limited the email. The tests passed and the commit was stamped.
  - The commit subject is now read against the diff. A subject word that names a line in a changed code file is flagged when that line is unchanged and a line of the same shape next to it changed (`'name' => [...]` left alone, `'email' => [...]` edited). A number in the subject that no added line contains is flagged too.
  - Only code files are searched (PHP including Blade, JS/TS and other languages), not prose, JSON, YAML, Twig, HTML or tests. Merges, reverts and commits of more than 25 files are skipped. Across 937 commits from five of our PHP repositories it flagged 23 (2.5%).
  - The `prepare-commit-msg` hook prints the warning on stderr before the commit exists.
  - `ai:verify:receipt --range` adds `claims` per commit, a warning annotation on the changed line with `--github`, and a "Message vs diff" list in the job summary. `--claims=warn|fail|off` sets how strict it is (default `warn`).
  - GitHub Action: new `claims` input. It is passed on only when the installed package supports it, so the Action still works with 0.4.
- The agent skill and guidelines tell agents what to do when the hook warns.

## 0.4.3 — 2026-10-09

- The `action.yml` description is now under GitHub Marketplace's 125-character limit, so the Action can be listed there. Use `uses: semitexa/laravel-ai-verify@v0.4.3`.

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
