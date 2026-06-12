<?php

namespace App\Jobs;

use App\Contracts\StudentAiChatClientContract;
use App\Exceptions\AiClientException;
use App\Models\ChatAiRequest;
use App\Models\ChatMessage;
use App\Services\Ai\StudentPayloadBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessStudentAiChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 450;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $aiRequestId) {}

    public function handle(StudentAiChatClientContract $client): void
    {
        // Atomic claim — prevents double-processing under concurrent retries
        $claimed = ChatAiRequest::query()
            ->whereKey($this->aiRequestId)
            ->where('status', ChatAiRequest::STATUS_QUEUED)
            ->update([
                'status'       => ChatAiRequest::STATUS_PROCESSING,
                'submitted_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $aiRequest = ChatAiRequest::find($this->aiRequestId);
        if ($aiRequest === null) {
            return;
        }

        $payload  = (new StudentPayloadBuilder($aiRequest))->build();
        $response = $client->send($payload);

        if (! isset($response['content']) || ! is_string($response['content'])) {
            throw new AiClientException('INVALID_AI_RESPONSE', 'The AI service returned an invalid response shape.');
        }

        DB::transaction(function () use ($aiRequest, $response) {
            ChatMessage::where('id', $aiRequest->assistant_message_id)->update([
                'content' => $response['content'],
                'status'  => ChatMessage::STATUS_COMPLETED,
            ]);
            $aiRequest->update([
                'status'       => ChatAiRequest::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        DB::transaction(function () use ($exception) {
            $aiRequest = ChatAiRequest::find($this->aiRequestId);
            if ($aiRequest === null) {
                return;
            }

            $errorCode    = $exception instanceof AiClientException ? $exception->errorCode    : 'UNEXPECTED_ERROR';
            $errorMessage = $exception instanceof AiClientException ? $exception->getMessage() : 'An unexpected error occurred.';

            $aiRequest->update([
                'status'        => ChatAiRequest::STATUS_FAILED,
                'failed_at'     => now(),
                'error_code'    => $errorCode,
                'error_message' => $errorMessage,
            ]);

            ChatMessage::where('id', $aiRequest->assistant_message_id)->update([
                'status' => ChatMessage::STATUS_FAILED,
            ]);
        });
    }
}
