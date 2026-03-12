<?php

namespace JarredCain\CanvasLms\Tests\Unit\Builder;

use JarredCain\CanvasLms\Exceptions\MissingContextException;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Models\Enrollment;
use JarredCain\CanvasLms\Models\Submission;
use JarredCain\CanvasLms\Query\Builder;
use JarredCain\CanvasLms\Tests\TestCase;

class BuilderUrlTest extends TestCase
{
    public function test_builds_top_level_url(): void
    {
        $builder = new Builder(Course::class);

        $this->assertSame('api/v1/courses', $builder->buildUrl());
    }

    public function test_builds_top_level_url_with_id(): void
    {
        $builder = new Builder(Course::class);

        $this->assertSame('api/v1/courses/42', $builder->buildUrl(42));
    }

    public function test_builds_nested_url_with_single_context(): void
    {
        $builder = new Builder(Enrollment::class);
        $builder->forCourse(42);

        $this->assertSame('api/v1/courses/42/enrollments', $builder->buildUrl());
    }

    public function test_builds_deeply_nested_url_with_multiple_contexts(): void
    {
        $builder = new Builder(Submission::class);
        $builder->forCourse(42)->forAssignment(99);

        $this->assertSame('api/v1/courses/42/assignments/99/submissions', $builder->buildUrl());
    }

    public function test_throws_missing_context_exception_for_context_required_model(): void
    {
        $this->expectException(MissingContextException::class);
        $this->expectExceptionMessageMatches('/Enrollment/');

        $builder = new Builder(Enrollment::class);
        $builder->buildUrl();
    }

    public function test_does_not_throw_when_context_is_provided(): void
    {
        $builder = new Builder(Enrollment::class);
        $builder->forCourse(1);

        $this->assertSame('api/v1/courses/1/enrollments', $builder->buildUrl());
    }

    public function test_context_methods_are_chainable(): void
    {
        $builder = new Builder(Submission::class);
        $result  = $builder->forCourse(1)->forAssignment(5);

        $this->assertSame($builder, $result);
    }
}
