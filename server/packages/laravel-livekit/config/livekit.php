<?php

return [
    'url' => env('LIVEKIT_URL', 'http://livekit:7880'),
    'public_url' => env('LIVEKIT_PUBLIC_URL', 'ws://localhost:7880'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),
    'join_token_ttl' => (int) env('LIVEKIT_JOIN_TOKEN_TTL', 120),
    'service_token_ttl' => (int) env('LIVEKIT_SERVICE_TOKEN_TTL', 60),
    'http_timeout' => (int) env('LIVEKIT_HTTP_TIMEOUT', 5),
];
