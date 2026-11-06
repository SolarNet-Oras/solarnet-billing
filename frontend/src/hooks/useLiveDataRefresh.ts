import { useEffect, useRef } from 'react';

export const LIVE_DATA_REFRESH_EVENT = 'solarnet:live-data-refresh';
const liveDataChannel = typeof BroadcastChannel !== 'undefined'
  ? new BroadcastChannel('solarnet-live-data')
  : null;

type Options = {
  enabled?: boolean;
  intervalMs?: number;
};

export function announceLiveDataChange(): void {
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(LIVE_DATA_REFRESH_EVENT));
  liveDataChannel?.postMessage({changedAt:Date.now()});
}

liveDataChannel?.addEventListener('message',()=>{
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(LIVE_DATA_REFRESH_EVENT));
});

export function useLiveDataRefresh(
  refresh: () => void | Promise<void>,
  { enabled = true, intervalMs = 3_000 }: Options = {},
): void {
  const refreshRef = useRef(refresh);
  refreshRef.current = refresh;

  useEffect(() => {
    if (!enabled) return;
    let running = false;
    const run = async (): Promise<void> => {
      if (running || document.visibilityState !== 'visible' || !navigator.onLine) return;
      running = true;
      try { await refreshRef.current(); }
      finally { running = false; }
    };
    const resume = (): void => { void run(); };
    const timer = window.setInterval(resume, intervalMs);
    window.addEventListener(LIVE_DATA_REFRESH_EVENT, resume);
    window.addEventListener('focus', resume);
    window.addEventListener('online', resume);
    document.addEventListener('visibilitychange', resume);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener(LIVE_DATA_REFRESH_EVENT, resume);
      window.removeEventListener('focus', resume);
      window.removeEventListener('online', resume);
      document.removeEventListener('visibilitychange', resume);
    };
  }, [enabled, intervalMs]);
}
