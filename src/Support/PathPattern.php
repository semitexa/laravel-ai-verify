<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Support;

/**
 * "Path prefix or glob", the one pattern language of the config (`kinds`,
 * `ignore`, `commands.*.when`). A pattern with `*`, `?` or `[` is a glob in
 * which `*` also crosses directories; anything else is a path prefix.
 */
final class PathPattern
{
    public static function matches(string $pattern, string $path): bool
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        $pattern = '/'.ltrim(str_replace('\\', '/', $pattern), '/');

        return strpbrk($pattern, '*?[') !== false
            ? fnmatch($pattern, $path)
            : str_starts_with($path, $pattern);
    }

    /** @param iterable<string> $patterns */
    public static function any(iterable $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches((string) $pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
