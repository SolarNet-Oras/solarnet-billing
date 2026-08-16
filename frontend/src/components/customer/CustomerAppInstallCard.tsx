import { useEffect, useState } from 'react';
import { Bell, BellOff, CheckCircle2, Download, LoaderCircle, Smartphone } from 'lucide-react';
import customerPortalService from '../../services/customerPortalService';
import {
  currentWebPushSubscription,
  subscribeToWebPush,
  supportsWebPush,
  unsubscribeFromWebPush,
} from '../../lib/webPush';

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
  const [pushStatus, setPushStatus] = useState<{
    enabled: boolean;
    publicKey: string | null;
    reason: string | null;
    subscriptionCount: number;
  } | null>(null);
  const [thisDeviceSubscribed, setThisDeviceSubscribed] = useState(false);
  const [pushPermission, setPushPermission] = useState<NotificationPermission | 'unsupported'>(() => supportsWebPush() ? Notification.permission : 'unsupported');
  const [pushBusy, setPushBusy] = useState(false);
  const [pushMessage, setPushMessage] = useState('');
  const subscribedDeviceCount = pushStatus?.subscriptionCount ?? 0;

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

  useEffect(() => {
    const loadPushStatus = async (): Promise<void> => {
      try {
        const [status, subscription] = await Promise.all([
          customerPortalService.getPushNotificationStatus(),
          currentWebPushSubscription(),
        ]);
        setPushStatus({ enabled: status.enabled, publicKey: status.public_key, reason: status.reason, subscriptionCount: status.subscription_count ?? 0 });
        setThisDeviceSubscribed(Boolean(subscription));
        setPushPermission(supportsWebPush() ? Notification.permission : 'unsupported');
      } catch {
        // The customer dashboard stays usable if an older deployment does not
        // yet expose the opt-in push routes.
        setPushStatus(null);
      }
    };

    void loadPushStatus();
  }, []);

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

  const enableAlerts = async (): Promise<void> => {
    setPushMessage('');
    if (!supportsWebPush()) {
      setPushMessage('This browser cannot receive portal notifications. On iPhone/iPad, install the SolarNet app from Safari first.');
      return;
    }
    if (Notification.permission === 'denied') {
      setPushPermission('denied');
      setPushMessage('Notifications are blocked in this browser. Enable notifications for this site in your browser or phone settings, then try again.');
      return;
    }
    if (!pushStatus?.enabled || !pushStatus.publicKey) {
      setPushMessage(pushStatus?.reason || 'Billing notifications are not configured on the server yet.');
      return;
    }

    setPushBusy(true);
    try {
      const subscription = await subscribeToWebPush(pushStatus.publicKey);
      const response = await customerPortalService.subscribePushNotifications(subscription);
      setThisDeviceSubscribed(true);
      setPushPermission(Notification.permission);
      setPushMessage(response.message);
    } catch (error: any) {
      setPushMessage(error.response?.data?.message || error.message || 'Could not enable alerts on this device.');
    } finally {
      setPushBusy(false);
    }
  };

  const disableAlerts = async (): Promise<void> => {
    setPushMessage('');
    setPushBusy(true);
    try {
      const existing = await currentWebPushSubscription();
      if (existing) {
        await customerPortalService.unsubscribePushNotifications(existing.endpoint);
      }
      await unsubscribeFromWebPush();
      setThisDeviceSubscribed(false);
      setPushMessage('Billing and service alerts are disabled for this device.');
    } catch (error: any) {
      setPushMessage(error.response?.data?.message || error.message || 'Could not disable alerts on this device.');
    } finally {
      setPushBusy(false);
    }
  };

  return (
    <section className="mt-6 rounded-2xl border border-sky-200 bg-gradient-to-r from-sky-50 to-indigo-50 p-5">
      <div className="flex gap-4">
        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
          <Smartphone className="h-5 w-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h3 className="font-semibold text-slate-900">SolarNet app & billing alerts</h3>
          <p className="mt-1 text-sm leading-6 text-slate-600">
            Receive optional payment reminders and service-status alerts on this device. Alerts are linked to your signed-in portal account, not your Wi-Fi address.
          </p>
          <div className="mt-4 flex flex-wrap gap-3">
            {!installed && (
              <button type="button" onClick={() => void install()} className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <Download className="h-4 w-4" /> Install app
              </button>
            )}
            {thisDeviceSubscribed ? (
              <button type="button" disabled={pushBusy} onClick={() => void disableAlerts()} className="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-70">
                {pushBusy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                Alerts enabled · turn off
              </button>
            ) : (
              <button type="button" disabled={pushBusy || !pushStatus?.enabled} onClick={() => void enableAlerts()} className="inline-flex items-center gap-2 rounded-lg bg-sky-700 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50">
                {pushBusy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Bell className="h-4 w-4" />}
                Enable billing alerts
              </button>
            )}
          </div>
          {pushPermission === 'denied' && <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Notifications are blocked for this site. Enable them in your browser or phone settings, then return here.</p>}
          {pushPermission === 'unsupported' && <p className="mt-3 rounded-lg border border-slate-200 bg-white/80 px-3 py-2 text-sm text-slate-700">This browser does not support portal notifications. You can still use the customer portal normally.</p>}
          {subscribedDeviceCount > 1 && <p className="mt-3 text-xs text-slate-600">Alerts are also enabled on {subscribedDeviceCount - 1} other signed-in device{subscribedDeviceCount === 2 ? '' : 's'}.</p>}
          <p className="mt-3 flex items-start gap-2 text-xs leading-5 text-slate-600">
            <BellOff className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" /> Permission is optional. You can turn alerts off here or in your phone’s browser/app settings.
          </p>
          {pushMessage && <p className="mt-2 rounded-lg bg-white/80 px-3 py-2 text-sm text-slate-700">{pushMessage}</p>}
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
