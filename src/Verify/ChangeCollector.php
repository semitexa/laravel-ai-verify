<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

use RuntimeException;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Gathers the change set from any mix of sources and merges it into one
 * de-duplicated list. First entry for a path wins, except that rename
 * knowledge and "the file exists" evidence always beat a bare entry / a deletion.
 */
final class ChangeCollector
{
    /** @var array<string, ChangedFile> */
    private array $files = [];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ProcessRunner $runner,
    ) {}

    /** @param list<string> $paths repeated and/or comma-separated */
    public function addFiles(array $paths): void
    {
        foreach ($paths as $chunk) {
            foreach (explode(',', $chunk) as $path) {
                $path = trim($path);

                if ($path !== '') {
                    $relative = $this->workspace->relative($path);
                    $status = $this->workspace->exists($relative) ? ChangedFile::MODIFIED : ChangedFile::DELETED;
                    $this->add(new ChangedFile($relative, $status));
                }
            }
        }
    }

    /** Everything that differs from `$ref`, committed or not. */
    public function addGitRef(string $ref): void
    {
        if ($ref === '' || str_starts_with($ref, '-')) {
            throw new RuntimeException("invalid git ref '{$ref}'");
        }

        $outcome = $this->runner->run(['git', 'diff', '--name-status', '-z', '--find-renames', $ref, '--']);

        if (! $outcome->succeeded()) {
            throw new RuntimeException("git diff against '{$ref}' failed: ".$outcome->lastLine());
        }

        $this->parseNameStatusZ($outcome->output);
        // `git diff REF` ignores untracked files; an agent's new files are exactly those.
        $this->addUntracked();
    }

    /** Uncommitted work: staged, unstaged and untracked. Returns false outside a git repo. */
    public function addDirty(): bool
    {
        $outcome = $this->runner->run(['git', 'status', '--porcelain=v1', '-z', '--untracked-files=all']);

        if (! $outcome->succeeded()) {
            return false;
        }

        $entries = explode("\0", $outcome->output);

        for ($i = 0, $n = count($entries); $i < $n; $i++) {
            $entry = $entries[$i];

            if (strlen($entry) < 4) {
                continue;
            }

            $code = substr($entry, 0, 2);
            $path = substr($entry, 3);

            if ($code === '??') {
                $this->add(new ChangedFile($path, ChangedFile::ADDED));
            } elseif (str_contains($code, 'R')) {
                // porcelain -z: "R  new\0old"
                $this->add(new ChangedFile($path, ChangedFile::RENAMED, $entries[++$i] ?? null));
            } elseif (str_contains($code, 'D')) {
                $this->add(new ChangedFile($path, ChangedFile::DELETED));
            } elseif (str_contains($code, 'A')) {
                $this->add(new ChangedFile($path, ChangedFile::ADDED));
            } else {
                $this->add(new ChangedFile($path, ChangedFile::MODIFIED));
            }
        }

        return true;
    }

    /** Accepts `git diff --name-only` or `--name-status` output. */
    public function addDiffText(string $text): void
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^([AMDTCU]|R\d*|C\d*)\t(.+)$/', $line, $m)) {
                $parts = explode("\t", $m[2]);

                if ($m[1][0] === 'R' && count($parts) === 2) {
                    $this->add(new ChangedFile($parts[1], ChangedFile::RENAMED, $parts[0]));
                } else {
                    $this->add(new ChangedFile(end($parts), $m[1] === 'D' ? ChangedFile::DELETED : ($m[1] === 'A' ? ChangedFile::ADDED : ChangedFile::MODIFIED)));
                }

                continue;
            }

            $this->addFiles([$line]);
        }
    }

    /** @return list<ChangedFile> */
    public function files(): array
    {
        return array_values($this->files);
    }

    private function addUntracked(): void
    {
        $outcome = $this->runner->run(['git', 'ls-files', '--others', '--exclude-standard', '-z']);

        foreach (array_filter(explode("\0", $outcome->output)) as $path) {
            $this->add(new ChangedFile($path, ChangedFile::ADDED));
        }
    }

    private function parseNameStatusZ(string $output): void
    {
        $tokens = explode("\0", rtrim($output, "\0"));

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $status = $tokens[$i];

            if ($status === '') {
                continue;
            }

            $letter = $status[0];

            if ($letter === 'R' || $letter === 'C') {
                $old = $tokens[++$i] ?? '';
                $new = $tokens[++$i] ?? '';
                $this->add($letter === 'R'
                    ? new ChangedFile($new, ChangedFile::RENAMED, $old)
                    : new ChangedFile($new, ChangedFile::ADDED));

                continue;
            }

            $path = $tokens[++$i] ?? '';
            $this->add(new ChangedFile($path, match ($letter) {
                'A' => ChangedFile::ADDED,
                'D' => ChangedFile::DELETED,
                default => ChangedFile::MODIFIED,
            }));
        }
    }

    private function add(ChangedFile $file): void
    {
        if ($file->path === '') {
            return;
        }

        $existing = $this->files[$file->path] ?? null;

        $replace = $existing === null
            || ($file->originalPath !== null && $existing->originalPath === null)
            || ($existing->isDeleted() && ! $file->isDeleted());

        if ($replace) {
            $this->files[$file->path] = $file;
        }
    }
}
