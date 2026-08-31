import React, { useEffect, useState } from 'react';
import { CheckCircle2, Download, Laptop, Share2, Smartphone } from 'lucide-react';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

declare global { interface Window { __solarnetInstallPrompt?: BeforeInstallPromptEvent; } }

export function StaffAppInstallCard(): React.JSX.Element {
  const [prompt, setPrompt] = useState<BeforeInstallPromptEvent | null>(() => window.__solarnetInstallPrompt || null);
  const [installed, setInstalled] = useState(() => window.matchMedia('(display-mode: standalone)').matches);
  const [help, setHelp] = useState(false);
  const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent);

  useEffect(() => {
    // Ensure Settings always advertises the staff manifest, even on a staging
    // hostname that does not start with "billing.".
    const manifest = document.querySelector<HTMLLinkElement>('link[rel="manifest"]');
    const previous = manifest?.href;
    manifest?.setAttribute('href', '/api/v1/admin-app/manifest.webmanifest');

    const onPrompt = (event: Event): void => {
      event.preventDefault();
      setPrompt(event as BeforeInstallPromptEvent);
    };
    const onCapturedPrompt = (): void => setPrompt(window.__solarnetInstallPrompt || null);
    const onInstalled = (): void => setInstalled(true);
    window.addEventListener('beforeinstallprompt', onPrompt);
    window.addEventListener('solarnet:install-prompt-ready', onCapturedPrompt);
    window.addEventListener('appinstalled', onInstalled);
    return () => {
      if (manifest && previous) manifest.href = previous;
      window.removeEventListener('beforeinstallprompt', onPrompt);
      window.removeEventListener('solarnet:install-prompt-ready', onCapturedPrompt);
      window.removeEventListener('appinstalled', onInstalled);
    };
  }, []);

  const install = async (): Promise<void> => {
    if (!prompt) { setHelp(true); return; }
    await prompt.prompt();
    const result = await prompt.userChoice;
    if (result.outcome === 'accepted') setInstalled(true);
    window.__solarnetInstallPrompt = undefined;
    setPrompt(null);
  };

  return <section className="overflow-hidden rounded-2xl border border-cyan-500/25 bg-gradient-to-br from-slate-950 via-slate-900 to-cyan-950 p-6 text-slate-100 shadow-xl" data-testid="admin-app-install">
    <div className="flex flex-wrap items-start justify-between gap-5">
      <div className="max-w-2xl">
        <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-[.18em] text-cyan-300"><Download className="h-4 w-4"/>Staff application</div>
        <h2 className="mt-2 text-xl font-bold">Install SolarNet Staff on this device</h2>
        <p className="mt-2 text-sm leading-6 text-slate-300">For authorized SolarNet employees including administrators, collectors, technicians, cashiers, NOC, and accounting. It opens the employee login and does not install or expose the customer portal.</p>
        <div className="mt-4 flex flex-wrap gap-3 text-xs text-slate-300"><span className="flex items-center gap-1.5"><Laptop className="h-4 w-4 text-cyan-300"/>Windows desktop app</span><span className="flex items-center gap-1.5"><Smartphone className="h-4 w-4 text-cyan-300"/>Android / iOS home screen</span></div>
      </div>
      {installed ? <div className="flex items-center gap-2 rounded-xl bg-emerald-500/15 px-4 py-3 text-sm font-semibold text-emerald-300"><CheckCircle2 className="h-5 w-5"/>Installed on this device</div> : <button type="button" onClick={() => void install()} className="inline-flex items-center gap-2 rounded-xl bg-cyan-400 px-5 py-3 text-sm font-bold text-slate-950 hover:bg-cyan-300"><Download className="h-4 w-4"/>Download and install</button>}
    </div>
    {help && <div className="mt-5 rounded-xl border border-slate-700 bg-slate-900/80 p-4 text-sm leading-6 text-slate-300">
      {isIos ? <><p className="font-semibold text-white">Install with Safari</p><p className="mt-1"><Share2 className="mr-1 inline h-4 w-4"/>Tap <strong>Share</strong>, choose <strong>Add to Home Screen</strong>, then tap <strong>Add</strong>.</p></> : <><p className="font-semibold text-white">The browser has not offered installation yet</p><p className="mt-1">After the latest deployment, reload this page once so Chrome or Edge can validate the new 192×192 and 512×512 company-logo icons. Then click <strong>Download and install</strong> again. If already installed, uninstall the old copy first.</p><button type="button" onClick={() => window.location.reload()} className="mt-3 rounded-lg border border-cyan-400/40 px-3 py-2 font-semibold text-cyan-200 hover:bg-cyan-400/10">Reload installation check</button></>}
    </div>}
    <p className="mt-4 text-xs leading-5 text-slate-400">This is a Progressive Web App served from the official billing domain. Authentication, roles, permissions, and server sessions remain unchanged. No password or API credential is embedded in the installation.</p>
  </section>;
}

/** Backward-compatible name used by the Super Administrator Settings page. */
export const AdminAppInstallCard = StaffAppInstallCard;
