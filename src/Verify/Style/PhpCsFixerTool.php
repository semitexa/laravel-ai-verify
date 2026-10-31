<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Style;

use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * PHP-CS-Fixer: `fix --dry-run --format=json`. `--path-mode=override` makes
 * the given files the whole job, so the config's Finder does not widen it.
 * The JSON report (3.95 at least) names the applied fixers only with `-v`; a file
 * listed without them still needs fixing.
 */
final class PhpCsFixerTool extends StyleTool
{
    public function id(): string
    {
        return 'php-cs-fixer';
    }

    public function label(): string
    {
        return 'PHP-CS-Fixer';
    }

    public function binary(): string
    {
        return 'php-cs-fixer';
    }

    public function configFiles(): array
    {
        return ['.php-cs-fixer.php', '.php-cs-fixer.dist.php'];
    }

    public function argv(string $php, string $bin, ?string $config, array $files): array
    {
        return [
            $php, $bin, 'fix', '--dry-run', '--format=json', '--using-cache=no',
            '--path-mode=override', '--show-progress=none', '--no-interaction', '-v',
            ...($config !== null ? ['--config='.$config] : []),
            ...$files,
        ];
    }

    public function parse(string $stdout, Workspace $workspace): ?array
    {
        $json = self::json($stdout, '{"');

        if ($json === null || ! array_key_exists('files', $json)) {
            return null;
        }

        $found = [];

        foreach ((array) $json['files'] as $file) {
            $path = $workspace->relative((string) ($file['name'] ?? ''));

            $fixers = (array) ($file['appliedFixers'] ?? []);

            foreach ($fixers === [] ? ['php-cs-fixer'] : $fixers as $fixer) {
                $found[$path][] = new Finding((string) $fixer);
            }
        }

        return $found;
    }

    public function fixCommand(array $files): ?string
    {
        return 'vendor/bin/php-cs-fixer fix '.implode(' ', $files);
    }
}
