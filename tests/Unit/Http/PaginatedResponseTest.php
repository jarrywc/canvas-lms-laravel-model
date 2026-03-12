<?php

namespace JarredCain\CanvasLms\Tests\Unit\Http;

use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Http\PaginatedResponse;
use JarredCain\CanvasLms\Http\Response;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Tests\TestCase;
use Mockery;

class PaginatedResponseTest extends TestCase
{
    public function test_parses_next_url_from_link_header(): void
    {
        $client   = Mockery::mock(CanvasClient::class);
        $response = new PaginatedResponse(
            [],
            Course::class,
            $client,
            '<https://canvas.example.com/api/v1/courses?page=2>; rel="next", <https://canvas.example.com/api/v1/courses?page=1>; rel="first"'
        );

        $this->assertTrue($response->hasNextPage());
        $this->assertFalse($response->hasPrevPage());
    }

    public function test_parses_link_header_case_insensitively(): void
    {
        // Canvas docs say Link header is case-insensitive
        $client   = Mockery::mock(CanvasClient::class);
        $response = new PaginatedResponse(
            [],
            Course::class,
            $client,
            '<https://canvas.example.com/api/v1/courses?page=3>; rel="next", <https://canvas.example.com/api/v1/courses?page=1>; rel="prev"'
        );

        $this->assertTrue($response->hasNextPage());
        $this->assertTrue($response->hasPrevPage());
    }

    public function test_has_next_page_false_when_no_link_header(): void
    {
        $client   = Mockery::mock(CanvasClient::class);
        $response = new PaginatedResponse([], Course::class, $client, '');

        $this->assertFalse($response->hasNextPage());
    }

    public function test_count_returns_item_count(): void
    {
        $items  = [new Course(['id' => '1']), new Course(['id' => '2'])];
        $client = Mockery::mock(CanvasClient::class);

        $response = new PaginatedResponse($items, Course::class, $client);

        $this->assertCount(2, $response);
    }

    public function test_is_iterable(): void
    {
        $courses = [new Course(['id' => '1', 'name' => 'Bio']), new Course(['id' => '2', 'name' => 'Chem'])];
        $client  = Mockery::mock(CanvasClient::class);

        $response = new PaginatedResponse($courses, Course::class, $client);

        $names = [];
        foreach ($response as $course) {
            $names[] = $course->name;
        }

        $this->assertSame(['Bio', 'Chem'], $names);
    }

    public function test_next_fetches_opaque_url_from_link_header(): void
    {
        $nextUrl  = 'https://canvas.example.com/api/v1/courses?page=2&per_page=10&some_token=xyz';
        $linkHeader = "<{$nextUrl}>; rel=\"next\"";

        $mockHttpResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);
        $mockHttpResponse->shouldReceive('json')->andReturn([['id' => '3', 'name' => 'Physics']]);
        $mockHttpResponse->shouldReceive('status')->andReturn(200);
        $mockHttpResponse->shouldReceive('successful')->andReturn(true);
        $mockHttpResponse->shouldReceive('failed')->andReturn(false);
        $mockHttpResponse->shouldReceive('header')->with('Link')->andReturn('');
        $mockHttpResponse->shouldReceive('header')->with('link')->andReturn('');

        $nextResponse = new Response($mockHttpResponse);

        $client = Mockery::mock(CanvasClient::class);
        // Verify that the EXACT opaque URL is used — not a reconstructed one
        $client->shouldReceive('getUrl')->with($nextUrl, [])->once()->andReturn($nextResponse);

        $response = new PaginatedResponse(
            [new Course(['id' => '1'])],
            Course::class,
            $client,
            $linkHeader
        );

        $nextPage = $response->next();

        $this->assertNotNull($nextPage);
        $this->assertCount(1, $nextPage);
    }

    public function test_from_response_hydrates_models(): void
    {
        $mockHttpResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);
        $mockHttpResponse->shouldReceive('json')->andReturn([
            ['id' => '1', 'name' => 'Biology'],
            ['id' => '2', 'name' => 'Chemistry'],
        ]);
        $mockHttpResponse->shouldReceive('header')->with('Link')->andReturn('');
        $mockHttpResponse->shouldReceive('header')->with('link')->andReturn('');

        $httpResponse = new Response($mockHttpResponse);
        $client       = Mockery::mock(CanvasClient::class);

        $paginated = PaginatedResponse::fromResponse($httpResponse, Course::class, $client);

        $this->assertCount(2, $paginated);
        $this->assertInstanceOf(Course::class, $paginated->first());
        $this->assertSame('Biology', $paginated->first()->name);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
