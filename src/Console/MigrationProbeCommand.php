<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Internal: executed in a child process by MigrationCheck. Loads one
 * migration and runs up()/down() inside Connection::pretend(), so Schema
 * calls are compiled to SQL but never executed. Prints one JSON line.
 */
final class MigrationProbeCommand extends Command
{
    protected $signature = 'ai:verify:migration {file : Migration path relative to the project}';

    protected $description = 'Internal probe used by ai:verify';

    protected $hidden = true;

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $path = base_path($file);

        if (! is_file($path)) {
            return $this->report('fail', "Migration file not found: {$file}", 1);
        }

        try {
            $before = get_declared_classes();
            $migration = require $path;

            if (! $migration instanceof Migration) {
                // Legacy named-class migrations: instantiate the class the file declared.
                $declared = array_values(array_filter(
                    array_diff(get_declared_classes(), $before),
                    static fn (string $class) => is_subclass_of($class, Migration::class),
                ));
                $migration = $declared !== [] ? new $declared[0] : null;
            }
        } catch (Throwable $e) {
            return $this->report('fail', 'Migration does not load: '.$e->getMessage(), 1, $e);
        }

        if (! $migration instanceof Migration) {
            return $this->report('fail', 'File does not return or declare an Illuminate\Database\Migrations\Migration', 1);
        }

        $connection = $this->laravel['db']->connection($migration->getConnection());
        $queries = 0;

        foreach (['up', 'down'] as $direction) {
            if (! method_exists($migration, $direction)) {
                continue;
            }

            try {
                $queries += count($connection->pretend(static fn () => $migration->{$direction}()));
            } catch (QueryException|PDOException $e) {
                // Introspection (hasTable, column listing) needs a live database; that is environment, not code.
                return $this->report('skipped', "{$direction}() needs a live database to verify: ".$e->getMessage(), 0);
            } catch (Throwable $e) {
                return $this->report('fail', "{$direction}() throws: ".$e->getMessage(), 1, $e);
            }
        }

        return $this->report('pass', "up/down compile in pretend mode ({$queries} statement(s))", 0);
    }

    private function report(string $status, string $message, int $exit, ?Throwable $e = null): int
    {
        $location = $e !== null ? $this->locate($e) : [];

        $this->line((string) json_encode(['migration' => $this->argument('file'), 'status' => $status, 'message' => $message, ...$location], JSON_UNESCAPED_SLASHES));

        return $exit;
    }

    /** @return array{file?: string, line?: int} */
    private function locate(Throwable $e): array
    {
        $base = rtrim(base_path(), '/').'/';

        foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $frame) {
            $file = $frame['file'] ?? '';

            if (str_starts_with($file, $base) && ! str_starts_with($file, $base.'vendor/')) {
                return ['file' => substr($file, strlen($base)), 'line' => (int) ($frame['line'] ?? 0)];
            }
        }

        return [];
    }
}
