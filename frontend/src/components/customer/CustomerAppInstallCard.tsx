import { useEffect, useState } from 'react';
import { Bell, Download, Smartphone } from 'lucide-react';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

declare global {
  interface WindowEventMap {
    beforeinstallprompt: BeforeInstallPromptEvent;
  }
}

export default function CustomerAppInstallCard(): React.JSX.Element | null {
  const [installEvent, setInstallEvent] = useState<BeforeInstallPromptEvent | null>(null);
  const [installed, setInstalled] = useState(() => window.matchMedia('(display-mode: standalone)').matches);
  const [showIosHelp, setShowIosHelp] = useState(false);

  useEffect(() => {
    const onBeforeInstall = (event: BeforeInstallPromptEvent): void => {
      event.preventDefault();
      setInstallEvent(event);
    };
    const onInstalled = (): void => setInstalled(true);

    window.addEventListener('beforeinstallprompt', onBeforeInstall);
    window.addEventListener('appinstalled', onInstalled);
    return () => {
      window.removeEventListener('beforeinstallprompt', onBeforeInstall);
      window.removeEventListener('appinstalled', onInstalled);
    };
  }, []);

  if (installed) return null;

  const install = async (): Promise<void> => {
    if (!installEvent) {
      setShowIosHelp(true);
      return;
    }
    await installEvent.prompt();
    const choice = await installEvent.userChoice;
    if (choice.outcome === 'accepted') setInstalled(true);
    setInstallEvent(null);
  };

  return (
    <section className="mt-6 rounded-2xl border border-sky-200 bg-gradient-to-r from-sky-50 to-indigo-50 p-5">
      <div className="flex gap-4">
        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
          <Smartphone className="h-5 w-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h3 className="font-semibold text-slate-900">Install the SolarNet Customer App</h3>
          <p className="mt-1 text-sm leading-6 text-slate-600">
            Add the portal to your phone for quick access to bills, payments, account status, and support.
          </p>
          <div className="mt-4 flex flex-wrap gap-3">
            <button type="button" onClick={() => void install()} className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
              <Download className="h-4 w-4" /> Install app
            </button>
            <span className="inline-flex items-center gap-2 py-2 text-sm text-slate-600">
              <Bell className="h-4 w-4 text-sky-700" /> Notification permission is always optional.
            </span>
          </div>
          {showIosHelp && (
            <p className="mt-3 rounded-lg bg-white/80 p-3 text-sm text-slate-700">
              On iPhone/iPad, open this page in Safari, tap Share, then choose <strong>Add to Home Screen</strong>.
            </p>
          )}
        </div>
      </div>
    </section>
  );
}
