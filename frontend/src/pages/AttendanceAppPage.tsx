import { useCallback, useEffect, useMemo, useState } from 'react';
import { CheckCircle2, Clock3, Download, LogIn, LogOut, RefreshCw, ShieldCheck } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import api, { getErrorMessage } from '@/services/api';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

type AttendanceRecord = {
  id: string;
  work_date: string;
  clocked_in_at: string | null;
  clocked_out_at: string | null;
};

type StatusPayload = {
  server_time: string;
  timezone: string;
  state: 'not_clocked_in' | 'clocked_in' | 'clocked_out';
  record: AttendanceRecord | null;
};

const time = (value: string | null | undefined): string => value
  ? new Date(value).toLocaleTimeString('en-PH', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit' })
  : '—';

export default function AttendanceAppPage(): React.JSX.Element {
  const { user, logout } = useAuth();
  const [data, setData] = useState<StatusPayload | null>(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [installPrompt, setInstallPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [clock, setClock] = useState(Date.now());
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
  const isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent);

  const load = useCallback(async () => {
    try {
      const response = await api.get('/staff-attendance/me');
      setData(response.data.data);
      setError('');
    } catch (reason) {
      setError(getErrorMessage(reason));
    }
  }, []);

  useEffect(() => {
    let manifest = document.querySelector<HTMLLinkElement>('link[rel="manifest"]');
    if (!manifest) {
      manifest = document.createElement('link');
      manifest.rel = 'manifest';
      document.head.appendChild(manifest);
    }
    const previousManifest = manifest.href;
    manifest.href = '/api/v1/attendance-app/manifest.webmanifest';
    const onInstall = (event: Event) => { event.preventDefault(); setInstallPrompt(event as BeforeInstallPromptEvent); };
    window.addEventListener('beforeinstallprompt', onInstall);
    void load();
    const refresh = window.setInterval(() => void load(), 30_000);
    const ticker = window.setInterval(() => setClock(Date.now()), 1_000);
    return () => {
      window.removeEventListener('beforeinstallprompt', onInstall);
      window.clearInterval(refresh);
      window.clearInterval(ticker);
      if (previousManifest) manifest.href = previousManifest;
    };
  }, [load]);

  const elapsed = useMemo(() => {
    if (!data?.record?.clocked_in_at) return '00:00:00';
    const start = new Date(data.record.clocked_in_at).getTime();
    const end = data.record.clocked_out_at ? new Date(data.record.clocked_out_at).getTime() : clock;
    const seconds = Math.max(0, Math.floor((end - start) / 1000));
    return [Math.floor(seconds / 3600), Math.floor((seconds % 3600) / 60), seconds % 60].map(value => String(value).padStart(2, '0')).join(':');
  }, [clock, data]);

  const act = async (action: 'clock-in' | 'clock-out') => {
    setBusy(true); setError(''); setMessage('');
    try {
      const response = await api.post(`/staff-attendance/${action}`);
      setMessage(response.data.message);
      await load();
    } catch (reason) {
      setError(getErrorMessage(reason));
    } finally {
      setBusy(false);
    }
  };

  const install = async () => {
    if (!installPrompt) return;
    await installPrompt.prompt();
    await installPrompt.userChoice;
    setInstallPrompt(null);
  };

  const stateLabel = data?.state === 'clocked_in' ? 'Currently working' : data?.state === 'clocked_out' ? 'Shift completed' : 'Not clocked in';

  return <main className="min-h-screen bg-gradient-to-br from-slate-950 via-sky-950 to-slate-950 p-4 text-white sm:p-6">
    <div className="mx-auto flex min-h-[calc(100vh-2rem)] max-w-lg flex-col justify-center">
      <section className="overflow-hidden rounded-3xl border border-sky-300/20 bg-slate-950/75 shadow-2xl shadow-sky-950/50 backdrop-blur">
        <header className="border-b border-white/10 bg-gradient-to-r from-sky-600/25 to-cyan-500/10 p-6">
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-3"><img src="/solarnet-company-logo-192.png" alt="SolarNet" className="h-12 w-12 rounded-xl object-contain"/><div><h1 className="text-xl font-bold">SolarNet Attendance</h1><p className="text-sm text-sky-100/75">{user?.name}</p></div></div>
            <button onClick={() => void load()} aria-label="Refresh attendance" className="rounded-xl border border-white/15 p-2.5 text-sky-100 hover:bg-white/10"><RefreshCw className="h-5 w-5"/></button>
          </div>
        </header>

        <div className="space-y-5 p-6">
          <div className="text-center"><p className="text-sm font-medium text-sky-200">Philippine server time</p><p className="mt-1 font-mono text-3xl font-bold tracking-tight">{new Date(clock).toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit'})}</p><p className="mt-1 text-xs text-slate-400">{new Date(clock).toLocaleDateString('en-PH',{timeZone:'Asia/Manila',weekday:'long',year:'numeric',month:'long',day:'numeric'})}</p></div>

          <div className="rounded-2xl border border-white/10 bg-white/5 p-5 text-center"><div className="flex items-center justify-center gap-2 text-sky-200"><ShieldCheck className="h-5 w-5"/><span className="font-semibold">{stateLabel}</span></div><p className="mt-3 font-mono text-4xl font-bold">{elapsed}</p><p className="mt-1 text-xs text-slate-400">Elapsed shift time</p><div className="mt-4 grid grid-cols-2 gap-3 text-sm"><div className="rounded-xl bg-slate-900/80 p-3"><p className="text-slate-400">Clock in</p><p className="mt-1 font-semibold">{time(data?.record?.clocked_in_at)}</p></div><div className="rounded-xl bg-slate-900/80 p-3"><p className="text-slate-400">Clock out</p><p className="mt-1 font-semibold">{time(data?.record?.clocked_out_at)}</p></div></div></div>

          {error && <p role="alert" className="rounded-xl border border-rose-400/30 bg-rose-500/10 p-3 text-sm text-rose-100">{error}</p>}
          {message && <p className="flex items-center gap-2 rounded-xl border border-emerald-400/30 bg-emerald-500/10 p-3 text-sm text-emerald-100"><CheckCircle2 className="h-4 w-4"/>{message}</p>}

          <div className="grid gap-3 sm:grid-cols-2"><button disabled={busy || data?.state !== 'not_clocked_in'} onClick={() => void act('clock-in')} className="inline-flex min-h-14 items-center justify-center gap-2 rounded-xl bg-emerald-500 px-5 py-3 font-bold text-slate-950 disabled:cursor-not-allowed disabled:opacity-35"><LogIn className="h-5 w-5"/>{busy ? 'Recording…' : 'Clock in'}</button><button disabled={busy || data?.state !== 'clocked_in'} onClick={() => void act('clock-out')} className="inline-flex min-h-14 items-center justify-center gap-2 rounded-xl bg-amber-400 px-5 py-3 font-bold text-slate-950 disabled:cursor-not-allowed disabled:opacity-35"><LogOut className="h-5 w-5"/>{busy ? 'Recording…' : 'Clock out'}</button></div>

          {!isStandalone && installPrompt && <button onClick={() => void install()} className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-sky-300/30 bg-sky-400/10 px-4 py-3 font-semibold text-sky-100"><Download className="h-4 w-4"/>Install attendance app</button>}
          {!isStandalone && !installPrompt && <p className="rounded-xl border border-sky-300/20 bg-sky-400/5 p-3 text-xs leading-5 text-sky-100/80">{isIos ? 'To install on iPhone: open this page in Safari, tap Share, then Add to Home Screen.' : 'To install: open your browser menu and choose Install app or Add to Home screen.'}</p>}
          <div className="flex items-center justify-between border-t border-white/10 pt-4 text-xs text-slate-400"><span className="inline-flex items-center gap-1.5"><Clock3 className="h-4 w-4"/>Records use server time</span><button onClick={() => void logout()} className="font-semibold text-sky-300 hover:text-sky-200">Sign out</button></div>
        </div>
      </section>
    </div>
  </main>;
}
