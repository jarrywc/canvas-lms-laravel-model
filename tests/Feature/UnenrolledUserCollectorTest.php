<?php

namespace JarredCain\CanvasLms\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Facades\Canvas;
use JarredCain\CanvasLms\Tests\TestCase;
use JarredCain\CanvasLms\Utilities\UnenrolledUserCollector;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UnenrolledUserCollectorTest extends TestCase
{
    private function noNextLink(string $url): string
    {
        return "<{$url}>; rel=\"current\"";
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_returns_collector_instance(): void
    {
        $this->assertInstanceOf(UnenrolledUserCollector::class, Canvas::unenrolledUsers());
    }

    public function test_returns_only_unenrolled_users(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [['id' => '23', 'name' => 'Math 101']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                    ['id' => '3', 'name' => 'Carol', 'email' => 'carol@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [
                    ['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment'],
                    ['id' => '101', 'user_id' => '2', 'type' => 'StudentEnrollment'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        $users = Canvas::unenrolledUsers()->get();

        $this->assertCount(1, $users);
        $this->assertSame('3', $users[0]['id']);
        $this->assertSame('Carol', $users[0]['name']);
        $this->assertSame('carol@example.com', $users[0]['email']);
    }

    public function test_returns_empty_when_all_users_are_enrolled(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [
                    ['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment'],
                    ['id' => '101', 'user_id' => '2', 'type' => 'StudentEnrollment'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        $users = Canvas::unenrolledUsers()->courses([23])->get();

        $this->assertCount(0, $users);
    }

    public function test_returns_all_users_when_no_courses_have_enrollments(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        $users = Canvas::unenrolledUsers()->courses([23])->get();

        $this->assertCount(2, $users);
    }

    public function test_returns_all_users_when_account_has_no_courses(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
        ]);

        $users = Canvas::unenrolledUsers()->get();

        $this->assertCount(2, $users);
    }

    public function test_courses_scoping_only_checks_specified_courses(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                    ['id' => '3', 'name' => 'Carol', 'email' => 'carol@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        // Only checking course 23 — Bob is enrolled in course 24 (not checked), so he appears as unenrolled
        $users = Canvas::unenrolledUsers()->courses([23])->get();

        $this->assertCount(2, $users);
        $ids = $users->pluck('id')->all();
        $this->assertContains('2', $ids);
        $this->assertContains('3', $ids);
        $this->assertNotContains('1', $ids);
    }

    public function test_students_only_sends_enrollment_type_parameter(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        Canvas::unenrolledUsers()->courses([23])->studentsOnly()->get();

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());
            return str_contains($url, 'type[]=StudentEnrollment');
        });
    }

    public function test_course_range_expands_to_inclusive_list(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/10/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/10/enrollments')]
            ),
            'canvas.example.com/api/v1/courses/11/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/11/enrollments')]
            ),
            'canvas.example.com/api/v1/courses/12/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/12/enrollments')]
            ),
        ]);

        Canvas::unenrolledUsers()->courseRange(10, 12)->get();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/10/enrollments'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/11/enrollments'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/12/enrollments'));
    }

    public function test_continues_when_one_course_returns_500(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                ['errors' => [['message' => 'An error occurred.']]],
                500,
            ),
            'canvas.example.com/api/v1/courses/24/enrollments*' => Http::response(
                [['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/24/enrollments')]
            ),
        ]);

        $collector = Canvas::unenrolledUsers()->courses([23, 24]);
        $users     = $collector->get();

        // Only user 1 is enrolled in course 24, so user 2 is unenrolled
        $this->assertCount(1, $users);
        $this->assertSame('2', $users[0]['id']);

        $errors = $collector->getErrors();
        $this->assertArrayHasKey('23', $errors);
        $this->assertStringContainsString('500', $errors['23']);
    }

    public function test_get_errors_is_empty_when_all_courses_succeed(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        $collector = Canvas::unenrolledUsers()->courses([23]);
        $collector->get();

        $this->assertEmpty($collector->getErrors());
    }

    public function test_each_result_has_id_name_and_email_keys(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '9', 'name' => 'Dana', 'email' => 'dana@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/5/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/5/enrollments')]
            ),
        ]);

        $users = Canvas::unenrolledUsers()->courses([5])->get();

        $user = $users->first();
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('name', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertSame('Dana', $user['name']);
        $this->assertCount(3, $user);
    }

    public function test_for_account_defaults_to_config_account_id(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
        ]);

        Canvas::unenrolledUsers()->get();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts/1/'));
    }

    // -------------------------------------------------------------------------
    // CSV / Response output
    // -------------------------------------------------------------------------

    public function test_to_csv_returns_csv_string(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        $csv   = Canvas::unenrolledUsers()->courses([23])->toCsv();
        $lines = explode("\n", trim($csv));

        $this->assertCount(2, $lines); // header + 1 data row
        $this->assertStringContainsString('user_id', $lines[0]);
        $this->assertStringContainsString('user_name', $lines[0]);
        $this->assertStringContainsString('user_email', $lines[0]);
        $this->assertStringContainsString('Bob', $lines[1]);
        $this->assertStringContainsString('bob@example.com', $lines[1]);
    }

    public function test_to_file_writes_csv_to_disk(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'unenrolled_') . '.csv';

        try {
            Canvas::unenrolledUsers()->courses([23])->toFile($path);

            $this->assertFileExists($path);
            $contents = file_get_contents($path);
            $this->assertStringContainsString('user_id', $contents);
            $this->assertStringContainsString('Alice', $contents);
        } finally {
            @unlink($path);
        }
    }

    public function test_to_response_returns_streamed_response(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        $response = Canvas::unenrolledUsers()->courses([23])->toResponse('unenrolled.csv');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('unenrolled.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_explicit_courses_override_for_account(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        Canvas::unenrolledUsers()->courses([23])->get();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'accounts/1/courses'));
    }

    public function test_for_account_records_error_when_account_fetch_fails(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                ['errors' => [['message' => 'An error occurred.']]],
                500,
            ),
        ]);

        $collector = Canvas::unenrolledUsers();
        $users     = $collector->get();

        $this->assertCount(0, $users);
        $this->assertArrayHasKey('_account', $collector->getErrors());
        $this->assertStringContainsString('500', $collector->getErrors()['_account']);
    }
}
