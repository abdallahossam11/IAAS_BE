<?php

return [
    'ai_driver' => env('AI_CHAT_DRIVER', 'fake'),

    'ai_queue' => env('AI_CHAT_QUEUE', 'ai-chat'),

    'student_ai' => [
        'url' => env('STUDENT_AI_API_URL', ''),
        'token' => env('STUDENT_AI_API_TOKEN', ''),
    ],

    'guest_ai' => [
        'url' => env('GUEST_AI_API_URL', ''),
        'token' => env('GUEST_AI_API_TOKEN', ''),
    ],

    'ai_connect_timeout' => (int) env('AI_CONNECT_TIMEOUT_SECONDS', 10),
    'ai_response_timeout' => (int) env('AI_RESPONSE_TIMEOUT_SECONDS', 420),

    'max_message_length' => (int) env('CHAT_MAX_MESSAGE_LENGTH', 4000),

    'guest_session_ttl' => (int) env('GUEST_SESSION_TTL_SECONDS', 86400),
    'guest_pending_ttl' => (int) env('GUEST_PENDING_TTL_SECONDS', 600),

    'guest_throttle' => [
        'requests' => (int) env('GUEST_MESSAGE_THROTTLE_REQUESTS', 10),
        'minutes' => (int) env('GUEST_MESSAGE_THROTTLE_MINUTES', 1),
    ],
];
