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
  const { user, logout } = useAuth();
  const watchId = useRef<number | null>(null);
  const lastUploadAt = useRef(0);
  const uploading = useRef(false);
  const [state, setState] = useState<TrackingState>(() => isTrackingShift() ? 'starting' : 'off_duty');
  const roles = [user?.role, ...(user?.roles || []).map((role) => typeof role === 'string' ? role : role.name)].filter(Boolean);
  const isFieldStaff = ['collector', 'technician'].some((role) => roles.includes(role));

  const upload = async (position: GeolocationPosition, force = false): Promise<void> => {
    if (uploading.current || (!force && Date.now() - lastUploadAt.current < 15000)) return;
    uploading.current = true;
    try {
      await api.put('/operations-map/my-live-location', {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy_meters: position.coords.accuracy,
      });
      lastUploadAt.current = Date.now();
      setState('active');
    } catch {
      setState('unavailable');
    } finally {
      uploading.current = false;
    }
  };

  const requestFreshPosition = (force = false): void => {
    if (!navigator.geolocation) {
      setState('unavailable');
      return;
    }
    if (!isTrackingShift()) {
      setState('off_duty');
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (position) => { void upload(position, force); },
      () => setState('blocked'),
      { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 },
    );
  };

  useEffect(() => {
    if (!isFieldStaff) return undefined;

    const stopWatch = (): void => {
      if (watchId.current !== null) navigator.geolocation.clearWatch(watchId.current);
      watchId.current = null;
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
    let permissionStatus: PermissionStatus | null = null;
    const permissionChanged = (): void => synchronizeShift();
    if (navigator.permissions?.query) {
      void navigator.permissions.query({ name: 'geolocation' }).then((status) => {
        permissionStatus = status;
        status.addEventListener('change', permissionChanged);
      }).catch(() => undefined);
    }

    return () => {
      window.clearInterval(minuteRefresh);
      document.removeEventListener('visibilitychange', resumeRefresh);
      window.removeEventListener('online', synchronizeShift);
      permissionStatus?.removeEventListener('change', permissionChanged);
      stopWatch();
    };
  }, [isFieldStaff]);

  if (!isFieldStaff || state === 'off_duty') return null;
  if (state === 'active') {
    return <div role="status" className="fixed bottom-3 left-3 z-[70] flex max-w-[calc(100vw-1.5rem)] items-center gap-2 rounded-full border border-emerald-400/50 bg-emerald-950/90 px-3 py-2 text-xs font-semibold text-emerald-100 shadow-lg backdrop-blur lg:left-[17rem]">
      <MapPin className="h-4 w-4 animate-pulse" />
      <span>Work location sharing active - 6 AM to 6 PM</span>
    </div>;
  }

  return <div className="fixed inset-0 z-[200] grid place-items-center bg-slate-950/95 p-4 backdrop-blur" role="alertdialog" aria-modal="true" aria-labelledby="location-required-title">
    <section className="w-full max-w-md rounded-2xl border border-amber-400/40 bg-slate-900 p-6 text-center text-white shadow-2xl">
      <ShieldAlert className="mx-auto h-12 w-12 text-amber-300" />
      <h1 id="location-required-title" className="mt-4 text-xl font-bold">Work location is required</h1>
      <p className="mt-2 text-sm leading-6 text-slate-200">Collector and technician access requires a current precise location from 6:00 AM to 6:00 PM Asia/Manila. Enable location for SolarNet in this device's browser or app settings.</p>
      <p className="mt-3 text-xs text-slate-400">The application remains locked until a real GPS position is accepted. A website cannot change the phone's Always Allow setting for you.</p>
      <button type="button" onClick={() => { setState('starting'); requestFreshPosition(true); }} className="mt-5 w-full rounded-xl bg-amber-400 px-4 py-3 font-bold text-slate-950 hover:bg-amber-300 disabled:opacity-60" disabled={state === 'starting'}>
        {state === 'starting' ? 'Checking precise location...' : 'Allow location and continue'}
      </button>
      <button type="button" onClick={() => void logout()} className="mt-3 text-sm font-semibold text-slate-300 underline underline-offset-4 hover:text-white">Sign out</button>
    </section>
  </div>;
}
