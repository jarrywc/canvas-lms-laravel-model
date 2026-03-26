<?php

namespace JarredCain\CanvasLms\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Facades\Canvas;
use JarredCain\CanvasLms\Tests\TestCase;
use JarredCain\CanvasLms\Utilities\AccountUserReport;

class AccountUserReportTest extends TestCase
{
    private function noNextLink(string $url): string
    {
        return "<{$url}>; rel=\"current\"";
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public function test_returns_report_instance(): void
    {
        $this->assertInstanceOf(AccountUserReport::class, Canvas::accountUserReport());
    }

    // -------------------------------------------------------------------------
    // get() — basic behavior
    // -------------------------------------------------------------------------

    public function test_returns_users_organized_by_course(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [
                    ['id' => '23', 'name' => 'Biology 101'],
                    ['id' => '24', 'name' => 'Chemistry 201'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
            'canvas.example.com/api/v1/courses/24/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '3', 'name' => 'Carol', 'email' => 'carol@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/24/users')]
            ),
        ]);

        $results = Canvas::accountUserReport()->forAccount(1)->get();

        // Alice appears in both courses — NOT deduplicated
        $this->assertCount(4, $results);

        $this->assertSame('23', $results[0]['course_id']);
        $this->assertSame('Biology 101', $results[0]['course_name']);
        $this->assertSame('1', $results[0]['user_id']);
        $this->assertSame('Alice', $results[0]['user_name']);
        $this->assertSame('alice@example.com', $results[0]['user_email']);

        $this->assertSame('24', $results[2]['course_id']);
        $this->assertSame('Chemistry 201', $results[2]['course_name']);
    }

    public function test_does_not_deduplicate_users_across_courses(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [
                    ['id' => '23', 'name' => 'Course A'],
                    ['id' => '24', 'name' => 'Course B'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
            'canvas.example.com/api/v1/courses/24/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/24/users')]
            ),
        ]);

        $results = Canvas::accountUserReport()->forAccount(1)->get();

        $this->assertCount(2, $results);
        $this->assertSame('23', $results[0]['course_id']);
        $this->assertSame('24', $results[1]['course_id']);
    }

    // -------------------------------------------------------------------------
    // Enrollment type filtering
    // -------------------------------------------------------------------------

    public function test_students_only_sends_enrollment_type_parameter(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [['id' => '23', 'name' => 'Math']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        Canvas::accountUserReport()->forAccount(1)->studentsOnly()->get();

        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), 'courses/23/users')) {
                return false;
            }
            $url = urldecode($request->url());
            return str_contains($url, 'enrollment_type')
                && str_contains($url, 'student');
        });
    }

    // -------------------------------------------------------------------------
    // Explicit courses
    // -------------------------------------------------------------------------

    public function test_explicit_courses_fetches_course_names(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/23' => Http::response(
                ['id' => '23', 'name' => 'Biology 101'],
                200,
            ),
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        $results = Canvas::accountUserReport()->courses([23])->get();

        $this->assertCount(1, $results);
        $this->assertSame('Biology 101', $results[0]['course_name']);
    }

    // -------------------------------------------------------------------------
    // CSV output
    // -------------------------------------------------------------------------

    public function test_to_csv_returns_csv_string(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [['id' => '23', 'name' => 'Biology 101']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        $csv = Canvas::accountUserReport()->forAccount(1)->toCsv();

        $lines = explode("\n", trim($csv));
        $this->assertCount(2, $lines); // header + 1 row

        $this->assertStringContainsString('course_id', $lines[0]);
        $this->assertStringContainsString('course_name', $lines[0]);
        $this->assertStringContainsString('user_id', $lines[0]);
        $this->assertStringContainsString('user_name', $lines[0]);
        $this->assertStringContainsString('user_email', $lines[0]);

        $this->assertStringContainsString('23', $lines[1]);
        $this->assertStringContainsString('Biology 101', $lines[1]);
        $this->assertStringContainsString('Alice', $lines[1]);
        $this->assertStringContainsString('alice@example.com', $lines[1]);
    }

    public function test_to_file_writes_csv(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [['id' => '23', 'name' => 'Biology']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        $path = sys_get_temp_dir() . '/account_user_report_test_' . uniqid() . '.csv';

        try {
            Canvas::accountUserReport()->forAccount(1)->toFile($path);

            $this->assertFileExists($path);
            $content = file_get_contents($path);
            $this->assertStringContainsString('course_id', $content);
            $this->assertStringContainsString('Alice', $content);
        } finally {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function test_returns_empty_when_account_has_no_courses(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
        ]);

        $results = Canvas::accountUserReport()->get();

        $this->assertCount(0, $results);
    }

    public function test_skips_courses_with_errors_and_records_them(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [
                    ['id' => '23', 'name' => 'Good Course'],
                    ['id' => '24', 'name' => 'Bad Course'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
            'canvas.example.com/api/v1/courses/24/users*' => Http::response(
                ['errors' => [['message' => 'An error occurred.']]],
                500,
            ),
        ]);

        $report  = Canvas::accountUserReport()->forAccount(1);
        $results = $report->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('24', $report->getErrors());
    }
}
