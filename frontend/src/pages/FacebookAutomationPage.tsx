import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { CheckCircle2, CircleAlert, Link2, Megaphone, MessageCircle, RefreshCw, Send, Settings2, ShieldCheck, Sparkles, UserRound } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { FacebookPostStudio } from '@/components/facebook/FacebookPostStudio';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

type Connection = {
  id: string;
  page_id: string;
  page_name: string;
  is_active: boolean;
  webhook_subscribed_at: string | null;
  last_webhook_at: string | null;
  last_error: string | null;
  connected_by?: { id: string; name: string } | null;
};

type Status = {
  oauth_ready: boolean;
  webhook_ready: boolean;
  ai_ready: boolean;
  webhook_url: string;
  redirect_url: string;
  graph_version: string;
  auto_reply_enabled: boolean;
  auto_reply_ready: boolean;
  auto_reply_blockers: string[];
  last_auto_reply: { delivery_status: string; delivery_error: string | null; created_at: string | null } | null;
  marketing_enabled: boolean;
  connections: Connection[];
};

type MessengerMessage = {
  id: string;
  direction: 'inbound' | 'outbound';
  source: 'webhook' | 'staff' | 'ai_auto' | 'campaign' | string;
  message_text: string | null;
  delivery_status: string;
  delivery_error: string | null;
  sent_at: string | null;
  created_at: string | null;
};

type Conversation = {
  id: string;
  connection_id: string;
  page_name?: string | null;
  display_name: string;
  human_handoff_required: boolean;
  marketing_opt_in_at: string | null;
  marketing_opt_out_at: string | null;
  last_inbound_at: string | null;
  last_outbound_at: string | null;
  last_message_at: string | null;
  within_response_window: boolean;
  last_message?: MessengerMessage | null;
};

type Campaign = {
  id: string;
  connection_id: string;
  name: string;
  message_text: string;
  status: string;
  recipient_count: number;
  sent_count: number;
  failed_count: number;
  last_error: string | null;
  created_at: string | null;
  approved_at: string | null;
  completed_at: string | null;
};

type PageCandidate = { id: string; name: string };

const displayDate = (value?: string | null): string => value ? new Date(value).toLocaleString('en-PH') : '—';

export default function FacebookAutomationPage(): React.JSX.Element {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const [status, setStatus] = useState<Status | null>(null);
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [selectedConversation, setSelectedConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<MessengerMessage[]>([]);
  const [pageCandidates, setPageCandidates] = useState<PageCandidate[]>([]);
  const [reply, setReply] = useState('');
  const [campaignForm, setCampaignForm] = useState({ connection_id: '', name: '', message_text: '' });
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const roles = [user?.role, ...(user?.roles || []).map((role) => typeof role === 'string' ? role : role.name)];
  const isAdmin = roles.includes('super_admin') || roles.includes('admin');

  const load = useCallback(async (): Promise<void> => {
    setError('');
    try {
      const [statusResponse, conversationResponse] = await Promise.all([
        api.get('/facebook-automation/status'),
        api.get('/facebook-automation/conversations'),
      ]);
      const nextStatus = statusResponse.data.data as Status;
      setStatus(nextStatus);
      setConversations(conversationResponse.data.data || []);
      setCampaignForm((current) => ({ ...current, connection_id: current.connection_id || nextStatus.connections.find((connection) => connection.is_active)?.id || '' }));
      if (isAdmin) {
        const campaignResponse = await api.get('/facebook-automation/campaigns');
        setCampaigns(campaignResponse.data.data || []);
      }
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not load Facebook Automation.');
    }
  }, [isAdmin]);

  const loadMessages = useCallback(async (conversation: Conversation): Promise<void> => {
    setSelectedConversation(conversation);
    setReply('');
    setError('');
    try {
      const response = await api.get(`/facebook-automation/conversations/${conversation.id}/messages`);
      setSelectedConversation(response.data.data.conversation);
      setMessages(response.data.data.messages || []);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not load this conversation.');
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    const interval = window.setInterval(() => {
      void load();
      if (selectedConversation) void loadMessages(selectedConversation);
    }, 15000);
    return () => window.clearInterval(interval);
  }, [load, loadMessages, selectedConversation]);

  useEffect(() => {
    const setupToken = searchParams.get('facebook_setup');
    const callbackError = searchParams.get('facebook_error');
    if (callbackError) {
      setError('Facebook connection was not completed. Confirm the Meta App redirect URL and Page permissions, then try again.');
      searchParams.delete('facebook_error');
      setSearchParams(searchParams, { replace: true });
      return;
    }
    if (!setupToken || !isAdmin) return;
    void api.get('/facebook-automation/page-candidates', { params: { setup_token: setupToken } })
      .then((response) => setPageCandidates(response.data.data || []))
      .catch((requestError) => setError(requestError.response?.data?.message || 'Facebook Page selection expired.'));
  }, [isAdmin, searchParams, setSearchParams]);

  const connectFacebook = async (): Promise<void> => {
    setBusy('connect'); setError('');
    try {
      const response = await api.get('/facebook-automation/connect-url');
      window.location.assign(response.data.data.url);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not start Facebook connection.');
      setBusy('');
    }
  };

  const choosePage = async (pageId: string): Promise<void> => {
    const setupToken = searchParams.get('facebook_setup');
    if (!setupToken) return;
    setBusy(`page:${pageId}`); setError('');
    try {
      await api.post('/facebook-automation/connect-page', { setup_token: setupToken, page_id: pageId });
      setPageCandidates([]);
      searchParams.delete('facebook_setup');
      setSearchParams(searchParams, { replace: true });
      setNotice('Facebook Page connected. Add the webhook in Meta before enabling AI replies.');
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not connect this Facebook Page.');
    } finally { setBusy(''); }
  };

  const subscribeWebhook = async (connection: Connection): Promise<void> => {
    setBusy(`subscribe:${connection.id}`); setError('');
    try {
      await api.post(`/facebook-automation/connections/${connection.id}/subscribe-webhook`);
      setNotice('The Facebook Page is subscribed to Messenger webhook events. Send a new message from another Facebook account to complete the live test.');
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Meta did not accept the Page webhook subscription.');
    } finally { setBusy(''); }
  };

  const saveControls = async (): Promise<void> => {
    if (!status) return;
    setBusy('controls'); setError('');
    try {
      await api.put('/facebook-automation/settings', {
        auto_reply_enabled: status.auto_reply_enabled,
        marketing_enabled: status.marketing_enabled,
      });
      setNotice('Facebook automation controls saved.');
      await load();
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not save controls.'); }
    finally { setBusy(''); }
  };

  const generateDraft = async (): Promise<void> => {
    if (!selectedConversation) return;
    setBusy('draft'); setError('');
    try {
      const response = await api.post(`/facebook-automation/conversations/${selectedConversation.id}/ai-draft`);
      setReply(response.data.reply || '');
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'AI could not prepare a reply.'); }
    finally { setBusy(''); }
  };

  const sendReply = async (): Promise<void> => {
    if (!selectedConversation || !reply.trim()) return;
    setBusy('reply'); setError('');
    try {
      await api.post(`/facebook-automation/conversations/${selectedConversation.id}/reply`, { message_text: reply.trim() });
      setReply('');
      await loadMessages(selectedConversation);
      await load();
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not send this Messenger reply.'); }
    finally { setBusy(''); }
  };

  const updateConversation = async (updates: Record<string, unknown>): Promise<void> => {
    if (!selectedConversation) return;
    setBusy('conversation'); setError('');
    try {
      const response = await api.patch(`/facebook-automation/conversations/${selectedConversation.id}`, updates);
      setSelectedConversation((current) => current ? { ...current, ...response.data.data } : current);
      await load();
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not update the conversation controls.'); }
    finally { setBusy(''); }
  };

  const createCampaign = async (): Promise<void> => {
    setBusy('campaign'); setError('');
    try {
      await api.post('/facebook-automation/campaigns', campaignForm);
      setCampaignForm((current) => ({ ...current, name: '', message_text: '' }));
      setNotice('Campaign saved as a draft. It sends nothing until an administrator approves it.');
      await load();
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not save campaign draft.'); }
    finally { setBusy(''); }
  };

  const sendCampaign = async (campaign: Campaign): Promise<void> => {
    if (!window.confirm(`Send “${campaign.name}” to eligible opted-in contacts with an active Messenger conversation?`)) return;
    setBusy(`campaign:${campaign.id}`); setError('');
    try {
      await api.post(`/facebook-automation/campaigns/${campaign.id}/send`, { confirm_send: true });
      setNotice('Campaign delivery was queued. Review the status after the worker processes it.');
      await load();
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not queue campaign delivery.'); }
    finally { setBusy(''); }
  };

  const connected = status?.connections.filter((connection) => connection.is_active) || [];
  const selectedMessages = useMemo(() => messages, [messages]);

  return <DashboardLayout>
    <main className="mx-auto max-w-7xl space-y-5">
      <header className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex gap-3"><div className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-sky-600 text-white shadow-sm"><MessageCircle className="h-6 w-6" /></div><div><h1 className="text-xl font-bold text-foreground sm:text-2xl">Facebook Automation</h1><p className="mt-1 max-w-3xl text-sm text-muted-foreground">A controlled Facebook Page Messenger inbox for staff replies, AI drafts, and consent-based campaign messages.</p></div></div>
        <button type="button" onClick={() => void load()} disabled={busy !== ''} className="inline-flex items-center justify-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-foreground hover:bg-muted disabled:opacity-60"><RefreshCw className={`h-4 w-4 ${busy === 'reload' ? 'animate-spin' : ''}`} />Refresh</button>
      </header>

      {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{error}</p>}
      {notice && <p className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200">{notice}</p>}

      {isAdmin && <FacebookPostStudio connections={connected} />}

      {isAdmin && status && <section className={`rounded-2xl border p-4 ${status.auto_reply_ready ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/30' : 'border-amber-200 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/30'}`}><div className="flex gap-2"><ShieldCheck className={`mt-0.5 h-5 w-5 ${status.auto_reply_ready ? 'text-emerald-600' : 'text-amber-600'}`} /><div><h2 className="font-semibold text-foreground">Automatic Messenger reply: {status.auto_reply_ready ? 'Ready' : 'Needs attention'}</h2>{status.auto_reply_blockers.length > 0 && <ul className="mt-2 list-disc space-y-1 pl-5 text-xs text-muted-foreground">{status.auto_reply_blockers.map((blocker) => <li key={blocker}>{blocker}</li>)}</ul>}{connected[0]?.webhook_subscribed_at && !connected[0]?.last_webhook_at && <p className="mt-2 text-xs font-medium text-emerald-700 dark:text-emerald-300">Page subscribed {displayDate(connected[0].webhook_subscribed_at)} — waiting for the first new Messenger test message.</p>}{status.last_auto_reply && <p className="mt-2 text-xs text-muted-foreground">Last attempt: {displayDate(status.last_auto_reply.created_at)} · {status.last_auto_reply.delivery_status}{status.last_auto_reply.delivery_error ? ` · ${status.last_auto_reply.delivery_error}` : ''}</p>}<p className="mt-2 text-xs text-muted-foreground">The production queue worker must be running. Every new text or attachment can receive an empathetic reply. Simply viewing a chat does not stop automation; only the explicit Human handoff control does.</p>{connected[0] && !connected[0].webhook_subscribed_at && <button type="button" onClick={() => void subscribeWebhook(connected[0])} disabled={busy !== ''} className="mt-3 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-primary-foreground disabled:opacity-60">{busy === `subscribe:${connected[0].id}` ? 'Subscribing…' : 'Subscribe Page webhook'}</button>}</div></div></section>}

      {pageCandidates.length > 0 && <section className="rounded-2xl border border-sky-200 bg-sky-50/70 p-4 dark:border-sky-900/60 dark:bg-sky-950/20"><div className="flex gap-2"><Link2 className="mt-0.5 h-5 w-5 text-sky-700 dark:text-sky-300" /><div><h2 className="font-semibold text-foreground">Choose the Facebook Page to connect</h2><p className="mt-1 text-sm text-muted-foreground">Only the selected Page is connected. Personal Facebook messages are never accessed.</p></div></div><div className="mt-4 flex flex-wrap gap-2">{pageCandidates.map((page) => <button key={page.id} type="button" disabled={busy !== ''} onClick={() => void choosePage(page.id)} className="rounded-lg bg-sky-700 px-3 py-2 text-sm font-semibold text-white hover:bg-sky-800 disabled:opacity-60">{busy === `page:${page.id}` ? 'Connecting…' : `Connect ${page.name}`}</button>)}</div></section>}

      <section className="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(19rem,0.6fr)]">
        <article className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><div className="flex items-center gap-2"><Link2 className="h-5 w-5 text-primary" /><h2 className="font-semibold text-foreground">Facebook Page connection</h2></div><p className="mt-1 text-sm text-muted-foreground">Connect a Meta-managed Page through OAuth. Page tokens stay encrypted on the server.</p></div>{isAdmin && <button type="button" onClick={() => void connectFacebook()} disabled={busy !== '' || !status?.oauth_ready} className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60">{busy === 'connect' ? 'Opening Facebook…' : 'Link Facebook Page'}</button>}</div><div className="mt-4 grid gap-3 sm:grid-cols-3"><StatusTile label="OAuth" good={!!status?.oauth_ready} detail={status?.oauth_ready ? 'Ready for Page admin login' : 'Server keys needed'} /><StatusTile label="Webhook" good={!!status?.webhook_ready} detail={status?.webhook_ready ? 'Signature verification ready' : 'Verify token / secret needed'} /><StatusTile label="AI drafts" good={!!status?.ai_ready} detail={status?.ai_ready ? 'OpenAI available' : 'OpenAI key needed'} /></div>{connected.length ? <div className="mt-4 space-y-2">{connected.map((connection) => <div key={connection.id} className="rounded-xl border border-border bg-background p-3"><div className="flex flex-wrap items-start justify-between gap-2"><div><p className="font-semibold text-foreground">{connection.page_name}</p><p className="mt-1 text-xs text-muted-foreground">Last webhook: {displayDate(connection.last_webhook_at)}{connection.connected_by ? ` · linked by ${connection.connected_by.name}` : ''}</p></div><span className="rounded-full bg-emerald-100 px-2 py-1 text-[11px] font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">CONNECTED</span></div>{connection.last_error && <p className="mt-2 text-xs text-amber-700 dark:text-amber-300">Last Meta error: {connection.last_error}</p>}</div>)}</div> : <p className="mt-4 rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">No Facebook Page is connected yet. Complete the server setup, then link a Page administrator account.</p>}</article>
        <article className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex items-center gap-2"><ShieldCheck className="h-5 w-5 text-emerald-600" /><h2 className="font-semibold text-foreground">Safety rules</h2></div><ul className="mt-3 space-y-2 text-sm text-muted-foreground"><li>• AI does not access SolarNet customer billing or network records.</li><li>• Replies need an active customer Messenger conversation.</li><li>• Campaigns require staff-recorded consent, an active conversation, and explicit send approval.</li><li>• “STOP”, “unsubscribe”, “huwag”, or “ayaw” opt out a contact from campaigns.</li></ul></article>
      </section>

      {isAdmin && status && <section className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex items-start gap-2"><Settings2 className="mt-0.5 h-5 w-5 text-primary" /><div><h2 className="font-semibold text-foreground">Automation controls</h2><p className="mt-1 text-sm text-muted-foreground">Both controls start disabled. Enabling them does not bypass Meta’s Page and messaging policies.</p></div></div><div className="mt-4 grid gap-3 sm:grid-cols-2"><ToggleCard label="AI reply after a new Messenger message" detail="The AI drafts and sends a generic support reply. A human-handoff conversation is excluded." checked={status.auto_reply_enabled} onChange={(value) => setStatus({ ...status, auto_reply_enabled: value })} /><ToggleCard label="Consent-based campaign delivery" detail="Allows an administrator to send an approved draft only to opted-in contacts within the active reply window." checked={status.marketing_enabled} onChange={(value) => setStatus({ ...status, marketing_enabled: value })} /></div><button type="button" onClick={() => void saveControls()} disabled={busy !== ''} className="mt-4 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60">{busy === 'controls' ? 'Saving…' : 'Save controls'}</button></section>}

      <section className="grid gap-4 xl:grid-cols-[minmax(19rem,0.55fr)_minmax(0,1.45fr)]"><article className="rounded-2xl border border-border bg-card p-3 sm:p-4"><div className="flex items-center justify-between gap-3"><div><h2 className="font-semibold text-foreground">Messenger inbox</h2><p className="mt-1 text-xs text-muted-foreground">Latest 100 Page conversations</p></div><span className="rounded-full bg-muted px-2.5 py-1 text-xs font-bold text-muted-foreground">{conversations.length}</span></div><div className="mt-3 max-h-[620px] space-y-2 overflow-y-auto pr-1">{conversations.map((conversation) => <button type="button" key={conversation.id} onClick={() => void loadMessages(conversation)} className={`w-full rounded-xl border p-3 text-left transition ${selectedConversation?.id === conversation.id ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-background hover:bg-muted/60'}`}><div className="flex items-start justify-between gap-2"><span className="min-w-0"><span className="flex items-center gap-1.5 truncate font-semibold text-foreground"><UserRound className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />{conversation.display_name}</span><span className="mt-1 block truncate text-xs text-muted-foreground">{conversation.last_message?.message_text || 'Non-text Messenger event'}</span></span><span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold ${conversation.within_response_window ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-muted text-muted-foreground'}`}>{conversation.within_response_window ? 'REPLY OPEN' : 'WINDOW CLOSED'}</span></div><div className="mt-2 flex flex-wrap gap-1.5 text-[10px] font-semibold"><span className={conversation.human_handoff_required ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground'}>{conversation.human_handoff_required ? 'HUMAN HANDOFF' : 'AI ELIGIBLE'}</span>{conversation.marketing_opt_in_at && <span className="text-sky-700 dark:text-sky-300">OPTED IN</span>}{conversation.marketing_opt_out_at && <span className="text-red-700 dark:text-red-300">OPTED OUT</span>}</div></button>)}{!conversations.length && <p className="rounded-xl border border-dashed border-border p-5 text-center text-sm text-muted-foreground">Messenger conversations will appear after Meta sends the first signed webhook.</p>}</div></article>
        <article className="min-w-0 rounded-2xl border border-border bg-card p-4 sm:p-5">{selectedConversation ? <><div className="flex flex-col gap-2 border-b border-border pb-4 sm:flex-row sm:items-start sm:justify-between"><div><div className="flex items-center gap-2"><MessageCircle className="h-5 w-5 text-primary" /><h2 className="font-semibold text-foreground">{selectedConversation.display_name}</h2></div><p className="mt-1 text-xs text-muted-foreground">Last incoming message: {displayDate(selectedConversation.last_inbound_at)} · {selectedConversation.within_response_window ? 'Reply window is open' : 'Reply window is closed'}</p></div><span className={`w-fit rounded-full px-2 py-1 text-[11px] font-bold ${selectedConversation.within_response_window ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-muted text-muted-foreground'}`}>{selectedConversation.within_response_window ? 'ACTIVE CONVERSATION' : 'NO ACTIVE WINDOW'}</span></div>{isAdmin && <div className="mt-3 flex flex-wrap gap-2"><button type="button" disabled={busy !== ''} onClick={() => void updateConversation({ human_handoff_required: !selectedConversation.human_handoff_required })} className="rounded-lg border border-border px-3 py-2 text-xs font-semibold text-foreground hover:bg-muted disabled:opacity-60">{selectedConversation.human_handoff_required ? 'Resume AI eligibility' : 'Require human handoff'}</button><button type="button" disabled={busy !== '' || !!selectedConversation.marketing_opt_out_at} onClick={() => { if (window.confirm('Confirm that this contact explicitly agreed to receive Messenger marketing.')) void updateConversation({ marketing_opt_in: true, confirmed_customer_consent: true }); }} className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-800 hover:bg-sky-100 disabled:opacity-60 dark:border-sky-900/60 dark:bg-sky-950/20 dark:text-sky-200">Record marketing consent</button>{selectedConversation.marketing_opt_in_at && <button type="button" disabled={busy !== ''} onClick={() => void updateConversation({ marketing_opt_in: false })} className="rounded-lg border border-border px-3 py-2 text-xs font-semibold text-muted-foreground hover:bg-muted disabled:opacity-60">Remove consent</button>}</div>}<div className="mt-4 max-h-[360px] space-y-3 overflow-y-auto rounded-xl bg-muted/30 p-3">{selectedMessages.map((message) => <div key={message.id} className={`max-w-[92%] rounded-xl px-3 py-2 text-sm ${message.direction === 'inbound' ? 'mr-auto bg-background text-foreground shadow-sm' : 'ml-auto bg-primary text-primary-foreground'}`}><p className="whitespace-pre-wrap break-words">{message.message_text || '[Non-text Messenger event]'}</p><p className={`mt-1 text-[10px] ${message.direction === 'inbound' ? 'text-muted-foreground' : 'text-primary-foreground/75'}`}>{message.source.replace('_', ' ')} · {displayDate(message.sent_at || message.created_at)}{message.delivery_status === 'failed' ? ' · failed' : ''}</p>{message.delivery_error && <p className="mt-1 text-[10px] text-red-300">{message.delivery_error}</p>}</div>)}{!selectedMessages.length && <p className="p-4 text-center text-sm text-muted-foreground">No messages recorded.</p>}</div><div className="mt-4"><div className="flex flex-wrap items-center justify-between gap-2"><label className="text-sm font-semibold text-foreground">Reply</label><button type="button" onClick={() => void generateDraft()} disabled={busy !== '' || !selectedConversation.within_response_window} className="inline-flex items-center gap-1.5 text-xs font-semibold text-violet-700 hover:underline disabled:opacity-50 dark:text-violet-300"><Sparkles className="h-3.5 w-3.5" />{busy === 'draft' ? 'Drafting…' : 'Generate safe AI draft'}</button></div><textarea value={reply} onChange={(event) => setReply(event.target.value)} maxLength={900} rows={4} placeholder={selectedConversation.within_response_window ? 'Write a customer-ready reply, or generate an AI draft.' : 'Wait for a new customer Messenger message before replying.'} disabled={!selectedConversation.within_response_window || busy === 'reply'} className="mt-2 w-full rounded-xl border border-input bg-background px-3 py-2 text-sm text-foreground disabled:cursor-not-allowed disabled:opacity-60" /><div className="mt-2 flex flex-wrap items-center justify-between gap-2"><p className="text-xs text-muted-foreground">{reply.length}/900 · Account and payment details stay in the secure portal.</p><button type="button" disabled={busy !== '' || !reply.trim() || !selectedConversation.within_response_window} onClick={() => void sendReply()} className="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60"><Send className="h-4 w-4" />{busy === 'reply' ? 'Sending…' : 'Send reply'}</button></div></div></> : <div className="grid min-h-[440px] place-items-center text-center"><div><MessageCircle className="mx-auto h-10 w-10 text-muted-foreground" /><h2 className="mt-3 font-semibold text-foreground">Select a Messenger conversation</h2><p className="mt-1 text-sm text-muted-foreground">Review the message history, prepare an AI draft, or send a staff response.</p></div></div>}</article></section>

      {isAdmin && <section className="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]"><article className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex gap-2"><Megaphone className="mt-0.5 h-5 w-5 text-primary" /><div><h2 className="font-semibold text-foreground">Consent-based marketing draft</h2><p className="mt-1 text-sm text-muted-foreground">This remains a draft until you explicitly approve the send. Only opted-in contacts with an active Messenger conversation are eligible.</p></div></div><div className="mt-4 space-y-3"><label className="block text-sm font-medium text-foreground">Facebook Page<select value={campaignForm.connection_id} onChange={(event) => setCampaignForm({ ...campaignForm, connection_id: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"> <option value="">Select Page</option>{connected.map((connection) => <option key={connection.id} value={connection.id}>{connection.page_name}</option>)}</select></label><label className="block text-sm font-medium text-foreground">Campaign name<input value={campaignForm.name} onChange={(event) => setCampaignForm({ ...campaignForm, name: event.target.value })} maxLength={120} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" placeholder="September plan inquiry follow-up" /></label><label className="block text-sm font-medium text-foreground">Message<textarea value={campaignForm.message_text} onChange={(event) => setCampaignForm({ ...campaignForm, message_text: event.target.value })} maxLength={900} rows={5} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" placeholder="Customer-ready message for contacts who explicitly opted in" /></label><button type="button" onClick={() => void createCampaign()} disabled={busy !== '' || !campaignForm.connection_id || campaignForm.name.trim().length < 3 || campaignForm.message_text.trim().length < 3} className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60">{busy === 'campaign' ? 'Saving…' : 'Save campaign draft'}</button></div></article><article className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex items-center justify-between gap-3"><div><h2 className="font-semibold text-foreground">Campaign history</h2><p className="mt-1 text-sm text-muted-foreground">Every delivered Messenger message is logged in its conversation.</p></div><span className="rounded-full bg-muted px-2.5 py-1 text-xs font-bold text-muted-foreground">{campaigns.length}</span></div><div className="mt-4 space-y-3">{campaigns.map((campaign) => <div key={campaign.id} className="rounded-xl border border-border bg-background p-3"><div className="flex flex-col justify-between gap-2 sm:flex-row"><div><p className="font-semibold text-foreground">{campaign.name}</p><p className="mt-1 text-xs text-muted-foreground">Eligible {campaign.recipient_count} · sent {campaign.sent_count} · failed {campaign.failed_count}</p></div><span className={`h-fit w-fit rounded-full px-2 py-1 text-[11px] font-bold ${campaign.status === 'sent' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : campaign.status === 'draft' ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'}`}>{campaign.status.toUpperCase()}</span></div><p className="mt-2 whitespace-pre-wrap text-sm text-muted-foreground">{campaign.message_text}</p>{campaign.last_error && <p className="mt-2 text-xs text-red-700 dark:text-red-300">{campaign.last_error}</p>}{campaign.status === 'draft' && <button type="button" disabled={busy !== '' || !status?.marketing_enabled} onClick={() => void sendCampaign(campaign)} className="mt-3 inline-flex items-center gap-2 rounded-lg bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-800 disabled:opacity-60"><Send className="h-3.5 w-3.5" />{busy === `campaign:${campaign.id}` ? 'Queueing…' : 'Approve & send now'}</button>}{campaign.status === 'draft' && !status?.marketing_enabled && <p className="mt-2 text-xs text-amber-700 dark:text-amber-300">Enable consent-based campaign delivery first.</p>}</div>)}{!campaigns.length && <p className="rounded-xl border border-dashed border-border p-5 text-center text-sm text-muted-foreground">No campaign drafts yet.</p>}</div></article></section>}

      <section className="rounded-2xl border border-border bg-card p-4 text-sm text-muted-foreground"><div className="flex gap-2"><CircleAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" /><div><p className="font-semibold text-foreground">Meta setup still required</p><p className="mt-1">Configure the Meta app’s valid OAuth redirect URL and webhook callback, subscribe the Page to Messenger events, and complete Meta review if required for people outside your app roles. This page does not use a personal Facebook profile or send anything until the Page connection and controls are enabled.</p>{status && <dl className="mt-3 grid gap-2 text-xs sm:grid-cols-2"><div><dt className="font-semibold text-foreground">Webhook callback</dt><dd className="mt-1 break-all font-mono">{status.webhook_url}</dd></div><div><dt className="font-semibold text-foreground">OAuth redirect</dt><dd className="mt-1 break-all font-mono">{status.redirect_url}</dd></div></dl>}</div></div></section>
    </main>
  </DashboardLayout>;
}

function StatusTile({ label, good, detail }: { label: string; good: boolean; detail: string }): React.JSX.Element {
  return <div className="rounded-xl border border-border bg-background p-3"><div className="flex items-center justify-between gap-2"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>{good ? <CheckCircle2 className="h-4 w-4 text-emerald-600" /> : <CircleAlert className="h-4 w-4 text-amber-600" />}</div><p className={`mt-2 text-sm font-bold ${good ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'}`}>{good ? 'Ready' : 'Needs setup'}</p><p className="mt-1 text-xs text-muted-foreground">{detail}</p></div>;
}

function ToggleCard({ label, detail, checked, onChange }: { label: string; detail: string; checked: boolean; onChange: (value: boolean) => void }): React.JSX.Element {
  return <label className="flex cursor-pointer gap-3 rounded-xl border border-border bg-background p-3"><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="mt-1 h-4 w-4" /><span><span className="block text-sm font-semibold text-foreground">{label}</span><span className="mt-1 block text-xs leading-5 text-muted-foreground">{detail}</span></span></label>;
}
