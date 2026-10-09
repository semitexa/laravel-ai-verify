<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Graph;

final class FileFacts
{
    public string $namespace = '';

    /** Fully-qualified name of the first class-like declared in the file. */
    public ?string $class = null;

    /** class|interface|trait|enum */
    public ?string $classType = null;

    public ?string $extends = null;

    /** @var list<string> */
    public array $implements = [];

    /** @var array<string, string> alias => FQCN */
    public array $imports = [];

    /** @var list<string> every name that may be a class reference, resolved */
    public array $references = [];

    /** @var list<string> */
    public array $strings = [];

    public function __construct(public readonly string $path) {}

    public function resolveShort(string $name): string
    {
        $name = ltrim($name, '\\');
        $first = strstr($name, '\\', true) ?: $name;

        return isset($this->imports[$first])
            ? $this->imports[$first].substr($name, strlen($first))
            : ltrim($this->namespace.'\\'.$name, '\\');
    }
}
