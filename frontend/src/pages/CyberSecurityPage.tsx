import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  CircleDot,
  Eye,
  Gauge,
  Network,
  RefreshCw,
  ScanSearch,
  Server,
  ShieldAlert,
  ShieldCheck,
  Wifi,
  WifiOff,
} from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import {
  routerService,
  type Router,
  type RouterMonitoringSnapshot,
  type RouterThreatObservation,
} from '@/services/routerService';

const formatRate = (bps: number | null | undefined): string => {
  if (bps === null || bps === undefined) return 'Waiting';
  if (bps < 1_000) return `${Math.round(bps)} bps`;
  if (bps < 1_000_000) return `${(bps / 1_000).toFixed(1)} Kbps`;
  return `${(bps / 1_000_000).toFixed(1)} Mbps`;
};

const formatDateTime = (value?: string | null): string => {
  if (!value) return 'Not checked yet';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? 'Not checked yet'
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
};

function SecurityMetric({
  label,
  value,
  helper,
  tone,
  icon: Icon,
}: {
  label: string;
  value: string | number;
  helper: string;
  tone: 'cyan' | 'emerald' | 'amber' | 'violet';
  icon: typeof ShieldCheck;
}) {
  const toneClasses = {
    cyan: 'border-cyan-300/20 bg-cyan-400/10 text-cyan-200',
    emerald: 'border-emerald-300/20 bg-emerald-400/10 text-emerald-200',
    amber: 'border-amber-300/20 bg-amber-400/10 text-amber-100',
    violet: 'border-violet-300/20 bg-violet-400/10 text-violet-200',
  };

  return (
    <article className="relative overflow-hidden rounded-2xl border border-slate-700/80 bg-slate-950/75 p-4 shadow-[0_20px_48px_-36px_rgba(34,211,238,0.9)] backdrop-blur sm:p-5">
      <div className={`absolute -right-10 -top-10 h-24 w-24 rounded-full blur-3xl ${tone === 'emerald' ? 'bg-emerald-400/25' : tone === 'amber' ? 'bg-amber-400/25' : tone === 'violet' ? 'bg-violet-500/25' : 'bg-cyan-400/25'}`} />
      <div className="relative flex items-start justify-between gap-3">
        <span className={`grid h-10 w-10 place-items-center rounded-xl border ${toneClasses[tone]}`}><Icon className="h-5 w-5" /></span>
        <span className={`rounded-full border px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] ${toneClasses[tone]}`}>{helper}</span>
      </div>
      <p className="relative mt-4 text-xs font-medium uppercase tracking-[0.15em] text-slate-400">{label}</p>
      <p className="relative mt-1 text-2xl font-bold tracking-tight text-white sm:text-3xl">{value}</p>
    </article>
  );
}

type ThreatRiskBand = 'protected' | 'attention' | 'high' | 'no_signal' | 'unavailable';

const riskPresentation: Record<ThreatRiskBand, { label: string; className: string }> = {
  protected: { label: 'Configured / no candidate', className: 'border-emerald-300/25 bg-emerald-400/10 text-emerald-100' },
  attention: { label: 'Candidate needs review', className: 'border-amber-300/25 bg-amber-400/10 text-amber-100' },
  high: { label: 'Several candidates', className: 'border-red-300/30 bg-red-400/10 text-red-100' },
  no_signal: { label: 'No labeled threat signal', className: 'border-slate-500/40 bg-slate-700/40 text-slate-200' },
  unavailable: { label: 'Waiting for monitor', className: 'border-slate-600 bg-slate-800/70 text-slate-300' },
};

function assessThreatRisk(sample: RouterMonitoringSnapshot | undefined, pendingMatches: number): { band: ThreatRiskBand; treatment: string } {
  if (!sample) return { band: 'unavailable', treatment: 'Refresh the router monitor first. No threat judgment is available until RouterOS data is read.' };
  if (pendingMatches >= 3) return { band: 'high', treatment: 'Several possible C2/botnet connection candidates need review. Verify the IP and direction, rule out trusted services, then manually block or dismiss each candidate in Network Devices.' };
  if (pendingMatches > 0) return { band: 'attention', treatment: 'A threat-feed candidate needs review. Confirm the IP and direction, then manually block or dismiss it in Network Devices. No automatic block is applied.' };
  if (sample.threat_signal_rules + sample.threat_address_list_entries > 0) return { band: 'protected', treatment: sample.threat_blocked_packets > 0 ? 'Protection rules or address-list entries are configured. Their RouterOS packet counters are lifetime counters, so a non-zero total alone is not proof of a current attack. Keep monitoring and run a read-only scan when activity is unexpected.' : 'Labeled protection rules or address-list entries are present with no current threat-feed candidate. Keep monitoring; do not delete or move the rule solely because its counter is zero.' };
  return { band: 'no_signal', treatment: 'No enabled firewall rule or address list with a threat-related name was found. This does not prove the router is unprotected; review the RouterOS firewall configuration before adding any rule.' };
}

export default function CyberSecurityPage() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [monitoring, setMonitoring] = useState<Record<string, RouterMonitoringSnapshot>>({});
  const [observations, setObservations] = useState<Record<string, RouterThreatObservation[]>>({});
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [selectedRouterId, setSelectedRouterId] = useState('');
  const [scanning, setScanning] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'warning' | 'error'; text: string } | null>(null);
  const [billingApiStatus, setBillingApiStatus] = useState<'checking' | 'online' | 'offline'>('checking');

  const loadData = useCallback(async (manual = false) => {
    if (manual) setRefreshing(true);
    try {
      const [routerList, billingApiReachable] = await Promise.all([
        routerService.getAll(),
        fetch('/api/health', { cache: 'no-store' }).then((response) => response.ok).catch(() => false),
      ]);
      setRouters(routerList);
      setBillingApiStatus(billingApiReachable ? 'online' : 'offline');
      setSelectedRouterId((current) => current || routerList.find((router) => router.is_active)?.id || '');

      const activeRouters = routerList.filter((router) => router.is_active && router.connection_status !== 'offline');
      const [monitorResults, observationResults] = await Promise.all([
        Promise.all(activeRouters.map(async (router) => {
          try { return [router.id, await routerService.monitoring(router.id)] as const; }
          catch { return null; }
        })),
        Promise.all(routerList.map(async (router) => {
          try { return [router.id, await routerService.threatObservations(router.id)] as const; }
          catch { return null; }
        })),
      ]);

      setMonitoring(Object.fromEntries(monitorResults.filter((item): item is readonly [string, RouterMonitoringSnapshot] => item !== null)));
      setObservations(Object.fromEntries(observationResults.filter((item): item is readonly [string, RouterThreatObservation[]] => item !== null)));
      if (manual) setNotice({ tone: 'success', text: 'Live RouterOS monitoring and threat observations refreshed.' });
    } catch (error: any) {
      setNotice({ tone: 'error', text: error?.response?.data?.message || 'Could not refresh Cybersecurity Center data.' });
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    void loadData();
    const interval = window.setInterval(() => void loadData(), 5_000);
    return () => window.clearInterval(interval);
  }, [loadData]);

  const selectedRouter = routers.find((router) => router.id === selectedRouterId) || null;
  const onlineRouters = routers.filter((router) => router.connection_status === 'online').length;
  const activeRouters = routers.filter((router) => router.is_active).length;
  const samples = Object.values(monitoring);
  const protectedRouters = samples.filter((sample) => sample.threat_status === 'protected').length;
  const firewallSignals = samples.reduce((total, sample) => total + sample.threat_signal_rules + sample.threat_address_list_entries, 0);
  const blockedPackets = samples.reduce((total, sample) => total + sample.threat_blocked_packets, 0);
  const totalRx = samples.reduce((total, sample) => total + (sample.rx_bps ?? 0), 0);
  const totalTx = samples.reduce((total, sample) => total + (sample.tx_bps ?? 0), 0);
  const pending = useMemo(() => Object.entries(observations).flatMap(([routerId, records]) => records.filter((record) => record.status === 'pending').map((record) => ({ ...record, router: routers.find((router) => router.id === routerId) }))), [observations, routers]);
  const latestRead = useMemo(() => samples.map((sample) => sample.scanned_at).filter(Boolean).sort().at(-1), [samples]);
  const dashboardHost = typeof window === 'undefined' ? 'SolarNet billing' : window.location.hostname;
  const vpnRelayHost = useMemo(() => routers.find((router) => /vpn|wireguard/i.test(router.host))?.host || 'Router management relay', [routers]);
  const flowMotion = totalRx + totalTx > 100_000_000 ? 'security-flow-fast' : totalRx + totalTx > 1_000_000 ? 'security-flow-normal' : 'security-flow-calm';
  const threatSignalBreakdown = useMemo(() => routers.map((router) => {
    const sample = monitoring[router.id];
    const pendingMatches = (observations[router.id] || []).filter((item) => item.status === 'pending').length;
    return {
      router,
      sample,
      pendingMatches,
      risk: assessThreatRisk(sample, pendingMatches),
      rules: sample?.threat_signal_details?.firewall_rules || [],
      lists: sample?.threat_signal_details?.address_list_entries || [],
      hiddenRules: sample?.threat_signal_details?.firewall_rules_hidden || 0,
      hiddenLists: sample?.threat_signal_details?.address_list_entries_hidden || 0,
    };
  }).filter((item) => item.sample && (item.sample.threat_signal_rules + item.sample.threat_address_list_entries > 0 || item.pendingMatches > 0)), [routers, monitoring, observations]);

  const scanSelectedRouter = async () => {
    if (!selectedRouter) {
      setNotice({ tone: 'warning', text: 'Select an online router before starting a read-only threat-feed scan.' });
      return;
    }
    if (selectedRouter.connection_status === 'offline') {
      setNotice({ tone: 'warning', text: `${selectedRouter.name} is offline, so it cannot be scanned.` });
      return;
    }

    try {
      setScanning(true);
      setNotice(null);
      const result = await routerService.scanThreatFeed(selectedRouter.id);
      const records = await routerService.threatObservations(selectedRouter.id);
      setObservations((current) => ({ ...current, [selectedRouter.id]: records }));
      setNotice({ tone: result.data.matches.length ? 'warning' : 'success', text: `${result.message} ${result.data.matches.length ? 'Matches are awaiting review; nothing was blocked automatically.' : 'No RouterOS configuration was changed.'}` });
    } catch (error: any) {
      setNotice({ tone: 'error', text: error?.response?.data?.message || 'Threat scan could not finish. No RouterOS configuration was changed.' });
    } finally {
      setScanning(false);
    }
  };

  return (
    <DashboardLayout>
      <section className="security-center relative isolate overflow-hidden rounded-3xl border border-slate-700 bg-[#020817] p-4 text-slate-100 shadow-2xl sm:p-6 lg:p-7">
        <div className="pointer-events-none absolute inset-0 -z-10 security-grid opacity-80" />
        <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_50%_-10%,rgba(109,40,217,0.38),transparent_34%),radial-gradient(circle_at_4%_24%,rgba(6,182,212,0.16),transparent_30%),radial-gradient(circle_at_100%_78%,rgba(16,185,129,0.12),transparent_24%)]" />
        <div className="security-scanline pointer-events-none absolute inset-x-0 top-0 -z-10 h-px bg-cyan-200/70" />

        <header className="flex flex-col gap-5 border-b border-slate-800 pb-6 xl:flex-row xl:items-center xl:justify-between">
          <div className="max-w-3xl">
            <p className="text-[11px] font-semibold uppercase tracking-[0.28em] text-cyan-300">SolarNet network defense</p>
            <h1 className="mt-2 text-3xl font-bold tracking-tight text-white sm:text-4xl">Cybersecurity Center</h1>
            <p className="mt-2 text-sm leading-6 text-slate-400">A live, read-only view of RouterOS perimeter signals and threat-feed observations. It does not claim endpoint antivirus, and it never changes firewall rules without an administrator’s explicit review.</p>
          </div>
          <div className="flex flex-wrap gap-3">
            <Link to="/network-devices" className="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:border-cyan-300/60 hover:text-white">
              <Server className="h-4 w-4" /> Network devices
            </Link>
            <button type="button" onClick={() => void loadData(true)} disabled={refreshing} className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-cyan-500 to-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/20 transition hover:brightness-110 disabled:opacity-60">
              <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} /> Refresh live data
            </button>
          </div>
        </header>

        {notice && <div className={`mt-5 rounded-xl border px-4 py-3 text-sm ${notice.tone === 'error' ? 'border-red-400/30 bg-red-500/10 text-red-100' : notice.tone === 'warning' ? 'border-amber-400/30 bg-amber-500/10 text-amber-100' : 'border-emerald-400/30 bg-emerald-500/10 text-emerald-100'}`}>{notice.text}</div>}

        <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SecurityMetric label="Protected routers" value={`${protectedRouters}/${activeRouters || routers.length}`} helper="RouterOS signals" tone="emerald" icon={ShieldCheck} />
          <SecurityMetric label="Live perimeter" value={`${onlineRouters}/${routers.length}`} helper="Routers online" tone="cyan" icon={Wifi} />
          <SecurityMetric label="Threat signals" value={firewallSignals} helper="Rules + lists" tone="violet" icon={ShieldAlert} />
          <SecurityMetric label="Review queue" value={pending.length} helper="Manual review" tone="amber" icon={AlertTriangle} />
        </div>

        <section className="mt-5 rounded-2xl border border-violet-300/20 bg-slate-950/75 p-4 shadow-[0_20px_48px_-36px_rgba(167,139,250,0.65)] sm:p-5">
          <div className="flex flex-col gap-3 border-b border-slate-800 pb-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-violet-300">Threat signal identification</p>
              <h2 className="mt-1 text-xl font-bold text-white">What “{firewallSignals}” means and how to treat it</h2>
              <p className="mt-1 max-w-4xl text-sm leading-6 text-slate-400">This is a count of enabled RouterOS <strong className="font-medium text-slate-200">drop rules</strong> whose comment has a security keyword, plus address-list entries whose list name has one. It is configuration evidence, not a count of viruses or confirmed infected clients.</p>
            </div>
            <span className="w-fit rounded-full border border-violet-300/25 bg-violet-400/10 px-3 py-1.5 text-xs font-semibold text-violet-100">Read-only identification</span>
          </div>

          <div className="mt-4 grid gap-3 xl:grid-cols-2">
            {threatSignalBreakdown.map(({ router, sample, pendingMatches, risk, rules, lists, hiddenRules, hiddenLists }) => {
              const presentation = riskPresentation[risk.band];
              const detailIsAvailable = Boolean(sample?.threat_signal_details);
              return <article key={router.id} className="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div className="min-w-0"><p className="truncate font-semibold text-white">{router.name}</p><p className="truncate text-xs text-slate-500">{router.host}</p></div>
                  <span className={`w-fit rounded-full border px-2.5 py-1 text-[11px] font-semibold ${presentation.className}`}>{presentation.label}</span>
                </div>

                <div className="mt-4 grid grid-cols-3 gap-2">
                  <div className="rounded-lg bg-violet-400/10 p-2.5"><p className="text-lg font-bold text-violet-100">{sample?.threat_signal_rules ?? 0}</p><p className="text-[10px] uppercase tracking-[0.1em] text-slate-500">Named drop rules</p></div>
                  <div className="rounded-lg bg-cyan-400/10 p-2.5"><p className="text-lg font-bold text-cyan-100">{sample?.threat_address_list_entries ?? 0}</p><p className="text-[10px] uppercase tracking-[0.1em] text-slate-500">List entries</p></div>
                  <div className="rounded-lg bg-amber-400/10 p-2.5"><p className="text-lg font-bold text-amber-100">{pendingMatches}</p><p className="text-[10px] uppercase tracking-[0.1em] text-slate-500">Feed candidates</p></div>
                </div>

                {!detailIsAvailable && <p className="mt-3 rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-xs text-slate-400">Rule and list names will appear after the latest backend deployment refreshes this monitor.</p>}
                {detailIsAvailable && <div className="mt-3 space-y-2">
                  {rules.map((rule) => <div key={rule.id || rule.comment} className="rounded-lg border border-violet-300/15 bg-violet-400/5 px-3 py-2"><div className="flex flex-wrap items-center justify-between gap-2"><p className="min-w-0 break-words text-xs font-semibold text-violet-100">Firewall rule: {rule.comment}</p><span className="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[10px] text-slate-300">{rule.chain || 'unknown'} · {rule.action || 'drop'}</span></div><p className="mt-1 text-[11px] text-slate-400">{rule.match_reason} Lifetime packets: {rule.packets.toLocaleString()}.</p></div>)}
                  {lists.map((entry) => <div key={entry.id || `${entry.list}:${entry.address}`} className="rounded-lg border border-cyan-300/15 bg-cyan-400/5 px-3 py-2"><div className="flex flex-wrap items-center justify-between gap-2"><p className="min-w-0 break-all text-xs font-semibold text-cyan-100">List: {entry.list} · {entry.address || 'no address value'}</p><span className="rounded bg-slate-800 px-1.5 py-0.5 text-[10px] text-slate-300">{entry.dynamic ? 'dynamic' : 'static'}</span></div><p className="mt-1 text-[11px] text-slate-400">{entry.comment || entry.match_reason}{entry.timeout ? ` · timeout ${entry.timeout}` : ''}</p></div>)}
                  {hiddenRules + hiddenLists > 0 && <p className="text-[11px] text-slate-500">{hiddenRules + hiddenLists} additional matched record{hiddenRules + hiddenLists === 1 ? '' : 's'} are not shown here to keep this live monitor fast.</p>}
                </div>}

                <div className="mt-3 rounded-lg border border-slate-700 bg-slate-950/65 p-3"><p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Safe treatment</p><p className="mt-1 text-xs leading-5 text-slate-300">{risk.treatment}</p></div>
              </article>;
            })}
            {!loading && threatSignalBreakdown.length === 0 && <div className="rounded-xl border border-dashed border-slate-700 bg-slate-900/50 p-4 text-sm leading-6 text-slate-400">No named threat rules or matching address-list entries were found in the available RouterOS samples. This does not prove a router has no firewall protection; it only means this monitor did not identify a rule/list with the security keywords it recognizes.</div>}
          </div>

          <div className="mt-4 flex flex-col gap-3 rounded-xl border border-amber-300/15 bg-amber-400/5 p-3 text-xs leading-5 text-slate-300 sm:flex-row sm:items-center sm:justify-between"><span><strong className="text-amber-100">Risk scale:</strong> configuration count alone does not increase risk. Amber is one or two pending feed candidates; red is three or more. Confirm a candidate before taking action.</span><Link to="/network-devices" className="shrink-0 font-semibold text-cyan-300 hover:text-cyan-100">Open manual review <ArrowRight className="inline h-3.5 w-3.5" /></Link></div>
        </section>

        <div className="mt-5 grid gap-5 2xl:grid-cols-[1.25fr_0.75fr]">
          <article className="relative overflow-hidden rounded-2xl border border-cyan-300/20 bg-slate-950/75 p-5 shadow-[0_25px_60px_-38px_rgba(34,211,238,0.95)]">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">Animated traffic flow</p>
                <h2 className="mt-1 text-xl font-bold text-white">Live network protection path</h2>
              </div>
              <span className="inline-flex items-center gap-2 rounded-full border border-emerald-400/25 bg-emerald-400/10 px-3 py-1.5 text-xs font-semibold text-emerald-200"><span className="relative flex h-2 w-2"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-75" /><span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-300" /></span> Read-only live sample</span>
            </div>

            <div className={`security-topology ${flowMotion} relative mt-7 overflow-hidden rounded-2xl border border-slate-800 bg-slate-950/70 p-4 sm:p-5`}>
              <div className="pointer-events-none absolute inset-0 security-topology-grid opacity-70" />
              <div className="relative">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-cyan-200">Aggregate RouterOS interface traffic</p>
                  <span className="rounded-full border border-cyan-300/20 bg-cyan-400/10 px-2 py-1 text-[10px] font-semibold text-cyan-100">RX <strong>{formatRate(totalRx)}</strong> · TX <strong>{formatRate(totalTx)}</strong></span>
                </div>
                <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_4.5rem_minmax(0,1fr)_4.5rem_minmax(0,1fr)_4.5rem_minmax(0,1fr)] md:items-center">
                  <div className="security-node border-cyan-300/30 bg-cyan-400/10 text-cyan-100"><Network className="h-5 w-5" /><div><p className="font-semibold">Internet edge</p><p className="text-xs text-cyan-100/70">External traffic</p></div></div>
                  <div className="security-data-link" aria-label="Live inbound and outbound traffic flow"><span className="security-flow-label security-flow-label-rx">Inbound</span><span className="security-flow-label security-flow-label-tx">Outbound</span><span className="security-data-packet security-data-packet-rx" /><span className="security-data-packet security-data-packet-rx delay-1" /><span className="security-data-packet security-data-packet-tx" /><span className="security-data-packet security-data-packet-tx delay-2" /></div>
                  <div className="security-node border-violet-300/30 bg-violet-400/10 text-violet-100"><ShieldCheck className="h-5 w-5" /><div><p className="font-semibold">MikroTik perimeter</p><p className="text-xs text-violet-100/70">Signals observed</p></div></div>
                  <div className="security-data-link" aria-label="Live DNS path flow"><span className="security-flow-label security-flow-label-rx">Requests</span><span className="security-flow-label security-flow-label-tx">Replies</span><span className="security-data-packet security-data-packet-rx" /><span className="security-data-packet security-data-packet-rx delay-2" /><span className="security-data-packet security-data-packet-tx" /><span className="security-data-packet security-data-packet-tx delay-1" /></div>
                  <div className="security-node border-sky-300/30 bg-sky-400/10 text-sky-100"><CircleDot className="h-5 w-5" /><div><p className="font-semibold">DNS path</p><p className="text-xs text-sky-100/70">Resolver path only</p></div></div>
                  <div className="security-data-link" aria-label="Live subscriber data flow"><span className="security-flow-label security-flow-label-rx">Download</span><span className="security-flow-label security-flow-label-tx">Upload</span><span className="security-data-packet security-data-packet-rx" /><span className="security-data-packet security-data-packet-rx delay-1" /><span className="security-data-packet security-data-packet-tx" /><span className="security-data-packet security-data-packet-tx delay-2" /></div>
                  <div className="security-node border-emerald-300/30 bg-emerald-400/10 text-emerald-100"><Wifi className="h-5 w-5" /><div><p className="font-semibold">SolarNet clients</p><p className="text-xs text-emerald-100/70">Service continuity</p></div></div>
                </div>

                <div className="security-management-rail mt-5 border-t border-dashed border-slate-700 pt-5">
                  <div className="mb-3 flex flex-wrap items-center justify-between gap-2"><p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-violet-200">Secure management path</p><span className="text-[10px] text-slate-500">Separate from customer internet traffic</span></div>
                  <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_4.5rem_minmax(0,1fr)_4.5rem_minmax(0,1fr)] md:items-center">
                    <div className="security-node border-indigo-300/30 bg-indigo-400/10 text-indigo-100"><Server className="h-5 w-5" /><div><p className="font-semibold">Billing VPS / API</p><p className="truncate text-xs text-indigo-100/70">{dashboardHost} · {billingApiStatus === 'online' ? 'API reachable' : billingApiStatus === 'offline' ? 'API unavailable' : 'Checking API'}</p></div></div>
                    <div className="security-management-link"><span className="security-management-packet" /><span className="security-management-packet delay-2" /></div>
                    <div className="security-node border-violet-300/30 bg-violet-400/10 text-violet-100"><Network className="h-5 w-5" /><div><p className="font-semibold">VPN / management path</p><p className="truncate text-xs text-violet-100/70">{vpnRelayHost} · not tunnel-probed</p></div></div>
                    <div className="security-management-link security-management-link-return"><span className="security-management-packet" /><span className="security-management-packet delay-1" /></div>
                    <div className="security-node border-emerald-300/30 bg-emerald-400/10 text-emerald-100"><ShieldCheck className="h-5 w-5" /><div><p className="font-semibold">RouterOS API</p><p className="text-xs text-emerald-100/70">Read-only monitoring</p></div></div>
                  </div>
                </div>
              </div>
            </div>

            <div className="mt-6 grid gap-3 sm:grid-cols-3">
              <div className="rounded-xl border border-slate-800 bg-slate-900/75 p-3"><p className="text-xs uppercase tracking-[0.15em] text-slate-500">Inbound / RX</p><p className="mt-1 text-xl font-bold text-cyan-200">{formatRate(totalRx)}</p></div>
              <div className="rounded-xl border border-slate-800 bg-slate-900/75 p-3"><p className="text-xs uppercase tracking-[0.15em] text-slate-500">Outbound / TX</p><p className="mt-1 text-xl font-bold text-rose-200">{formatRate(totalTx)}</p></div>
              <div className="rounded-xl border border-slate-800 bg-slate-900/75 p-3"><p className="text-xs uppercase tracking-[0.15em] text-slate-500">Blocked packet counters</p><p className="mt-1 text-xl font-bold text-emerald-200">{blockedPackets.toLocaleString()}</p></div>
            </div>
            <p className="mt-4 text-xs text-slate-500">Last RouterOS sample: <span className="font-medium text-slate-300">{formatDateTime(latestRead)}</span> · animated particles are driven by aggregate RX/TX counters, which can include bridges and VLANs. DNS and VPN are topology paths until explicitly inspected.</p>
          </article>

          <article className="rounded-2xl border border-amber-300/20 bg-gradient-to-br from-amber-500/10 via-slate-950/80 to-slate-950/80 p-5">
            <div className="flex items-start gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl border border-amber-300/30 bg-amber-400/10 text-amber-200"><ScanSearch className="h-5 w-5" /></span><div><p className="text-xs font-semibold uppercase tracking-[0.18em] text-amber-200">Manual threat review</p><h2 className="mt-1 text-xl font-bold text-white">Run a safe connection scan</h2></div></div>
            <p className="mt-4 text-sm leading-6 text-slate-400">The scan compares a bounded sample of active RouterOS connections against the configured threat feed. It records a possible match only; it does not scan customer devices and it does not block an IP automatically.</p>
            <label className="mt-5 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Router to inspect</label>
            <select value={selectedRouterId} onChange={(event) => setSelectedRouterId(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-3 text-sm text-white outline-none transition focus:border-cyan-300">
              <option value="">Select a router</option>
              {routers.map((router) => <option key={router.id} value={router.id}>{router.name} · {router.connection_status}</option>)}
            </select>
            <button type="button" onClick={() => void scanSelectedRouter()} disabled={scanning || !selectedRouter} className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-amber-300/30 bg-amber-400/10 px-4 py-3 text-sm font-semibold text-amber-100 transition hover:bg-amber-400/20 disabled:cursor-not-allowed disabled:opacity-50">
              <ScanSearch className={`h-4 w-4 ${scanning ? 'animate-pulse' : ''}`} /> {scanning ? 'Scanning live connections…' : 'Run read-only threat scan'}
            </button>
            <div className="mt-4 rounded-xl border border-slate-800 bg-slate-900/70 p-3 text-xs leading-5 text-slate-400"><span className="font-semibold text-slate-200">Safety rule:</span> a pending observation needs explicit administrator review in Network Devices before any SolarNet-owned firewall entry is added.</div>
          </article>
        </div>

        <div className="mt-5 grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
          <section className="rounded-2xl border border-slate-700/80 bg-slate-950/75 p-5">
            <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-[0.2em] text-violet-300">Router perimeter</p><h2 className="mt-1 text-xl font-bold text-white">Live device posture</h2><p className="mt-1 text-sm text-slate-400">Actual RouterOS monitoring values, sampled without altering device configuration.</p></div><Gauge className="h-6 w-6 text-violet-300" /></div>
            <div className="mt-5 grid gap-3 sm:grid-cols-2">
              {routers.map((router) => {
                const sample = monitoring[router.id];
                const online = router.connection_status === 'online';
                return <article key={router.id} className="rounded-xl border border-slate-800 bg-slate-900/70 p-4"><div className="flex items-center justify-between gap-3"><div className="min-w-0"><p className="truncate font-semibold text-white">{router.name}</p><p className="truncate text-xs text-slate-500">{router.host}</p></div><span className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-1 text-[11px] font-semibold ${online ? 'bg-emerald-400/10 text-emerald-200' : 'bg-red-400/10 text-red-200'}`}>{online ? <Wifi className="h-3.5 w-3.5" /> : <WifiOff className="h-3.5 w-3.5" />}{router.connection_status}</span></div><div className="mt-4 grid grid-cols-2 gap-2"><div className="rounded-lg bg-cyan-400/10 p-2"><p className="text-sm font-bold text-cyan-200">{formatRate(sample?.rx_bps)}</p><p className="text-[11px] text-slate-500">RX</p></div><div className="rounded-lg bg-violet-400/10 p-2"><p className="text-sm font-bold text-violet-200">{formatRate(sample?.tx_bps)}</p><p className="text-[11px] text-slate-500">TX</p></div></div><p className="mt-3 text-xs text-slate-500">{sample ? `${sample.running_interfaces} running interfaces · CPU ${sample.cpu_load}%` : 'No monitoring sample available yet'}</p></article>;
              })}
              {!loading && routers.length === 0 && <p className="rounded-xl border border-dashed border-slate-700 p-5 text-sm text-slate-400">No MikroTik router is configured yet. Add one in Network Devices to begin monitoring.</p>}
            </div>
          </section>

          <section className="rounded-2xl border border-slate-700/80 bg-slate-950/75 p-5">
            <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-[0.2em] text-amber-300">Observation queue</p><h2 className="mt-1 text-xl font-bold text-white">Threat candidates awaiting review</h2><p className="mt-1 text-sm text-slate-400">A candidate is an observation, not a confirmed infection.</p></div><Eye className="h-6 w-6 text-amber-300" /></div>
            <div className="mt-5 space-y-3">
              {pending.slice(0, 5).map((observation) => <article key={observation.id} className="flex flex-col gap-3 rounded-xl border border-amber-300/15 bg-amber-400/5 p-3 sm:flex-row sm:items-center sm:justify-between"><div><p className="font-mono text-sm font-semibold text-amber-100">{observation.remote_ip}</p><p className="mt-1 text-xs text-slate-400">{observation.feed_name} · {observation.router?.name || 'Router'} · {formatDateTime(observation.last_observed_at)}</p></div><span className="inline-flex w-fit items-center gap-1 rounded-full bg-amber-400/10 px-2 py-1 text-[11px] font-semibold text-amber-200"><CircleDot className="h-3 w-3" /> Pending</span></article>)}
              {!pending.length && <div className="rounded-xl border border-emerald-300/15 bg-emerald-400/5 p-4"><div className="flex items-center gap-2 text-emerald-200"><CheckCircle2 className="h-4 w-4" /><p className="text-sm font-semibold">No pending observations</p></div><p className="mt-1 text-xs leading-5 text-slate-400">Run a read-only scan when needed. Any future match will appear here for review before a firewall change is possible.</p></div>}
            </div>
            <Link to="/network-devices" className="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-cyan-300 transition hover:text-cyan-100">Review router-level details <ArrowRight className="h-4 w-4" /></Link>
          </section>
        </div>

        <footer className="mt-5 flex flex-col gap-3 rounded-2xl border border-slate-700/80 bg-slate-950/65 px-4 py-4 text-xs leading-5 text-slate-400 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-start gap-2"><ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-300" /><span><strong className="text-slate-200">Safety boundary:</strong> this center reads RouterOS telemetry and threat-feed observations. It does not inspect customer device files, change DHCP, queues, VLANs, NAT, or firewall rules during refresh.</span></div>
          <span className="shrink-0 font-medium text-slate-300">5-second live monitoring</span>
        </footer>
      </section>
    </DashboardLayout>
  );
}
