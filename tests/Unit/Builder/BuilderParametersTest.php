<?php

namespace JarredCain\CanvasLms\Tests\Unit\Builder;

use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Models\Enrollment;
use JarredCain\CanvasLms\Query\Builder;
use JarredCain\CanvasLms\Tests\TestCase;

class BuilderParametersTest extends TestCase
{
    public function test_where_adds_scalar_parameter(): void
    {
        $builder = new Builder(Course::class);
        $builder->where('workflow_state', 'available');

        $this->assertSame(['workflow_state' => 'available', 'per_page' => 100], $builder->buildQueryParameters());
    }

    public function test_where_in_adds_array_parameter(): void
    {
        $builder = new Builder(Enrollment::class);
        $builder->whereIn('type', ['StudentEnrollment', 'TeacherEnrollment']);

        $params = $builder->buildQueryParameters();
        $this->assertSame(['StudentEnrollment', 'TeacherEnrollment'], $params['type']);
    }

    public function test_include_adds_to_include_array(): void
    {
        $builder = new Builder(Course::class);
        $builder->include(['teachers', 'term']);

        $params = $builder->buildQueryParameters();
        $this->assertSame(['teachers', 'term'], $params['include']);
    }

    public function test_include_deduplicates(): void
    {
        $builder = new Builder(Course::class);
        $builder->include('teachers')->include('teachers')->include('term');

        $params = $builder->buildQueryParameters();
        $this->assertSame(['teachers', 'term'], $params['include']);
    }

    public function test_search_sets_search_term_parameter(): void
    {
        $builder = new Builder(Course::class);
        $builder->search('biology');

        $this->assertSame(['search_term' => 'biology', 'per_page' => 100], $builder->buildQueryParameters());
    }

    public function test_per_page_sets_per_page_parameter(): void
    {
        $builder = new Builder(Course::class);
        $builder->perPage(50);

        $this->assertSame(['per_page' => 50], $builder->buildQueryParameters());
    }

    public function test_order_by_sets_sort_and_order_parameters(): void
    {
        $builder = new Builder(Course::class);
        $builder->orderBy('name', 'desc');

        $params = $builder->buildQueryParameters();
        $this->assertSame('name', $params['sort']);
        $this->assertSame('desc', $params['order']);
    }

    public function test_methods_are_chainable(): void
    {
        $builder = new Builder(Course::class);
        $result  = $builder->where('state', 'active')->include('teachers')->perPage(25);

        $this->assertSame($builder, $result);
    }

    public function test_empty_parameters_when_no_filters_set(): void
    {
        $builder = new Builder(Course::class);

        $this->assertSame(['per_page' => 100], $builder->buildQueryParameters());
    }
}
