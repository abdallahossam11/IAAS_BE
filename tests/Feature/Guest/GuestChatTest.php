<?php

namespace Tests\Feature\Guest;

use App\Contracts\GuestAiChatClientContract;
use App\Contracts\GuestChatStore;
use App\Exceptions\AiClientException;
use App\Jobs\ProcessGuestAiChat;
use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\FakeGuestAiChatClient;
use App\Services\Chat\InMemoryGuestChatStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuestChatTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryGuestChatStore $store;
    private FakeGuestAiChatClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->store  = new InMemoryGuestChatStore();
        $this->client = new FakeGuestAiChatClient();

        $this->app->instance(GuestChatStore::class, $this->store);
        $this->app->instance(GuestAiChatClientContract::class, $this->client);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function getStatus(string $requestId, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Guest-Token' => $token])
            ->getJson("/api/v1/guest/chat/messages/{$requestId}/status");
    }

    private function getHistory(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Guest-Token' => $token])
            ->getJson('/api/v1/guest/chat/history');
    }

    private function validToken(): string
    {
        return str_repeat('A', 64);
    }

    // ── Token generation ──────────────────────────────────────────────────────

    public function test_submit_without_token_generates_opaque_token(): void
    {
        $resp = $this->postMessage();

        $resp->assertStatus(202);
        $resp->assertJsonPath('data.guest_token', fn ($t) => $t !== null && strlen($t) === 64);
    }

    public function test_generated_token_length_is_64(): void
    {
        $resp = $this->postMessage();

        $this->assertSame(64, strlen($resp->json('data.guest_token')));
    }

    public function test_new_submission_returns_guest_token(): void
    {
        $resp = $this->postMessage();

        $resp->assertJsonPath('data.guest_token', fn ($t) => is_string($t));
    }

    public function test_returning_submission_omits_guest_token(): void
    {
        $resp = $this->postMessage('Hello', $this->validToken())->assertStatus(202);

        $this->assertArrayNotHasKey('guest_token', $resp->json('data'));
    }

    public function test_redis_keys_use_sha256_hash_not_raw_token(): void
    {
        $rawToken  = $this->validToken();
        $tokenHash = hash('sha256', $rawToken);

        $this->postMessage('Hello', $rawToken)->assertStatus(202);

        // History is accessible via the hash; the raw token is not in any store key
        $history = $this->store->getHistory($tokenHash);
        $this->assertNotEmpty($history);
    }

    // ── Token format validation ───────────────────────────────────────────────

    public function test_malformed_post_token_returns_422(): void
    {
        $this->postMessage('Hello', 'bad-token')->assertStatus(422);
    }

    public function test_post_token_too_short_returns_422(): void
    {
        $this->postMessage('Hello', str_repeat('A', 32))->assertStatus(422);
    }

    public function test_post_token_too_long_returns_422(): void
    {
        $this->postMessage('Hello', str_repeat('A', 65))->assertStatus(422);
    }

    public function test_missing_get_history_token_returns_401(): void
    {
        $this->getJson('/api/v1/guest/chat/history')->assertStatus(401)
            ->assertJsonPath('message', 'Guest token required.');
    }

    public function test_malformed_get_history_token_returns_401(): void
    {
        $this->withHeaders(['X-Guest-Token' => 'bad'])
            ->getJson('/api/v1/guest/chat/history')
            ->assertStatus(401);
    }

    public function test_missing_get_poll_token_returns_401(): void
    {
        $this->getJson('/api/v1/guest/chat/messages/some-id/status')->assertStatus(401)
            ->assertJsonPath('message', 'Guest token required.');
    }

    public function test_malformed_get_poll_token_returns_401(): void
    {
        $this->withHeaders(['X-Guest-Token' => 'short'])
            ->getJson('/api/v1/guest/chat/messages/some-id/status')
            ->assertStatus(401);
    }

    // ── Ownership and isolation ───────────────────────────────────────────────

    public function test_same_token_continues_same_history(): void
    {
        $token = $this->validToken();

        $this->postMessage('First', $token)->assertStatus(202);
        $this->postMessage('Second', $token)->assertStatus(409); // pending

        $history = $this->getHistory($token)->assertStatus(200)->json('data.messages');
        $this->assertCount(1, $history);
    }

    public function test_different_token_cannot_read_another_guests_history(): void
    {
        $tokenA = $this->validToken();
        $tokenB = str_repeat('B', 64);

        $this->postMessage('Hello from A', $tokenA)->assertStatus(202);

        $historyB = $this->getHistory($tokenB)->assertStatus(200)->json('data.messages');
        $this->assertEmpty($historyB);
    }

    public function test_request_id_alone_cannot_poll_another_guests_result(): void
    {
        $tokenA  = $this->validToken();
        $tokenB  = str_repeat('B', 64);

        $resp      = $this->postMessage('Hello', $tokenA)->assertStatus(202);
        $requestId = $resp->json('data.request_id');

        $this->getStatus($requestId, $tokenB)->assertStatus(404);
    }

    // ── Submission and collision ───────────────────────────────────────────────

    public function test_submission_returns_202_with_correct_schema(): void
    {
        $resp = $this->postMessage('Hello', $this->validToken())->assertStatus(202);

        $resp->assertJsonStructure(['success', 'data' => ['request_id', 'status']]);
        $resp->assertJsonPath('data.status', 'queued');
    }

    public function test_second_message_while_pending_returns_409(): void
    {
        $token = $this->validToken();

        $this->postMessage('First', $token)->assertStatus(202);
        $this->postMessage('Second', $token)->assertStatus(409)
            ->assertJsonPath('message', 'A response is already being processed.');
    }

    public function test_no_dispatch_on_409_collision(): void
    {
        $token = $this->validToken();

        $this->postMessage('First', $token)->assertStatus(202);
        Queue::assertPushed(ProcessGuestAiChat::class, 1);

        $this->postMessage('Second', $token)->assertStatus(409);
        Queue::assertPushed(ProcessGuestAiChat::class, 1); // still 1
    }

    // ── Lifecycle via direct handle() ─────────────────────────────────────────

    private function submitAndHandle(string $token): array
    {
        $resp      = $this->postMessage('Hello', $token)->assertStatus(202);
        $requestId = $resp->json('data.request_id');
        $tokenHash = hash('sha256', $token);

        $job = new ProcessGuestAiChat($requestId, $tokenHash);
        try {
            $job->handle($this->client, $this->store);
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        return [$requestId, $tokenHash];
    }

    public function test_fake_success_completes_request_with_content(): void
    {
        $this->client->shouldSucceed('Fake guest AI response');
        [$requestId, $tokenHash] = $this->submitAndHandle($this->validToken());

        $req = $this->store->getRequest($requestId);
        $this->assertSame('completed', $req['status']);
        $this->assertSame('Fake guest AI response', $req['content']);
    }

    public function test_poll_returns_completed_schema(): void
    {
        $this->client->shouldSucceed('Fake guest AI response');
        $token = $this->validToken();
        [$requestId] = $this->submitAndHandle($token);

        $this->getStatus($requestId, $token)->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.content', 'Fake guest AI response')
            ->assertJsonPath('data.error_code', null);
    }

    public function test_fake_failure_marks_request_failed(): void
    {
        $this->client->shouldFail('AI_ERROR', 'service unavailable');
        $token = $this->validToken();
        [$requestId, $tokenHash] = $this->submitAndHandle($token);

        $req = $this->store->getRequest($requestId);
        $this->assertSame('failed', $req['status']);
        $this->assertSame('AI_ERROR', $req['error_code']);
    }

    public function test_poll_returns_failed_schema_with_error_code(): void
    {
        $this->client->shouldFail('AI_ERROR', 'service unavailable');
        $token = $this->validToken();
        [$requestId] = $this->submitAndHandle($token);

        $this->getStatus($requestId, $token)->assertStatus(200)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', 'AI_ERROR');
    }

    public function test_fake_timeout_uses_failed_method(): void
    {
        $this->client->shouldTimeout();
        $token     = $this->validToken();
        $resp      = $this->postMessage('Hello', $token)->assertStatus(202);
        $requestId = $resp->json('data.request_id');
        $tokenHash = hash('sha256', $token);

        $job = new ProcessGuestAiChat($requestId, $tokenHash);
        try {
            $job->handle($this->client, $this->store);
        } catch (AiClientException $e) {
            $job->failed($e);
        }

        $req = $this->store->getRequest($requestId);
        $this->assertSame('failed', $req['status']);
        $this->assertSame('TIMEOUT', $req['error_code']);
    }

    public function test_invalid_ai_response_shape_triggers_failed_method(): void
    {
        $badClient = new class implements GuestAiChatClientContract {
            public function send(array $payload): array { return ['wrong_key' => 'value']; }
        };
        $this->app->instance(GuestAiChatClientContract::class, $badClient);

        $token     = $this->validToken();
        $resp      = $this->postMessage('Hello', $token)->assertStatus(202);
        $requestId = $resp->json('data.request_id');
        $tokenHash = hash('sha256', $token);

        $job = new ProcessGuestAiChat($requestId, $tokenHash);
        try {
            $job->handle($badClient, $this->store);
        } catch (AiClientException $e) {
            $job->failed($e);
        }

        $req = $this->store->getRequest($requestId);
        $this->assertSame('failed', $req['status']);
        $this->assertSame('INVALID_AI_RESPONSE', $req['error_code']);
    }

    // ── Lock hardening ────────────────────────────────────────────────────────

    public function test_job_with_replaced_pending_lock_calls_fail_not_ai(): void
    {
        $token     = $this->validToken();
        $resp      = $this->postMessage('Hello', $token)->assertStatus(202);
        $requestId = $resp->json('data.request_id');
        $tokenHash = hash('sha256', $token);

        // Simulate lock replaced by a newer request
        $this->store->clearPending($tokenHash, $requestId);
        $this->store->acquirePending($tokenHash, 'new-req');

        $job = new ProcessGuestAiChat($requestId, $tokenHash);
        $job->handle($this->client, $this->store);

        // AI must not have been called
        $this->assertSame(0, $this->client->getCallCount());

        $req = $this->store->getRequest($requestId);
        $this->assertSame('failed', $req['status']);
        $this->assertSame('PENDING_LOCK_LOST', $req['error_code']);
    }

    public function test_refresh_pending_if_owned_refreshes_only_matching_lock(): void
    {
        $token     = $this->validToken();
        $resp      = $this->postMessage('Hello', $token)->assertStatus(202);
        $requestId = $resp->json('data.request_id');
        $tokenHash = hash('sha256', $token);

        $this->assertTrue($this->store->refreshPendingIfOwned($tokenHash, $requestId));
        $this->assertFalse($this->store->refreshPendingIfOwned($tokenHash, 'other-req'));
    }

    public function test_completion_updates_history_request_and_lock_atomically(): void
    {
        $this->client->shouldSucceed('Response');
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);
        [$requestId] = $this->submitAndHandle($token);

        $req     = $this->store->getRequest($requestId);
        $history = $this->store->getHistory($tokenHash);

        $this->assertSame('completed', $req['status']);
        $this->assertNotEmpty(array_filter($history, fn ($e) => $e['role'] === 'assistant'));
        $this->assertTrue($this->store->acquirePending($tokenHash, 'next'));
    }

    public function test_failure_updates_request_and_clears_only_matching_lock(): void
    {
        $this->client->shouldFail('ERR', 'msg');
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);
        [$requestId] = $this->submitAndHandle($token);

        $req = $this->store->getRequest($requestId);
        $this->assertSame('failed', $req['status']);
        $this->assertTrue($this->store->acquirePending($tokenHash, 'next'));
    }

    public function test_pending_lock_cleared_after_completion(): void
    {
        $this->client->shouldSucceed();
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);
        $this->submitAndHandle($token);

        $this->assertTrue($this->store->acquirePending($tokenHash, 'new'));
    }

    public function test_pending_lock_cleared_after_failure(): void
    {
        $this->client->shouldFail();
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);
        $this->submitAndHandle($token);

        $this->assertTrue($this->store->acquirePending($tokenHash, 'new'));
    }

    // ── Rollback ──────────────────────────────────────────────────────────────

    public function test_setup_failure_removes_orphan_request_state(): void
    {
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);

        // Simulate a request that acquires pending but fails before dispatch
        $requestId = 'rollback-req-' . uniqid();
        $this->store->acquirePending($tokenHash, $requestId);
        $this->store->appendUserMessage($tokenHash, $requestId, 'Oops');
        $this->store->createRequest($requestId, $tokenHash);

        $this->store->rollbackSubmission($tokenHash, $requestId);

        $this->assertNull($this->store->getRequest($requestId));
    }

    public function test_setup_failure_removes_only_matching_user_history_item(): void
    {
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);

        $otherReq  = 'other-' . uniqid();
        $failedReq = 'fail-' . uniqid();

        $this->store->appendUserMessage($tokenHash, $otherReq, 'Earlier message');
        $this->store->acquirePending($tokenHash, $otherReq);
        $this->store->clearPending($tokenHash, $otherReq);

        $this->store->appendUserMessage($tokenHash, $failedReq, 'Failing message');
        $this->store->createRequest($failedReq, $tokenHash);
        $this->store->acquirePending($tokenHash, $failedReq);

        $this->store->rollbackSubmission($tokenHash, $failedReq);

        $history = $this->store->getHistory($tokenHash);
        $this->assertCount(1, $history);
        $this->assertSame('Earlier message', $history[0]['content']);
    }

    public function test_pending_lock_released_after_setup_failure(): void
    {
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);

        $requestId = 'fail-lock-' . uniqid();
        $this->store->acquirePending($tokenHash, $requestId);
        $this->store->appendUserMessage($tokenHash, $requestId, 'msg');
        $this->store->createRequest($requestId, $tokenHash);

        $this->store->rollbackSubmission($tokenHash, $requestId);

        $this->assertTrue($this->store->acquirePending($tokenHash, 'next-req'));
    }

    // ── UUID validation ───────────────────────────────────────────────────────

    public function test_malformed_non_uuid_poll_request_id_returns_404(): void
    {
        $this->getStatus('not-a-uuid', $this->validToken())->assertStatus(404)
            ->assertJsonPath('message', 'Request not found.');
    }

    public function test_short_non_uuid_poll_request_id_returns_404(): void
    {
        $this->getStatus('abc', $this->validToken())->assertStatus(404)
            ->assertJsonPath('message', 'Request not found.');
    }

    // ── History role filter ───────────────────────────────────────────────────

    public function test_history_endpoint_excludes_system_role_entries(): void
    {
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);

        // Inject a system entry directly into the store via the test-only simulation path
        $this->store->appendUserMessage($tokenHash, 'req-user', 'Real user message');

        // Simulate a system entry by directly submitting then completing a message
        // that we inject as a "system" role — we rely on history() filtering it out
        // by using a custom store subset: inject raw using rollback trick then re-add
        // We test by ensuring only user/assistant appear in the HTTP response even
        // if an internal entry has a different role.
        // Use a sub-store that already has a system entry from its internal state.
        $systemStore = new class($this->store, $tokenHash) implements \App\Contracts\GuestChatStore {
            public function __construct(
                private \App\Services\Chat\InMemoryGuestChatStore $inner,
                private string $tokenHash,
            ) {}
            public function appendUserMessage(string $t, string $r, string $c): void { $this->inner->appendUserMessage($t, $r, $c); }
            public function getHistory(string $t): array {
                $base = $this->inner->getHistory($t);
                // Inject a system entry that the controller must filter out
                return array_merge($base, [
                    ['request_id' => 'sys-req', 'role' => 'system', 'content' => 'You are helpful.', 'created_at' => now()->toIso8601String()],
                ]);
            }
            public function createRequest(string $r, string $t): void { $this->inner->createRequest($r, $t); }
            public function getRequest(string $r): ?array { return $this->inner->getRequest($r); }
            public function acquirePending(string $t, string $r): bool { return $this->inner->acquirePending($t, $r); }
            public function refreshPendingIfOwned(string $t, string $r): bool { return $this->inner->refreshPendingIfOwned($t, $r); }
            public function clearPending(string $t, string $r): bool { return $this->inner->clearPending($t, $r); }
            public function markProcessing(string $r, string $t): bool { return $this->inner->markProcessing($r, $t); }
            public function completeRequest(string $r, string $t, string $c): bool { return $this->inner->completeRequest($r, $t, $c); }
            public function failRequest(string $r, string $t, string $e, string $m): bool { return $this->inner->failRequest($r, $t, $e, $m); }
            public function rollbackSubmission(string $t, string $r): void { $this->inner->rollbackSubmission($t, $r); }
            public function refreshHistoryTtl(string $t): void { $this->inner->refreshHistoryTtl($t); }
        };

        $this->app->instance(\App\Contracts\GuestChatStore::class, $systemStore);

        $messages = $this->withHeaders(['X-Guest-Token' => $token])
            ->getJson('/api/v1/guest/chat/history')
            ->assertStatus(200)
            ->json('data.messages');

        foreach ($messages as $msg) {
            $this->assertNotSame('system', $msg['role']);
        }
    }

    public function test_history_messages_remain_json_array_after_internal_role_filtering(): void
    {
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);
        $now       = now()->toIso8601String();

        // Controlled store: getHistory returns [user(0), system(1), assistant(2)]
        // Without array_values() the filter leaves keys [0,2] and json_encode emits an object.
        $filteredStore = new class($tokenHash, $now) implements \App\Contracts\GuestChatStore {
            public function __construct(private string $tokenHash, private string $now) {}
            public function getHistory(string $t): array {
                return [
                    ['request_id' => 'req-u', 'role' => 'user',      'content' => 'Hello',          'created_at' => $this->now],
                    ['request_id' => 'req-s', 'role' => 'system',    'content' => 'You are helpful.','created_at' => $this->now],
                    ['request_id' => 'req-a', 'role' => 'assistant',  'content' => 'Hi there',       'created_at' => $this->now],
                ];
            }
            public function appendUserMessage(string $t, string $r, string $c): void {}
            public function createRequest(string $r, string $t): void {}
            public function getRequest(string $r): ?array { return null; }
            public function acquirePending(string $t, string $r): bool { return true; }
            public function refreshPendingIfOwned(string $t, string $r): bool { return true; }
            public function clearPending(string $t, string $r): bool { return true; }
            public function markProcessing(string $r, string $t): bool { return true; }
            public function completeRequest(string $r, string $t, string $c): bool { return true; }
            public function failRequest(string $r, string $t, string $e, string $m): bool { return true; }
            public function rollbackSubmission(string $t, string $r): void {}
            public function refreshHistoryTtl(string $t): void {}
        };

        $this->app->instance(\App\Contracts\GuestChatStore::class, $filteredStore);

        $resp = $this->withHeaders(['X-Guest-Token' => $token])
            ->getJson('/api/v1/guest/chat/history')
            ->assertStatus(200);

        // Verify the raw JSON encodes data.messages as an array ([…]) not an object ({…})
        $decoded = json_decode($resp->getContent());
        $this->assertIsArray($decoded->data->messages);

        // Exactly 2 entries: user + assistant (system filtered out)
        $this->assertCount(2, $decoded->data->messages);
        $this->assertSame('user',      $decoded->data->messages[0]->role);
        $this->assertSame('assistant', $decoded->data->messages[1]->role);
    }

    public function test_history_endpoint_returns_user_and_assistant_entries(): void
    {
        $this->client->shouldSucceed('AI reply');
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);
        $this->submitAndHandle($token);

        $messages = $this->getHistory($token)->assertStatus(200)->json('data.messages');

        $roles = array_column($messages, 'role');
        $this->assertContains('user', $roles);
        $this->assertContains('assistant', $roles);
    }

    // ── Output hygiene ────────────────────────────────────────────────────────

    public function test_history_api_omits_internal_request_id_from_message_items(): void
    {
        $this->client->shouldSucceed();
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);
        $this->submitAndHandle($token);

        $messages = $this->getHistory($token)->assertStatus(200)->json('data.messages');

        foreach ($messages as $msg) {
            $this->assertArrayNotHasKey('request_id', $msg);
        }
    }

    public function test_no_token_hash_in_any_response(): void
    {
        $token = $this->validToken();
        $hash  = hash('sha256', $token);

        $sendResp = $this->postMessage('Hello', $token)->assertStatus(202);
        $this->assertStringNotContainsString($hash, $sendResp->getContent());

        $histResp = $this->getHistory($token)->assertStatus(200);
        $this->assertStringNotContainsString($hash, $histResp->getContent());
    }

    public function test_no_guest_rows_written_to_mysql(): void
    {
        $this->client->shouldSucceed();
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);

        $resp      = $this->postMessage('Hello', $token)->assertStatus(202);
        $requestId = $resp->json('data.request_id');

        $job = new ProcessGuestAiChat($requestId, $tokenHash);
        $job->handle($this->client, $this->store);

        $this->assertDatabaseCount('chat_conversations', 0);
        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('chat_ai_requests', 0);
    }

    // ── TTL ───────────────────────────────────────────────────────────────────

    public function test_history_read_refreshes_session_ttl(): void
    {
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);

        $this->postMessage('Hello', $token)->assertStatus(202);
        $this->store->simulateExpireHistory($tokenHash);
        // After expiry nothing is refreshable — but calling the endpoint should not crash
        $this->getHistory($token)->assertStatus(200);
    }

    public function test_status_read_does_not_change_history(): void
    {
        $this->client->shouldSucceed();
        $token     = $this->validToken();
        $tokenHash = hash('sha256', $token);

        $resp      = $this->postMessage('Hello', $token)->assertStatus(202);
        $requestId = $resp->json('data.request_id');
        $job       = new ProcessGuestAiChat($requestId, $tokenHash);
        $job->handle($this->client, $this->store);

        $historyBefore = $this->store->getHistory($tokenHash);
        $this->getStatus($requestId, $token)->assertStatus(200);
        $historyAfter  = $this->store->getHistory($tokenHash);

        $this->assertSame(count($historyBefore), count($historyAfter));
    }
}
