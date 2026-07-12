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
