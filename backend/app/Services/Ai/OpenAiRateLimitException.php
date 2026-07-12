<?php

namespace App\Services\Ai;

/**
 * Thrown when OpenAI returns HTTP 429 (rate limit / quota).
 * AiController translates this into a user-friendly HTTP 429 response
 * so the frontend can distinguish provider throttling from real bugs.
 */
class OpenAiRateLimitException extends \RuntimeException
{
}
