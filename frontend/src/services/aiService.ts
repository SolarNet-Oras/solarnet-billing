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
  usage: { prompt_tokens: number; completion_tokens: number };
}

export interface AiConversationSummary {
  id: string;
  title: string | null;
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

  async getMessages(conversationId: string): Promise<{ conversation: { id: string; title: string | null }; messages: AiPersistedMessage[] }> {
    const res = await api.get<{ success: boolean; data: any }>(`/ai/conversations/${conversationId}/messages`);
    return res.data.data;
  },

  async deleteConversation(conversationId: string): Promise<void> {
    await api.delete(`/ai/conversations/${conversationId}`);
  },
};
