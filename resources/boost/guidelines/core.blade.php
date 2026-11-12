## Verifying changes (semitexa/laravel-ai-verify)

- After every coherent change, run `php artisan ai:verify` before reporting the work as done. It plans and runs only the checks the change needs: syntax, Blade compile, the project's code style tool, Larastan, Laravel boot probes, migration pretend-run, the project's custom commands, and the related tests.
- Read the last NDJSON line, `{"kind":"verdict",...}`:
    - `pass`: the change set is verified, except for any check listed in `gaps`. Name those checks when you report the result.
    - `fail`: fix each `violation` (it has `path`, `line`, `message` and often a `tip` with the fixing command), then re-run.
    - `incomplete`: something could not be verified. Read `unchecked_files` and the `incomplete` results. Do not report the task as done.
- When you change `composer.json` or `composer.lock`, the run includes `composer audit`. An advisory on a package you added or moved fails it: move the package to a fixed version. Advisories on packages you did not touch are warnings; mention them in your report, do not update them unasked.
- `ai:verify` does not look at indexes. When you add a query that filters or sorts by a column, check that the column has one (`php artisan db:table <table>`), and add it in a new migration if not.
- Violations marked `severity: warning` or `pre-existing` were already in the code before the change. They do not fail the run.
- Never delete, skip or weaken a test to get a `pass`; the test-integrity check fails it. If a test change is intentional, add `// verify:accept-test-change <reason>` to the test file.
- Every run writes a receipt (the `receipt` event). Before you say a change is verified, `php artisan ai:verify:receipt` must report `holds`: the run passed and nothing changed since.
- Commit exactly the state you verified (`git add -A` after a pass). Verified commits get an `AI-Verify:` trailer that CI checks against the commit's tree. If you edit anything after verifying, verify again. To stamp a commit you already made, run `php artisan ai:verify --git-ref=HEAD~1`, then `git commit --amend --no-edit`. If the hook says the commit message may not describe the change, check whether you edited the line you meant to.
- `restart` events list commands such as `queue:restart` that are needed before running workers see the change.
- With Laravel Boost's MCP server, the same commands are tools: `ai-verify` (verdict for the change), `ai-graph` (node or impact) and `ai-verify-receipt`. They return the same JSON as the commands with `--json`; use whichever your client offers.
- Before editing unfamiliar code, run `php artisan ai:graph <Class|route-name|/uri|view.name>` to see what it touches and which tests cover it. `--impact=<path>` shows the blast radius of a change.
