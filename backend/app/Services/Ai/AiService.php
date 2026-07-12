<?php

namespace App\Services\Ai;

use App\Models\AiAuditLog;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a single user message across:
 *   - OpenAI Chat Completions with tool schemas
 *   - Any tool_calls the model emits (looped up to max_tool_iterations)
 *   - Persisting the resulting message trail to ai_messages
 *   - Writing an AiAuditLog row per tool call
 */
class AiService
{
    public function __construct(
        protected OpenAiClient $client,
        protected AiToolRegistry $tools,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return array{
     *   conversation_id: string,
     *   assistant: string,
     *   tool_calls: array,
     *   model: string,
     *   usage: array
     * }
     */
    public function handleUserMessage(User $user, ?string $conversationId, string $userText): array
    {
        // 1. Load or create conversation
        $conversation = $conversationId
            ? AiConversation::where('user_id', $user->id)->find($conversationId)
            : null;
        if (!$conversation) {
            $conversation = AiConversation::create([
                'user_id' => $user->id,
                'title'   => \Illuminate\Support\Str::limit($userText, 60),
            ]);
        }

        // 2. Persist user message
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $userText,
        ]);

        // 3. Build OpenAI-shaped message array (system + history + new user)
        $messages = $this->buildMessageArray($user, $conversation);

        // 4. Loop: call OpenAI, run tools, feed results back — until no more tool calls
        $toolSchemas = $this->tools->schemasFor($user);
        $maxIters    = (int) config('openai.max_tool_iterations', 5);
        $collectedToolCalls = [];
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $finalAssistantContent = null;

        for ($i = 0; $i < $maxIters; $i++) {
            $resp = $this->client->chatCompletion($messages, $toolSchemas);
            $totalPromptTokens     += (int) ($resp['usage']['prompt_tokens']     ?? 0);
            $totalCompletionTokens += (int) ($resp['usage']['completion_tokens'] ?? 0);

            // No tool calls → terminal message
            if (empty($resp['tool_calls'])) {
                $finalAssistantContent = $resp['content'] ?? '';
                AiMessage::create([
                    'conversation_id'   => $conversation->id,
                    'role'              => 'assistant',
                    'content'           => $finalAssistantContent,
                    'prompt_tokens'     => $resp['usage']['prompt_tokens']     ?? null,
                    'completion_tokens' => $resp['usage']['completion_tokens'] ?? null,
                ]);
                break;
            }

            // Persist the assistant message that requested the tool call(s)
            $assistantToolCallPayload = array_map(fn ($tc) => [
                'id'       => $tc['id'],
                'type'     => 'function',
                'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments_raw']],
            ], $resp['tool_calls']);

            AiMessage::create([
                'conversation_id'   => $conversation->id,
                'role'              => 'assistant',
                'content'           => $resp['content'] ?: null,
                'tool_calls'        => $assistantToolCallPayload,
                'prompt_tokens'     => $resp['usage']['prompt_tokens']     ?? null,
                'completion_tokens' => $resp['usage']['completion_tokens'] ?? null,
            ]);

            // Add assistant tool-call turn to the outgoing messages array
            $messages[] = [
                'role'       => 'assistant',
                'content'    => $resp['content'],
                'tool_calls' => $assistantToolCallPayload,
            ];

            // Execute each tool call, persist result as role='tool'
            foreach ($resp['tool_calls'] as $tc) {
                $result = $this->executeTool($user, $conversation->id, $tc['name'], $tc['arguments']);
                $collectedToolCalls[] = [
                    'id'        => $tc['id'],
                    'name'      => $tc['name'],
                    'arguments' => $tc['arguments'],
                    'result'    => $result,
                ];

                $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE);
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role'            => 'tool',
                    'content'         => $resultJson,
                    'tool_call_id'    => $tc['id'],
                    'tool_name'       => $tc['name'],
                ]);
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content'      => $resultJson,
                ];
            }
        }

        if ($finalAssistantContent === null) {
            $finalAssistantContent = 'I stopped after the maximum number of tool iterations. Please rephrase or narrow your request.';
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role'            => 'assistant',
                'content'         => $finalAssistantContent,
            ]);
        }

        return [
            'conversation_id' => $conversation->id,
            'assistant'       => $finalAssistantContent,
            'tool_calls'      => $collectedToolCalls,
            'model'           => $this->client->model(),
            'usage'           => [
                'prompt_tokens'     => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
            ],
        ];
    }

    /**
     * Run a single tool with RBAC + audit logging.
     *
     * @return array The tool's JSON-serializable result, safe to feed back to OpenAI.
     */
    protected function executeTool(User $user, string $conversationId, string $name, array $arguments): array
    {
        $tool = $this->tools->get($name);
        $started = microtime(true);
        $status  = 'ok';
        $error   = null;
        $result  = [];

        if (!$tool) {
            $status = 'error';
            $error  = "Unknown tool: {$name}";
            $result = ['error' => $error];
        } elseif (!$tool->authorize($user)) {
            $status = 'denied';
            $error  = 'Permission denied for tool ' . $name;
            $result = ['error' => $error];
        } else {
            try {
                $result = $tool->execute($user, $arguments);
            } catch (\Throwable $e) {
                Log::error('AI tool execution failed', [
                    'tool'      => $name,
                    'arguments' => $arguments,
                    'error'     => $e->getMessage(),
                ]);
                $status = 'error';
                $error  = $e->getMessage();
                $result = ['error' => 'Tool execution failed: ' . $e->getMessage()];
            }
        }

        AiAuditLog::create([
            'user_id'         => $user->id,
            'conversation_id' => $conversationId,
            'tool_name'       => $name,
            'arguments'       => $arguments,
            'result'          => $result,
            'latency_ms'      => (int) ((microtime(true) - $started) * 1000),
            'status'          => $status,
            'error'           => $error,
        ]);

        return $result;
    }

    /**
     * Compose messages array in OpenAI shape: system prompt + prior messages + new user msg.
     * (We already persisted the user msg — it lives at the tail of $conversation->messages.)
     */
    protected function buildMessageArray(User $user, AiConversation $conversation): array
    {
        $businessName = (string) config('openai.business_name', 'Solarnet Internet');
        $currency     = (string) config('openai.currency', '₱');

        $system = <<<PROMPT
You are the operational AI assistant for {$businessName}, an ISP running a Laravel + MikroTik billing system. The signed-in user is "{$user->name}" (email: {$user->email}, roles: {$user->roles->pluck('name')->implode(', ')}).

Rules:
- Answer briefly and factually. When the user asks for data (customers, invoices, network status, leases, etc.), CALL the appropriate tool rather than guessing.
- Format currency amounts with the {$currency} symbol.
- Never invent customer names, account numbers, IPs, or MAC addresses — always call a tool.
- If a request would require an action (disconnect/reconnect/edit/delete), politely say those tools are not yet available in this version. Only read-only tools exist in Wave 1.
- Keep responses concise. Use short bullet points when listing rows.
PROMPT;

        $messages = [['role' => 'system', 'content' => $system]];

        // Grab prior + current messages (chronological, cap history for token budget)
        $history = $conversation->messages()->orderBy('created_at')->limit(40)->get();
        foreach ($history as $m) {
            $msg = ['role' => $m->role, 'content' => (string) ($m->content ?? '')];
            if ($m->role === 'assistant' && !empty($m->tool_calls)) {
                $msg['tool_calls'] = $m->tool_calls;
            }
            if ($m->role === 'tool') {
                $msg['tool_call_id'] = $m->tool_call_id;
            }
            $messages[] = $msg;
        }

        return $messages;
    }
}
