# Security policy

## Supported versions

Security fixes go into the latest release of the newest minor line. Until 1.0, there are no backports: upgrade to the latest `0.x` release to get a fix.

| Version | Supported |
|---|---|
| latest 0.x release | yes |
| older releases | no |

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately on GitHub: **[Report a vulnerability](https://github.com/semitexa/laravel-ai-verify/security/advisories/new)**. Only the maintainers see the report, and the fix and its advisory are prepared there with you. If you can't use GitHub, email **support@semitexa.com** instead.

Either way, include:

- the affected version,
- what an attacker can do and under which conditions,
- steps or a minimal project that reproduces it.

You will get an answer within five working days. Once the problem is confirmed, the fix is released as soon as it is ready, and the changelog credits you unless you prefer otherwise.

## What the package runs

Knowing this helps tell a vulnerability from intended behaviour:

- `ai:verify` runs tools from the project's own `vendor/bin` (Pest or PHPUnit, Pint, PHP-CS-Fixer, PHP_CodeSniffer, ECS, PHPStan), `php artisan` boot probes and the commands listed in `config/ai-verify.php` under `commands`. Anyone who can change the project's code or config can already change what these run; treat it like `composer.json` scripts.
- `ai:verify:install --hook` adds a Stop hook to `.claude/settings.json`; `--git-hook` adds a block to `.git/hooks/prepare-commit-msg`. Both only call `php artisan` commands of this package.
- Child processes do not inherit variables loaded from `.env`, so tests do not run against the `.env` database.
- Config, route and event caches are written to temporary files, never to `bootstrap/cache`.
- Receipts are written to `storage/ai-verify/receipts`. Nothing is sent over the network.

Reports about any of these crossing their stated boundary are welcome.
