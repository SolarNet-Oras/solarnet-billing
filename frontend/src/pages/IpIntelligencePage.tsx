import { useState } from 'react';
import { Building2, Globe2, Network, Search, ShieldAlert, type LucideIcon } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api, { getErrorMessage } from '@/services/api';

type Lookup = {
  ip: string; ip_type: string | null; asn: string | null; as_name: string | null; domain: string | null;
  country: string | null; country_code: string | null; continent: string | null; continent_code: string | null;
  hosting: boolean | null; proxy: boolean | null; vpn: boolean | null; tor: boolean | null;
  anonymous: boolean | null; classification: 'hosting' | 'access_network' | 'unavailable'; checked_at: string; source: string;
};
const display = (input: unknown): string => input === null || input === undefined || input === '' ? 'Not reported' : String(input);

export default function IpIntelligencePage(): React.JSX.Element {
  const [ip, setIp] = useState('');
  const [result, setResult] = useState<Lookup | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const lookup = async (): Promise<void> => {
    setBusy(true); setError(''); setResult(null);
    try { const response = await api.post('/ip-intelligence/lookup', { ip: ip.trim() }); setResult(response.data.data); }
    catch (e) { setError(getErrorMessage(e)); }
    finally { setBusy(false); }
  };
  const flag = (label: string, active: boolean | null) => <span className={`rounded-full px-3 py-1 text-xs font-bold ${active === null ? 'bg-muted text-muted-foreground' : active ? 'bg-rose-500/15 text-rose-700 dark:text-rose-300' : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'}`}>{label}: {active === null ? 'Unavailable' : active ? 'Detected' : 'No'}</span>;

  return <DashboardLayout headerTitle="IP / ASN Lookup" headerSubtitle="Read-only public IP ownership intelligence"><main className="mx-auto max-w-5xl space-y-5">
    <section className="rounded-2xl border border-border bg-card p-5"><div className="flex items-start gap-3"><Globe2 className="mt-1 h-7 w-7 text-primary"/><div><h1 className="text-xl font-bold text-foreground">IP address & ASN ownership</h1><p className="text-sm text-muted-foreground">Inspect a public IPv4 or IPv6 address. This tool does not modify routers, customers, firewall rules, or DNS.</p></div></div><form onSubmit={e => { e.preventDefault(); void lookup(); }} className="mt-5 flex flex-col gap-3 sm:flex-row"><input value={ip} onChange={e => setIp(e.target.value)} placeholder="Example: 8.8.8.8 or 2001:4860:4860::8888" spellCheck={false} className="min-w-0 flex-1 rounded-xl border border-input bg-background px-4 py-3 font-mono text-foreground"/><button disabled={busy || !ip.trim()} className="inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 font-bold text-primary-foreground disabled:opacity-50"><Search className={`h-4 w-4 ${busy ? 'animate-pulse' : ''}`}/>{busy ? 'Checking…' : 'Lookup IP'}</button></form>{error && <p role="alert" className="mt-3 rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/30 dark:text-rose-200">{error}</p>}</section>
    {result && <><section className="grid gap-4 md:grid-cols-3"><Card icon={Network} label="Network" main={result.asn || 'ASN unavailable'} detail={result.as_name || 'Owner unavailable'}/><Card icon={Building2} label="AS organization" main={result.as_name || 'Not reported'} detail={result.domain || 'Domain unavailable'}/><Card icon={Globe2} label="Country" main={result.country || 'Location unavailable'} detail={[result.country_code, result.continent].filter(Boolean).join(' · ')}/></section><section className="rounded-2xl border border-border bg-card p-5"><h2 className="font-bold text-foreground">Network ownership</h2><dl className="mt-4 grid gap-3 sm:grid-cols-2"><Row label="IP" text={result.ip}/><Row label="ASN" text={display(result.asn)}/><Row label="AS Name" text={display(result.as_name)}/><Row label="AS Domain" text={display(result.domain)}/><Row label="Country" text={result.country_code ? `${result.country_code}${result.country ? ` · ${result.country}` : ''}` : display(result.country)}/><Row label="Continent" text={result.continent_code ? `${result.continent_code}${result.continent ? ` · ${result.continent}` : ''}` : display(result.continent)}/><Row label="IP version" text={display(result.ip_type)}/><Row label="Data source" text={result.source}/></dl></section><section className="rounded-2xl border border-amber-400/40 bg-amber-500/5 p-5"><h2 className="flex items-center gap-2 font-bold text-foreground"><ShieldAlert className="h-5 w-5 text-amber-600"/>Hosting and privacy indicators</h2><div className="mt-3 flex flex-wrap gap-2">{flag('Hosting', result.hosting)}{flag('Proxy', result.proxy)}{flag('VPN', result.vpn)}{flag('Tor', result.tor)}{flag('Anonymous', result.anonymous)}</div><p className="mt-3 text-xs text-muted-foreground">IPinfo Lite does not include hosting, proxy, VPN, Tor, or anonymity detection, so these fields are unavailable—not false negatives. Source: {result.source}; checked {new Date(result.checked_at).toLocaleString('en-PH')}.</p></section></>}
  </main></DashboardLayout>;
}
function Card({ icon: Icon, label, main, detail }: { icon: LucideIcon; label: string; main: string; detail: string }): React.JSX.Element { return <article className="rounded-2xl border border-border bg-card p-4"><Icon className="h-5 w-5 text-primary"/><p className="mt-3 text-xs uppercase tracking-wide text-muted-foreground">{label}</p><p className="mt-1 break-words text-lg font-bold text-foreground">{main}</p><p className="break-words text-xs text-muted-foreground">{detail}</p></article>; }
function Row({ label, text }: { label: string; text: string }): React.JSX.Element { return <div className="rounded-xl bg-muted/40 p-3"><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-1 break-words font-medium text-foreground">{text}</dd></div>; }
