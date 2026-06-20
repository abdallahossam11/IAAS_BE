<?php

namespace Tests\Feature\Jobs;

use App\Contracts\AiChatClientContract;
use App\Jobs\ProcessStudentAiChat;
use App\Jobs\SummarizeChatConversation;
use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Student;
use App\Services\Ai\FakeAiChatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Job behavior tests for ProcessStudentAiChat and SummarizeChatConversation.
 *
 * Strategy: build DB state manually with factories, then call $job->handle($client)
 * directly. This sidesteps the afterCommit() issue (tests run inside a transaction
 * so the outer level never reaches 0), avoids HTTP overhead, and gives fine-grained
 * control over the FakeAiChatClient mode.
 *
 * FakeAiChatClient modes:
 *   - success (default): chat() → {message, user_id, session_id}
 *   - failure:           chat() → throws AiClientException
 *   - timeout:           chat() → throws TimeoutException
 *
 * SummarizeChatConversation also tested here because it is directly coupled:
 *   - success: sets summary_updated_at on the conversation
 *   - skipped: leaves summary_updated_at null; logs warning
 *   - missing conversation: returns early (null guard)
 *   - null session_id: returns early
 */
class ProcessStudentAiChatTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiChatClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeClient = new FakeAiChatClient;
        $this->app->instance(AiChatClientContract::class, $this->fakeClient);
        Queue::fake(); // Prevent SummarizeChatConversation from queuing during job handle
    }

    // =========================================================================
    // A) ProcessStudentAiChat — success path
    // =========================================================================

    public function test_job_completes_new_conversation_on_success(): void
    {
        [$aiRequest, $conversation, $userMsg, $assistantMsg] = $this->makeQueuedRequest(
            sessionId: null,  // New conversation — AI will assign a session_id.
        );

        $job = new ProcessStudentAiChat($aiRequest->id);
        $job->handle($this->fakeClient);

        $aiRequest->refresh();
        $conversation->refresh();
        $assistantMsg->refresh();

        $this->assertSame(ChatAiRequest::STATUS_COMPLETED, $aiRequest->status);
        $this->assertSame(ChatMessage::STATUS_COMPLETED, $assistantMsg->status);
        $this->assertNotNull($assistantMsg->content, 'Assistant message must have content after success');

        // session_id must be populated after first successful AI response.
        $this->assertNotNull($conversation->session_id);
    }

    public function test_job_uses_existing_session_id_for_follow_up_message(): void
    {
        [$aiRequest, $conversation, $userMsg, $assistantMsg] = $this->makeQueuedRequest(
            sessionId: 'existing-session-abc',
        );

        $this->fakeClient->shouldSucceed();

        $job = new ProcessStudentAiChat($aiRequest->id);
        $job->handle($this->fakeClient);

        $conversation->refresh();
        $this->assertSame('existing-session-abc', $conversation->session_id);
    }

    public function test_job_increments_call_count_on_client(): void
    {
        [$aiRequest] = $this->makeQueuedRequest();

        $job = new ProcessStudentAiChat($aiRequest->id);
        $job->handle($this->fakeClient);

        $this->assertSame(1, $this->fakeClient->getCallCount());
    }

    public function test_job_returns_early_when_ai_request_already_processed(): void
    {
        [$aiRequest] = $this->makeQueuedRequest();

        // Mark as already processing by another worker.
        $aiRequest->update(['status' => ChatAiRequest::STATUS_PROCESSING]);

        // Manually set to completed to simulate a second worker completing it.
        $aiRequest->update(['status' => ChatAiRequest::STATUS_COMPLETED]);

        $job = new ProcessStudentAiChat($aiRequest->id);
        $job->handle($this->fakeClient);

        // Client was never called — job exits at the atomic claim step.
        $this->assertSame(0, $this->fakeClient->getCallCount());
    }

    // =========================================================================
    // B) ProcessStudentAiChat — failure path
    // =========================================================================

    public function test_job_failed_marks_ai_request_and_assistant_message_as_failed(): void
    {
        [$aiRequest, $conversation, $userMsg, $assistantMsg] = $this->makeQueuedRequest();

        $job = new ProcessStudentAiChat($aiRequest->id);
        $job->failed(new \RuntimeException('AI service unavailable'));

        $aiRequest->refresh();
        $assistantMsg->refresh();

        $this->assertSame(ChatAiRequest::STATUS_FAILED, $aiRequest->status);
        $this->assertSame(ChatMessage::STATUS_FAILED, $assistantMsg->status);
    }

    public function test_failed_does_not_throw_when_ai_request_is_missing(): void
    {
        // If DB row is gone, failed() should not throw.
        $job = new ProcessStudentAiChat(99999);

        $this->expectNotToPerformAssertions();
        $job->failed(new \RuntimeException('Orphaned job'));
    }

    // =========================================================================
    // C) SummarizeChatConversation
    // =========================================================================

    public function test_summarize_job_sets_summary_updated_at_on_success(): void
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->withSession()->create([
            'student_id' => $student->id,
            'summary_updated_at' => null,
        ]);

        // FakeAiChatClient summarize() returns status='created' by default.
        $job = new SummarizeChatConversation($conversation->id);
        $job->handle($this->fakeClient);

        $conversation->refresh();
        $this->assertNotNull($conversation->summary_updated_at);
    }

    public function test_summarize_job_does_nothing_when_conversation_missing(): void
    {
        $job = new SummarizeChatConversation(99999);

        $this->expectNotToPerformAssertions();
        $job->handle($this->fakeClient);
    }

    public function test_summarize_job_returns_early_when_session_id_is_null(): void
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create([
            'student_id' => $student->id,
            'session_id' => null,
        ]);

        $job = new SummarizeChatConversation($conversation->id);
        $job->handle($this->fakeClient);

        // Client was never called.
        $this->assertSame(0, $this->fakeClient->getCallCount());
    }

    public function test_summarize_job_does_not_update_summary_updated_at_when_skipped(): void
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->withSession()->create([
            'student_id' => $student->id,
            'summary_updated_at' => null,
        ]);

        // Fake client returns status='skipped'.
        $this->fakeClient->shouldSucceed(null); // reset mode
        // Override the summarize return to return 'skipped'.
        $fakeClientSkipped = new class extends FakeAiChatClient
        {
            public function summarize(int $userId, string $sessionId): array
            {
                return [
                    'status' => 'skipped',
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'summary_preview' => null,
                    'reason' => 'too_short',
                ];
            }
        };

        $job = new SummarizeChatConversation($conversation->id);
        $job->handle($fakeClientSkipped);

        $conversation->refresh();
        $this->assertNull($conversation->summary_updated_at,
            'summary_updated_at must not be set when AI returns status=skipped');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a minimal set of DB rows for a queued AI request.
     * Returns [ChatAiRequest, ChatConversation, ChatMessage (user), ChatMessage (assistant)].
     */
    private function makeQueuedRequest(?string $sessionId = null): array
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create([
            'student_id' => $student->id,
            'session_id' => $sessionId,
        ]);
        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number' => 2,
        ]);
        $aiRequest = ChatAiRequest::factory()
            ->queued()
            ->forCycle($conversation, $userMsg, $assistantMsg)
            ->create();

        return [$aiRequest, $conversation, $userMsg, $assistantMsg];
    }
}
