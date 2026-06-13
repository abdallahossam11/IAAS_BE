<?php

namespace App\Services\Ai;

use App\Contracts\StudentAiChatClientContract;
use App\Exceptions\AiClientException;

class HttpStudentAiChatClient implements StudentAiChatClientContract
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
        $url   = (string) config('chat.student_ai.url', '');
        $token = (string) config('chat.student_ai.token', '');

        $this->outboundValidator->validateStudent($payload);

        $response = $this->transport->send($url, $token, $payload);

        return $this->responseValidator->validate($response, $payload['request_id']);
    }
}
