<?php

namespace Tests\Unit;

use App\Services\Chat\RedisGuestChatStore;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisGuestChatStoreTest extends TestCase
{
    private RedisGuestChatStore $store;
    private string $tokenHash;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension not available.');
        }

        try {
            $pong = Redis::connection()->ping();
            if (strtoupper((string) $pong) !== 'PONG' && $pong !== true) {
                $this->markTestSkipped('Redis server not reachable (ping failed).');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Redis server not reachable.');
        }

        $this->store     = new RedisGuestChatStore();
        $this->tokenHash = 'test-' . bin2hex(random_bytes(8));
        $this->requestId = 'testreq-' . uniqid();
    }

    protected function tearDown(): void
    {
        try {
            $conn = Redis::connection();
            $conn->del("guest_chat:{$this->tokenHash}:messages");
            $conn->del("guest_chat:{$this->tokenHash}:pending");
            $conn->del("guest_ai_request:{$this->requestId}");
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    public function test_ping_returns_pong(): void
    {
        $pong = Redis::connection()->ping();

        $this->assertTrue(strtoupper((string) $pong) === 'PONG' || $pong === true);
    }

    public function test_acquire_pending_set_nx_second_acquire_returns_false(): void
    {
        $this->assertTrue($this->store->acquirePending($this->tokenHash, $this->requestId));
        $this->assertFalse($this->store->acquirePending($this->tokenHash, 'other-req'));
    }

    public function test_pending_key_ttl_is_approximately_600(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $ttl = Redis::connection()->ttl("guest_chat:{$this->tokenHash}:pending");

        $this->assertGreaterThan(595, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
    }

    public function test_messages_key_ttl_is_approximately_86400(): void
    {
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Hello');

        $ttl = Redis::connection()->ttl("guest_chat:{$this->tokenHash}:messages");

        $this->assertGreaterThan(86390, $ttl);
        $this->assertLessThanOrEqual(86400, $ttl);
    }

    public function test_redis_keys_contain_token_hash_not_raw_token(): void
    {
        $rawToken  = str_repeat('X', 64);
        $tokenHash = hash('sha256', $rawToken);

        $store = new RedisGuestChatStore();
        $store->appendUserMessage($tokenHash, $this->requestId, 'test');

        // Clean up
        Redis::connection()->del("guest_chat:{$tokenHash}:messages");

        // The raw token must never appear as a Redis key segment
        $this->assertStringNotContainsString($rawToken, "guest_chat:{$tokenHash}:messages");
    }

    public function test_refresh_pending_if_owned_compare_and_swap(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->assertTrue($this->store->refreshPendingIfOwned($this->tokenHash, $this->requestId));
        $this->assertFalse($this->store->refreshPendingIfOwned($this->tokenHash, 'wrong-req'));
    }

    public function test_clear_pending_compare_and_delete(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->assertFalse($this->store->clearPending($this->tokenHash, 'wrong-req'));
        $this->assertTrue($this->store->clearPending($this->tokenHash, $this->requestId));
    }

    public function test_complete_request_atomic_terminal_effects_and_lock_cleared(): void
    {
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Hello');
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);
        $this->store->markProcessing($this->requestId, $this->tokenHash);

        $result = $this->store->completeRequest($this->requestId, $this->tokenHash, 'AI reply');

        $this->assertTrue($result);

        $req = $this->store->getRequest($this->requestId);
        $this->assertSame('completed', $req['status']);

        $history = $this->store->getHistory($this->tokenHash);
        $assistant = array_filter($history, fn (array $e) => $e['role'] === 'assistant');
        $this->assertNotEmpty($assistant);

        // Lock must be cleared
        $this->assertTrue($this->store->acquirePending($this->tokenHash, 'new-req'));
        Redis::connection()->del("guest_chat:{$this->tokenHash}:pending");
    }

    public function test_fail_request_atomic_terminal_effects_and_lock_cleared(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $result = $this->store->failRequest($this->requestId, $this->tokenHash, 'AI_ERROR', 'oops');

        $this->assertTrue($result);

        $req = $this->store->getRequest($this->requestId);
        $this->assertSame('failed', $req['status']);
        $this->assertSame('AI_ERROR', $req['error_code']);

        $this->assertTrue($this->store->acquirePending($this->tokenHash, 'new-req'));
        Redis::connection()->del("guest_chat:{$this->tokenHash}:pending");
    }

    public function test_complete_request_does_not_clear_newer_lock(): void
    {
        $oldReq = $this->requestId;
        $newReq = 'new-req-' . uniqid();

        $this->store->createRequest($oldReq, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $oldReq);
        $this->store->markProcessing($oldReq, $this->tokenHash);
        $this->store->clearPending($this->tokenHash, $oldReq);
        $this->store->acquirePending($this->tokenHash, $newReq);

        $result = $this->store->completeRequest($oldReq, $this->tokenHash, 'late response');

        $this->assertFalse($result);

        // New lock must still be there
        $this->assertFalse($this->store->clearPending($this->tokenHash, $oldReq));
        $this->assertTrue($this->store->clearPending($this->tokenHash, $newReq));

        Redis::connection()->del("guest_ai_request:{$newReq}");
        Redis::connection()->del("guest_chat:{$this->tokenHash}:pending");
    }

    // ── configurable TTL (injected values) ────────────────────────────────────

    public function test_acquire_pending_uses_working_set_nx_ex_behavior(): void
    {
        $store = new RedisGuestChatStore(86400, 300);

        $this->assertTrue($store->acquirePending($this->tokenHash, $this->requestId));
        $this->assertFalse($store->acquirePending($this->tokenHash, 'different-request'));

        Redis::connection()->del("guest_chat:{$this->tokenHash}:pending");
    }

    public function test_pending_ttl_follows_injected_value(): void
    {
        $store = new RedisGuestChatStore(86400, 321);
        $store->acquirePending($this->tokenHash, $this->requestId);

        $ttl = Redis::connection()->ttl("guest_chat:{$this->tokenHash}:pending");

        $this->assertGreaterThan(316, $ttl);
        $this->assertLessThanOrEqual(321, $ttl);

        Redis::connection()->del("guest_chat:{$this->tokenHash}:pending");
    }

    public function test_history_ttl_follows_injected_value(): void
    {
        $store = new RedisGuestChatStore(1234, 600);
        $store->appendUserMessage($this->tokenHash, $this->requestId, 'hello');

        $ttl = Redis::connection()->ttl("guest_chat:{$this->tokenHash}:messages");

        $this->assertGreaterThan(1229, $ttl);
        $this->assertLessThanOrEqual(1234, $ttl);
    }
}
