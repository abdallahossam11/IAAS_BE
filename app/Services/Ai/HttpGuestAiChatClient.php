<?php

namespace App\Services\Ai;

use App\Contracts\GuestAiChatClientContract;
use App\Exceptions\AiClientException;

class HttpGuestAiChatClient implements GuestAiChatClientContract
{
    public function __construct(
        private readonly AiHttpTransport           $transport,
        private readonly AiOutboundPayloadValidator $outboundValidator,
        private readonly AiHttpResponseValidator    $responseValidator,
    ) {}

    /**
     * @return array{content: string}
     * @throws AiClientException
     */
    public function send(array $payload): array
    {
        $url   = (string) config('chat.guest_ai.url', '');
        $token = (string) config('chat.guest_ai.token', '');

        $this->outboundValidator->validateGuest($payload);

        $response = $this->transport->send($url, $token, $payload);

        return $this->responseValidator->validate($response, $payload['request_id']);
    }
}
