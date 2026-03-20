<?php

namespace JarredCain\CanvasLms\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Models\User;
use JarredCain\CanvasLms\Tests\TestCase;

class UserSuspendTest extends TestCase
{
    private function userFixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/Responses/user_single.json'),
            true
        );
    }

    public function test_suspend_sends_correct_event_to_canvas(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/users/42' => Http::response($this->userFixture(), 200),
        ]);

        $user = User::newWithId(42);
        $user->suspend();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'users/42')
                && $request->method() === 'PUT'
                && $request->data()['user']['event'] === 'suspend';
        });
    }

    public function test_unsuspend_sends_correct_event_to_canvas(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/users/42' => Http::response($this->userFixture(), 200),
        ]);

        $user = User::newWithId(42);
        $user->unsuspend();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'users/42')
                && $request->method() === 'PUT'
                && $request->data()['user']['event'] === 'unsuspend';
        });
    }

    public function test_suspend_returns_updated_user_instance(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/users/42' => Http::response($this->userFixture(), 200),
        ]);

        $user   = User::newWithId(42);
        $result = $user->suspend();

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('42', $result->id);
        $this->assertSame('Jane Doe', $result->name);
    }

    public function test_unsuspend_returns_updated_user_instance(): void
    {
        Http::fake([
            'canvas.example.com/api/v1/users/42' => Http::response($this->userFixture(), 200),
        ]);

        $user   = User::newWithId(42);
        $result = $user->unsuspend();

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('42', $result->id);
    }
}
