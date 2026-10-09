<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Support;

/**
 * Turns tool and framework error output into the two things an agent needs:
 * a one-line message and the first location inside the project's own code.
 */
final class ErrorText
{
    /** Paths that are never "where the bug is". */
    private const FOREIGN = ['vendor/', 'storage/', 'bootstrap/cache/'];

    /**
     * Collision/Laravel console exception block:
     *
     *      LogicException
     *
     *     Your configuration files could not be serialized …
     *
     *     at vendor/laravel/framework/src/…/ConfigCacheCommand.php:77
     *
     * @return array{class: string, message: string}|null
     */
    public static function consoleException(string $output): ?array
    {
        $output = self::stripAnsi($output);

        if (! preg_match('/^\s{2,}([A-Z][\w\\\\]*(?:Exception|Error))\s*$\R+((?:^(?!\s*at\s).*\S.*$\R?)+)/m', $output, $m)) {
            return null;
        }

        $message = trim((string) preg_replace('/\s+/', ' ', $m[2]));

        return ['class' => $m[1], 'message' => $message];
    }

    /** Message with stack trace and absolute-path noise removed, capped. */
    public static function message(string $text, ?Workspace $workspace = null, int $max = 500): string
    {
        $text = self::stripAnsi($text);
        // PHPUnit appends the trace as a paragraph of bare `path:line` lines.
        $text = (string) preg_replace('/\R\s*\R\s*(?:\/|[A-Za-z]:\\\\)\S+\.php:\d+.*$/s', '', $text);
        $text = (string) preg_replace('/\s*(Stack trace:|#0 ).*$/s', '', $text);

        if ($workspace !== null) {
            $text = str_replace(rtrim($workspace->basePath, '/').'/', '', $text);
        }

        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strimwidth($text, 0, $max, '…');
    }

    /** @return array{0: ?string, 1: ?int} first project-owned `path:line` in the text */
    public static function location(string $text, Workspace $workspace): array
    {
        $text = self::stripAnsi($text);

        if (! preg_match_all('#(?<![\w/.-])((?:/|[A-Za-z]:\\\\)?[\w./\\\\-]+\.php)(?::|\(| on line )(\d+)#', $text, $m, PREG_SET_ORDER)) {
            return [null, null];
        }

        foreach ($m as [, $path, $line]) {
            $relative = $workspace->relative($path);

            if (str_starts_with($path, '/') && $relative === ltrim($path, './')) {
                continue; // absolute path outside the project
            }

            foreach (self::FOREIGN as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            return [$relative, (int) $line];
        }

        return [null, null];
    }

    public static function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $text);
    }
}
