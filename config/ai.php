<?php

declare(strict_types=1);

return [
    'provider' => env('AI_PROVIDER', 'openai'),
    'api_key' => env('OPENAI_API_KEY', ''),
    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'endpoint' => env('OPENAI_API_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),
];
