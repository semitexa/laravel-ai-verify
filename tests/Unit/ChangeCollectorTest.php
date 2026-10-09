<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;
use Semitexa\LaravelAiVerify\Support\Workspace;
use Semitexa\LaravelAiVerify\Verify\ChangeCollector;
use Semitexa\LaravelAiVerify\Verify\ChangedFile;

final class ChangeCollectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-verify-collector-'.uniqid();
        mkdir($this->dir.'/app', 0o777, true);
        file_put_contents($this->dir.'/app/A.php', '<?php');
        file_put_contents($this->dir.'/app/B.php', '<?php');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function collector(): ChangeCollector
    {
        return new ChangeCollector(new Workspace($this->dir), new ProcessRunner($this->dir));
    }

    public function test_files_are_split_on_commas_relativised_and_deduplicated(): void
    {
        $collector = $this->collector();
        $collector->addFiles(['app/A.php, '.$this->dir.'/app/B.php', 'app/A.php']);

        $this->assertSame(['app/A.php', 'app/B.php'], array_map(fn (ChangedFile $f) => $f->path, $collector->files()));
    }

    public function test_missing_files_are_deletions(): void
    {
        $collector = $this->collector();
        $collector->addFiles(['app/Gone.php']);

        $this->assertTrue($collector->files()[0]->isDeleted());
    }

    public function test_name_status_text_keeps_renames_and_deletions(): void
    {
        $collector = $this->collector();
        $collector->addDiffText("M\tapp/A.php\nR087\tapp/Old.php\tapp/B.php\nD\tapp/C.php\nA\tapp/New.php\n");

        $files = [];

        foreach ($collector->files() as $file) {
            $files[$file->path] = [$file->status, $file->originalPath];
        }

        $this->assertSame([
            'app/A.php' => ['M', null],
            'app/B.php' => ['R', 'app/Old.php'],
            'app/C.php' => ['D', null],
            'app/New.php' => ['A', null],
        ], $files);
    }

    public function test_rename_knowledge_and_existence_beat_earlier_entries(): void
    {
        $collector = $this->collector();
        $collector->addDiffText("D\tapp/A.php\nM\tapp/B.php");
        $collector->addDiffText("M\tapp/A.php\nR100\tapp/Old.php\tapp/B.php");

        [$a, $b] = $collector->files();

        $this->assertSame('M', $a->status, 'a live file beats an earlier deletion');
        $this->assertSame('app/Old.php', $b->originalPath, 'rename knowledge beats a bare entry');
    }

    public function test_dirty_reads_porcelain_status_including_untracked_files(): void
    {
        exec('cd '.escapeshellarg($this->dir).' && git init -q && git add app/A.php && git -c user.name=t -c user.email=t@t commit -qm init');
        file_put_contents($this->dir.'/app/A.php', '<?php // changed');

        $collector = $this->collector();
        $this->assertTrue($collector->addDirty());

        $statuses = [];

        foreach ($collector->files() as $file) {
            $statuses[$file->path] = $file->status;
        }

        $this->assertSame(['app/A.php' => 'M', 'app/B.php' => 'A'], $statuses);
    }
}
