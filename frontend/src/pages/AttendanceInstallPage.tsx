import { useEffect, useState } from 'react';
import { CheckCircle2, Download, Share2, ShieldCheck, Smartphone } from 'lucide-react';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

declare global { interface Window { __solarnetInstallPrompt?: BeforeInstallPromptEvent; } }

export default function AttendanceInstallPage(): React.JSX.Element {
  const attendanceHost = new URL(import.meta.env.VITE_ATTENDANCE_URL || 'https://attendance.solarnetportal.com').host;
  const [prompt, setPrompt] = useState<BeforeInstallPromptEvent | null>(() => window.__solarnetInstallPrompt || null);
  const [installed, setInstalled] = useState(() => window.matchMedia('(display-mode: standalone)').matches);
  const [help, setHelp] = useState(false);
  const isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
  const isAndroid = /android/i.test(window.navigator.userAgent);
  const chromeIntent = `intent://${attendanceHost}/attendance-app/install#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url=${encodeURIComponent(`https://${attendanceHost}/attendance-app/install`)};end`;
  const standaloneContainer = window.matchMedia('(display-mode: standalone)').matches;
  const isAttendanceOrigin = window.location.host === attendanceHost;

  useEffect(() => {
    if (isAttendanceOrigin && standaloneContainer) {
      window.location.replace('/attendance-app');
      return;
    }
    const manifest = document.querySelector<HTMLLinkElement>('link[rel="manifest"]');
    manifest?.setAttribute('href', '/api/v1/attendance-app/manifest.webmanifest');
    const onPrompt = () => setPrompt(window.__solarnetInstallPrompt || null);
    const onInstalled = () => {
      setInstalled(true);
    };
    window.addEventListener('solarnet:install-prompt-ready', onPrompt);
    window.addEventListener('appinstalled', onInstalled);
    return () => {
      window.removeEventListener('solarnet:install-prompt-ready', onPrompt);
      window.removeEventListener('appinstalled', onInstalled);
    };
  }, [isAttendanceOrigin, standaloneContainer]);

  const install = async () => {
    if (!prompt) {
      setHelp(true);
      if (isAndroid) window.location.href = chromeIntent;
      return;
    }
    await prompt.prompt();
    const choice = await prompt.userChoice;
    if (choice.outcome === 'accepted') {
      setInstalled(true);
      return;
    }
    window.__solarnetInstallPrompt = undefined;
    setPrompt(null);
  };

  return <main className="grid min-h-screen place-items-center bg-gradient-to-br from-slate-950 via-sky-950 to-slate-950 p-4 text-white">
    <section className="w-full max-w-lg rounded-3xl border border-sky-300/20 bg-slate-950/80 p-6 shadow-2xl backdrop-blur sm:p-8">
      <div className="flex items-center gap-4"><img src="/solarnet-attendance-icon-192.png?v=20260914-attendance" alt="SolarNet Attendance" className="h-20 w-20 rounded-2xl object-contain"/><div><p className="text-xs font-bold uppercase tracking-[.18em] text-sky-300">Separate attendance kiosk</p><h1 className="mt-1 text-2xl font-bold">SolarNet Attendance</h1></div></div>
      <p className="mt-5 leading-7 text-slate-300">Install this attendance-only kiosk on a designated Super Administrator phone or computer. Employees must physically use that device to record time-in and time-out. It does not include billing, customers, or the Staff app menu.</p>
      <div className="mt-5 space-y-3 text-sm text-slate-200"><p className="flex gap-2"><Smartphone className="mt-0.5 h-5 w-5 text-sky-300"/>Designed for a designated shared attendance device—not employee phones.</p><p className="flex gap-2"><ShieldCheck className="mt-0.5 h-5 w-5 text-emerald-300"/>A Super Administrator authorizes the kiosk; each employee confirms a punch with their own password.</p></div>
      {installed ? <div className="mt-6 space-y-3"><div className="flex items-center justify-center gap-2 rounded-xl bg-emerald-500/15 px-5 py-4 font-semibold text-emerald-200"><CheckCircle2 className="h-5 w-5"/>Attendance app installed</div><p className="text-center text-xs text-slate-400">Open SolarNet Time from your phone's app list or home screen.</p></div> : <button onClick={() => void install()} className="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-sky-400 px-5 py-4 font-bold text-slate-950 hover:bg-sky-300"><Download className="h-5 w-5"/>{isAndroid&&!prompt?'Open in Chrome to install':'Install Attendance App'}</button>}
      {standaloneContainer && !installed && <div className="mt-4 rounded-xl border border-amber-300/30 bg-amber-400/10 p-4 text-sm leading-6 text-amber-100"><strong>This is the SolarNet Staff app window, not the Attendance app.</strong> Open this installer in Chrome or Safari first, then install it to create a separate SolarNet Time icon.</div>}
      {isAndroid && !prompt && !installed && <a href={chromeIntent} className="mt-3 inline-flex w-full items-center justify-center rounded-xl border border-sky-300/30 px-4 py-3 font-semibold text-sky-100">Continue installation in Chrome</a>}
      {(help || isIos) && !installed && <div className="mt-4 rounded-xl border border-white/10 bg-white/5 p-4 text-sm leading-6 text-slate-300">{isIos ? <><Share2 className="mr-1 inline h-4 w-4"/>Open this link in Safari, tap <strong>Share</strong>, then <strong>Add to Home Screen</strong>.</> : <>Open this page in Chrome’s normal browser window, then choose <strong>Install app</strong> or <strong>Add to Home screen</strong>. If an old Attendance shortcut exists, remove it first and reload this page.</>}</div>}
      <a href="/attendance-app" className="mt-5 block text-center text-sm font-semibold text-sky-300 hover:text-sky-200">Open attendance without installing</a>
    </section>
  </main>;
}
