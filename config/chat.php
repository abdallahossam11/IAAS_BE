<?php

return [
    'ai_driver' => env('AI_CHAT_DRIVER', 'fake'),

    'ai_queue' => env('AI_CHAT_QUEUE', 'ai-chat'),

    // Queue connection the AI chat jobs are dispatched onto. Defaults to redis
    // in production; the test suite overrides this to "sync" via phpunit.xml.
    'ai_connection' => env('AI_CHAT_CONNECTION', 'redis'),

    // Final AI contract (single service, single token, path-appended).
    'ai' => [
        'base_url' => env('AI_SERVICE_URL'),
        'token' => env('AI_SERVICE_TOKEN'),
        'chat_path' => env('AI_CHAT_PATH', '/api/chat'),
        'summarize_path' => env('AI_CHAT_SUMMARIZE_PATH', '/api/chat/summarize'),
    ],

    'ai_connect_timeout' => (int) env('AI_CONNECT_TIMEOUT_SECONDS', 10),
    'ai_response_timeout' => (int) env('AI_RESPONSE_TIMEOUT_SECONDS', 420),

    'max_message_length' => (int) env('CHAT_MAX_MESSAGE_LENGTH', 3000),

    'guest_session_ttl' => (int) env('GUEST_SESSION_TTL_SECONDS', 7200),
    'guest_pending_ttl' => (int) env('GUEST_PENDING_TTL_SECONDS', 600),

    // Signed-in conversations idle for at least this many seconds are eligible
    // for summarization (default 2 hours). Guests are never summarized.
    'summarize_idle_seconds' => (int) env('CHAT_SUMMARIZE_IDLE_SECONDS', 7200),

    'guest_throttle' => [
        'requests' => (int) env('GUEST_MESSAGE_THROTTLE_REQUESTS', 10),
        'minutes' => (int) env('GUEST_MESSAGE_THROTTLE_MINUTES', 1),
    ],
];
