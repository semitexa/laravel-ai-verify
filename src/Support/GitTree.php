<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Support;

/**
 * Git object ids for "what exactly was verified". The working-tree id is the
 * tree a commit would get if everything (tracked changes and untracked,
 * non-ignored files) were committed as it is now — computed through a
 * throw-away index, so the user's real index and staging are never touched.
 */
final class GitTree
{
    public function __construct(private readonly ProcessRunner $runner) {}

    /** Tree id of the working tree as it is right now, or null outside a git repository. */
    public function working(): ?string
    {
        $index = $this->runner->run(['git', 'rev-parse', '--git-path', 'index']);
        $top = $this->runner->run(['git', 'rev-parse', '--show-toplevel']);

        if (! $index->succeeded() || ! $top->succeeded()) {
            return null;
        }

        $real = trim($index->stdout);
        $real = str_starts_with($real, '/') ? $real : rtrim(trim($top->stdout), '/').'/'.$real;
        $temp = tempnam(sys_get_temp_dir(), 'ai-verify-index-');

        try {
            // Seeding from the real index reuses its stat cache, so unchanged files are not re-hashed.
            if (is_file($real)) {
                copy($real, $temp);
            } else {
                @unlink($temp);
            }

            $env = ['GIT_INDEX_FILE' => $temp];
            $add = $this->runner->run(['git', 'add', '--all', '--', '.'], $env);
            $tree = $this->runner->run(['git', 'write-tree'], $env);

            return $add->succeeded() && $tree->succeeded() ? $this->id($tree->stdout) : null;
        } finally {
            @unlink($temp);
        }
    }

    /** Tree id of a commit-ish (HEAD, a sha, a branch), or null when it does not resolve. */
    public function ofCommit(string $ref): ?string
    {
        if ($ref === '' || str_starts_with($ref, '-')) {
            return null;
        }

        $out = $this->runner->run(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{tree}']);

        return $out->succeeded() ? $this->id($out->stdout) : null;
    }

    /** Tree id of what is staged (respects GIT_INDEX_FILE, as during `git commit -a`). */
    public function staged(): ?string
    {
        $out = $this->runner->run(['git', 'write-tree']);

        return $out->succeeded() ? $this->id($out->stdout) : null;
    }

    public function head(): ?string
    {
        $out = $this->runner->run(['git', 'rev-parse', '--verify', '--quiet', 'HEAD']);

        return $out->succeeded() ? $this->id($out->stdout) : null;
    }

    private function id(string $output): ?string
    {
        $id = trim($output);

        return preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/', $id) ? $id : null;
    }
}
