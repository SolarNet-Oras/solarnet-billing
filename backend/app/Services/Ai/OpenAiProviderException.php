<?php

namespace App\Services\Ai;

/** A safe, classified OpenAI failure suitable for returning to the UI. */
class OpenAiProviderException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 503,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
