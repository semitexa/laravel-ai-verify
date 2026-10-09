## Verifying changes (semitexa/laravel-ai-verify)

- After every coherent change, run `php artisan semitexa:verify` before reporting the work as done. It plans and runs only the checks the change needs: syntax, Blade compile, Pint, Larastan, Laravel boot probes, migration pretend-run, and the related tests.
- Read the last NDJSON line, `{"kind":"verdict",...}`:
    - `pass`: the change set is verified.
    - `fail`: fix each `violation` (it has `path`, `line`, `message` and often a `tip` with the fixing command), then re-run.
    - `incomplete`: something could not be verified. Read `unchecked_files` and the `incomplete` results. Do not report the task as done.
- Violations marked `severity: warning` or `pre-existing` were already in the code before the change. They do not fail the run.
- Never delete, skip or weaken a test to get a `pass`; the test-integrity check fails it. If a test change is intentional, add `// verify:accept-test-change <reason>` to the test file.
- `restart` events list commands such as `queue:restart` that are needed before running workers see the change.
- Before editing unfamiliar code, run `php artisan semitexa:graph <Class|route-name|/uri|view.name>` to see what it touches and which tests cover it. `--impact=<path>` shows the blast radius of a change.
