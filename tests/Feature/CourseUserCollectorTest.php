<?php

namespace JarredCain\CanvasLms\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Facades\Canvas;
use JarredCain\CanvasLms\Tests\TestCase;
use JarredCain\CanvasLms\Utilities\CourseUserCollector;

class CourseUserCollectorTest extends TestCase
{
    private function noNextLink(string $url): string
    {
        return "<{$url}>; rel=\"current\"";
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fakeCourseUsers(int|string $courseId, array $users): void
    {
        Http::fake([
            "canvas.example.com/api/v1/courses/{$courseId}/users*" => Http::response(
                $users,
                200,
                ['Link' => $this->noNextLink("https://canvas.example.com/api/v1/courses/{$courseId}/users")]
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_returns_collector_instance(): void
    {
        $this->assertInstanceOf(CourseUserCollector::class, Canvas::courseUserList());
    }

    public function test_collects_users_from_single_course(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        $users = Canvas::courseUserList()->courses([23])->get();

        $this->assertCount(2, $users);
        $this->assertSame('1', $users[0]['id']);
        $this->assertSame('alice@example.com', $users[0]['email']);
    }

    public function test_deduplicates_users_appearing_in_multiple_courses(): void
    {
        Http::fake([
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
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'], // duplicate
                    ['id' => '3', 'name' => 'Carol', 'email' => 'carol@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/24/users')]
            ),
        ]);

        $users = Canvas::courseUserList()->courses([23, 24])->get();

        $this->assertCount(3, $users);

        $ids = $users->pluck('id')->all();
        $this->assertSame(['1', '2', '3'], $ids);
    }

    public function test_course_range_expands_to_inclusive_list(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/10/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/10/users')]
            ),
            'canvas.example.com/api/v1/courses/11/users*' => Http::response(
                [['id' => '2', 'name' => 'Bob', 'email' => 'bob@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/11/users')]
            ),
            'canvas.example.com/api/v1/courses/12/users*' => Http::response(
                [['id' => '3', 'name' => 'Carol', 'email' => 'carol@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/12/users')]
            ),
        ]);

        $users = Canvas::courseUserList()->courseRange(10, 12)->get();

        $this->assertCount(3, $users);

        // Confirm all three courses were requested
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/10/users'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/11/users'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/12/users'));
    }

    public function test_requests_include_email(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        Canvas::courseUserList()->courses([23])->get();

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());
            return str_contains($url, 'include[0]=email')
                || str_contains($url, 'include[]=email');
        });
    }

    public function test_returns_empty_collection_when_no_courses_set(): void
    {
        Http::fake();

        $users = Canvas::courseUserList()->get();

        $this->assertCount(0, $users);
        Http::assertNothingSent();
    }

    public function test_each_result_has_id_name_and_email_keys(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/5/users*' => Http::response(
                [['id' => '9', 'name' => 'Dana', 'email' => 'dana@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/5/users')]
            ),
        ]);

        $users = Canvas::courseUserList()->courses([5])->get();

        $user = $users->first();
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('name', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertSame('Dana', $user['name']);
        $this->assertCount(3, $user);
    }

    public function test_students_only_sends_enrollment_type_parameter(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        Canvas::courseUserList()->courses([23])->studentsOnly()->get();

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());
            return str_contains($url, 'enrollment_type')
                && str_contains($url, 'student');
        });
    }

    public function test_continues_when_one_course_returns_500(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                ['errors' => [['message' => 'An error occurred.']]],
                500,
            ),
            'canvas.example.com/api/v1/courses/24/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/24/users')]
            ),
        ]);

        $collector = Canvas::courseUserList()->courses([23, 24]);
        $users     = $collector->get();

        $this->assertCount(1, $users);
        $this->assertSame('1', $users[0]['id']);

        $errors = $collector->getErrors();
        $this->assertArrayHasKey('23', $errors);
        $this->assertStringContainsString('500', $errors['23']);
    }

    public function test_get_errors_is_empty_when_all_courses_succeed(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        $collector = Canvas::courseUserList()->courses([23]);
        $collector->get();

        $this->assertEmpty($collector->getErrors());
    }

    // -------------------------------------------------------------------------
    // forAccount()
    // -------------------------------------------------------------------------

    public function test_for_account_fetches_courses_then_users(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [['id' => '23', 'name' => 'Math 101'], ['id' => '24', 'name' => 'Science 201']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
            'canvas.example.com/api/v1/courses/24/users*' => Http::response(
                [['id' => '2', 'name' => 'Bob', 'email' => 'bob@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/24/users')]
            ),
        ]);

        $users = Canvas::courseUserList()->forAccount(1)->get();

        $this->assertCount(2, $users);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts/1/courses'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/23/users'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/24/users'));
    }

    public function test_for_account_defaults_to_config_account_id(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
        ]);

        Canvas::courseUserList()->forAccount()->get();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts/1/courses'));
    }

    public function test_explicit_courses_override_for_account(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        $users = Canvas::courseUserList()->forAccount(1)->courses([23])->get();

        $this->assertCount(1, $users);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'accounts/'));
    }

    public function test_for_account_records_error_when_account_fetch_fails(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                ['errors' => [['message' => 'An error occurred.']]],
                500,
            ),
        ]);

        $collector = Canvas::courseUserList()->forAccount(1);
        $users     = $collector->get();

        $this->assertCount(0, $users);
        $this->assertArrayHasKey('_account', $collector->getErrors());
        $this->assertStringContainsString('500', $collector->getErrors()['_account']);
    }

    public function test_without_enrollment_filter_does_not_send_enrollment_type(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/23/users*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/users')]
            ),
        ]);

        Canvas::courseUserList()->courses([23])->get();

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());
            return !str_contains($url, 'enrollment_type');
        });
    }
}
