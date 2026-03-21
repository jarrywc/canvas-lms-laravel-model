<?php

namespace JarredCain\CanvasLms\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Facades\Canvas;
use JarredCain\CanvasLms\Users\UserEmailLookup;
use JarredCain\CanvasLms\Users\UserLookupResult;
use JarredCain\CanvasLms\Tests\TestCase;

class UserEmailLookupTest extends TestCase
{
    private function searchFixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/Responses/users_search.json'),
            true
        );
    }

    private function loginsActiveFixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/Responses/user_logins_active.json'),
            true
        );
    }

    private function loginsSuspendedFixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/Responses/user_logins_suspended.json'),
            true
        );
    }

    // -------------------------------------------------------------------------
    // Basic lookup
    // -------------------------------------------------------------------------

    public function test_found_user_is_mapped_correctly(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response($this->searchFixture(), 200),
        ]);

        $results = Canvas::userEmailLookup()
            ->fromEmails(['alice@example.com'])
            ->lookup();

        $this->assertCount(1, $results);

        /** @var UserLookupResult $result */
        $result = $results->first();
        $this->assertInstanceOf(UserLookupResult::class, $result);
        $this->assertTrue($result->found);
        $this->assertSame('alice@example.com', $result->email);
        $this->assertSame('42', $result->id);
        $this->assertSame('sis_alice', $result->sisUserId);
        $this->assertSame('Alice Smith', $result->name);
        $this->assertNull($result->status); // withStatus() not called
    }

    public function test_not_found_user_returns_correct_result(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response([], 200),
        ]);

        $results = Canvas::userEmailLookup()
            ->fromEmails(['nobody@example.com'])
            ->lookup();

        $result = $results->first();
        $this->assertFalse($result->found);
        $this->assertSame('nobody@example.com', $result->email);
        $this->assertNull($result->id);
        $this->assertNull($result->sisUserId);
        $this->assertNull($result->name);
    }

    public function test_fuzzy_search_results_are_filtered_by_exact_email(): void
    {
        // Fixture returns both alice@example.com and alicia@example.com — only alice should match
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response($this->searchFixture(), 200),
        ]);

        $results = Canvas::userEmailLookup()
            ->fromEmails(['alice@example.com'])
            ->lookup();

        $result = $results->first();
        $this->assertTrue($result->found);
        $this->assertSame('42', $result->id);
        $this->assertSame('Alice Smith', $result->name);
    }

    public function test_multiple_emails_produce_one_result_each(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::sequence()
                ->push($this->searchFixture(), 200)
                ->push([], 200),
        ]);

        $results = Canvas::userEmailLookup()
            ->fromEmails(['alice@example.com', 'missing@example.com'])
            ->lookup();

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->found);
        $this->assertFalse($results[1]->found);
    }

    public function test_search_request_includes_search_term(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response([], 200),
        ]);

        Canvas::userEmailLookup()
            ->fromEmails(['alice@example.com'])
            ->lookup();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'accounts/')
                && str_contains($request->url(), '/users')
                && str_contains(urldecode($request->url()), 'alice@example.com');
        });
    }

    // -------------------------------------------------------------------------
    // withStatus()
    // -------------------------------------------------------------------------

    public function test_with_status_returns_active_for_active_logins(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*'  => Http::response($this->searchFixture(), 200),
            'canvas.example.com/api/v1/users/42/logins*'   => Http::response($this->loginsActiveFixture(), 200),
        ]);

        $result = Canvas::userEmailLookup()
            ->fromEmails(['alice@example.com'])
            ->withStatus()
            ->lookup()
            ->first();

        $this->assertSame('active', $result->status);
    }

    public function test_with_status_returns_suspended_when_login_is_suspended(): void
    {
        $searchFixture = [['id' => 43, 'name' => 'Bob Jones', 'email' => 'bob@example.com', 'sis_user_id' => 'sis_bob']];

        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response($searchFixture, 200),
            'canvas.example.com/api/v1/users/43/logins*'  => Http::response($this->loginsSuspendedFixture(), 200),
        ]);

        $result = Canvas::userEmailLookup()
            ->fromEmails(['bob@example.com'])
            ->withStatus()
            ->lookup()
            ->first();

        $this->assertSame('suspended', $result->status);
    }

    public function test_with_status_does_not_fetch_logins_for_not_found_users(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response([], 200),
        ]);

        Canvas::userEmailLookup()
            ->fromEmails(['ghost@example.com'])
            ->withStatus()
            ->lookup();

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/logins');
        });
    }

    public function test_without_status_no_logins_request_is_made(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response($this->searchFixture(), 200),
        ]);

        Canvas::userEmailLookup()
            ->fromEmails(['alice@example.com'])
            ->lookup();

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/logins');
        });
    }

    // -------------------------------------------------------------------------
    // fromCsvString / fromCsv
    // -------------------------------------------------------------------------

    public function test_from_csv_string_reads_email_column(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response($this->searchFixture(), 200),
        ]);

        $csv = "email,name\nalice@example.com,Alice\nbob@example.com,Bob\n";

        $results = Canvas::userEmailLookup()
            ->fromCsvString($csv)
            ->lookup();

        $this->assertCount(2, $results);
    }

    public function test_from_csv_string_supports_custom_column_name(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response([], 200),
        ]);

        $csv = "Email Address,Department\nalice@example.com,Math\n";

        $results = Canvas::userEmailLookup()
            ->fromCsvString($csv, 'Email Address')
            ->lookup();

        $this->assertCount(1, $results);
        $this->assertSame('alice@example.com', $results->first()->email);
    }

    public function test_from_csv_string_throws_if_column_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Column 'email' not found");

        Canvas::userEmailLookup()->fromCsvString("name,department\nalice,Math\n");
    }

    public function test_from_csv_string_deduplicates_emails(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response([], 200),
        ]);

        $csv = "email\nalice@example.com\nalice@example.com\nbob@example.com\n";

        $results = Canvas::userEmailLookup()->fromCsvString($csv)->lookup();

        $this->assertCount(2, $results);
    }

    // -------------------------------------------------------------------------
    // toCsv()
    // -------------------------------------------------------------------------

    public function test_to_csv_without_status_produces_correct_columns(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response($this->searchFixture(), 200),
        ]);

        $csv   = Canvas::userEmailLookup()->fromEmails(['alice@example.com'])->toCsv();
        $lines = $this->csvLines($csv);

        $this->assertSame(['email', 'id', 'sis_user_id', 'name', 'found'], $lines[0]);
        $this->assertSame(['alice@example.com', '42', 'sis_alice', 'Alice Smith', 'true'], $lines[1]);
    }

    public function test_to_csv_with_status_includes_status_column(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response($this->searchFixture(), 200),
            'canvas.example.com/api/v1/users/42/logins*'  => Http::response($this->loginsActiveFixture(), 200),
        ]);

        $csv   = Canvas::userEmailLookup()
            ->fromEmails(['alice@example.com'])
            ->withStatus()
            ->toCsv();

        $lines = $this->csvLines($csv);

        $this->assertSame(['email', 'id', 'sis_user_id', 'name', 'status', 'found'], $lines[0]);
        $this->assertSame(['alice@example.com', '42', 'sis_alice', 'Alice Smith', 'active', 'true'], $lines[1]);
    }

    public function test_to_csv_not_found_row_has_empty_fields(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/*/users*' => Http::response([], 200),
        ]);

        $csv   = Canvas::userEmailLookup()->fromEmails(['ghost@example.com'])->toCsv();
        $lines = $this->csvLines($csv);

        $this->assertSame(['ghost@example.com', '', '', '', 'false'], $lines[1]);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_lookup_throws_when_no_emails_provided(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No emails to look up');

        Canvas::userEmailLookup()->lookup();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function csvLines(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }
}