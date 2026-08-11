import { useRef, useState } from 'react';
import { Globe2, LoaderCircle, LockKeyhole, MonitorUp, RadioTower, RefreshCw, ShieldCheck, X } from 'lucide-react';

type Provider = 'hsgq' | 'custom';

const HSGQ_LOGIN_URL = 'https://hsgqcloud.com/login';

export function OltCloudAccess() {
  const [provider, setProvider] = useState<Provider>('hsgq');
  const [customUrl, setCustomUrl] = useState('');
  const [accountHint, setAccountHint] = useState('');
  const [error, setError] = useState('');
  const [embeddedUrl, setEmbeddedUrl] = useState('');
  const [isPortalLoading, setIsPortalLoading] = useState(false);
  const portalFrame = useRef<HTMLIFrameElement>(null);

  const portalUrl = provider === 'hsgq' ? HSGQ_LOGIN_URL : customUrl.trim();

  const validatedPortalUrl = (): string | null => {
    setError('');
    try {
      const url = new URL(portalUrl);
      if (url.protocol !== 'https:') {
        setError('Use a secure HTTPS portal address.');
        return null;
      }
      return url.toString();
    } catch {
      setError('Enter a complete secure portal URL, for example https://cloud.vendor.com/login');
      return null;
    }
  };

  const openPrivateWindow = () => {
    const url = validatedPortalUrl();
    if (url) {
      setIsPortalLoading(true);
      setEmbeddedUrl(url);
    }
  };

  const reloadPortal = () => {
    if (!portalFrame.current) return;
    setIsPortalLoading(true);
    portalFrame.current.src = portalFrame.current.src;
  };

  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-3xl border border-primary/15 bg-gradient-to-br from-slate-950 via-slate-900 to-primary/90 p-6 text-white shadow-xl shadow-primary/10 md:p-8">
        <div className="absolute -right-20 -top-20 h-60 w-60 rounded-full bg-cyan-400/25 blur-3xl" />
        <div className="relative flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
          <div>
            <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-cyan-200"><MonitorUp className="h-4 w-4" /> Private portal window</div>
            <h2 className="text-2xl font-semibold tracking-tight">OLT Devices</h2>
            <p className="mt-2 max-w-2xl text-sm text-slate-300">Open your OLT vendor cloud portal without enabling SNMP, CLI, or device polling.</p>
          </div>
          <div className="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm text-slate-200"><ShieldCheck className="h-4 w-4 text-emerald-300" /> No credentials stored</div>
        </div>
      </section>

      <section className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
        <div className="mb-5"><h3 className="text-lg font-semibold text-foreground">Choose cloud access</h3><p className="mt-1 text-sm text-muted-foreground">Select HSGQ Cloud or enter another OLT vendor secure cloud login URL.</p></div>
        <div className="grid gap-3 md:grid-cols-2">
          <button type="button" onClick={() => setProvider('hsgq')} className={`rounded-xl border p-4 text-left transition ${provider === 'hsgq' ? 'border-primary bg-primary/5 ring-2 ring-primary/15' : 'border-border hover:border-primary/40'}`}><div className="flex items-center gap-3"><div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><RadioTower className="h-5 w-5" /></div><div><p className="font-semibold text-foreground">HSGQ Cloud</p><p className="text-sm text-muted-foreground">Open the HSGQ Cloud login portal</p></div></div></button>
          <button type="button" onClick={() => setProvider('custom')} className={`rounded-xl border p-4 text-left transition ${provider === 'custom' ? 'border-primary bg-primary/5 ring-2 ring-primary/15' : 'border-border hover:border-primary/40'}`}><div className="flex items-center gap-3"><div className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><Globe2 className="h-5 w-5" /></div><div><p className="font-semibold text-foreground">Other OLT cloud</p><p className="text-sm text-muted-foreground">Use any vendor HTTPS cloud portal</p></div></div></button>
        </div>
        <div className="mt-6 grid gap-4 md:grid-cols-2">
          <label className="block"><span className="text-sm font-medium text-foreground">Cloud portal URL</span><input value={provider === 'hsgq' ? HSGQ_LOGIN_URL : customUrl} onChange={(event) => setCustomUrl(event.target.value)} disabled={provider === 'hsgq'} placeholder="https://cloud.vendor.com/login" className="mt-2 h-11 w-full rounded-xl border border-input bg-background px-3 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15 disabled:cursor-not-allowed disabled:bg-muted" /></label>
          <label className="block"><span className="text-sm font-medium text-foreground">Account email or username <span className="font-normal text-muted-foreground">(optional reference)</span></span><input value={accountHint} onChange={(event) => setAccountHint(event.target.value)} placeholder="Enter the account you will use" autoComplete="username" className="mt-2 h-11 w-full rounded-xl border border-input bg-background px-3 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15" /></label>
        </div>
        <div className="mt-5 flex flex-col gap-4 rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 text-sm text-muted-foreground md:flex-row md:items-center md:justify-between"><div className="flex gap-3"><LockKeyhole className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" /><p>Your password is never collected or stored by SolarNet Billing. Sign in inside the vendor portal window.</p></div><button type="button" onClick={openPrivateWindow} className="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 font-semibold text-primary-foreground transition hover:opacity-90"><MonitorUp className="h-4 w-4" />Access device</button></div>
        {error && <p className="mt-3 text-sm font-medium text-rose-600 dark:text-rose-400">{error}</p>}
      </section>

      {embeddedUrl && <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-xl"><div className="flex items-center justify-between gap-4 border-b border-border/70 bg-muted/35 px-4 py-3"><div className="min-w-0"><p className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">Private OLT portal window</p><p className="mt-0.5 truncate text-sm text-foreground">{embeddedUrl}</p></div><div className="flex items-center gap-1"><button type="button" onClick={reloadPortal} disabled={isPortalLoading} className="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${isPortalLoading ? 'animate-spin' : ''}`} />Reload</button><button type="button" onClick={() => { setEmbeddedUrl(''); setIsPortalLoading(false); }} className="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"><X className="h-4 w-4" />Close</button></div></div><div className="relative bg-white"><iframe ref={portalFrame} title="OLT cloud portal" src={embeddedUrl} loading="eager" onLoad={() => setIsPortalLoading(false)} className="h-[680px] w-full bg-white" sandbox="allow-forms allow-scripts allow-same-origin" referrerPolicy="strict-origin-when-cross-origin" />{isPortalLoading && <div className="absolute inset-0 flex items-center justify-center bg-background/80 backdrop-blur-sm"><div className="flex items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 text-sm font-medium text-foreground shadow-lg"><LoaderCircle className="h-4 w-4 animate-spin text-primary" />Loading secure portal…</div></div>}</div><div className="border-t border-border/70 px-4 py-3 text-xs text-muted-foreground">The portal is contained inside SolarNet Billing. If the provider blocks embedded login, it is enforcing its own security policy and cannot be bypassed by the billing app.</div></section>}
    </div>
  );
}
