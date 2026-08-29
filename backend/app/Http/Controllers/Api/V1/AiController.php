<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\Setting;
use App\Services\Ai\AiService;
use App\Services\Ai\OpenAiProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AiController extends Controller
{
    public function __construct(protected AiService $ai) {}

    /**
     * POST /api/v1/ai/chat
     * Body: { message: string, conversation_id?: string, model?: string }
     */
    public function chat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message'         => 'required|string|min:1|max:8000',
            'conversation_id' => 'nullable|uuid',
            'model'           => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        if (!$this->ai->isConfigured()) {
            return response()->json([
                'success' => false,
                'code' => 'OPENAI_NOT_CONFIGURED',
                'message' => 'AI Assistant is not configured on the server. Set OPENAI_API_KEY and restart the backend.',
            ], 503);
        }

        if (!(bool) Setting::get('ai.enabled', true)) {
            return response()->json([
                'success' => false,
                'code' => 'AI_DISABLED',
                'message' => 'AI Assistant is disabled in Settings.',
            ], 503);
        }

        $selectedModel = $request->filled('model') ? trim((string) $request->input('model')) : null;
        if ($selectedModel !== null && !$request->user()->hasAnyRole(['super_admin', 'admin'])) {
            return response()->json([
                'success' => false,
                'code' => 'OPENAI_MODEL_SELECTION_FORBIDDEN',
                'message' => 'Only Super Administrators and Administrators may select an AI model.',
            ], 403);
        }

        if ($selectedModel !== null && !$this->ai->canSelectChatModel($selectedModel)) {
            return response()->json([
                'success' => false,
                'code' => 'OPENAI_MODEL_NOT_ALLOWED',
                'message' => 'The selected AI model is not allowed by SolarNet server policy.',
            ], 422);
        }

        try {
            $result = $this->ai->handleUserMessage(
                $request->user(),
                $request->input('conversation_id'),
                $request->input('message'),
                $selectedModel,
            );
        } catch (OpenAiProviderException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => $e->errorCode,
            ], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('AI chat failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'AI Assistant encountered an internal error. Please try again later.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * GET /api/v1/ai/conversations — list this user's conversations.
     */
    public function listConversations(Request $request): JsonResponse
    {
        $rows = AiConversation::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['id', 'title', 'language', 'created_at', 'updated_at']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * GET /api/v1/ai/conversations/{id}/messages
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = AiConversation::where('user_id', $request->user()->id)->find($id);
        if (!$conversation) {
            return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
        }

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get(['id', 'role', 'content', 'tool_calls', 'tool_name', 'tool_call_id', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'conversation' => [
                    'id'    => $conversation->id,
                    'title' => $conversation->title,
                    'language' => $conversation->language,
                ],
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * DELETE /api/v1/ai/conversations/{id}
     */
    public function destroyConversation(Request $request, string $id): JsonResponse
    {
        $conversation = AiConversation::where('user_id', $request->user()->id)->find($id);
        if (!$conversation) {
            return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
        }
        $conversation->delete();
        return response()->json(['success' => true, 'message' => 'Deleted']);
    }
}
