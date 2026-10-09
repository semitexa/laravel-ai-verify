<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

final class ChangedFile
{
    public const ADDED = 'A';

    public const MODIFIED = 'M';

    public const DELETED = 'D';

    public const RENAMED = 'R';

    public FileKind $kind = FileKind::NonPhp;

    public function __construct(
        public readonly string $path,
        public readonly string $status = self::MODIFIED,
        public readonly ?string $originalPath = null,
    ) {}

    public function isDeleted(): bool
    {
        return $this->status === self::DELETED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'path' => $this->path,
            'kind' => $this->kind->value,
            'status' => $this->status,
            'original_path' => $this->originalPath,
        ], static fn ($value) => $value !== null);
    }
}
