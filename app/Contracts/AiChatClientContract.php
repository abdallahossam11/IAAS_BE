<?php

namespace App\Contracts;

use App\Exceptions\AiClientException;

/**
 * Final AI contract client.
 *
 *  POST {base}/api/chat            {user_id, message, session_id?} → {message, user_id, session_id}
 *  POST {base}/api/chat/summarize  {user_id, session_id}           → {status, session_id, user_id, summary_preview, reason}
 */
interface AiChatClientContract
{
    /**
     * @return array{message: string, user_id: int, session_id: string}
     *
     * @throws AiClientException
     */
    public function chat(int $userId, string $message, ?string $sessionId = null): array;

    /**
     * @return array{status: string, session_id: string, user_id: int, summary_preview: string, reason: ?string}
     *
     * @throws AiClientException
     */
    public function summarize(int $userId, string $sessionId): array;
}
