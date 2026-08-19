import React, { useEffect, useRef, useState } from 'react';
import { Sparkles, X, Send, Loader2, Wrench, Trash2, MessageSquarePlus, Copy, Check } from 'lucide-react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { aiService, getAiErrorMessage, type AiChatResponse, type AiConversationSummary } from '@/services/aiService';
import { useAuth } from '@/hooks/useAuth';

interface UiMessage {
  role: 'user' | 'assistant';
  content: string;
  toolCalls?: AiChatResponse['tool_calls'];
  ts: number;
}

const SUGGESTIONS: string[] = [
  'Give me a network status summary',
  'Show me all suspended customers',
  'Bakit po mabagal ang internet namin?',
  'How many unregistered leases are ready to register?',
];

const FINANCE_SUGGESTIONS: string[] = [
  'Explain this month\'s verified financial monitoring study.',
  'How much did SolarNet recognize as collections this month by payment channel?',
  'What finance review candidates need human attention this month?',
];

const SUPER_ADMIN_SUGGESTIONS: string[] = [
  'Review /app/backend/app/Services/Ai/AiService.php and suggest 2 improvements',
  'Where do we validate account_number? Show the code and suggest a cleaner regex.',
  'Add a new read-only tool that returns today\'s collection total. Show me the full new file.',
  'Refactor the Sidebar nav items into a config array — show the diff.',
];

/**
 * Copyable code block for markdown fenced blocks.
 */
const CodeBlock: React.FC<{ language?: string; children: string }> = ({ language, children }) => {
  const [copied, setCopied] = useState<boolean>(false);
  const handleCopy = async (): Promise<void> => {
    try {
      await navigator.clipboard.writeText(children);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch { /* ignore */ }
  };
  return (
    <div className="my-2 rounded-md overflow-hidden border border-border bg-zinc-950 text-zinc-100" data-testid="ai-code-block">
      <div className="flex items-center justify-between px-3 py-1.5 bg-zinc-900 text-xs text-zinc-400 border-b border-zinc-800">
        <span className="font-mono">{language || 'code'}</span>
        <button
          type="button"
          onClick={handleCopy}
          className="flex items-center gap-1 hover:text-zinc-100"
          data-testid="ai-copy-code-btn"
        >
          {copied ? <><Check className="w-3 h-3" /> Copied</> : <><Copy className="w-3 h-3" /> Copy</>}
        </button>
      </div>
      <pre className="px-3 py-2 text-[12px] leading-snug overflow-x-auto font-mono whitespace-pre">
        <code>{children}</code>
      </pre>
    </div>
  );
};

/**
 * Markdown renderer used for assistant messages.
 * Defined outside the parent to keep component identity stable across renders.
 */
const InlineCode: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <code className="px-1 py-0.5 rounded bg-secondary text-foreground font-mono text-[12px]">{children}</code>
);

const MarkdownLink: React.FC<{ children?: React.ReactNode; href?: string }> = ({ children, href }) => (
  <a className="text-primary underline" target="_blank" rel="noopener noreferrer" href={href}>{children}</a>
);

const MARKDOWN_COMPONENTS: any = {
  code({ inline, className, children }: any) {
    const raw = String(children).replace(/\n$/, '');
    if (inline) return <InlineCode>{children}</InlineCode>;
    const match = /language-(\w+)/.exec(className || '');
    return <CodeBlock language={match?.[1]}>{raw}</CodeBlock>;
  },
  a: MarkdownLink,
};

const AssistantMarkdown: React.FC<{ content: string }> = ({ content }) => (
  <div className="prose prose-sm dark:prose-invert max-w-none prose-p:my-1.5 prose-pre:my-0 prose-headings:mt-3 prose-headings:mb-1.5 prose-ul:my-1.5 prose-li:my-0">
    <ReactMarkdown remarkPlugins={[remarkGfm]} components={MARKDOWN_COMPONENTS}>
      {content}
    </ReactMarkdown>
  </div>
);

/**
 * Floating AI assistant. Appears bottom-right on every authenticated page.
 * Wave 1: read-only tools + super-admin code exploration, non-streaming JSON reply.
 */
const FloatingAiAssistant: React.FC = () => {
  const { user, isAuthenticated } = useAuth();
  const [open, setOpen] = useState<boolean>(false);
  const [message, setMessage] = useState<string>('');
  const [messages, setMessages] = useState<UiMessage[]>([]);
  const [conversationId, setConversationId] = useState<string | null>(null);
  const [conversations, setConversations] = useState<AiConversationSummary[]>([]);
  const [sending, setSending] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [showSidebar, setShowSidebar] = useState<boolean>(false);
  const [languageName, setLanguageName] = useState<string>('English / Filipino');
  const scrollRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (open && isAuthenticated) {
      void refreshConversations();
      // Focus input on open
      setTimeout(() => inputRef.current?.focus(), 100);
    }
  }, [open, isAuthenticated]);

  // Pages can open the assistant with a safe, user-reviewable prompt. The
  // message is not sent automatically; the user still chooses whether to send
  // it, and all server-side finance tools retain their own role checks.
  useEffect(() => {
    const openWithPrompt = (event: Event): void => {
      const detail = (event as CustomEvent<{ prompt?: string }>).detail;
      setShowSidebar(false);
      if (detail?.prompt) setMessage(detail.prompt);
      setOpen(true);
    };
    window.addEventListener('solarnet:open-ai', openWithPrompt);
    return () => window.removeEventListener('solarnet:open-ai', openWithPrompt);
  }, []);

  useEffect(() => {
    // Auto-scroll to bottom on new message
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, sending]);

  const refreshConversations = async (): Promise<void> => {
    try {
      const rows = await aiService.listConversations();
      setConversations(rows);
    } catch { /* non-fatal */ }
  };

  const sendMessage = async (text: string): Promise<void> => {
    const trimmed = text.trim();
    if (!trimmed || sending) return;
    setMessage('');
    setError('');
    const now = Date.now();
    setMessages((prev) => [...prev, { role: 'user', content: trimmed, ts: now }]);
    setSending(true);
    try {
      const res = await aiService.chat(trimmed, conversationId);
      setConversationId(res.conversation_id);
      if (res.language?.language_name) setLanguageName(res.language.language_name);
      setMessages((prev) => [...prev, {
        role: 'assistant',
        content: res.assistant,
        toolCalls: res.tool_calls,
        ts: Date.now(),
      }]);
      void refreshConversations();
    } catch (err: any) {
      setError(getAiErrorMessage(err));
    } finally {
      setSending(false);
    }
  };

  const startNewConversation = (): void => {
    setConversationId(null);
    setMessages([]);
    setError('');
    setShowSidebar(false);
    setLanguageName('English / Filipino');
    setTimeout(() => inputRef.current?.focus(), 100);
  };

  const loadConversation = async (id: string): Promise<void> => {
    try {
      const { conversation, messages: msgs } = await aiService.getMessages(id);
      const ui: UiMessage[] = msgs
        .filter((m) => m.role === 'user' || m.role === 'assistant')
        .map((m) => ({
          role: m.role as 'user' | 'assistant',
          content: m.content || '',
          ts: new Date(m.created_at).getTime(),
        }));
      setConversationId(id);
      setLanguageName(conversation.language === 'fil' ? 'Filipino' : 'English / Filipino');
      setMessages(ui);
      setShowSidebar(false);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to load conversation');
    }
  };

  const deleteConversation = async (id: string): Promise<void> => {
    try {
      await aiService.deleteConversation(id);
      if (id === conversationId) startNewConversation();
      void refreshConversations();
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to delete');
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>): void => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      void sendMessage(message);
    }
  };

  if (!isAuthenticated) return null;

  const roleNames = [(user as any)?.role, ...(((user as any)?.roles || []).map((role: any) => typeof role === 'string' ? role : role?.name))].filter(Boolean);
  const isSuperAdmin = roleNames.includes('super_admin');
  const isFinanceRole = roleNames.some((role: string) => ['super_admin', 'admin', 'cashier', 'accounting'].includes(role));
  const suggestions = [...SUGGESTIONS, ...(isFinanceRole ? FINANCE_SUGGESTIONS : []), ...(isSuperAdmin ? SUPER_ADMIN_SUGGESTIONS : [])];

  return (
    <>
      {/* Floating button */}
      {!open && (
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="fixed bottom-6 right-6 z-40 w-14 h-14 rounded-full bg-gradient-to-br from-fuchsia-500 via-violet-600 to-blue-600 text-white shadow-2xl hover:scale-105 active:scale-95 transition-all flex items-center justify-center group"
          aria-label="Open AI Assistant"
          data-testid="ai-assistant-open-btn"
        >
          <Sparkles className="w-6 h-6 group-hover:rotate-12 transition-transform" />
          <span className="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-emerald-400 border-2 border-background" />
        </button>
      )}

      {/* Drawer */}
      {open && (
        <div
          className="fixed bottom-6 right-6 z-40 w-[520px] max-w-[calc(100vw-24px)] h-[680px] max-h-[calc(100vh-48px)] bg-card border border-border rounded-2xl shadow-2xl flex flex-col overflow-hidden"
          data-testid="ai-assistant-drawer"
        >
          {/* Header */}
          <div className="flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-fuchsia-500 via-violet-600 to-blue-600 text-white">
            <div className="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
              <Sparkles className="w-4 h-4" />
            </div>
            <div className="flex-1 min-w-0">
              <div className="font-semibold text-sm">SolarNet Assistant</div>
              <div className="text-xs opacity-80">
                <span>{languageName} · </span>
                {conversationId ? 'Ongoing chat' : 'New chat'} · Hi, {user?.name?.split(' ')[0] || 'there'} 👋
              </div>
            </div>
            <button
              type="button"
              onClick={() => setShowSidebar((v) => !v)}
              className="p-1.5 hover:bg-white/20 rounded transition-colors"
              aria-label="History"
              data-testid="ai-history-btn"
              title="Chat history"
            >
              <MessageSquarePlus className="w-4 h-4" />
            </button>
            <button
              type="button"
              onClick={() => setOpen(false)}
              className="p-1.5 hover:bg-white/20 rounded transition-colors"
              aria-label="Close"
              data-testid="ai-assistant-close-btn"
            >
              <X className="w-4 h-4" />
            </button>
          </div>

          {/* History sidebar */}
          {showSidebar && (
            <div
              className="absolute inset-0 top-[64px] bg-card z-10 flex flex-col p-3 overflow-y-auto"
              data-testid="ai-history-panel"
            >
              <button
                type="button"
                onClick={startNewConversation}
                className="mb-3 flex items-center gap-2 px-3 py-2 rounded-md bg-primary text-primary-foreground hover:opacity-90 text-sm"
                data-testid="ai-new-chat-btn"
              >
                <MessageSquarePlus className="w-4 h-4" />
                New chat
              </button>
              <div className="space-y-1">
                {conversations.length === 0 && (
                  <div className="text-xs text-muted-foreground p-2">No past conversations yet.</div>
                )}
                {conversations.map((c) => (
                  <div
                    key={c.id}
                    className={`group flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-secondary text-sm cursor-pointer ${
                      c.id === conversationId ? 'bg-secondary' : ''
                    }`}
                    onClick={() => loadConversation(c.id)}
                  >
                    <div className="flex-1 truncate text-foreground">{c.title || 'Untitled chat'}</div>
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        void deleteConversation(c.id);
                      }}
                      className="opacity-0 group-hover:opacity-100 text-red-500 hover:text-red-600 p-0.5"
                      aria-label="Delete"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Messages */}
          <div ref={scrollRef} className="flex-1 overflow-y-auto p-4 space-y-4 bg-background/40">
            {messages.length === 0 && (
              <div className="space-y-4">
                <div className="text-center py-4">
                  <div className="w-14 h-14 mx-auto mb-3 rounded-full bg-gradient-to-br from-fuchsia-500 via-violet-600 to-blue-600 flex items-center justify-center">
                    <Sparkles className="w-6 h-6 text-white" />
                  </div>
                  <div className="font-semibold text-foreground">How can I help?</div>
                  <div className="text-xs text-muted-foreground mt-1">
                    Support in English or Filipino. I use read-only customer, DHCP lease, and network data when needed.
                  </div>
                </div>
                <div className="grid grid-cols-1 gap-2">
                  {suggestions.map((s, idx) => (
                    <button
                      key={s}
                      type="button"
                      onClick={() => void sendMessage(s)}
                      className="text-left px-3 py-2 rounded-lg border border-border hover:bg-secondary text-sm text-foreground transition-colors"
                      data-testid={`ai-suggestion-${idx}`}
                    >
                      {s}
                    </button>
                  ))}
                </div>
              </div>
            )}
            {messages.map((m, i) => (
              <div key={m.ts + '-' + i} className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                <div
                  className={`max-w-[92%] rounded-2xl px-3.5 py-2 text-sm leading-relaxed ${
                    m.role === 'user'
                      ? 'bg-primary text-primary-foreground rounded-br-sm whitespace-pre-wrap'
                      : 'bg-secondary text-foreground rounded-bl-sm border border-border'
                  }`}
                  data-testid={`ai-msg-${m.role}-${i}`}
                >
                  {m.toolCalls && m.toolCalls.length > 0 && (
                    <div className="mb-2 pb-2 border-b border-border/60 space-y-1">
                      {m.toolCalls.map((tc) => (
                        <div key={tc.id} className="flex items-center gap-1.5 text-xs opacity-80">
                          <Wrench className="w-3 h-3 flex-shrink-0" />
                          <code className="font-mono">{tc.name}</code>
                          {(tc.result as any)?.error ? (
                            <span className="text-red-500">· error</span>
                          ) : (
                            <span className="text-emerald-500">· ok</span>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                  {m.role === 'assistant' ? (
                    <AssistantMarkdown content={m.content} />
                  ) : (
                    m.content
                  )}
                </div>
              </div>
            ))}
            {sending && (
              <div className="flex justify-start">
                <div className="max-w-[85%] rounded-2xl rounded-bl-sm px-3.5 py-2 text-sm bg-secondary text-muted-foreground border border-border flex items-center gap-2">
                  <Loader2 className="w-4 h-4 animate-spin" />
                  Thinking…
                </div>
              </div>
            )}
            {error && (
              <div className="text-xs text-red-500 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-2">
                {error}
              </div>
            )}
          </div>

          {/* Input */}
          <div className="border-t border-border p-3 bg-card">
            <div className="flex items-end gap-2">
              <textarea
                ref={inputRef}
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Ask in English or Filipino..."
                rows={1}
                className="flex-1 resize-none px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-primary max-h-32"
                data-testid="ai-input"
                disabled={sending}
              />
              <button
                type="button"
                onClick={() => void sendMessage(message)}
                disabled={sending || !message.trim()}
                className="w-10 h-10 rounded-lg bg-primary text-primary-foreground hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center transition-opacity"
                aria-label="Send"
                data-testid="ai-send-btn"
              >
                {sending ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
              </button>
            </div>
            <div className="text-[10px] text-muted-foreground mt-1.5 text-center">
              Enter to send · Shift+Enter for newline · Powered by GPT-5.4 Mini
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default FloatingAiAssistant;
