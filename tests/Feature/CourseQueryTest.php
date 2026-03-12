<?php

namespace JarredCain\CanvasLms\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Tests\TestCase;

class CourseQueryTest extends TestCase
{
    public function test_can_list_courses(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/Responses/courses_list.json'), true),
                200,
                ['Link' => '<https://canvas.example.com/api/v1/courses?page=1>; rel="current"']
            ),
        ]);

        $courses = Course::query()->get();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'canvas.example.com/api/v1/courses')
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });

        $this->assertCount(2, $courses);
        $this->assertInstanceOf(Course::class, $courses->first());
        $this->assertSame('Introduction to Biology', $courses->first()->name);
    }

    public function test_can_find_course_by_id(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses/1' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/Responses/course_single.json'), true),
                200
            ),
        ]);

        $course = Course::find(1);

        $this->assertInstanceOf(Course::class, $course);
        $this->assertSame('1', $course->id);
        $this->assertSame('Introduction to Biology', $course->name);
        $this->assertSame('BIO101', $course->course_code);
    }

    public function test_can_filter_courses_with_where(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses*' => Http::response([], 200),
        ]);

        Course::query()->where('enrollment_type', 'teacher')->get();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'enrollment_type=teacher');
        });
    }

    public function test_can_filter_with_where_in(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses*' => Http::response([], 200),
        ]);

        Course::query()->whereIn('enrollment_state', ['active', 'completed'])->get();

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());
            return str_contains($url, 'enrollment_state[]=active')
                && str_contains($url, 'enrollment_state[]=completed');
        });
    }

    public function test_can_include_sideloaded_data(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses*' => Http::response([], 200),
        ]);

        Course::query()->include(['teachers', 'term'])->get();

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());
            return str_contains($url, 'include[]=teachers')
                && str_contains($url, 'include[]=term');
        });
    }

    public function test_can_search_courses(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses*' => Http::response([], 200),
        ]);

        Course::query()->search('biology')->get();

        Http::assertSent(function (Request $request) {
            return str_contains(urldecode($request->url()), 'search_term=biology');
        });
    }

    public function test_can_paginate_with_per_page(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses*' => Http::response([], 200),
        ]);

        Course::query()->perPage(50)->get();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'per_page=50');
        });
    }

    public function test_can_create_a_course(): void
    {
        $created = ['id' => '99', 'name' => 'New Course', 'course_code' => 'NEW101'];

        Http::fake([
            'canvas.example.com/api/v1/courses' => Http::response($created, 201),
        ]);

        $course = Course::query()->create(['name' => 'New Course', 'course_code' => 'NEW101']);

        Http::assertSent(function (Request $request) {
            return $request->isJson()
                && $request->method() === 'POST'
                && str_contains($request->url(), 'api/v1/courses');
        });

        $this->assertSame('New Course', $course->name);
    }
}
