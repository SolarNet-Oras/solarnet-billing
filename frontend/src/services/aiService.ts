import api from './api';

export interface AiToolCall {
  id: string;
  name: string;
  arguments: Record<string, unknown>;
  result: Record<string, unknown> | { error: string };
}

export interface AiChatResponse {
  conversation_id: string;
  assistant: string;
  tool_calls: AiToolCall[];
  model: string;
  language?: {
    language: string;
    language_name: string;
    detected_language: string;
    detected_language_name: string;
    source: string;
    explicit: boolean;
    fallback_required: boolean;
  };
  usage: { prompt_tokens: number; completion_tokens: number };
}

export interface AiConversationSummary {
  id: string;
  title: string | null;
  language?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AiPersistedMessage {
  id: string;
  role: 'system' | 'user' | 'assistant' | 'tool';
  content: string | null;
  tool_calls?: Array<{ id: string; function: { name: string; arguments: string } }>;
  tool_name?: string | null;
  tool_call_id?: string | null;
  created_at: string;
}

const AI_ERROR_MESSAGES: Record<string, string> = {
  OPENAI_AUTH_ERROR: 'AI Assistant credentials were rejected. An administrator must update the server-side API key.',
  OPENAI_MODEL_ACCESS_ERROR: 'The API key is valid, but its OpenAI project does not have access to the configured model. Enable that model or change the server model setting.',
  OPENAI_MODEL_ERROR: 'The configured OpenAI model is unavailable. An administrator must check OPENAI_MODEL on the server.',
  OPENAI_BILLING_ERROR: 'AI Assistant is unavailable because this OpenAI API project has no available billing or credits.',
  OPENAI_RATE_LIMIT: 'OpenAI is temporarily rate-limiting this project. Please wait a minute and try again.',
  OPENAI_PERMISSION_ERROR: 'The OpenAI project denied this request. An administrator must check project permissions and model access.',
  OPENAI_TIMEOUT: 'AI Assistant could not reach OpenAI. Please try again shortly.',
  OPENAI_SERVER_ERROR: 'OpenAI is temporarily unavailable. Please try again shortly.',
};

export const getAiErrorMessage = (error: any): string => {
  const payload = error?.response?.data;
  const code = payload?.code as string | undefined;
  return (code && AI_ERROR_MESSAGES[code]) || payload?.message || error?.message || 'AI request failed';
};

export const aiService = {
  async chat(message: string, conversationId?: string | null): Promise<AiChatResponse> {
    const res = await api.post<{ success: boolean; data: AiChatResponse; message?: string }>(
      '/ai/chat',
      { message, conversation_id: conversationId || undefined }
    );
    return res.data.data;
  },

  async listConversations(): Promise<AiConversationSummary[]> {
    const res = await api.get<{ success: boolean; data: AiConversationSummary[] }>('/ai/conversations');
    return res.data.data;
  },

  async getMessages(conversationId: string): Promise<{ conversation: { id: string; title: string | null; language?: string | null }; messages: AiPersistedMessage[] }> {
    const res = await api.get<{ success: boolean; data: any }>(`/ai/conversations/${conversationId}/messages`);
    return res.data.data;
  },

  async deleteConversation(conversationId: string): Promise<void> {
    await api.delete(`/ai/conversations/${conversationId}`);
  },
};
