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

    /**
     * Fake the account-level users endpoint that provides name + email lookup.
     */
    private function fakeAccountUsers(array $users): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                $users,
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
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

        $users = Canvas::courseUserList()->courses([23])->get();

        $this->assertCount(2, $users);
        $this->assertSame('1', $users[0]['id']);
        $this->assertSame('alice@example.com', $users[0]['email']);
    }

    public function test_deduplicates_users_appearing_in_multiple_courses(): void
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
                [
                    ['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment'],
                    ['id' => '101', 'user_id' => '2', 'type' => 'StudentEnrollment'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
            'canvas.example.com/api/v1/courses/24/enrollments*' => Http::response(
                [
                    ['id' => '102', 'user_id' => '2', 'type' => 'StudentEnrollment'], // duplicate
                    ['id' => '103', 'user_id' => '3', 'type' => 'StudentEnrollment'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/24/enrollments')]
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
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [
                    ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => '2', 'name' => 'Bob',   'email' => 'bob@example.com'],
                    ['id' => '3', 'name' => 'Carol', 'email' => 'carol@example.com'],
                ],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/10/enrollments*' => Http::response(
                [['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/10/enrollments')]
            ),
            'canvas.example.com/api/v1/courses/11/enrollments*' => Http::response(
                [['id' => '101', 'user_id' => '2', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/11/enrollments')]
            ),
            'canvas.example.com/api/v1/courses/12/enrollments*' => Http::response(
                [['id' => '102', 'user_id' => '3', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/12/enrollments')]
            ),
        ]);

        $users = Canvas::courseUserList()->courseRange(10, 12)->get();

        $this->assertCount(3, $users);

        // Confirm all three courses were requested
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/10/enrollments'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/11/enrollments'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/12/enrollments'));
    }

    public function test_returns_empty_collection_when_account_has_no_courses(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/courses*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/courses')]
            ),
        ]);

        $users = Canvas::courseUserList()->get();

        $this->assertCount(0, $users);
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
                [['id' => '100', 'user_id' => '9', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/5/enrollments')]
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
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        Canvas::courseUserList()->courses([23])->studentsOnly()->get();

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());
            return str_contains($url, 'type[]=StudentEnrollment');
        });
    }

    public function test_continues_when_one_course_returns_500(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
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
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
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
            'canvas.example.com/api/v1/courses/24/enrollments*' => Http::response(
                [['id' => '101', 'user_id' => '2', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/24/enrollments')]
            ),
        ]);

        $users = Canvas::courseUserList()->forAccount(1)->get();

        $this->assertCount(2, $users);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts/1/courses'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts/1/users'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/23/enrollments'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'courses/24/enrollments'));
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
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [['id' => '100', 'user_id' => '1', 'type' => 'StudentEnrollment']],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        $users = Canvas::courseUserList()->forAccount(1)->courses([23])->get();

        $this->assertCount(1, $users);
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

        $collector = Canvas::courseUserList()->forAccount(1);
        $users     = $collector->get();

        $this->assertCount(0, $users);
        $this->assertArrayHasKey('_account', $collector->getErrors());
        $this->assertStringContainsString('500', $collector->getErrors()['_account']);
    }

    public function test_without_enrollment_filter_does_not_send_type(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/accounts/1/users*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/accounts/1/users')]
            ),
            'canvas.example.com/api/v1/courses/23/enrollments*' => Http::response(
                [],
                200,
                ['Link' => $this->noNextLink('https://canvas.example.com/api/v1/courses/23/enrollments')]
            ),
        ]);

        Canvas::courseUserList()->courses([23])->get();

        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), 'enrollments')) {
                return true; // skip non-enrollment requests
            }
            $url = urldecode($request->url());
            return !str_contains($url, 'type[]=');
        });
    }
}
