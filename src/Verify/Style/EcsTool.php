<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Style;

use Semitexa\LaravelAiVerify\Support\Workspace;

/** Easy Coding Standard: `ecs check --output-format=json`, fixers and sniffs in one report. */
final class EcsTool extends StyleTool
{
    public function id(): string
    {
        return 'ecs';
    }

    public function label(): string
    {
        return 'Easy Coding Standard';
    }

    public function binary(): string
    {
        return 'ecs';
    }

    public function configFiles(): array
    {
        return ['ecs.php'];
    }

    public function argv(string $php, string $bin, ?string $config, array $files): array
    {
        return [
            $php, $bin, 'check', '--output-format=json', '--no-progress-bar', '--no-interaction',
            ...($config !== null ? ['--config='.$config] : []),
            ...$files,
        ];
    }

    public function parse(string $stdout, Workspace $workspace): ?array
    {
        $json = self::json($stdout);

        if ($json === null || ! isset($json['totals'])) {
            return null;
        }

        $found = [];

        foreach ((array) ($json['files'] ?? []) as $path => $file) {
            $path = $workspace->relative((string) $path);

            foreach ((array) ($file['diffs'] ?? []) as $diff) {
                foreach ((array) ($diff['applied_checkers'] ?? $diff['checkers'] ?? []) as $checker) {
                    $found[$path][] = new Finding(self::shortClass((string) $checker));
                }
            }

            foreach ((array) ($file['errors'] ?? []) as $error) {
                $found[$path][] = new Finding(
                    self::shortClass((string) ($error['source_class'] ?? 'ecs')),
                    isset($error['line']) ? (int) $error['line'] : null,
                    isset($error['message']) ? (string) $error['message'] : null,
                );
            }
        }

        return $found;
    }

    public function fixCommand(array $files): ?string
    {
        return 'vendor/bin/ecs check --fix '.implode(' ', $files);
    }
}
