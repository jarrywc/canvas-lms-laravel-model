<?php

namespace JarredCain\CanvasLms\Tests\Unit\Models;

use Carbon\Carbon;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Models\Enrollment;
use JarredCain\CanvasLms\Tests\TestCase;

class CanvasModelTest extends TestCase
{
    public function test_magic_get_returns_attribute(): void
    {
        $course = new Course(['name' => 'Biology 101']);

        $this->assertSame('Biology 101', $course->name);
    }

    public function test_magic_get_returns_null_for_missing_attribute(): void
    {
        $course = new Course();

        $this->assertNull($course->nonexistent_field);
    }

    public function test_magic_set_updates_attribute(): void
    {
        $course       = new Course();
        $course->name = 'Updated Name';

        $this->assertSame('Updated Name', $course->name);
    }

    public function test_id_is_cast_to_string(): void
    {
        $course = new Course(['id' => 42]);

        $this->assertSame('42', $course->id);
        $this->assertIsString($course->id);
    }

    public function test_datetime_cast_returns_carbon_instance(): void
    {
        $course = new Course(['created_at' => '2024-01-15T10:00:00Z']);

        $this->assertInstanceOf(Carbon::class, $course->created_at);
    }

    public function test_bool_cast(): void
    {
        $course = new Course(['is_public' => 0]);

        $this->assertFalse($course->is_public);
        $this->assertIsBool($course->is_public);
    }

    public function test_float_cast(): void
    {
        $enrollment = new Enrollment(['total_activity_time' => '3600']);

        $this->assertIsInt($enrollment->total_activity_time);
        $this->assertSame(3600, $enrollment->total_activity_time);
    }

    public function test_fill_merges_attributes(): void
    {
        $course = new Course(['name' => 'Old Name']);
        $course->fill(['name' => 'New Name', 'course_code' => 'BIO101']);

        $this->assertSame('New Name', $course->name);
        $this->assertSame('BIO101', $course->course_code);
    }

    public function test_to_array_returns_raw_attributes(): void
    {
        $attrs  = ['id' => '1', 'name' => 'Biology'];
        $course = new Course($attrs);

        $this->assertSame($attrs, $course->toArray());
    }

    public function test_new_with_id_sets_id_only(): void
    {
        $course = Course::newWithId(42);

        $this->assertSame('42', $course->id);
        $this->assertNull($course->name);
    }

    public function test_get_endpoint_returns_static_endpoint(): void
    {
        $this->assertSame('courses', Course::getEndpoint());
        $this->assertSame('enrollments', Enrollment::getEndpoint());
    }

    public function test_requires_context_reflects_model_setting(): void
    {
        $this->assertFalse(Course::requiresContext());
        $this->assertTrue(Enrollment::requiresContext());
    }
}
