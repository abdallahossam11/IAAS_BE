<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\CreateChatRequest;
use App\Http\Requests\Student\RenameChatRequest;
use App\Http\Requests\Student\SendMessageRequest;
use App\Jobs\ProcessStudentAiChat;
use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Student;
use App\Support\Security\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    // ── store ─────────────────────────────────────────────────────────────────

    public function store(CreateChatRequest $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();
        $clientMessageId = $request->input('client_message_id');
        $messageContent = $request->input('message');

        $existing = ChatMessage::where('client_message_id', $clientMessageId)->first();
        if ($existing !== null) {
            return $this->idempotencyResponse($existing, $student);
        }

        $txResult = null;
        try {
            $txResult = DB::transaction(
                function () use ($student, $clientMessageId, $messageContent) {
                    $conversation = ChatConversation::create([
                        'student_id' => $student->id,
                        'title' => Str::limit($messageContent, 50, ''),
                        'status' => ChatConversation::STATUS_ACTIVE,
                        'last_message_at' => now(),
                    ]);

                    $userMessage = ChatMessage::create([
                        'chat_conversation_id' => $conversation->id,
                        'role' => ChatMessage::ROLE_USER,
                        'content' => $messageContent,
                        'status' => ChatMessage::STATUS_COMPLETED,
                        'sequence_number' => 1,
                        'client_message_id' => $clientMessageId,
                    ]);

                    $assistantMessage = ChatMessage::create([
                        'chat_conversation_id' => $conversation->id,
                        'role' => ChatMessage::ROLE_ASSISTANT,
                        'content' => null,
                        'status' => ChatMessage::STATUS_PENDING,
                        'sequence_number' => 2,
                    ]);

                    $aiRequest = ChatAiRequest::create([
                        'chat_conversation_id' => $conversation->id,
                        'user_message_id' => $userMessage->id,
                        'assistant_message_id' => $assistantMessage->id,
                        'status' => ChatAiRequest::STATUS_QUEUED,
                        'attempt_number' => 1,
                    ]);

                    ProcessStudentAiChat::dispatch($aiRequest->id)
                        ->onConnection(config('chat.ai_connection'))
                        ->onQueue(config('chat.ai_queue'))
                        ->afterCommit();

                    return [$conversation, $userMessage, $assistantMessage, $aiRequest];
                }
            );
        } catch (QueryException $e) {
            $found = ChatMessage::where('client_message_id', $clientMessageId)->first();
            if ($found !== null) {
                return $this->idempotencyResponse($found, $student);
            }
            throw $e;
        }

        [$conversation, $userMessage, $assistantMessage, $aiRequest] = $txResult;

        return $this->cycleResponse($conversation, $userMessage, $assistantMessage, $aiRequest);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $paginator = ChatConversation::where('student_id', $student->id)
            ->visibleToStudent()
            ->orderByDesc('last_message_at')
            ->paginate(20);

        $conversations = $paginator->getCollection()->map(fn (ChatConversation $c) => [
            'uuid' => $c->uuid,
            'title' => $c->title,
            'status' => $c->status,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'conversations' => $conversations,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function show(Request $request, string $chatUuid): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $conversation = ChatConversation::where('uuid', $chatUuid)
            ->where('student_id', $student->id)
            ->whereNull('deleted_by_student_at')
            ->firstOrFail();

        $messages = $conversation->messages()
            ->whereIn('role', [
                ChatMessage::ROLE_USER,
                ChatMessage::ROLE_ASSISTANT,
            ])
            ->orderBy('sequence_number')
            ->get()
            ->map(fn (ChatMessage $m) => [
                'uuid' => $m->uuid,
                'role' => $m->role,
                'content' => $m->content,
                'status' => $m->status,
                'sequence_number' => $m->sequence_number,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => [
                    'uuid' => $conversation->uuid,
                    'title' => $conversation->title,
                    'status' => $conversation->status,
                    'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                ],
                'messages' => $messages,
            ],
        ]);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function update(RenameChatRequest $request, string $chatUuid): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $conversation = ChatConversation::where('uuid', $chatUuid)
            ->where('student_id', $student->id)
            ->whereNull('deleted_by_student_at')
            ->firstOrFail();

        $conversation->update(['title' => $request->validated('title')]);

        return response()->json([
            'success' => true,
            'data' => [
                'uuid' => $conversation->uuid,
                'title' => $conversation->title,
            ],
        ]);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function destroy(Request $request, string $chatUuid): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $conversation = ChatConversation::where('uuid', $chatUuid)
            ->where('student_id', $student->id)
            ->whereNull('deleted_by_student_at')
            ->firstOrFail();

        $conversation->update(['deleted_by_student_at' => now()]);

        AuditLog::info('chat_deleted', [
            'actor_student_id' => $student->id,
            'chat_uuid' => $conversation->uuid,
        ]);

        return response()->json(['success' => true]);
    }

    // ── sendMessage ───────────────────────────────────────────────────────────

    public function sendMessage(SendMessageRequest $request, string $chatUuid): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();
        $clientMessageId = $request->input('client_message_id');
        $messageContent = $request->input('message');

        // Layer 1: pre-check before acquiring lock
        $existing = ChatMessage::where('client_message_id', $clientMessageId)->first();
        if ($existing !== null) {
            return $this->idempotencyResponse($existing, $student);
        }

        $idempotent = null;
        $txResult = null;
        try {
            $txResult = DB::transaction(
                function () use ($student, $chatUuid, $clientMessageId, $messageContent, &$idempotent) {
                    $conversation = ChatConversation::where('uuid', $chatUuid)
                        ->where('student_id', $student->id)
                        ->whereNull('deleted_by_student_at')
                        ->lockForUpdate()
                        ->firstOrFail();

                    // Layer 2: re-check inside lock
                    $existing2 = ChatMessage::where('client_message_id', $clientMessageId)->first();
                    if ($existing2 !== null) {
                        $idempotent = $existing2;

                        return null;
                    }

                    $hasPending = ChatMessage::where('chat_conversation_id', $conversation->id)
                        ->where('role', ChatMessage::ROLE_ASSISTANT)
                        ->where('status', ChatMessage::STATUS_PENDING)
                        ->exists();

                    if ($hasPending) {
                        abort(409, 'A response is already pending for this conversation.');
                    }

                    $nextSeq = (int) ChatMessage::where('chat_conversation_id', $conversation->id)
                        ->max('sequence_number') + 1;

                    $userMessage = ChatMessage::create([
                        'chat_conversation_id' => $conversation->id,
                        'role' => ChatMessage::ROLE_USER,
                        'content' => $messageContent,
                        'status' => ChatMessage::STATUS_COMPLETED,
                        'sequence_number' => $nextSeq,
                        'client_message_id' => $clientMessageId,
                    ]);

                    $assistantMessage = ChatMessage::create([
                        'chat_conversation_id' => $conversation->id,
                        'role' => ChatMessage::ROLE_ASSISTANT,
                        'content' => null,
                        'status' => ChatMessage::STATUS_PENDING,
                        'sequence_number' => $nextSeq + 1,
                    ]);

                    $aiRequest = ChatAiRequest::create([
                        'chat_conversation_id' => $conversation->id,
                        'user_message_id' => $userMessage->id,
                        'assistant_message_id' => $assistantMessage->id,
                        'status' => ChatAiRequest::STATUS_QUEUED,
                        'attempt_number' => 1,
                    ]);

                    $conversation->update(['last_message_at' => now()]);

                    ProcessStudentAiChat::dispatch($aiRequest->id)
                        ->onConnection(config('chat.ai_connection'))
                        ->onQueue(config('chat.ai_queue'))
                        ->afterCommit();

                    return [$conversation, $userMessage, $assistantMessage, $aiRequest];
                }
            );
        } catch (QueryException $e) {
            // Layer 3: portable re-query — do not inspect DB-specific error text
            $found = ChatMessage::where('client_message_id', $clientMessageId)->first();
            if ($found !== null) {
                return $this->idempotencyResponse($found, $student);
            }
            throw $e;
        }

        if ($idempotent !== null) {
            return $this->idempotencyResponse($idempotent, $student);
        }

        [$conversation, $userMessage, $assistantMessage, $aiRequest] = $txResult;

        return $this->cycleResponse($conversation, $userMessage, $assistantMessage, $aiRequest);
    }

    // ── messageStatus ─────────────────────────────────────────────────────────

    public function messageStatus(Request $request, string $chatUuid, string $messageUuid): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $conversation = ChatConversation::where('uuid', $chatUuid)
            ->where('student_id', $student->id)
            ->whereNull('deleted_by_student_at')
            ->firstOrFail();

        $assistant = ChatMessage::where('uuid', $messageUuid)
            ->where('chat_conversation_id', $conversation->id)
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->firstOrFail();

        $aiRequest = $assistant->aiRequestsAsAssistant()
            ->orderByDesc('attempt_number')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'assistant_message' => [
                    'uuid' => $assistant->uuid,
                    'content' => $assistant->content,
                    'status' => $assistant->status,
                ],
                'ai_request' => $aiRequest ? [
                    'uuid' => $aiRequest->uuid,
                    'status' => $aiRequest->status,
                    'attempt_number' => $aiRequest->attempt_number,
                    'error_code' => $aiRequest->error_code,
                ] : null,
            ],
        ]);
    }

    // ── retryMessage ──────────────────────────────────────────────────────────

    public function retryMessage(Request $request, string $chatUuid, string $messageUuid): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $txResult = DB::transaction(
            function () use ($student, $chatUuid, $messageUuid) {
                $conversation = ChatConversation::where('uuid', $chatUuid)
                    ->where('student_id', $student->id)
                    ->whereNull('deleted_by_student_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                // Reload assistant inside the locked transaction
                $assistant = ChatMessage::where('uuid', $messageUuid)
                    ->where('chat_conversation_id', $conversation->id)
                    ->where('role', ChatMessage::ROLE_ASSISTANT)
                    ->firstOrFail();

                if ($assistant->status !== ChatMessage::STATUS_FAILED) {
                    abort(422, 'Only failed assistant messages can be retried.');
                }

                $hasActive = $assistant->aiRequestsAsAssistant()
                    ->whereIn('status', [ChatAiRequest::STATUS_QUEUED, ChatAiRequest::STATUS_PROCESSING])
                    ->exists();

                if ($hasActive) {
                    abort(409, 'An AI request is already queued or processing for this message.');
                }

                $latestRequest = $assistant->aiRequestsAsAssistant()
                    ->orderByDesc('attempt_number')
                    ->firstOrFail();

                $assistant->update([
                    'status' => ChatMessage::STATUS_PENDING,
                    'content' => null,
                ]);

                $newAiRequest = ChatAiRequest::create([
                    'chat_conversation_id' => $conversation->id,
                    'user_message_id' => $latestRequest->user_message_id,
                    'assistant_message_id' => $assistant->id,
                    'status' => ChatAiRequest::STATUS_QUEUED,
                    'attempt_number' => $latestRequest->attempt_number + 1,
                ]);

                ProcessStudentAiChat::dispatch($newAiRequest->id)
                    ->onConnection(config('chat.ai_connection'))
                    ->onQueue(config('chat.ai_queue'))
                    ->afterCommit();

                $userMessage = ChatMessage::find($latestRequest->user_message_id);

                return [$conversation, $userMessage, $assistant, $newAiRequest];
            }
        );

        [$conversation, $userMessage, $assistant, $newAiRequest] = $txResult;

        return $this->cycleResponse($conversation, $userMessage, $assistant, $newAiRequest);
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function cycleResponse(
        ChatConversation $conversation,
        ChatMessage $userMessage,
        ChatMessage $assistantMessage,
        ChatAiRequest $aiRequest,
        int $status = 202,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => [
                'chat' => [
                    'uuid' => $conversation->uuid,
                    'title' => $conversation->title,
                    'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                ],
                'user_message' => [
                    'uuid' => $userMessage->uuid,
                    'role' => $userMessage->role,
                    'content' => $userMessage->content,
                    'status' => $userMessage->status,
                    'sequence_number' => $userMessage->sequence_number,
                ],
                'assistant_message' => [
                    'uuid' => $assistantMessage->uuid,
                    'role' => $assistantMessage->role,
                    'content' => $assistantMessage->content,
                    'status' => $assistantMessage->status,
                    'sequence_number' => $assistantMessage->sequence_number,
                ],
                'ai_request' => [
                    'uuid' => $aiRequest->uuid,
                    'status' => $aiRequest->status,
                    'attempt_number' => $aiRequest->attempt_number,
                ],
            ],
        ], $status);
    }

    private function idempotencyResponse(ChatMessage $existingUserMessage, Student $student): JsonResponse
    {
        $conversation = $existingUserMessage->conversation;

        if ((int) $conversation->student_id !== (int) $student->id) {
            return $this->collisionResponse();
        }

        if ($conversation->deleted_by_student_at !== null) {
            return $this->collisionResponse();
        }

        $latestAiRequest = $existingUserMessage->aiRequestsAsUser()
            ->orderByDesc('attempt_number')
            ->first();

        $assistantMessage = $latestAiRequest
            ? ChatMessage::find($latestAiRequest->assistant_message_id)
            : null;

        if ($assistantMessage === null || $latestAiRequest === null) {
            return $this->collisionResponse();
        }

        return $this->cycleResponse($conversation, $existingUserMessage, $assistantMessage, $latestAiRequest);
    }

    private function collisionResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Message ID already used.',
        ], 409);
    }
}
