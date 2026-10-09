<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Support;

use Dotenv\Dotenv;

/**
 * Where the project lives and which tools it has installed. Every check asks
 * the workspace instead of probing the filesystem itself.
 */
final class Workspace
{
    /** @var array<string, string|null> */
    private array $binaries = [];

    /** @var array<string, string>|null */
    private ?array $psr4 = null;

    public function __construct(
        public readonly string $basePath,
        public readonly string $phpBinary = PHP_BINARY,
    ) {}

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->basePath : $this->basePath.'/'.ltrim($relative, '/');
    }

    public function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : ltrim($path, './');
    }

    public function exists(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    /** Absolute path of a vendor/bin tool, or null when the project does not have it. */
    public function bin(string $name): ?string
    {
        if (! array_key_exists($name, $this->binaries)) {
            $candidate = $this->path('vendor/bin/'.$name);
            $this->binaries[$name] = is_file($candidate) ? $candidate : null;
        }

        return $this->binaries[$name];
    }

    /** @return list<string> */
    public function artisan(string ...$args): array
    {
        return [$this->phpBinary, $this->path('artisan'), ...$args];
    }

    public function hasPackage(string $name): bool
    {
        return is_dir($this->path('vendor/'.$name));
    }

    /** @return array<string, string> PSR-4 prefix => relative directory, from the project's composer.json */
    public function psr4(): array
    {
        if ($this->psr4 !== null) {
            return $this->psr4;
        }

        $composer = json_decode((string) @file_get_contents($this->path('composer.json')), true);
        $map = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ($composer[$section]['psr-4'] ?? [] as $prefix => $dirs) {
                foreach ((array) $dirs as $dir) {
                    $map[$prefix] ??= rtrim($dir, '/');
                }
            }
        }

        return $this->psr4 = $map ?: ['App\\' => 'app'];
    }

    public function isProjectNamespace(string $class): bool
    {
        foreach (array_keys($this->psr4()) as $prefix) {
            if (str_starts_with($class, $prefix) && ! str_starts_with($prefix, 'Tests\\')) {
                return true;
            }
        }

        return false;
    }

    /** Where PSR-4 says a class lives (whether or not the file exists). */
    public function pathForClass(string $class): ?string
    {
        foreach ($this->psr4() as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                return $dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            }
        }

        return null;
    }

    /**
     * Variables this process inherited from the project's .env (Laravel puts
     * them into the real environment with putenv). Child processes must not
     * inherit them: phpunit.xml `<env>` entries do not override existing
     * variables, so tests would run against the .env database and drivers.
     * Children load .env themselves anyway. A variable whose value differs from
     * .env was set by the shell/CI on purpose and is kept.
     *
     * @return list<string>
     */
    public function dotenvLeaks(): array
    {
        $file = $this->path('.env');

        if (! is_file($file) || ! class_exists(Dotenv::class)) {
            return [];
        }

        try {
            $values = Dotenv::parse((string) file_get_contents($file));
        } catch (\Throwable) {
            return [];
        }

        $leaks = [];

        foreach ($values as $key => $value) {
            $current = getenv($key);

            if ($current !== false && $current === $value) {
                $leaks[] = $key;
            }
        }

        return $leaks;
    }

    public function tempPath(string $name): string
    {
        $dir = sys_get_temp_dir().'/semitexa-ai-verify-'.substr(md5($this->basePath), 0, 8);

        if (! is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }

        return $dir.'/'.$name;
    }
}
