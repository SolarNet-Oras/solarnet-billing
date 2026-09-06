import React, { useEffect, useRef, useState } from 'react';
import { X, Send, Loader2, Wrench, Trash2, MessageSquarePlus, Copy, Check, ShieldCheck, Zap } from 'lucide-react';
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

const ADMIN_CHAT_MODELS = [
  'gpt-5.4-mini',
  'gpt-5.4',
  'gpt-5.4-pro',
  'gpt-5.6-luna',
  'gpt-5.3-codex',
] as const;

const ADMIN_CHAT_MODEL_STORAGE_KEY = 'solarnet-ai-admin-chat-model';

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
  const [selectedModel, setSelectedModel] = useState<string>(ADMIN_CHAT_MODELS[0]);
  const scrollRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const roleNames = [(user as any)?.role, ...(((user as any)?.roles || []).map((role: any) => typeof role === 'string' ? role : role?.name))].filter(Boolean);
  const canSelectModel = roleNames.some((role: string) => ['super_admin', 'admin'].includes(role));
  const isSuperAdmin = roleNames.includes('super_admin');
  const isFinanceRole = roleNames.some((role: string) => ['super_admin', 'admin', 'cashier', 'accounting'].includes(role));

  useEffect(() => {
    if (!canSelectModel) return;

    const saved = window.localStorage.getItem(ADMIN_CHAT_MODEL_STORAGE_KEY);
    if (saved && ADMIN_CHAT_MODELS.includes(saved as (typeof ADMIN_CHAT_MODELS)[number])) {
      setSelectedModel(saved);
    }
  }, [canSelectModel]);

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
      const res = await aiService.chat(trimmed, conversationId, canSelectModel ? selectedModel : undefined);
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

  const suggestions = [...SUGGESTIONS, ...(isFinanceRole ? FINANCE_SUGGESTIONS : []), ...(isSuperAdmin ? SUPER_ADMIN_SUGGESTIONS : [])];

  return (
    <>
      {/* Floating button */}
      {!open && (
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="group fixed bottom-5 right-5 z-40 h-[4.5rem] w-[4.5rem] overflow-visible rounded-full border-2 border-cyan-300 bg-slate-950 shadow-[0_0_0_5px_rgba(14,165,233,.12),0_16px_45px_rgba(2,132,199,.45)] transition-all duration-300 hover:-translate-y-1 hover:scale-105 active:translate-y-0 active:scale-95 sm:bottom-6 sm:right-6"
          aria-label="Open AI Assistant"
          data-testid="ai-assistant-open-btn"
        >
          <span className="absolute inset-0 overflow-hidden rounded-full"><img src="/solarnet-ai-chat.png" alt="" className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110" /></span>
          <span className="absolute -right-0.5 -top-0.5 h-4 w-4 rounded-full border-[3px] border-slate-950 bg-emerald-400 shadow-[0_0_12px_rgba(74,222,128,.9)]" />
        </button>
      )}

      {/* Drawer */}
      {open && (
        <div
          className="fixed bottom-3 right-3 z-40 flex h-[720px] max-h-[calc(100vh-24px)] w-[590px] max-w-[calc(100vw-24px)] flex-col overflow-hidden rounded-[1.6rem] border border-cyan-400/40 bg-card shadow-[0_28px_90px_rgba(2,8,23,.38),0_0_0_1px_rgba(56,189,248,.12)] sm:bottom-6 sm:right-6 sm:max-h-[calc(100vh-48px)]"
          data-testid="ai-assistant-drawer"
        >
          {/* Header */}
          <div className="relative flex items-center gap-3 overflow-hidden border-b border-cyan-300/20 bg-[linear-gradient(115deg,#020617_0%,#082f49_46%,#1d4ed8_100%)] px-4 py-3.5 text-white">
            <div className="pointer-events-none absolute -right-10 -top-16 h-40 w-40 rounded-full bg-cyan-300/15 blur-2xl" />
            <div className="relative h-11 w-11 shrink-0 overflow-hidden rounded-full border-2 border-cyan-200/80 bg-slate-950 shadow-[0_0_18px_rgba(34,211,238,.35)]">
              <img src="/solarnet-ai-chat.png" alt="" className="h-full w-full object-cover" />
            </div>
            <div className="relative min-w-0 flex-1">
              <div className="flex items-center gap-2"><span className="text-sm font-bold tracking-wide">SolarNet Assistant</span><span className="rounded-full border border-emerald-300/30 bg-emerald-400/15 px-2 py-0.5 text-[9px] font-bold uppercase tracking-[.18em] text-emerald-200">Online</span></div>
              <div className="mt-0.5 truncate text-xs text-cyan-100/80">
                <span>{languageName} · </span>
                {conversationId ? 'Ongoing chat' : 'New chat'} · Hi, {user?.name?.split(' ')[0] || 'there'} 👋
              </div>
            </div>
            <button
              type="button"
              onClick={() => setShowSidebar((v) => !v)}
              className="relative rounded-xl border border-white/10 bg-white/5 p-2 transition-colors hover:bg-white/15"
              aria-label="History"
              data-testid="ai-history-btn"
              title="Chat history"
            >
              <MessageSquarePlus className="w-4 h-4" />
            </button>
            <button
              type="button"
              onClick={() => setOpen(false)}
              className="relative rounded-xl border border-white/10 bg-white/5 p-2 transition-colors hover:bg-white/15"
              aria-label="Close"
              data-testid="ai-assistant-close-btn"
            >
              <X className="w-4 h-4" />
            </button>
          </div>

          {/* History sidebar */}
          {showSidebar && (
            <div
              className="absolute inset-0 top-[72px] z-10 flex flex-col overflow-y-auto bg-card/95 p-4 backdrop-blur-xl"
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
          <div ref={scrollRef} className="relative flex-1 space-y-4 overflow-y-auto bg-[radial-gradient(circle_at_top,rgba(14,165,233,.12),transparent_38%)] p-4 sm:p-5">
            {messages.length === 0 && (
              <div className="space-y-5">
                <div className="pt-2 text-center">
                  <div className="mx-auto mb-3 h-24 w-24 overflow-hidden rounded-full border-[3px] border-cyan-300 bg-slate-950 shadow-[0_0_0_7px_rgba(14,165,233,.08),0_14px_35px_rgba(2,132,199,.28)]">
                    <img src="/solarnet-ai-chat.png" alt="SolarNet AI Chat" className="h-full w-full object-cover" />
                  </div>
                  <div className="text-xl font-bold tracking-tight text-foreground">How can I help?</div>
                  <div className="mx-auto mt-1.5 max-w-sm text-xs leading-5 text-muted-foreground">
                    Support in English or Filipino. I use read-only customer, DHCP lease, and network data when needed.
                  </div>
                  <div className="mt-3 flex flex-wrap justify-center gap-2"><span className="inline-flex items-center gap-1 rounded-full border border-emerald-300/40 bg-emerald-500/10 px-2.5 py-1 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300"><ShieldCheck className="h-3 w-3" />Read-only insights</span><span className="inline-flex items-center gap-1 rounded-full border border-cyan-300/40 bg-cyan-500/10 px-2.5 py-1 text-[10px] font-semibold text-cyan-700 dark:text-cyan-300"><Zap className="h-3 w-3" />English + Filipino</span></div>
                </div>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {suggestions.map((s, idx) => (
                    <button
                      key={s}
                      type="button"
                      onClick={() => void sendMessage(s)}
                      className="group rounded-xl border border-border/80 bg-card/80 px-3.5 py-3 text-left text-xs font-medium leading-5 text-foreground shadow-sm transition-all hover:-translate-y-0.5 hover:border-cyan-400/60 hover:bg-cyan-500/5 hover:shadow-md"
                      data-testid={`ai-suggestion-${idx}`}
                    >
                      <span className="mr-2 text-cyan-500">✦</span>{s}
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
          <div className="border-t border-cyan-500/15 bg-card/95 p-3.5 backdrop-blur-xl">
            {canSelectModel && (
              <label className="mb-2 flex items-center justify-between gap-3 text-xs text-muted-foreground">
                <span className="font-medium text-foreground">AI model</span>
                <select
                  value={selectedModel}
                  onChange={(event) => {
                    const nextModel = event.target.value;
                    setSelectedModel(nextModel);
                    window.localStorage.setItem(ADMIN_CHAT_MODEL_STORAGE_KEY, nextModel);
                  }}
                  disabled={sending}
                  className="min-w-0 max-w-[220px] rounded-md border border-input bg-background px-2 py-1 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50"
                  data-testid="ai-model-selector"
                  aria-label="AI model"
                >
                  {ADMIN_CHAT_MODELS.map((model) => <option key={model} value={model}>{model}</option>)}
                </select>
              </label>
            )}
            <div className="flex items-end gap-2 rounded-2xl border border-input bg-background p-1.5 shadow-inner focus-within:border-cyan-400 focus-within:ring-2 focus-within:ring-cyan-400/15">
              <textarea
                ref={inputRef}
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Ask in English or Filipino..."
                rows={1}
                className="max-h-32 flex-1 resize-none border-0 bg-transparent px-2.5 py-2 text-sm text-foreground outline-none placeholder:text-muted-foreground focus:ring-0"
                data-testid="ai-input"
                disabled={sending}
              />
              <button
                type="button"
                onClick={() => void sendMessage(message)}
                disabled={sending || !message.trim()}
                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-500 to-blue-700 text-white shadow-md shadow-blue-500/20 transition-all hover:scale-105 disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="Send"
                data-testid="ai-send-btn"
              >
                {sending ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
              </button>
            </div>
            <div className="text-[10px] text-muted-foreground mt-1.5 text-center">
              Enter to send · Shift+Enter for newline · Powered by {canSelectModel ? selectedModel : 'the SolarNet default model'}
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default FloatingAiAssistant;
