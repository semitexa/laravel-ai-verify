<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\LaravelAiVerify\Receipts\CommitClaims;
use Semitexa\LaravelAiVerify\Support\ProcessRunner;

final class CommitClaimsTest extends TestCase
{
    private const REQUEST = <<<'PHP'
        <?php

        namespace App\Http\Requests;

        class StoreContactMessageRequest extends FormRequest
        {
            public function rules(): array
            {
                return [
                    'name' => ['required', 'string', 'max:200'],
                    'email' => ['required', 'email', 'max:250'],
                    'message' => ['required', 'string', 'max:5000'],
                ];
            }
        }
        PHP;

    /** The commit an agent made in the demo app: the subject says name, the diff limits the email. */
    private const EMAIL_DIFF = <<<'DIFF'
        diff --git a/app/Http/Requests/StoreContactMessageRequest.php b/app/Http/Requests/StoreContactMessageRequest.php
        index 09b01ec..1e9d1bb 100644
        --- a/app/Http/Requests/StoreContactMessageRequest.php
        +++ b/app/Http/Requests/StoreContactMessageRequest.php
        @@ -11 +11 @@ public function rules(): array
        -            'email' => ['required', 'email', 'max:255'],
        +            'email' => ['required', 'email', 'max:250'],
        DIFF;

    private function claims(string $subject, string $diff, ?string $content = self::REQUEST): array
    {
        return (new CommitClaims(new ProcessRunner(sys_get_temp_dir())))
            ->analyze(CommitClaims::subject($subject), CommitClaims::parseDiff($diff), static fn () => $content);
    }

    public function test_a_subject_that_names_a_field_the_diff_never_touches_is_flagged(): void
    {
        $findings = $this->claims('Limit contact name to 250 chars', self::EMAIL_DIFF);

        $this->assertCount(1, $findings);
        $this->assertSame('term', $findings[0]['kind']);
        $this->assertSame('name', $findings[0]['term']);
        $this->assertSame('app/Http/Requests/StoreContactMessageRequest.php', $findings[0]['path']);
        $this->assertSame(10, $findings[0]['line']);
        $this->assertSame(['path' => 'app/Http/Requests/StoreContactMessageRequest.php', 'line' => 11, 'text' => "'email' => ['required', 'email', 'max:250'],"], $findings[0]['changed']);
        $this->assertStringContainsString('names `name`', $findings[0]['message']);
        $this->assertStringContainsString('its line app/Http/Requests/StoreContactMessageRequest.php:10 is unchanged; the change went to the similar line 11', $findings[0]['message']);
    }

    /** A find-and-replace of max:255 that hit the name rule and its neighbour, the email rule. */
    private const BOTH_DIFF = <<<'DIFF'
        diff --git a/app/Http/Requests/StoreContactMessageRequest.php b/app/Http/Requests/StoreContactMessageRequest.php
        --- a/app/Http/Requests/StoreContactMessageRequest.php
        +++ b/app/Http/Requests/StoreContactMessageRequest.php
        @@ -10,2 +10,2 @@ public function rules(): array
        -            'name' => ['required', 'string', 'max:255'],
        -            'email' => ['required', 'email', 'max:255'],
        +            'name' => ['required', 'string', 'max:250'],
        +            'email' => ['required', 'email', 'max:250'],
        DIFF;

    public function test_the_same_edit_on_the_named_lines_twin_is_flagged(): void
    {
        $findings = $this->claims('Limit contact name to 250 chars', self::BOTH_DIFF);

        $this->assertCount(1, $findings);
        $this->assertSame('twin', $findings[0]['kind']);
        $this->assertSame('name', $findings[0]['term']);
        $this->assertSame(10, $findings[0]['line']);
        $this->assertSame(['path' => 'app/Http/Requests/StoreContactMessageRequest.php', 'line' => 11, 'text' => "'email' => ['required', 'email', 'max:250'],"], $findings[0]['changed']);
        $this->assertStringContainsString('names `name` (line app/Http/Requests/StoreContactMessageRequest.php:10), but the same edit also went to the similar line 11, which it does not mention', $findings[0]['message']);
    }

    public function test_a_twin_the_subject_also_names_is_part_of_the_claim(): void
    {
        $this->assertSame([], $this->claims('Limit contact name and email to 250 chars', self::BOTH_DIFF));
        $this->assertSame([], $this->claims('Limit every contact field to 250 chars', self::BOTH_DIFF), 'no single twin named');
    }

    public function test_a_different_edit_on_the_neighbour_is_not_collateral(): void
    {
        $diff = str_replace("'email', 'max:250']", "'email', 'max:300']", self::BOTH_DIFF);

        $this->assertSame([], $this->claims('Limit contact name to 250 chars', $diff));
    }

    public function test_a_parameter_carried_to_a_sibling_method_is_not_collateral(): void
    {
        $diff = <<<'DIFF'
            diff --git a/app/Security/Encrypter.php b/app/Security/Encrypter.php
            --- a/app/Security/Encrypter.php
            +++ b/app/Security/Encrypter.php
            @@ -34 +34 @@
            -    public function encrypt(string $plaintext): string
            +    public function encrypt(string $plaintext, string $context = ''): string
            @@ -42 +42 @@
            -    public function decrypt(string $token): string
            +    public function decrypt(string $token, string $context = ''): string
            DIFF;

        $this->assertSame([], $this->claims('A token can be tied to what it is for', $diff, null));
    }

    public function test_a_rename_that_also_touches_its_other_uses_is_not_collateral(): void
    {
        $diff = <<<'DIFF'
            diff --git a/app/Models/Contact.php b/app/Models/Contact.php
            --- a/app/Models/Contact.php
            +++ b/app/Models/Contact.php
            @@ -20,2 +20,2 @@
            -        $fullName = trim($this->name);
            -        return ucfirst($fullName);
            +        $displayName = trim($this->name);
            +        return ucfirst($displayName);
            DIFF;

        $this->assertSame([], $this->claims('Rename fullName to displayName', $diff, null));
    }

    public function test_a_subject_that_matches_the_diff_is_clean(): void
    {
        $this->assertSame([], $this->claims('Limit contact email to 250 chars', self::EMAIL_DIFF));
        $this->assertSame([], $this->claims('fix(contact): cap emails at 250', self::EMAIL_DIFF), 'prefix stripped, plural matched');
    }

    public function test_a_number_no_added_line_contains_is_flagged(): void
    {
        $findings = $this->claims('Limit contact email to 200 chars', self::EMAIL_DIFF);

        $this->assertSame(['number'], array_column($findings, 'kind'));
        $this->assertSame('200', $findings[0]['term']);
        $this->assertStringContainsString('max:250', $findings[0]['message']);
    }

    public function test_versions_issue_refs_and_hashes_are_not_numbers(): void
    {
        $this->assertSame([], $this->claims('Bump email for v0.4.3, see #42 and 1e9d1bb', self::EMAIL_DIFF));
    }

    public function test_words_the_changed_files_never_mention_are_ignored(): void
    {
        $this->assertSame([], $this->claims('Tidy contact validation for clarity', self::EMAIL_DIFF));
    }

    public function test_a_term_in_a_changed_path_counts_as_touched(): void
    {
        $this->assertSame([], $this->claims('Touch the message request', self::EMAIL_DIFF));
    }

    public function test_camel_case_and_dotted_names_split_into_words(): void
    {
        $this->assertSame(['contact', 'name', 'route', 'contact', 'form', 'html', 'id'], CommitClaims::words('contactName route(\'contact.form\') HTMLId'));
    }

    public function test_merges_reverts_and_empty_messages_are_skipped(): void
    {
        $this->assertSame([], $this->claims('Merge branch name-limit', self::EMAIL_DIFF));
        $this->assertSame([], $this->claims('Revert "Limit contact name to 250 chars"', self::EMAIL_DIFF));
        $this->assertSame([], $this->claims("# Please enter the commit message\n", self::EMAIL_DIFF));
    }

    public function test_the_subject_skips_comments_and_conventional_prefixes(): void
    {
        $this->assertSame('cap emails at 250', CommitClaims::subject("\n# comment\nfeat(contact)!: cap emails at 250\n\nbody"));
    }

    public function test_diff_parsing_keeps_new_side_line_numbers_and_deleted_files(): void
    {
        $files = CommitClaims::parseDiff(<<<'DIFF'
            diff --git a/a.php b/a.php
            --- a/a.php
            +++ b/a.php
            @@ -3,0 +4,2 @@
            +one
            +two
            @@ -9 +10,0 @@
            -gone
            diff --git a/old.php b/old.php
            deleted file mode 100644
            --- a/old.php
            +++ /dev/null
            @@ -1 +0,0 @@
            -bye
            diff --git a/logo.png b/logo.png
            Binary files a/logo.png and b/logo.png differ
            DIFF);

        $this->assertSame([4 => 'one', 5 => 'two'], $files['a.php']['added']);
        $this->assertSame([10 => 'gone'], $files['a.php']['removed']);
        $this->assertSame([1 => 'bye'], $files['old.php']['removed']);
        $this->assertSame([], $files['a.php']['edits'], 'pure additions and deletions are not in-place edits');
        $this->assertArrayNotHasKey('logo.png', $files);
    }

    public function test_diff_parsing_pairs_lines_rewritten_in_place(): void
    {
        $files = CommitClaims::parseDiff(<<<'DIFF'
            diff --git a/a.php b/a.php
            --- a/a.php
            +++ b/a.php
            @@ -3,2 +3,2 @@
            -one
            -two
            +uno
            +dos
            @@ -9 +9,2 @@
            -three
            +tres
            +cuatro
            DIFF);

        $this->assertSame([['line' => 3, 'old' => 'one', 'new' => 'uno'], ['line' => 4, 'old' => 'two', 'new' => 'dos']], $files['a.php']['edits']);
        $this->assertArrayNotHasKey('logo.png', $files);
    }
}
