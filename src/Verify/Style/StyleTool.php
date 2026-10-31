<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Style;

use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * One code style tool: how to ask it about a list of files without changing
 * them, and how to read its machine report. StyleCheck does the rest (what
 * the change introduced vs what was already there), the same for every tool.
 */
abstract class StyleTool
{
    /** Short id used in the config and as the check's target id. */
    abstract public function id(): string;

    abstract public function label(): string;

    /** The executable in vendor/bin. */
    abstract public function binary(): string;

    /**
     * Config files that make this tool the project's standard, in the order
     * the tool itself looks for them.
     *
     * @return list<string>
     */
    abstract public function configFiles(): array;

    /**
     * Report-only run: never writes to the files.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    abstract public function argv(string $php, string $bin, ?string $config, array $files): array;

    /**
     * The tool's report as path => findings, or null when stdout holds no
     * report the tool would write.
     *
     * @return array<string, list<Finding>>|null
     */
    abstract public function parse(string $stdout, Workspace $workspace): ?array;

    /** @param list<string> $files */
    abstract public function fixCommand(array $files): ?string;

    /** Reports from tools start after any warnings they print first. */
    protected static function json(string $stdout, string $marker = '{'): ?array
    {
        $start = strpos($stdout, $marker);
        $json = $start === false ? null : json_decode(trim(substr($stdout, $start)), true);

        return is_array($json) ? $json : null;
    }

    /** `PhpCsFixer\Fixer\Whitespace\NoTrailingWhitespaceFixer` reads better as `NoTrailingWhitespaceFixer`. */
    protected static function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
