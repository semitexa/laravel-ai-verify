<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Receipts\Trailer;

final class TrailerTest extends TestCase
{
    private const TREE = '5619ded42b5d1c9371718999e1528af5f0f750c9';

    public function test_round_trip(): void
    {
        $line = Trailer::line(['verdict' => 'pass', 'id' => 'rcpt-20261009-134928-2c4273', 'tree' => self::TREE, 'scope' => 'standard', 'checks' => [1, 2, 3]]);

        $this->assertSame('AI-Verify: pass rcpt-20261009-134928-2c4273 tree='.self::TREE.' scope=standard checks=3', $line);
        $this->assertSame(
            ['verdict' => 'pass', 'id' => 'rcpt-20261009-134928-2c4273', 'tree' => self::TREE, 'scope' => 'standard', 'checks' => 3],
            Trailer::parse("Add contact form\n\nBody text.\n\n{$line}\nCo-Authored-By: Claude <noreply@anthropic.com>\n"),
        );
    }

    public function test_the_last_trailer_wins_and_junk_is_ignored(): void
    {
        $message = "x\n\nAI-Verify: pass rcpt-a tree=".str_repeat('a', 40)."\nAI-Verify: fail rcpt-b tree=".str_repeat('b', 40)."\n";

        $this->assertSame('rcpt-b', Trailer::parse($message)['id']);
        $this->assertNull(Trailer::parse('AI-Verify: pass but no receipt here'));
        $this->assertNull(Trailer::parse('mentions AI-Verify: pass rcpt-a tree='.str_repeat('a', 40).' mid-line'));
    }

    public function test_detects_agent_assisted_commits(): void
    {
        $this->assertTrue(Trailer::fromAgent("fix\n\nCo-Authored-By: Claude Opus <noreply@anthropic.com>"));
        $this->assertTrue(Trailer::fromAgent("fix\n\nCo-authored-by: Copilot <copilot@github.com>"));
        $this->assertTrue(Trailer::fromAgent("fix\n\n🤖 Generated with [Claude Code](https://claude.com/claude-code)"));
        $this->assertFalse(Trailer::fromAgent("fix\n\nCo-authored-by: Jane Doe <jane@example.com>"));
    }
}
