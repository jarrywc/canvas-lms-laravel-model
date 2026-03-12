<?php

namespace JarredCain\CanvasLms\Tests\Feature;

use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Tests\TestCase;

class PaginationTest extends TestCase
{
    public function test_paginated_response_detects_next_page(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/courses*' => Http::response(
                [['id' => '1', 'name' => 'Biology']],
                200,
                ['Link' => '<https://canvas.example.com/api/v1/courses?page=2&per_page=1>; rel="next"']
            ),
        ]);

        $page = Course::query()->perPage(1)->get();

        $this->assertTrue($page->hasNextPage());
        $this->assertCount(1, $page);
    }

    public function test_all_follows_all_pages(): void
    {
        Http::fakeSequence()
            ->push(
                [['id' => '1', 'name' => 'Biology']],
                200,
                ['Link' => '<https://canvas.example.com/api/v1/courses?page=2>; rel="next"']
            )
            ->push(
                [['id' => '2', 'name' => 'Chemistry']],
                200,
                ['Link' => '<https://canvas.example.com/api/v1/courses?page=2>; rel="current"']
            );

        $all = Course::query()->all();

        $this->assertCount(2, $all);
        $this->assertSame('Biology', $all->first()->name);
        $this->assertSame('Chemistry', $all->last()->name);
    }

    public function test_lazy_streams_all_pages(): void
    {
        Http::fakeSequence()
            ->push(
                [['id' => '1', 'name' => 'Biology']],
                200,
                ['Link' => '<https://canvas.example.com/api/v1/courses?page=2>; rel="next"']
            )
            ->push(
                [['id' => '2', 'name' => 'Chemistry']],
                200,
                []
            );

        $names = Course::query()->lazy()->map(fn($c) => $c->name)->values()->all();

        $this->assertSame(['Biology', 'Chemistry'], $names);
    }
}
