<?php

namespace App\Services\Ai;

class GuestPayloadBuilder
{
    public function build(string $requestId, string $tokenHash, array $history): array
    {
        $messages = [];

        foreach ($history as $entry) {
            if (! in_array($entry['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }

            $messages[] = [
                'role'    => $entry['role'],
                'content' => $entry['content'],
            ];
        }

        return [
            'request_id'              => $requestId,
            'guest_session_reference' => $tokenHash,
            'language'                => 'auto',
            'messages'                => $messages,
        ];
    }
}
