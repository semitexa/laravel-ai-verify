<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Graph;

/**
 * Token-level PHP reader: namespace, declared class, imports, every name that
 * could be a class reference, and string literals. No AST dependency — names
 * are resolved the way PHP would, and the caller filters them against the set
 * of classes that actually exist in the project.
 */
final class PhpFileInspector
{
    private const MAX_STRINGS = 400;

    public function inspect(string $path, string $relative): FileFacts
    {
        $code = @file_get_contents($path);
        $facts = new FileFacts($relative);

        if ($code === false || $code === '') {
            return $facts;
        }

        $tokens = token_get_all($code);
        $count = count($tokens);
        $depth = 0;
        $namespace = '';
        $names = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }

                continue;
            }

            [$id, $text] = $token;

            switch ($id) {
                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $depth++;
                    break;

                case T_NAMESPACE:
                    $next = $this->nextSignificant($tokens, $i);

                    if ($next !== null && in_array($tokens[$next][0] ?? null, [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $tokens[$next][1];
                        $facts->namespace = $namespace;
                        $i = $next;
                    }
                    break;

                case T_USE:
                    // Only file-level imports; `use Trait;` inside a class body is a reference.
                    $next = $this->nextSignificant($tokens, $i);
                    $isClosureUse = $next !== null && $tokens[$next] === '(';

                    if (! $isClosureUse && ($depth === 0 || ($depth === 1 && $facts->class === null))) {
                        $i = $this->readImports($tokens, $i, $facts);
                    }
                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case T_ENUM:
                    $prev = $this->prevSignificant($tokens, $i);

                    if ($prev !== null && in_array($tokens[$prev][0] ?? null, [T_DOUBLE_COLON, T_NEW], true)) {
                        break; // Foo::class or anonymous class
                    }

                    $next = $this->nextSignificant($tokens, $i);

                    if ($facts->class === null && $next !== null && ($tokens[$next][0] ?? null) === T_STRING) {
                        $facts->class = ltrim($namespace.'\\'.$tokens[$next][1], '\\');
                        $facts->classType = strtolower(substr(token_name($id), 2));
                        $i = $next;
                    }
                    break;

                case T_EXTENDS:
                case T_IMPLEMENTS:
                    $j = $i;

                    while (true) {
                        $next = $this->nextSignificant($tokens, $j);

                        if ($next === null || ! is_array($tokens[$next])
                            || ! in_array($tokens[$next][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                            break;
                        }

                        $resolved = $this->resolve($tokens[$next][0], $tokens[$next][1], $namespace, $facts->imports);

                        if ($id === T_EXTENDS && $facts->extends === null && $facts->classType === 'class') {
                            $facts->extends = $resolved;
                        } else {
                            $facts->implements[] = $resolved;
                        }

                        $names[$resolved] = true;
                        $j = $next;
                        $comma = $this->nextSignificant($tokens, $j);

                        if ($comma === null || $tokens[$comma] !== ',') {
                            break;
                        }

                        $j = $comma;
                    }

                    $i = $j;
                    break;

                case T_STRING:
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                    $names[$this->resolve($id, $text, $namespace, $facts->imports)] = true;
                    break;

                case T_CONSTANT_ENCAPSED_STRING:
                    if (count($facts->strings) < self::MAX_STRINGS && strlen($text) <= 202) {
                        $facts->strings[] = stripcslashes(substr($text, 1, -1));
                    }
                    break;
            }
        }

        foreach ($facts->imports as $fqcn) {
            $names[$fqcn] = true;
        }

        unset($names[(string) $facts->class]);
        $facts->references = array_keys($names);

        return $facts;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function readImports(array $tokens, int $i, FileFacts $facts): int
    {
        $count = count($tokens);
        $prefix = '';
        $current = '';
        $alias = null;
        $expectAlias = false;
        $kind = 'class';

        for ($i++; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;

            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            if ($id === T_FUNCTION || $id === T_CONST) {
                $kind = 'skip';
            } elseif ($id === T_AS) {
                $expectAlias = true;
            } elseif (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                if ($expectAlias) {
                    $alias = $text;
                    $expectAlias = false;
                } else {
                    $current = ltrim($text, '\\');
                }
            } elseif ($id === T_NS_SEPARATOR) {
                // `use Foo\{Bar, Baz}` — Foo\ arrives as T_NAME_QUALIFIED + '\' in some PHP versions
                $current .= '\\';
            } elseif ($text === '{') {
                $prefix = rtrim($current, '\\').'\\';
                $current = '';
            } elseif ($text === ',' || $text === '}' || $text === ';') {
                if ($current !== '' && $kind === 'class') {
                    $fqcn = ltrim($prefix.$current, '\\');
                    $short = $alias ?? substr($fqcn, (int) strrpos('\\'.$fqcn, '\\'));
                    $facts->imports[$short] = $fqcn;
                }

                $current = '';
                $alias = null;

                if ($text === '}') {
                    $prefix = '';
                }

                if ($text === ';') {
                    return $i;
                }
            }
        }

        return $i;
    }

    /** @param array<string, string> $imports */
    private function resolve(int $id, string $name, string $namespace, array $imports): string
    {
        if ($id === T_NAME_FULLY_QUALIFIED) {
            return ltrim($name, '\\');
        }

        $first = strstr($name, '\\', true) ?: $name;

        if (isset($imports[$first])) {
            return $imports[$first].substr($name, strlen($first));
        }

        return ltrim($namespace.'\\'.$name, '\\');
    }

    /** @param array<int, mixed> $tokens */
    private function nextSignificant(array $tokens, int $i): ?int
    {
        for ($i++, $n = count($tokens); $i < $n; $i++) {
            if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function prevSignificant(array $tokens, int $i): ?int
    {
        for ($i--; $i >= 0; $i--) {
            if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }
}
