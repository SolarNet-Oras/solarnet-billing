<?php

namespace App\Services\Ai;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Central server-side OpenAI Chat Completions client.
 * The API key is loaded once from OPENAI_API_KEY and is never returned or logged.
 */
class OpenAiClient
{
    protected HttpClient $http;
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('openai.api_key');
        $this->model = (string) config('openai.model', 'gpt-5.4-mini');
        $this->baseUrl = rtrim((string) config('openai.base_url', 'https://api.openai.com/v1'), '/');
        $this->http = new HttpClient([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => (int) config('openai.timeout', 60),
            'connect_timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
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

    /** @return array<int, string> */
    public function administratorChatModels(): array
    {
        return array_values(array_unique(array_filter(
            config('openai.admin_chat_models', []),
            fn ($model) => is_string($model) && trim($model) !== '',
        )));
    }

    public function canSelectChatModel(string $model): bool
    {
        return in_array($model, $this->administratorChatModels(), true);
    }

    /**
     * @return array{content: ?string, tool_calls: array<int, array{id: string, name: string, arguments: array, arguments_raw: string}>, usage: array{prompt_tokens: ?int, completion_tokens: ?int}, raw: array, response_id?: ?string}
     */
    public function chatCompletion(
        array $messages,
        array $tools = [],
        ?string $selectedModel = null,
        ?string $previousResponseId = null,
        array $functionCallOutputs = [],
    ): array {
        if (!$this->isConfigured()) {
            throw new OpenAiProviderException(
                'OPENAI_NOT_CONFIGURED',
                'AI Assistant is not configured on the server.',
            );
        }

        if ($selectedModel !== null && !$this->canSelectChatModel($selectedModel)) {
            throw new OpenAiProviderException(
                'OPENAI_MODEL_NOT_ALLOWED',
                'The selected AI model is not allowed by SolarNet server policy.',
                422,
            );
        }

        $model = $selectedModel ?: $this->model;

        // GPT-5.6 Luna uses the Responses transport here and GPT-5.4 Pro is
        // Responses-only. Their tool calls keep the same guarded SolarNet
        // workflow as the legacy Chat Completions transport.
        if ($this->usesResponsesApi($model)) {
            return $this->responsesCompletion($messages, $tools, $model, $previousResponseId, $functionCallOutputs);
        }

        $payload = ['model' => $model, 'messages' => $messages];
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $body = $this->postJson('chat/completions', $payload, $model);
        $choice = $body['choices'][0]['message'] ?? [];
        $toolCalls = [];
        foreach (($choice['tool_calls'] ?? []) as $toolCall) {
            $toolCalls[] = [
                'id' => $toolCall['id'] ?? uniqid('call_'),
                'name' => $toolCall['function']['name'] ?? '',
                'arguments' => json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [],
                'arguments_raw' => $toolCall['function']['arguments'] ?? '{}',
            ];
        }

        return [
            'content' => $choice['content'] ?? null,
            'tool_calls' => $toolCalls,
            'usage' => [
                'prompt_tokens' => $body['usage']['prompt_tokens'] ?? null,
                'completion_tokens' => $body['usage']['completion_tokens'] ?? null,
            ],
            'raw' => $body,
        ];
    }

    protected function usesResponsesApi(string $model): bool
    {
        return in_array($model, ['gpt-5.6-luna', 'gpt-5.4-pro'], true);
    }

    /** @return array<string, mixed> */
    protected function responsesCompletion(array $messages, array $tools, string $model, ?string $previousResponseId, array $functionCallOutputs): array
    {
        $payload = [
            'model' => $model,
            'instructions' => $this->responseInstructions($messages),
            'input' => $previousResponseId !== null ? array_values($functionCallOutputs) : $this->responseInput($messages),
            'store' => true,
            'parallel_tool_calls' => true,
        ];

        if ($previousResponseId !== null) {
            $payload['previous_response_id'] = $previousResponseId;
        }

        $responseTools = $this->responseTools($tools);
        if ($responseTools !== []) {
            $payload['tools'] = $responseTools;
            $payload['tool_choice'] = 'auto';
        }

        $body = $this->postJson('responses', $payload, $model);
        $toolCalls = [];
        foreach (($body['output'] ?? []) as $output) {
            if (($output['type'] ?? null) !== 'function_call') {
                continue;
            }

            $argumentsRaw = (string) ($output['arguments'] ?? '{}');
            $toolCalls[] = [
                'id' => $output['call_id'] ?? $output['id'] ?? uniqid('call_'),
                'name' => $output['name'] ?? '',
                'arguments' => json_decode($argumentsRaw, true) ?: [],
                'arguments_raw' => $argumentsRaw,
            ];
        }

        return [
            'content' => $body['output_text'] ?? $this->responseOutputText($body['output'] ?? []),
            'tool_calls' => $toolCalls,
            'usage' => [
                'prompt_tokens' => $body['usage']['input_tokens'] ?? null,
                'completion_tokens' => $body['usage']['output_tokens'] ?? null,
            ],
            'response_id' => $body['id'] ?? null,
            'raw' => $body,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function responseInput(array $messages): array
    {
        $input = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            if ($role === 'system') {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));
            if ($role === 'tool') {
                $toolName = (string) ($message['tool_name'] ?? 'SolarNet lookup');
                $content = "{$toolName} result: {$content}";
                $role = 'user';
            }

            if ($content === '') {
                continue;
            }

            $input[] = [
                'role' => in_array($role, ['user', 'assistant'], true) ? $role : 'user',
                'content' => $content,
            ];
        }

        return $input;
    }

    protected function responseInstructions(array $messages): string
    {
        return implode("\n\n", array_values(array_filter(array_map(
            fn ($message) => ($message['role'] ?? null) === 'system' ? trim((string) ($message['content'] ?? '')) : null,
            $messages,
        ))));
    }

    /** @return array<int, array<string, mixed>> */
    protected function responseTools(array $tools): array
    {
        $responseTools = [];
        foreach ($tools as $tool) {
            $function = $tool['function'] ?? null;
            if (!is_array($function) || empty($function['name'])) {
                continue;
            }

            $responseTools[] = [
                'type' => 'function',
                'name' => $function['name'],
                'description' => $function['description'] ?? '',
                'parameters' => $function['parameters'] ?? ['type' => 'object', 'properties' => []],
            ];
        }

        return $responseTools;
    }

    protected function responseOutputText(array $output): string
    {
        $parts = [];
        foreach ($output as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    $parts[] = (string) $content['text'];
                }
            }
        }

        return implode("\n", $parts);
    }

    /** @return array<string, mixed> */
    protected function postJson(string $endpoint, array $payload, string $model): array
    {
        try {
            $response = $this->http->post($endpoint, ['json' => $payload]);
        } catch (ConnectException $e) {
            Log::warning('OpenAI connection failed', ['model' => $model, 'endpoint' => $endpoint, 'error' => $e->getMessage()]);
            throw new OpenAiProviderException(
                'OPENAI_TIMEOUT',
                'AI Assistant could not reach OpenAI. Please try again shortly.',
                503,
                $e,
            );
        } catch (RequestException $e) {
            $this->throwRequestException($e, $model, $endpoint);
        }

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    protected function throwRequestException(RequestException $e, string $model, string $endpoint): never
    {
        $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
        $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
        $providerError = json_decode($body, true)['error'] ?? [];
        $providerCode = (string) ($providerError['code'] ?? '');
        $providerType = (string) ($providerError['type'] ?? '');
        $providerMessage = trim((string) ($providerError['message'] ?? ''));

        Log::warning('OpenAI request failed', [
            'http_status' => $status,
            'provider_code' => $providerCode,
            'provider_type' => $providerType,
            'provider_message' => substr($providerMessage, 0, 300),
            'model' => $model,
            'endpoint' => $endpoint,
        ]);

        if ($status === 429) {
            $quota = $providerCode === 'insufficient_quota' || $providerType === 'insufficient_quota';
            throw new OpenAiProviderException(
                $quota ? 'OPENAI_BILLING_ERROR' : 'OPENAI_RATE_LIMIT',
                $quota
                    ? 'AI Assistant is unavailable because this OpenAI API project has no available billing or credits. Check OpenAI Platform billing for the project that owns this key.'
                    : 'OpenAI is temporarily rate-limiting this project. Wait a minute and try again.',
                $quota ? 503 : 429,
                $e,
            );
        }

        if ($status === 401) {
            throw new OpenAiProviderException(
                'OPENAI_AUTH_ERROR',
                'AI Assistant credentials were rejected by OpenAI. Update the server-side OPENAI_API_KEY and restart the backend.',
                503,
                $e,
            );
        }

        if ($status === 403 && $this->isModelAccessError($providerError)) {
            throw new OpenAiProviderException(
                'OPENAI_MODEL_ACCESS_ERROR',
                'The OpenAI API key is valid, but this OpenAI project does not have access to the selected model. Choose an available model or update the project model access.',
                503,
                $e,
            );
        }

        if ($status === 403) {
            throw new OpenAiProviderException(
                'OPENAI_PERMISSION_ERROR',
                'The OpenAI project denied this AI request. Check the project permissions and model access settings.',
                503,
                $e,
            );
        }

        if (($status === 400 || $status === 404) && $this->isModelNotFoundError($providerError)) {
            throw new OpenAiProviderException(
                'OPENAI_MODEL_ERROR',
                'The selected OpenAI model is unavailable to this API project. Choose another model or check the server model configuration.',
                503,
                $e,
            );
        }

        throw new OpenAiProviderException(
            $status >= 500 ? 'OPENAI_SERVER_ERROR' : 'OPENAI_REQUEST_ERROR',
            $status >= 500
                ? 'OpenAI is temporarily unavailable. Please try again shortly.'
                : 'AI Assistant could not complete this request. Check the server AI configuration.',
            503,
            $e,
        );
    }

    /**
     * Generate one reviewable marketing image. The caller stores the returned
     * bytes privately and does not expose the OpenAI response URL or API key.
     *
     * @return array{bytes:string,mime:string}
     */
    public function generateImage(string $prompt): array
    {
        if (! $this->isConfigured()) {
            throw new OpenAiProviderException(
                'OPENAI_NOT_CONFIGURED',
                'AI image generation is not configured on the server.',
            );
        }

        try {
            $response = $this->http->post('images/generations', [
                'json' => [
                    'model' => (string) config('openai.image_model', 'gpt-image-2'),
                    'prompt' => $prompt,
                    'size' => (string) config('openai.image_size', '1024x1024'),
                    'quality' => (string) config('openai.image_quality', 'low'),
                ],
            ]);
        } catch (ConnectException $e) {
            Log::warning('OpenAI image generation connection failed', ['error' => $e->getMessage()]);
            throw new OpenAiProviderException('OPENAI_IMAGE_TIMEOUT', 'AI image generation could not be reached. Please try again shortly.', 503, $e);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $providerError = json_decode($body, true)['error'] ?? [];
            $providerCode = (string) ($providerError['code'] ?? '');
            $providerType = (string) ($providerError['type'] ?? '');

            Log::warning('OpenAI image generation failed', [
                'http_status' => $status,
                'provider_code' => $providerCode,
                'provider_type' => $providerType,
                'model' => (string) config('openai.image_model', 'gpt-image-2'),
            ]);

            if ($status === 429) {
                $quota = $providerCode === 'insufficient_quota' || $providerType === 'insufficient_quota';
                throw new OpenAiProviderException(
                    $quota ? 'OPENAI_IMAGE_BILLING_ERROR' : 'OPENAI_IMAGE_RATE_LIMIT',
                    $quota ? 'AI image generation is unavailable because this OpenAI project has no available image-generation credits.' : 'AI image generation is temporarily rate-limited. Please try again shortly.',
                    $quota ? 503 : 429,
                    $e,
                );
            }

            if ($status === 401) {
                throw new OpenAiProviderException('OPENAI_IMAGE_AUTH_ERROR', 'OpenAI rejected the server credentials for image generation.', 503, $e);
            }

            if ($status === 403 || ($status === 404 && $providerCode === 'model_not_found')) {
                throw new OpenAiProviderException('OPENAI_IMAGE_MODEL_ACCESS_ERROR', 'This OpenAI project does not have access to the configured image model. Check OPENAI_IMAGE_MODEL and project permissions.', 503, $e);
            }

            throw new OpenAiProviderException('OPENAI_IMAGE_REQUEST_ERROR', 'AI image generation could not complete. Check the server image-model configuration.', 503, $e);
        }

        $body = json_decode((string) $response->getBody(), true);
        $encoded = (string) ($body['data'][0]['b64_json'] ?? '');
        $bytes = $encoded === '' ? false : base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            Log::warning('OpenAI image generation returned no image bytes', ['model' => (string) config('openai.image_model', 'gpt-image-2')]);
            throw new OpenAiProviderException('OPENAI_IMAGE_EMPTY', 'AI image generation returned no usable image. Please try again.', 503);
        }

        return ['bytes' => $bytes, 'mime' => 'image/png'];
    }

    /** @param array<string, mixed> $providerError */
    protected function isModelAccessError(array $providerError): bool
    {
        $message = strtolower((string) ($providerError['message'] ?? ''));

        return str_contains($message, 'does not have access to model')
            || (str_contains($message, 'model') && str_contains($message, 'access'));
    }

    /** @param array<string, mixed> $providerError */
    protected function isModelNotFoundError(array $providerError): bool
    {
        if (($providerError['code'] ?? null) === 'model_not_found') {
            return true;
        }

        $message = strtolower((string) ($providerError['message'] ?? ''));

        return str_contains($message, 'model')
            && (str_contains($message, 'not found') || str_contains($message, 'does not exist'));
    }
}
