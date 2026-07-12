<?php

namespace App\Services\Ai;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * Thin OpenAI Chat Completions client.
 * We use Guzzle instead of the openai-php SDK to keep composer deps light.
 */
class OpenAiClient
{
    protected HttpClient $http;
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = (string) config('openai.api_key');
        $this->model   = (string) config('openai.model', 'gpt-5.4-mini');
        $this->baseUrl = rtrim((string) config('openai.base_url', 'https://api.openai.com/v1'), '/');
        $this->http    = new HttpClient([
            'base_uri' => $this->baseUrl . '/',
            'timeout'  => (int) config('openai.timeout', 60),
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * POST /chat/completions with tool-calling support.
     *
     * @param  array  $messages  OpenAI message array
     * @param  array  $tools     OpenAI tool schemas (empty array = no tools)
     * @return array{
     *     content: ?string,
     *     tool_calls: array<int, array{id: string, name: string, arguments: array}>,
     *     usage: array{prompt_tokens: ?int, completion_tokens: ?int},
     *     raw: array
     * }
     * @throws \RuntimeException
     */
    public function chatCompletion(array $messages, array $tools = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('OPENAI_API_KEY is not configured on the server.');
        }

        $payload = [
            'model'    => $this->model,
            'messages' => $messages,
        ];
        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = $this->http->post('chat/completions', ['json' => $payload]);
        } catch (RequestException $e) {
            $body   = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;

            // OpenAI rate limit / quota — mark distinctly so the controller can return 429
            if ($status === 429) {
                throw new OpenAiRateLimitException(
                    'OpenAI rate limit or quota reached. Please try again shortly or upgrade your plan.',
                    429,
                    $e
                );
            }
            throw new \RuntimeException('OpenAI API error: ' . $body, $status, $e);
        }

        $body = json_decode((string) $response->getBody(), true);
        $choice = $body['choices'][0]['message'] ?? [];

        $toolCalls = [];
        foreach (($choice['tool_calls'] ?? []) as $tc) {
            $toolCalls[] = [
                'id'        => $tc['id'] ?? uniqid('call_'),
                'name'      => $tc['function']['name'] ?? '',
                'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
                // Keep raw args string so we can echo it back exactly in the next turn
                'arguments_raw' => $tc['function']['arguments'] ?? '{}',
            ];
        }

        return [
            'content'    => $choice['content'] ?? null,
            'tool_calls' => $toolCalls,
            'usage'      => [
                'prompt_tokens'     => $body['usage']['prompt_tokens']     ?? null,
                'completion_tokens' => $body['usage']['completion_tokens'] ?? null,
            ],
            'raw' => $body,
        ];
    }
}
