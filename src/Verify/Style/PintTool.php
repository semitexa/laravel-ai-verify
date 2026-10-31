<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Style;

use Semitexa\LaravelAiVerify\Support\Workspace;

/** Laravel Pint: `pint --test --format=json`, with the project's pint.json or the laravel preset. */
final class PintTool extends StyleTool
{
    public function id(): string
    {
        return 'pint';
    }

    public function label(): string
    {
        return 'Pint';
    }

    public function binary(): string
    {
        return 'pint';
    }

    public function configFiles(): array
    {
        return ['pint.json'];
    }

    public function argv(string $php, string $bin, ?string $config, array $files): array
    {
        return [$php, $bin, '--test', '--format=json', ...($config !== null ? ['--config='.$config] : []), ...$files];
    }

    public function parse(string $stdout, Workspace $workspace): ?array
    {
        $json = self::json($stdout);

        if ($json === null) {
            return null;
        }

        $found = [];

        foreach ((array) ($json['files'] ?? []) as $file) {
            $path = $workspace->relative((string) ($file['path'] ?? ''));

            foreach ((array) ($file['fixers'] ?? []) as $fixer) {
                $found[$path][] = new Finding((string) $fixer);
            }
        }

        return $found;
    }

    public function fixCommand(array $files): ?string
    {
        return 'vendor/bin/pint '.implode(' ', $files);
    }
}
