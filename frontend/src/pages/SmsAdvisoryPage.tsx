import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, MessageSquareText, RefreshCw, Send, Sparkles } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';

type Filter = 'all' | 'active' | 'suspended' | 'disconnected';
type Preview = { eligible_recipients: number; excluded_invalid_or_missing_phone: number; sms_parts: number; estimated_units: number; provider_configured: boolean; sample: Array<{ name: string; phone: string }> };
type Campaign = { id: string; title: string; message: string; recipient_filter: Filter; router_id: string | null; router_name: string | null; status: string; recipient_count: number; sent_count: number; failed_count: number; skipped_count: number; pending_count: number; created_at: string; creator?: { name: string } };
type RouterOption = { id: string; name: string; location: string | null; is_active: boolean; connection_status: string; registered_customers: number };

const emptyForm = { title: '', message: '', recipient_filter: 'active' as Filter, router_id: '' };

export default function SmsAdvisoryPage(): React.JSX.Element {
  const [form, setForm] = useState(emptyForm);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [routers, setRouters] = useState<RouterOption[]>([]);
  const [confirmation, setConfirmation] = useState('');
  const [authorized, setAuthorized] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [aiForm, setAiForm] = useState({ topic: '', verified_facts: '', language: 'bilingual', tone: 'empathetic' });
  const [aiBusy, setAiBusy] = useState(false);

  const load = useCallback(async (): Promise<void> => {
    try { const [history, options] = await Promise.all([api.get('/sms-advisories'), api.get('/sms-advisories/options')]); setCampaigns(history.data.data || []); setRouters(options.data.data?.routers || []); }
    catch { setError('Could not load SMS advisory history.'); }
  }, []);
  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    if (!campaigns.some((campaign) => campaign.pending_count > 0)) return undefined;
    const refresh = window.setInterval(() => { void load(); }, 5000);
    return () => window.clearInterval(refresh);
  }, [campaigns, load]);

  const characterCount = form.message.length;
  const localParts = useMemo(() => characterCount <= 160 ? 1 : Math.ceil(characterCount / 153), [characterCount]);
  const update = (field: keyof typeof form, value: string): void => { setForm((current) => ({ ...current, [field]: value })); setPreview(null); setMessage(''); };

  const runPreview = async (): Promise<void> => {
    setBusy(true); setError(''); setMessage('');
    try { const response = await api.post('/sms-advisories/preview', form); setPreview(response.data.data); }
    catch (requestError: unknown) { const e = requestError as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; setError(e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : e.response?.data?.message || 'Could not preview this advisory.'); }
    finally { setBusy(false); }
  };

  const composeWithAi = async (): Promise<void> => {
    setAiBusy(true); setError(''); setMessage('');
    try {
      const response = await api.post('/sms-advisories/compose', aiForm);
      setForm((current) => ({ ...current, title: current.title || aiForm.topic, message: response.data.data.message }));
      setPreview(null);
      setMessage('AI draft prepared. Review every fact before previewing recipients.');
    } catch (requestError: unknown) {
      const e = requestError as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
      setError(e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : e.response?.data?.message || 'AI could not prepare an advisory draft.');
    } finally { setAiBusy(false); }
  };

  const send = async (): Promise<void> => {
    if (!preview || !window.confirm(`Queue this advisory for ${preview.eligible_recipients} verified recipient(s)?`)) return;
    setBusy(true); setError(''); setMessage('');
    try {
      const response = await api.post('/sms-advisories/send', { ...form, confirmation, authorized });
      setMessage(response.data.message); setForm(emptyForm); setPreview(null); setConfirmation(''); setAuthorized(false); await load();
    } catch (requestError: unknown) { const e = requestError as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; setError(e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : e.response?.data?.message || 'No advisory was queued.'); }
    finally { setBusy(false); }
  };

  return <DashboardLayout headerTitle="Mass SMS Advisory" headerSubtitle="Preview, approve, and audit operational customer advisories">
    <main className="mx-auto max-w-6xl space-y-5">
      {error && <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200">{error}</p>}
      {message && <p className="rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">{message}</p>}

      <section className="rounded-2xl border border-violet-400/40 bg-gradient-to-br from-violet-500/10 to-blue-500/5 p-4 sm:p-6">
        <div className="flex items-start gap-3"><Sparkles className="mt-0.5 h-6 w-6 text-violet-600 dark:text-violet-300" /><div><h1 className="text-lg font-bold text-foreground">AI advisory composer</h1><p className="text-sm text-muted-foreground">Provide verified operational facts. AI prepares text only—it cannot select recipients, queue, or send an SMS.</p></div></div>
        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <label className="text-sm font-medium text-foreground">Topic<input value={aiForm.topic} onChange={(e) => setAiForm((current) => ({ ...current, topic: e.target.value }))} maxLength={160} placeholder="Example: Maintenance in Barangay Bato" className="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2.5" /></label>
          <div className="grid grid-cols-2 gap-3"><label className="text-sm font-medium text-foreground">Language<select value={aiForm.language} onChange={(e) => setAiForm((current) => ({ ...current, language: e.target.value }))} className="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2.5"><option value="bilingual">English + Filipino</option><option value="english">English</option><option value="filipino">Filipino</option></select></label><label className="text-sm font-medium text-foreground">Tone<select value={aiForm.tone} onChange={(e) => setAiForm((current) => ({ ...current, tone: e.target.value }))} className="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2.5"><option value="empathetic">Empathetic</option><option value="clear">Clear</option><option value="urgent">Urgent</option></select></label></div>
          <label className="text-sm font-medium text-foreground md:col-span-2">Verified facts<textarea value={aiForm.verified_facts} onChange={(e) => setAiForm((current) => ({ ...current, verified_facts: e.target.value }))} maxLength={1200} rows={4} placeholder="Enter only confirmed date, time, affected area, reason, expected restoration, and approved contact information." className="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2.5" /><span className="mt-1 block text-xs text-muted-foreground">AI is instructed not to invent missing details. You remain responsible for factual review.</span></label>
        </div>
        <button type="button" disabled={aiBusy || aiForm.topic.trim().length < 3 || aiForm.verified_facts.trim().length < 5} onClick={() => void composeWithAi()} className="mt-4 inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><Sparkles className={`h-4 w-4 ${aiBusy ? 'animate-pulse' : ''}`} />{aiBusy ? 'Preparing draft…' : 'Generate advisory draft'}</button>
      </section>

      <section className="rounded-2xl border border-border bg-card p-4 sm:p-6">
        <div className="flex items-start gap-3"><MessageSquareText className="mt-0.5 h-6 w-6 text-primary" /><div><h1 className="text-lg font-bold text-foreground">Prepare customer advisory</h1><p className="text-sm text-muted-foreground">Operational notices only. Recipient phone numbers remain hidden and no message is sent during preview.</p></div></div>
        <div className="mt-5 grid gap-4 md:grid-cols-3">
          <label className="text-sm font-medium text-foreground">Advisory title<input value={form.title} onChange={(e) => update('title', e.target.value)} maxLength={120} placeholder="Example: Scheduled maintenance — Oras" className="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2.5" /></label>
          <label className="text-sm font-medium text-foreground">Recipients<select value={form.recipient_filter} onChange={(e) => update('recipient_filter', e.target.value)} className="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2.5"><option value="active">Active customers</option><option value="suspended">Suspended customers</option><option value="disconnected">Disconnected customers</option><option value="all">All registered customers</option></select></label>
          <label className="text-sm font-medium text-foreground">Router assignment<select value={form.router_id} onChange={(e) => update('router_id', e.target.value)} className="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2.5"><option value="">All routers / devices</option>{routers.map((router) => <option key={router.id} value={router.id}>{router.name} · {router.registered_customers} customer(s){router.is_active ? '' : ' · inactive'}</option>)}</select><span className="mt-1 block text-xs text-muted-foreground">Future saved routers appear here automatically.</span></label>
          <label className="text-sm font-medium text-foreground md:col-span-3">SMS message<textarea value={form.message} onChange={(e) => update('message', e.target.value)} maxLength={459} rows={6} placeholder="SOLARNET ADVISORY: ..." className="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2.5" /><span className="mt-1 block text-xs text-muted-foreground">{characterCount}/459 characters · approximately {localParts} SMS part(s). Unicode may reduce characters per part.</span></label>
        </div>
        <button type="button" disabled={busy || !form.title.trim() || form.message.trim().length < 5} onClick={() => void runPreview()} className="mt-4 inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${busy ? 'animate-spin' : ''}`} />Preview recipients</button>
      </section>

      {preview && <section className="rounded-2xl border border-blue-400/40 bg-blue-500/5 p-4 sm:p-6"><h2 className="font-bold text-foreground">Preview only — nothing has been sent</h2><p className="mt-1 text-xs text-muted-foreground">Target: {form.recipient_filter} customers · {routers.find((router) => router.id === form.router_id)?.name || 'all routers and devices'}</p><div className="mt-4 grid gap-3 sm:grid-cols-4"><Metric label="Eligible recipients" value={preview.eligible_recipients} /><Metric label="Excluded phones" value={preview.excluded_invalid_or_missing_phone} /><Metric label="SMS parts each" value={preview.sms_parts} /><Metric label="Estimated units" value={preview.estimated_units} /></div>{!preview.provider_configured && <p className="mt-4 flex gap-2 rounded-lg bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200"><AlertTriangle className="h-5 w-5 shrink-0" />PhilSMS is not configured; sending remains blocked.</p>}<div className="mt-4 text-xs text-muted-foreground">Sample: {preview.sample.map((item) => `${item.name} (${item.phone})`).join(' · ') || 'No eligible recipients'}</div><div className="mt-5 space-y-3 border-t border-border pt-4"><label className="flex items-start gap-2 text-sm text-foreground"><input type="checkbox" checked={authorized} onChange={(e) => setAuthorized(e.target.checked)} className="mt-1" />I confirm this is an authorized SolarNet operational advisory, not unsolicited marketing.</label><label className="block text-sm font-medium text-foreground">Type exactly: <strong>SEND SOLARNET ADVISORY</strong><input value={confirmation} onChange={(e) => setConfirmation(e.target.value)} className="mt-1.5 w-full max-w-md rounded-lg border border-input bg-background px-3 py-2.5" /></label><button type="button" disabled={busy || !authorized || confirmation !== 'SEND SOLARNET ADVISORY' || preview.eligible_recipients < 1 || !preview.provider_configured} onClick={() => void send()} className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50"><Send className="h-4 w-4" />Queue advisory</button></div></section>}

      <section className="rounded-2xl border border-border bg-card p-4 sm:p-6"><div className="flex items-center justify-between"><div><h2 className="font-bold text-foreground">Delivery audit</h2><p className="text-sm text-muted-foreground">Provider acceptance is counted as sent; failures and skipped recipients remain visible.</p></div><button type="button" onClick={() => void load()} className="rounded-lg border border-border p-2" aria-label="Refresh history"><RefreshCw className="h-4 w-4" /></button></div><div className="mt-4 space-y-3">{campaigns.map((campaign) => <article key={campaign.id} className="rounded-xl border border-border bg-background/60 p-3"><div className="flex flex-wrap items-start justify-between gap-2"><div><p className="font-semibold text-foreground">{campaign.title}</p><p className="text-xs text-muted-foreground">{new Date(campaign.created_at).toLocaleString('en-PH')} · {campaign.creator?.name || 'Administrator'} · {campaign.recipient_filter} · {campaign.router_name || 'all routers'}</p></div><span className="rounded-full bg-muted px-2.5 py-1 text-xs font-bold uppercase text-foreground">{campaign.status}</span></div><p className="mt-2 line-clamp-2 whitespace-pre-line text-sm text-muted-foreground">{campaign.message}</p><div className="mt-3 flex flex-wrap gap-3 text-xs"><span>Recipients {campaign.recipient_count}</span><span className="text-blue-600">Pending {campaign.pending_count}</span><span className="text-emerald-600">Sent {campaign.sent_count}</span><span className="text-rose-600">Failed {campaign.failed_count}</span><span className="text-amber-600">Skipped {campaign.skipped_count}</span></div></article>)}{!campaigns.length && <p className="py-5 text-sm text-muted-foreground">No SMS advisory campaign has been created.</p>}</div></section>
    </main>
  </DashboardLayout>;
}

function Metric({ label, value }: { label: string; value: number }): React.JSX.Element { return <div className="rounded-xl border border-border bg-background p-3"><CheckCircle2 className="h-4 w-4 text-blue-600" /><p className="mt-2 text-2xl font-bold text-foreground">{value}</p><p className="text-xs text-muted-foreground">{label}</p></div>; }
