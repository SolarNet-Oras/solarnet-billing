import { useEffect, useRef, useState } from 'react';
import { MapPin, ShieldAlert } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

type TrackingState = 'off_duty' | 'starting' | 'active' | 'blocked' | 'unavailable';

const isTrackingShift = (): boolean => {
  const hour = Number(new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    hour: '2-digit',
    hourCycle: 'h23',
  }).format(new Date()));
  return hour >= 6 && hour < 18;
};

export default function MandatoryFieldLocation(): React.JSX.Element | null {
  const { user } = useAuth();
  const watchId = useRef<number | null>(null);
  const lastUploadAt = useRef(0);
  const [state, setState] = useState<TrackingState>(() => isTrackingShift() ? 'starting' : 'off_duty');
  const roles = [user?.role, ...(user?.roles || []).map((role) => typeof role === 'string' ? role : role.name)].filter(Boolean);
  const isFieldStaff = ['collector', 'technician'].some((role) => roles.includes(role));

  useEffect(() => {
    if (!isFieldStaff) return undefined;

    const upload = async (position: GeolocationPosition): Promise<void> => {
      if (Date.now() - lastUploadAt.current < 15000) return;
      lastUploadAt.current = Date.now();
      try {
        await api.put('/operations-map/my-live-location', {
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy_meters: position.coords.accuracy,
        });
        setState('active');
      } catch {
        setState('unavailable');
      }
    };
    const stopWatch = (): void => {
      if (watchId.current !== null) navigator.geolocation.clearWatch(watchId.current);
      watchId.current = null;
    };
    const requestFreshPosition = (): void => {
      if (!navigator.geolocation || !isTrackingShift()) return;
      navigator.geolocation.getCurrentPosition(
        (position) => { void upload(position); },
        () => setState('blocked'),
        { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 },
      );
    };
    const synchronizeShift = (): void => {
      if (!isTrackingShift()) {
        stopWatch();
        setState('off_duty');
        return;
      }
      if (!navigator.geolocation) {
        setState('unavailable');
        return;
      }
      if (watchId.current === null) {
        setState('starting');
        watchId.current = navigator.geolocation.watchPosition(
          (position) => { void upload(position); },
          () => setState('blocked'),
          { enableHighAccuracy: true, timeout: 30000, maximumAge: 10000 },
        );
      }
      requestFreshPosition();
    };

    synchronizeShift();
    const minuteRefresh = window.setInterval(synchronizeShift, 60000);
    const resumeRefresh = (): void => { if (document.visibilityState === 'visible') synchronizeShift(); };
    document.addEventListener('visibilitychange', resumeRefresh);
    window.addEventListener('online', synchronizeShift);

    return () => {
      window.clearInterval(minuteRefresh);
      document.removeEventListener('visibilitychange', resumeRefresh);
      window.removeEventListener('online', synchronizeShift);
      stopWatch();
    };
  }, [isFieldStaff]);

  if (!isFieldStaff || state === 'off_duty') return null;
  const active = state === 'active';
  return <div role="status" className={`fixed bottom-3 left-3 z-[70] flex max-w-[calc(100vw-1.5rem)] items-center gap-2 rounded-full border px-3 py-2 text-xs font-semibold shadow-lg backdrop-blur lg:left-[17rem] ${active ? 'border-emerald-400/50 bg-emerald-950/90 text-emerald-100' : 'border-amber-400/50 bg-amber-950/95 text-amber-100'}`}>
    {active ? <MapPin className="h-4 w-4 animate-pulse" /> : <ShieldAlert className="h-4 w-4" />}
    <span>{active ? 'Work location sharing active · 6 AM–6 PM' : state === 'starting' ? 'Starting required work location sharing…' : 'Location permission required for field duty (6 AM–6 PM)'}</span>
  </div>;
}
