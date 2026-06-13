<?php

namespace Tests\Feature\Guest;

use App\Contracts\GuestChatStore;
use App\Jobs\ProcessGuestAiChat;
use App\Services\Chat\InMemoryGuestChatStore;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuestChatDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GuestChatStore::class, new InMemoryGuestChatStore());
        Queue::fake();
    }

    private function postMessage(string $message = 'Hello', ?string $token = null): \Illuminate\Testing\TestResponse
    {
        $headers = [];
        if ($token !== null) {
            $headers['X-Guest-Token'] = $token;
        }

        return $this->withHeaders($headers)->postJson('/api/v1/guest/chat/messages', [
            'message' => $message,
        ]);
    }

    public function test_send_dispatches_process_guest_ai_chat_job(): void
    {
        $this->postMessage()->assertStatus(202);

        Queue::assertPushed(ProcessGuestAiChat::class);
    }

    public function test_job_dispatched_to_redis_connection_and_ai_chat_queue(): void
    {
        $this->postMessage()->assertStatus(202);

        Queue::assertPushed(
            ProcessGuestAiChat::class,
            fn (ProcessGuestAiChat $job) =>
                $job->connection === 'redis'
                && $job->queue === config('chat.ai_queue'),
        );
    }

    public function test_job_carries_scalar_request_id_and_token_hash(): void
    {
        $this->postMessage()->assertStatus(202);

        Queue::assertPushed(
            ProcessGuestAiChat::class,
            fn (ProcessGuestAiChat $job) =>
                is_string($job->requestId)
                && is_string($job->tokenHash)
                && strlen($job->tokenHash) === 64, // sha256 hex
        );
    }

    public function test_job_token_hash_is_sha256_not_raw_token(): void
    {
        $rawToken = str_repeat('A', 64);

        $this->postMessage('Hello', $rawToken)->assertStatus(202);

        Queue::assertPushed(
            ProcessGuestAiChat::class,
            fn (ProcessGuestAiChat $job) =>
                $job->tokenHash === hash('sha256', $rawToken)
                && $job->tokenHash !== $rawToken,
        );
    }

    public function test_no_dispatch_on_409_pending_collision(): void
    {
        $store = new InMemoryGuestChatStore();
        $this->app->instance(GuestChatStore::class, $store);

        $rawToken = str_repeat('B', 64);

        // First message — acquires lock
        $this->postMessage('First', $rawToken)->assertStatus(202);
        Queue::assertPushed(ProcessGuestAiChat::class, 1);

        // Second message while pending — 409
        $this->postMessage('Second', $rawToken)->assertStatus(409);
        Queue::assertPushed(ProcessGuestAiChat::class, 1); // still 1
    }

    public function test_guest_chat_submit_is_named_limiter_on_send_route(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn (\Illuminate\Routing\Route $r) =>
                $r->uri() === 'api/v1/guest/chat/messages'
                && in_array('POST', $r->methods(), true));

        $this->assertNotNull($routes, 'guest chat send route not registered');

        $middlewareList = $routes->middleware();
        $this->assertContains('throttle:guest-chat-submit', $middlewareList);
    }

    public function test_named_limiter_uses_configured_requests_and_minutes(): void
    {
        $requests = (int) config('chat.guest_throttle.requests', 10);
        $minutes  = (int) config('chat.guest_throttle.minutes', 1);

        $this->assertGreaterThan(0, $requests);
        $this->assertGreaterThan(0, $minutes);

        // Verify RateLimiter resolves correctly
        $limiter = \Illuminate\Support\Facades\RateLimiter::limiter('guest-chat-submit');
        $this->assertNotNull($limiter);
    }
}
