<?php

namespace Tests\Unit;

use App\Services\Chat\InMemoryGuestChatStore;
use Tests\TestCase;

class InMemoryGuestChatStoreTest extends TestCase
{
    private InMemoryGuestChatStore $store;
    private string $tokenHash;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store     = new InMemoryGuestChatStore();
        $this->tokenHash = hash('sha256', str_repeat('a', 64));
        $this->requestId = 'req-' . uniqid();
    }

    // ── appendUserMessage / getHistory ────────────────────────────────────────

    public function test_append_and_get_history_returns_entries_with_internal_request_id(): void
    {
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Hello');

        $history = $this->store->getHistory($this->tokenHash);

        $this->assertCount(1, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('Hello', $history[0]['content']);
        $this->assertSame($this->requestId, $history[0]['request_id']);
    }

    // ── createRequest / getRequest ────────────────────────────────────────────

    public function test_create_request_stores_queued_status_and_token_hash(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);

        $req = $this->store->getRequest($this->requestId);

        $this->assertNotNull($req);
        $this->assertSame('queued', $req['status']);
        $this->assertSame($this->tokenHash, $req['token_hash']);
    }

    public function test_get_request_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->store->getRequest('nonexistent'));
    }

    // ── acquirePending ────────────────────────────────────────────────────────

    public function test_acquire_pending_returns_true_first_time(): void
    {
        $this->assertTrue($this->store->acquirePending($this->tokenHash, $this->requestId));
    }

    public function test_acquire_pending_returns_false_while_held(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->assertFalse($this->store->acquirePending($this->tokenHash, 'other-req'));
    }

    // ── refreshPendingIfOwned ─────────────────────────────────────────────────

    public function test_refresh_pending_returns_true_for_owning_request(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->assertTrue($this->store->refreshPendingIfOwned($this->tokenHash, $this->requestId));
    }

    public function test_refresh_pending_returns_false_for_other_request(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->assertFalse($this->store->refreshPendingIfOwned($this->tokenHash, 'other-req'));
    }

    // ── clearPending ──────────────────────────────────────────────────────────

    public function test_clear_pending_returns_true_when_owned(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->assertTrue($this->store->clearPending($this->tokenHash, $this->requestId));
    }

    public function test_clear_pending_returns_false_when_not_owned(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->assertFalse($this->store->clearPending($this->tokenHash, 'other-req'));
    }

    public function test_clear_pending_does_not_cross_delete_different_request(): void
    {
        $req1 = 'req-1';
        $req2 = 'req-2';

        $this->store->acquirePending($this->tokenHash, $req1);
        // req2 cannot acquire while req1 holds
        $this->assertFalse($this->store->clearPending($this->tokenHash, $req2));
        // req1 still held
        $this->assertFalse($this->store->acquirePending($this->tokenHash, $req2));
    }

    public function test_old_worker_cannot_clear_newer_request_lock(): void
    {
        $oldReq = 'old-req';
        $newReq = 'new-req';

        $this->store->acquirePending($this->tokenHash, $oldReq);
        // Simulate old lock cleared (TTL expired), new request acquired
        $this->store->clearPending($this->tokenHash, $oldReq);
        $this->store->acquirePending($this->tokenHash, $newReq);

        // Old worker tries to clear — must fail
        $this->assertFalse($this->store->clearPending($this->tokenHash, $oldReq));
        // New lock still intact
        $this->assertTrue($this->store->clearPending($this->tokenHash, $newReq));
    }

    // ── markProcessing ────────────────────────────────────────────────────────

    public function test_mark_processing_transitions_queued_to_processing(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->assertTrue($this->store->markProcessing($this->requestId, $this->tokenHash));

        $req = $this->store->getRequest($this->requestId);
        $this->assertSame('processing', $req['status']);
    }

    public function test_mark_processing_returns_false_on_second_call(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);
        $this->store->markProcessing($this->requestId, $this->tokenHash);

        $this->assertFalse($this->store->markProcessing($this->requestId, $this->tokenHash));
    }

    // ── completeRequest ───────────────────────────────────────────────────────

    public function test_complete_request_owned_appends_assistant_and_clears_lock(): void
    {
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Hi');
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);
        $this->store->markProcessing($this->requestId, $this->tokenHash);

        $result = $this->store->completeRequest($this->requestId, $this->tokenHash, 'AI reply');

        $this->assertTrue($result);

        $req     = $this->store->getRequest($this->requestId);
        $history = $this->store->getHistory($this->tokenHash);

        $this->assertSame('completed', $req['status']);
        $this->assertSame('AI reply', $req['content']);
        $this->assertCount(2, $history);
        $this->assertSame('assistant', $history[1]['role']);

        // Pending lock cleared — new acquire must succeed
        $this->assertTrue($this->store->acquirePending($this->tokenHash, 'new-req'));
    }

    public function test_complete_request_returns_false_for_wrong_token(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);
        $this->store->markProcessing($this->requestId, $this->tokenHash);

        $foreignHash = hash('sha256', str_repeat('b', 64));

        $this->assertFalse($this->store->completeRequest($this->requestId, $foreignHash, 'response'));
    }

    public function test_complete_request_returns_false_when_lock_replaced(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);
        $this->store->markProcessing($this->requestId, $this->tokenHash);

        // Simulate lock replaced by a newer request
        $this->store->clearPending($this->tokenHash, $this->requestId);
        $this->store->acquirePending($this->tokenHash, 'new-req');

        $this->assertFalse($this->store->completeRequest($this->requestId, $this->tokenHash, 'response'));
    }

    public function test_complete_request_does_not_append_assistant_when_returns_false(): void
    {
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Hi');
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);
        $this->store->markProcessing($this->requestId, $this->tokenHash);

        // Replace the lock
        $this->store->clearPending($this->tokenHash, $this->requestId);
        $this->store->acquirePending($this->tokenHash, 'new-req');

        $this->store->completeRequest($this->requestId, $this->tokenHash, 'response');

        $history = $this->store->getHistory($this->tokenHash);
        $this->assertCount(1, $history); // only the user message
    }

    // ── failRequest ───────────────────────────────────────────────────────────

    public function test_fail_request_owned_sets_failed_status_and_clears_lock(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $result = $this->store->failRequest($this->requestId, $this->tokenHash, 'AI_ERROR', 'oops');

        $this->assertTrue($result);

        $req = $this->store->getRequest($this->requestId);
        $this->assertSame('failed', $req['status']);
        $this->assertSame('AI_ERROR', $req['error_code']);
        $this->assertTrue($this->store->acquirePending($this->tokenHash, 'next-req'));
    }

    public function test_fail_request_returns_false_for_foreign_token(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $foreignHash = hash('sha256', str_repeat('c', 64));

        $this->assertFalse($this->store->failRequest($this->requestId, $foreignHash, 'ERR', 'msg'));
    }

    public function test_fail_request_does_not_overwrite_completed_request(): void
    {
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);
        $this->store->markProcessing($this->requestId, $this->tokenHash);
        $this->store->completeRequest($this->requestId, $this->tokenHash, 'done');

        $result = $this->store->failRequest($this->requestId, $this->tokenHash, 'LATE', 'too late');

        $this->assertFalse($result);
        $req = $this->store->getRequest($this->requestId);
        $this->assertSame('completed', $req['status']);
    }

    public function test_fail_request_does_not_delete_newer_lock(): void
    {
        $oldReq = 'old-req';
        $newReq = 'new-req';

        $this->store->createRequest($oldReq, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $oldReq);
        $this->store->clearPending($this->tokenHash, $oldReq);
        $this->store->acquirePending($this->tokenHash, $newReq);

        // Old request was queued; fail it — must not clear the new lock
        $this->store->failRequest($oldReq, $this->tokenHash, 'ERR', 'msg');

        $this->assertFalse($this->store->clearPending($this->tokenHash, $oldReq));
        $this->assertTrue($this->store->clearPending($this->tokenHash, $newReq));
    }

    // ── rollbackSubmission ────────────────────────────────────────────────────

    public function test_rollback_removes_request_and_matching_user_history(): void
    {
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Hello');
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->store->rollbackSubmission($this->tokenHash, $this->requestId);

        $this->assertNull($this->store->getRequest($this->requestId));
        $this->assertEmpty($this->store->getHistory($this->tokenHash));
        $this->assertTrue($this->store->acquirePending($this->tokenHash, 'new-req'));
    }

    public function test_rollback_does_not_remove_unrelated_history_entries(): void
    {
        $otherReq = 'other-req';
        $this->store->appendUserMessage($this->tokenHash, $otherReq, 'First');
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Second');
        $this->store->createRequest($this->requestId, $this->tokenHash);
        $this->store->acquirePending($this->tokenHash, $this->requestId);

        $this->store->rollbackSubmission($this->tokenHash, $this->requestId);

        $history = $this->store->getHistory($this->tokenHash);
        $this->assertCount(1, $history);
        $this->assertSame('First', $history[0]['content']);
    }

    // ── TTL simulation ────────────────────────────────────────────────────────

    public function test_history_expires_after_simulated_ttl(): void
    {
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Hello');
        $this->store->simulateExpireHistory($this->tokenHash);

        $this->assertEmpty($this->store->getHistory($this->tokenHash));
    }

    public function test_refresh_history_ttl_extends_expiry(): void
    {
        $this->store->appendUserMessage($this->tokenHash, $this->requestId, 'Hello');
        $this->store->refreshHistoryTtl($this->tokenHash);

        // After refresh the history should still be accessible
        $history = $this->store->getHistory($this->tokenHash);
        $this->assertCount(1, $history);
    }

    public function test_pending_expires_after_simulated_ttl(): void
    {
        $this->store->acquirePending($this->tokenHash, $this->requestId);
        $this->store->simulateExpirePending($this->tokenHash);

        // After expiry, a new acquire must succeed
        $this->assertTrue($this->store->acquirePending($this->tokenHash, 'new-req'));
    }

    // ── configurable TTL ──────────────────────────────────────────────────────

    public function test_custom_history_ttl_is_honored(): void
    {
        $store = new \App\Services\Chat\InMemoryGuestChatStore(historyTtl: 1);
        $store->appendUserMessage($this->tokenHash, $this->requestId, 'Hello');
        $store->simulateExpireHistory($this->tokenHash);

        $this->assertEmpty($store->getHistory($this->tokenHash));
    }

    public function test_custom_pending_ttl_is_honored(): void
    {
        $store = new \App\Services\Chat\InMemoryGuestChatStore(pendingTtl: 1);
        $store->acquirePending($this->tokenHash, $this->requestId);
        $store->simulateExpirePending($this->tokenHash);

        $this->assertTrue($store->acquirePending($this->tokenHash, 'new-req'));
    }
}
