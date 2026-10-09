<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Convention fallback for test selection: `app/Models/Invoice.php` →
 * every `*Test.php` under tests/ whose name contains "Invoice". The graph
 * finds tests by what they reference; this finds tests by what they're named.
 */
final class TestLocator
{
    /** @var list<string>|null */
    private ?array $tests = null;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $testsPath = 'tests',
    ) {}

    /** @return list<string> */
    public function byName(string $sourcePath): array
    {
        $name = basename($sourcePath, '.php');

        if (strlen($name) < 3) {
            return [];
        }

        return array_values(array_filter(
            $this->all(),
            static fn (string $test) => str_contains(basename($test, 'Test.php'), $name),
        ));
    }

    /** Nearest directory at or above `$fixture` that holds tests; null means "the whole suite". */
    public function directoryFor(string $fixture): ?string
    {
        $dir = dirname($fixture);

        while ($dir !== '.' && $dir !== '' && $dir !== $this->testsPath) {
            foreach ($this->all() as $test) {
                if (str_starts_with($test, $dir.'/')) {
                    return $dir;
                }
            }

            $dir = dirname($dir);
        }

        return null;
    }

    /** @return list<string> */
    public function all(): array
    {
        if ($this->tests !== null) {
            return $this->tests;
        }

        $this->tests = [];
        $root = $this->workspace->path($this->testsPath);

        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (str_ends_with($file->getFilename(), 'Test.php')) {
                    $this->tests[] = $this->workspace->relative($file->getPathname());
                }
            }

            sort($this->tests);
        }

        return $this->tests;
    }
}
