# Changelog

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
