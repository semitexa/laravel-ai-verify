<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Receipts;

/**
 * The commit-message trailer that carries a receipt to code review and CI:
 *
 *   AI-Verify: pass rcpt-20261009-134928-2c4273 tree=5619ded… scope=standard checks=9
 *
 * The tree id is the point: CI compares it with the commit's own tree, so a
 * trailer only holds for a commit whose content is exactly what was verified.
 */
final class Trailer
{
    public const KEY = 'AI-Verify';

    private const PATTERN = '/^AI-Verify:[ \t]*(pass|fail|incomplete|skipped)[ \t]+(rcpt-[\w-]+)[ \t]+tree=([0-9a-f]{40}(?:[0-9a-f]{24})?)(?:[ \t]+scope=(\w+))?(?:[ \t]+checks=(\d+))?[ \t]*$/m';

    /** Markers that a commit was written with an AI agent. */
    private const AGENT_MARKERS = '/^(?:Co-Authored-By|Co-authored-by|Assisted-by|Generated-by):.*\b(?:Claude|Anthropic|Codex|OpenAI|ChatGPT|Copilot|Cursor|Gemini|Devin|Jules|Amp|Aider|Junie|OpenCode|Windsurf|Cline)\b|Generated with \[?(?:Claude Code|Codex|Cursor|Copilot|Gemini)/mi';

    /** @param array<string, mixed> $receipt */
    public static function value(array $receipt): string
    {
        $checks = count((array) ($receipt['checks'] ?? []));

        return sprintf('%s %s tree=%s scope=%s checks=%d',
            $receipt['verdict'] ?? 'unknown', $receipt['id'] ?? '?', $receipt['tree'] ?? '?', $receipt['scope'] ?? '?', $checks);
    }

    /** @param array<string, mixed> $receipt */
    public static function line(array $receipt): string
    {
        return self::KEY.': '.self::value($receipt);
    }

    /** @return array{verdict: string, id: string, tree: string, scope: ?string, checks: ?int}|null the last AI-Verify trailer in a message */
    public static function parse(string $message): ?array
    {
        if (! preg_match_all(self::PATTERN, $message, $all, PREG_SET_ORDER)) {
            return null;
        }

        $m = end($all);

        return [
            'verdict' => $m[1],
            'id' => $m[2],
            'tree' => $m[3],
            'scope' => ($m[4] ?? '') !== '' ? $m[4] : null,
            'checks' => ($m[5] ?? '') !== '' ? (int) $m[5] : null,
        ];
    }

    public static function fromAgent(string $message): bool
    {
        return (bool) preg_match(self::AGENT_MARKERS, $message);
    }
}
