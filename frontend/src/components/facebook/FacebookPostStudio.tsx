import React, { useEffect, useState } from 'react';
import { CheckCircle2, CircleAlert, Megaphone, RefreshCw, Send, Sparkles } from 'lucide-react';
import api from '@/services/api';

type PageConnection = { id: string; page_name: string; is_active: boolean };

type PostDraft = {
  id: string;
  connection_id: string;
  topic: string;
  message_text: string;
  status: 'draft' | 'publishing' | 'published' | 'failed' | string;
  facebook_post_id: string | null;
  last_error: string | null;
  created_at: string | null;
  published_at: string | null;
};

const formatDate = (value: string | null): string => value ? new Date(value).toLocaleString('en-PH') : 'Not yet';

export function FacebookPostStudio({ connections }: { connections: PageConnection[] }): React.JSX.Element {
  const [posts, setPosts] = useState<PostDraft[]>([]);
  const [connectionId, setConnectionId] = useState('');
  const [topic, setTopic] = useState('');
  const [details, setDetails] = useState('');
  const [messageText, setMessageText] = useState('');
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = async (): Promise<void> => {
    try {
      const response = await api.get('/facebook-automation/posts');
      setPosts(response.data.data || []);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not load the Facebook post history.');
    }
  };

  useEffect(() => { void load(); }, []);
  useEffect(() => {
    if (!connectionId) setConnectionId(connections.find((connection) => connection.is_active)?.id || '');
  }, [connectionId, connections]);

  const generate = async (): Promise<void> => {
    setBusy('generate'); setError(''); setNotice('');
    try {
      const response = await api.post('/facebook-automation/posts/generate', { topic: topic.trim(), details: details.trim() || undefined });
      setMessageText(response.data.message_text || '');
      setNotice('AI draft generated. Review and edit it before saving.');
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'AI could not prepare a post draft.');
    } finally { setBusy(''); }
  };

  const saveDraft = async (): Promise<void> => {
    setBusy('save'); setError(''); setNotice('');
    try {
      await api.post('/facebook-automation/posts', { connection_id: connectionId, topic: topic.trim(), message_text: messageText.trim() });
      setTopic(''); setDetails(''); setMessageText('');
      setNotice('Post saved as a draft. It has not been published.');
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not save this Facebook post draft.');
    } finally { setBusy(''); }
  };

  const publish = async (post: PostDraft): Promise<void> => {
    if (!window.confirm(`Publish this approved post to the SolarNet Facebook Page now?\n\n${post.message_text}`)) return;
    setBusy(`publish:${post.id}`); setError(''); setNotice('');
    try {
      await api.post(`/facebook-automation/posts/${post.id}/publish`, { confirm_publish: true });
      setNotice('Post published to Facebook.');
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Facebook could not publish this post.');
      await load();
    } finally { setBusy(''); }
  };

  const activeConnections = connections.filter((connection) => connection.is_active);

  return <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex gap-2"><Megaphone className="mt-0.5 h-5 w-5 shrink-0 text-primary" /><div><h2 className="font-semibold text-foreground">AI Post Studio</h2><p className="mt-1 text-sm text-muted-foreground">Create a public Facebook Page post, review it, then publish it manually. AI never posts by itself.</p></div></div>
      <button type="button" onClick={() => void load()} disabled={busy !== ''} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-border px-3 py-2 text-xs font-semibold text-foreground hover:bg-muted disabled:opacity-60"><RefreshCw className="h-3.5 w-3.5" />Refresh posts</button>
    </div>

    {error && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{error}</p>}
    {notice && <p className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200">{notice}</p>}

    <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
      <div className="space-y-3 rounded-xl border border-border bg-background p-3 sm:p-4">
        <label className="block text-sm font-medium text-foreground">Facebook Page<select value={connectionId} onChange={(event) => setConnectionId(event.target.value)} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"><option value="">Select Page</option>{activeConnections.map((connection) => <option key={connection.id} value={connection.id}>{connection.page_name}</option>)}</select></label>
        <label className="block text-sm font-medium text-foreground">Post topic<input value={topic} onChange={(event) => setTopic(event.target.value)} maxLength={160} placeholder="Example: New installation inquiries in Oras" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" /></label>
        <label className="block text-sm font-medium text-foreground">Verified details for AI (optional)<textarea value={details} onChange={(event) => setDetails(event.target.value)} maxLength={1000} rows={4} placeholder="Only facts you approve: exact offer, location, contact channel, or dates." className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" /></label>
        <button type="button" onClick={() => void generate()} disabled={busy !== '' || topic.trim().length < 3} className="inline-flex items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800 hover:bg-violet-100 disabled:opacity-60 dark:border-violet-900/60 dark:bg-violet-950/20 dark:text-violet-200"><Sparkles className="h-4 w-4" />{busy === 'generate' ? 'Writing...' : 'Generate AI post draft'}</button>
      </div>

      <div className="rounded-xl border border-border bg-background p-3 sm:p-4"><div className="flex items-center justify-between gap-2"><label className="text-sm font-medium text-foreground">Post text</label><span className="text-xs text-muted-foreground">{messageText.length}/5000</span></div><textarea value={messageText} onChange={(event) => setMessageText(event.target.value)} maxLength={5000} rows={11} placeholder="Generate an AI draft or write the approved public post here." className="mt-2 w-full rounded-lg border border-input bg-card px-3 py-2 text-sm text-foreground" /><div className="mt-3 flex flex-wrap items-center justify-between gap-2"><p className="max-w-md text-xs leading-5 text-muted-foreground">Review factual accuracy, price, locations, and tone. Do not include client, payment, or account information.</p><button type="button" onClick={() => void saveDraft()} disabled={busy !== '' || !connectionId || topic.trim().length < 3 || messageText.trim().length < 3} className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60">{busy === 'save' ? 'Saving...' : 'Save post draft'}</button></div></div>
    </div>

    <div className="mt-5 border-t border-border pt-4"><div className="flex items-center justify-between gap-2"><div><h3 className="font-semibold text-foreground">Post history</h3><p className="mt-1 text-xs text-muted-foreground">Drafts can only be sent by an explicit Administrator action.</p></div><span className="rounded-full bg-muted px-2.5 py-1 text-xs font-bold text-muted-foreground">{posts.length}</span></div><div className="mt-3 space-y-3">{posts.map((post) => <article key={post.id} className="rounded-xl border border-border bg-background p-3"><div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><p className="font-semibold text-foreground">{post.topic}</p><p className="mt-1 text-xs text-muted-foreground">Created {formatDate(post.created_at)}{post.published_at ? ` - published ${formatDate(post.published_at)}` : ''}</p></div><span className={`w-fit rounded-full px-2 py-1 text-[11px] font-bold ${post.status === 'published' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : post.status === 'draft' ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' : post.status === 'failed' ? 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'}`}>{post.status.toUpperCase()}</span></div><p className="mt-3 whitespace-pre-wrap break-words text-sm text-muted-foreground">{post.message_text}</p>{post.last_error && <p className="mt-2 text-xs text-red-700 dark:text-red-300">{post.last_error}</p>}{post.status === 'draft' && <button type="button" onClick={() => void publish(post)} disabled={busy !== ''} className="mt-3 inline-flex items-center gap-2 rounded-lg bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-800 disabled:opacity-60"><Send className="h-3.5 w-3.5" />{busy === `publish:${post.id}` ? 'Publishing...' : 'Approve & publish now'}</button>}{post.status === 'published' && <p className="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300"><CheckCircle2 className="h-3.5 w-3.5" />Published to the linked Facebook Page</p>}</article>)}{!posts.length && <div className="rounded-xl border border-dashed border-border p-5 text-center"><CircleAlert className="mx-auto h-5 w-5 text-muted-foreground" /><p className="mt-2 text-sm text-muted-foreground">No Facebook Page post drafts yet.</p></div>}</div></div>
  </section>;
}
