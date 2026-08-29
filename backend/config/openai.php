<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    | Server-side only. Never expose these values to the browser.
    */

    'api_key' => env('OPENAI_API_KEY'),

    /**
     * Chat model. Default = gpt-5.4-mini (fast + cheap, great for tool calling).
     */
    'model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),

    /**
     * Models that an Administrator or Super Administrator may select for an
     * individual staff-assistant reply. This is a server-side allow-list;
     * other roles always use the default OPENAI_MODEL above.
     */
    'admin_chat_models' => [
        'gpt-5.4-mini',
        'gpt-5.4',
        'gpt-5.4-pro',
        'gpt-5.6-luna',
        'gpt-5.3-codex',
    ],

    // Kept separate from the text model. Image generation is an explicit
    // administrator action in Facebook Post Studio and may incur API usage.
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
    'image_quality' => env('OPENAI_IMAGE_QUALITY', 'low'),
    'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),

    /**
     * REST base URL.
     */
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

    /**
     * HTTP timeout for OpenAI calls (seconds).
     */
    'timeout' => (int) env('OPENAI_TIMEOUT', 60),

    /**
     * Max tool-calling round-trips per user message.
     * Prevents infinite loops if the model keeps calling tools.
     */
    'max_tool_iterations' => (int) env('OPENAI_MAX_TOOL_ITERATIONS', 5),

    /**
     * Business context passed to the system prompt so answers use ISP terminology.
     */
    'business_name' => env('OPENAI_BUSINESS_NAME', 'Solarnet Internet'),
    'currency'      => env('OPENAI_CURRENCY', '₱'),
];
