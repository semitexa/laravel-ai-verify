<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Style;

use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * PHP_CodeSniffer: `phpcs --report=json`. Sniff warnings are reported but
 * never fail the check; errors introduced by the change do.
 */
final class PhpcsTool extends StyleTool
{
    public function id(): string
    {
        return 'phpcs';
    }

    public function label(): string
    {
        return 'PHP_CodeSniffer';
    }

    public function binary(): string
    {
        return 'phpcs';
    }

    public function configFiles(): array
    {
        return ['.phpcs.xml', 'phpcs.xml', '.phpcs.xml.dist', 'phpcs.xml.dist'];
    }

    public function argv(string $php, string $bin, ?string $config, array $files): array
    {
        return [
            $php, $bin, '--report=json', '-q', '--no-colors',
            ...($config !== null ? ['--standard='.$config] : []),
            ...$files,
        ];
    }

    public function parse(string $stdout, Workspace $workspace): ?array
    {
        $json = self::json($stdout, '{"totals"');

        if ($json === null) {
            return null;
        }

        $found = [];

        foreach ((array) ($json['files'] ?? []) as $path => $file) {
            $path = $workspace->relative((string) $path);

            foreach ((array) ($file['messages'] ?? []) as $message) {
                $found[$path][] = new Finding(
                    (string) ($message['source'] ?? 'phpcs'),
                    isset($message['line']) ? (int) $message['line'] : null,
                    isset($message['message']) ? (string) $message['message'] : null,
                    warning: ($message['type'] ?? 'ERROR') !== 'ERROR',
                );
            }
        }

        return $found;
    }

    public function fixCommand(array $files): ?string
    {
        return 'vendor/bin/phpcbf '.implode(' ', $files);
    }
}
