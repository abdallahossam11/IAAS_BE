<?php

namespace App\Contracts;

use App\Exceptions\AiClientException;

interface StudentAiChatClientContract
{
    /**
     * @return array{content: string}
     * @throws AiClientException
     */
    public function send(array $payload): array;
}
