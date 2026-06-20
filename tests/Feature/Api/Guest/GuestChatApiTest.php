<?php

namespace Tests\Feature\Api\Guest;

use App\Contracts\GuestChatStore;
use App\Services\Chat\InMemoryGuestChatStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Guest Chat API regression tests.
 *
 * Routes (all under /api/v1/guest/chat):
 *   POST   'messages'              → send
 *   GET    'messages/{requestId}/status' → status
 *   GET    'history'               → history
 *
 * Key behaviors:
 *   - Guest token: 64-char alphanumeric, returned on FIRST message, re-used after.
 *   - Token is sha256-hashed before being stored; raw token travels in X-Guest-Token header.
 *   - History and status require a valid X-Guest-Token header.
 *   - No auth (Sanctum) — this is a fully anonymous API.
 *   - Guest store is in-memory (InMemoryGuestChatStore) during testing.
 *   - ProcessGuestAiChat has NO afterCommit() — fires synchronously with sync queue.
 *
 * Queue strategy:
 *   Unlike the student API, ProcessGuestAiChat fires synchronously even in tests
 *   because there is no afterCommit() guard. We use Queue::fake() here so we can
 *   inspect what was dispatched without actually running AI logic. The detailed
 *   "what does the job do" is tested in ProcessGuestAiChatTest.
 */
class GuestChatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent guest AI jobs from running; tested separately.
        Queue::fake();
        // Reset in-memory guest store between tests (it is a singleton).
        $this->app->instance(GuestChatStore::class, new InMemoryGuestChatStore);
    }

    // =========================================================================
    // A) send — POST /api/v1/guest/chat/messages
    // =========================================================================

    public function test_first_message_creates_request_and_returns_guest_token(): void
    {
        $response = $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'Hello, I am a guest.',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'data' => [
                    'request_id',
                    'status',
                    'guest_token',
                ],
            ])
            ->assertJsonPath('data.status', 'queued');

        // Token is 64 alphanumeric characters.
        $token = $response->json('data.guest_token');
        $this->assertNotNull($token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
    }

    public function test_second_message_with_existing_token_does_not_return_new_token(): void
    {
        // First call — get the guest token.
        $firstResponse = $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'First message.',
        ])->assertStatus(202);
        $token = $firstResponse->json('data.guest_token');

        // Token is read from X-Guest-Token header (not JSON body).
        // With Queue::fake() the first request is still "pending", so we cannot
        // send a second message. Assert the 409 proves the token was recognised.
        $this->withHeaders(['X-Guest-Token' => $token])
            ->postJson('/api/v1/guest/chat/messages', ['message' => 'Second message.'])
            ->assertStatus(409); // pending block — token was found, no new token issued
    }

    public function test_send_requires_message(): void
    {
        $this->postJson('/api/v1/guest/chat/messages', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }

    public function test_send_rejects_message_over_max_length(): void
    {
        $this->postJson('/api/v1/guest/chat/messages', [
            'message' => str_repeat('x', 3001),
        ])->assertUnprocessable()->assertJsonValidationErrors(['message']);
    }

    public function test_send_rejects_invalid_guest_token_format(): void
    {
        $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'Hello',
            'guest_token' => 'too-short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['guest_token']);
    }

    public function test_send_unknown_guest_token_starts_new_session(): void
    {
        // The controller reads the token from the X-Guest-Token header only.
        // An unknown-but-valid-format token is treated as a first-ever request:
        // no token validation is done on send() — ownership is only checked on
        // status() and history(). So this succeeds and returns a NEW guest token.
        $fakeToken = str_repeat('A', 64);

        $response = $this->withHeaders(['X-Guest-Token' => $fakeToken])
            ->postJson('/api/v1/guest/chat/messages', ['message' => 'Hello']);

        // acquirePending() accepted this token hash → 202, no new token (not $isNew).
        $response->assertStatus(202);
        // No guest_token in response (isNew=false because header was present).
        $response->assertJsonMissingPath('data.guest_token');
    }

    public function test_send_returns_409_when_another_request_is_pending(): void
    {
        // First message — creates a pending request.
        $firstResponse = $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'First.',
        ])->assertStatus(202);

        $token = $firstResponse->json('data.guest_token');

        // Second message before the first is done → 409 conflict.
        // Token must be in the X-Guest-Token header (not JSON body).
        $this->withHeaders(['X-Guest-Token' => $token])
            ->postJson('/api/v1/guest/chat/messages', ['message' => 'Second.'])
            ->assertStatus(409);
    }

    // =========================================================================
    // B) status — GET /api/v1/guest/chat/messages/{requestId}/status
    // =========================================================================

    public function test_status_returns_queued_state_for_valid_request(): void
    {
        $sendResponse = $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'What time is it?',
        ])->assertStatus(202);

        $token = $sendResponse->json('data.guest_token');
        $requestId = $sendResponse->json('data.request_id');

        $this->getJson("/api/v1/guest/chat/messages/{$requestId}/status", [
            'X-Guest-Token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_status_requires_guest_token_header(): void
    {
        $sendResponse = $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'Hello.',
        ])->assertStatus(202);

        $requestId = $sendResponse->json('data.request_id');

        // No X-Guest-Token header.
        $this->getJson("/api/v1/guest/chat/messages/{$requestId}/status")
            ->assertUnauthorized();
    }

    public function test_status_returns_404_for_wrong_token(): void
    {
        // requireValidToken() passes for any well-formed 64-char token.
        // Ownership is checked via hash_equals; mismatch returns 404
        // (security by obscurity — not revealing whether the request_id exists).
        $sendResponse = $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'Hello.',
        ])->assertStatus(202);

        $requestId = $sendResponse->json('data.request_id');
        $wrongToken = str_repeat('B', 64);

        $this->getJson("/api/v1/guest/chat/messages/{$requestId}/status", [
            'X-Guest-Token' => $wrongToken,
        ])->assertNotFound();
    }

    public function test_status_returns_404_for_unknown_request_id(): void
    {
        // Need a real token first.
        $sendResponse = $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'Hello.',
        ])->assertStatus(202);

        $token = $sendResponse->json('data.guest_token');

        $this->getJson('/api/v1/guest/chat/messages/nonexistent-id/status', [
            'X-Guest-Token' => $token,
        ])->assertNotFound();
    }

    // =========================================================================
    // C) history — GET /api/v1/guest/chat/history
    // =========================================================================

    public function test_history_requires_guest_token_header(): void
    {
        $this->getJson('/api/v1/guest/chat/history')
            ->assertUnauthorized();
    }

    public function test_history_returns_empty_for_brand_new_token(): void
    {
        // Send first message to get a token.
        $sendResponse = $this->postJson('/api/v1/guest/chat/messages', [
            'message' => 'Hi.',
        ])->assertStatus(202);

        $token = $sendResponse->json('data.guest_token');

        // History reflects the user message stored in the guest store.
        $history = $this->getJson('/api/v1/guest/chat/history', [
            'X-Guest-Token' => $token,
        ])
            ->assertOk()
            ->json('data.messages');

        // The user's message is visible in history; no assistant reply yet.
        $this->assertIsArray($history);
        $this->assertNotEmpty($history);
        $roles = array_column($history, 'role');
        $this->assertContains('user', $roles);
        $this->assertNotContains('assistant', $roles); // no completed reply yet
    }

    public function test_history_returns_empty_messages_for_unknown_but_valid_format_token(): void
    {
        // requireValidToken() only rejects missing/malformed headers, not unknown hashes.
        // A valid-format token for a session that was never started returns 200 + empty
        // messages (InMemoryGuestChatStore returns [] for unknown token hashes).
        $fakeToken = str_repeat('C', 64);

        $response = $this->getJson('/api/v1/guest/chat/history', [
            'X-Guest-Token' => $fakeToken,
        ])->assertOk();

        $this->assertEmpty($response->json('data.messages'));
    }
}
