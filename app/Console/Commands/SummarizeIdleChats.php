<?php

namespace App\Console\Commands;

use App\Jobs\SummarizeChatConversation;
use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SummarizeIdleChats extends Command
{
    protected $signature = 'chat:summarize-idle';

    protected $description = 'Dispatch summarization for idle signed-in chat conversations (guests excluded).';

    public function handle(): int
    {
        $idleSeconds = (int) config('chat.summarize_idle_seconds', 7200);
        $threshold = now()->subSeconds($idleSeconds);

        $count = 0;

        $this->eligibleQuery($threshold)
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use (&$count): void {
                foreach ($conversations as $conversation) {
                    SummarizeChatConversation::dispatch($conversation->id)
                        ->onConnection(config('chat.ai_connection'))
                        ->onQueue(config('chat.ai_queue'));

                    $count++;
                }
            });

        $this->info("Dispatched {$count} summarization job(s).");

        return self::SUCCESS;
    }

    /**
     * Signed-in conversations that:
     *  - have an AI session_id,
     *  - have been idle past the threshold,
     *  - have no queued/processing AI work,
     *  - have no up-to-date summary (missing, or summary older than last message).
     *
     * Student-hidden conversations are included (still saved; admin-restorable).
     * Guests are inherently excluded — they have no chat_conversations rows.
     */
    private function eligibleQuery(Carbon $threshold): Builder
    {
        return ChatConversation::query()
            ->whereNotNull('session_id')
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<', $threshold)
            ->whereDoesntHave('aiRequests', function (Builder $q): void {
                $q->whereIn('status', [
                    ChatAiRequest::STATUS_QUEUED,
                    ChatAiRequest::STATUS_PROCESSING,
                ]);
            })
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('chat_summaries')
                    ->whereColumn('chat_summaries.user_id', 'chat_conversations.student_id')
                    ->whereColumn('chat_summaries.session_id', 'chat_conversations.session_id')
                    ->whereColumn('chat_summaries.updated_at', '>=', 'chat_conversations.last_message_at');
            });
    }
}
