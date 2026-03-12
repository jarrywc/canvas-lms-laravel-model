<?php

namespace JarredCain\CanvasLms\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Exceptions\MissingContextException;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Models\Enrollment;
use JarredCain\CanvasLms\Tests\TestCase;

class EnrollmentRelationTest extends TestCase
{
    public function test_can_list_enrollments_via_course_relationship(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/1/enrollments*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/Responses/enrollments_list.json'), true),
                200
            ),
        ]);

        $course      = Course::newWithId(1);
        $enrollments = $course->enrollments()->get();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'courses/1/enrollments');
        });

        $this->assertCount(2, $enrollments);
        $this->assertInstanceOf(Enrollment::class, $enrollments->first());
    }

    public function test_can_filter_enrollments_via_relationship(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/1/enrollments*' => Http::response([], 200),
        ]);

        $course = Course::newWithId(1);
        $course->enrollments()
            ->whereIn('type', ['StudentEnrollment'])
            ->where('enrollment_state', 'active')
            ->get();

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());
            return str_contains($url, 'courses/1/enrollments')
                && str_contains($url, 'type[]=StudentEnrollment')
                && str_contains($url, 'enrollment_state=active');
        });
    }

    public function test_throws_when_enrollment_queried_without_context(): void
    {
        $this->expectException(MissingContextException::class);

        Http::fake(['*' => Http::response([], 200)]);

        Enrollment::query()->get();
    }

    public function test_can_query_enrollments_via_builder_for_course(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/42/enrollments*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/Responses/enrollments_list.json'), true),
                200
            ),
        ]);

        $enrollments = Enrollment::query()->forCourse(42)->get();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'courses/42/enrollments');
        });

        $this->assertCount(2, $enrollments);
    }

    public function test_enrollment_grades_are_cast_to_array(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/1/enrollments*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/Responses/enrollments_list.json'), true),
                200
            ),
        ]);

        $enrollments = Enrollment::query()->forCourse(1)->get();
        $enrollment  = $enrollments->first();

        $this->assertIsArray($enrollment->grades);
        $this->assertArrayHasKey('current_score', $enrollment->grades);
    }
}
