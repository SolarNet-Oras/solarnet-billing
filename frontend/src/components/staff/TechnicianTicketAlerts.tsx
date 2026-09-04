import { useEffect, useMemo, useRef, useState } from 'react';
import { BellRing, ExternalLink, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

type TicketAlert = {
  id: string;
  ticket_number: string;
  subject: string;
  assigned_to?: string | null;
  workflow_status: string;
  created_at: string;
  customer?: { full_name?: string; address?: string } | null;
};

const STORAGE_KEY = 'solarnet:technician-ticket-alerts';

export default function TechnicianTicketAlerts() {
  const { user } = useAuth();
  const roles = useMemo(() => [user?.role, ...(user?.roles || []).map((role) => typeof role === 'string' ? role : role.name)].filter(Boolean), [user]);
  const isTechnician = roles.includes('technician');
  const [alert, setAlert] = useState<TicketAlert | null>(null);
  const initialized = useRef(false);

  useEffect(() => {
    if (!isTechnician || !user) return;
    let stopped = false;

    const poll = async () => {
      try {
        const response = await api.get('/dashboard/technician');
        const tickets: TicketAlert[] = response.data?.tickets || [];
        const relevant = tickets.filter((ticket) => ticket.assigned_to === user.id || !ticket.assigned_to);
        const saved: string[] = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
        const seen = new Set(saved);

        if (!initialized.current) {
          relevant.forEach((ticket) => seen.add(`${ticket.id}:${ticket.assigned_to || 'available'}`));
          initialized.current = true;
        } else {
          const incoming = relevant.find((ticket) => !seen.has(`${ticket.id}:${ticket.assigned_to || 'available'}`));
          if (incoming && !stopped) {
            setAlert(incoming);
            if ('Notification' in window && Notification.permission === 'granted') {
              const registration = await navigator.serviceWorker?.ready;
              await registration?.showNotification(`New ticket ${incoming.ticket_number}`, {
                body: `${incoming.customer?.full_name || 'Customer'} — ${incoming.subject}`,
                icon: '/solarnet-company-logo-192.png',
                badge: '/solarnet-company-logo-192.png',
                tag: `technician-ticket-${incoming.id}`,
                renotify: true,
                requireInteraction: true,
                vibrate: [200, 100, 200],
                data: { url: '/dashboard', type: 'TECHNICIAN_TICKET' },
              });
            }
          }
        }

        relevant.forEach((ticket) => seen.add(`${ticket.id}:${ticket.assigned_to || 'available'}`));
        localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(seen).slice(-500)));
      } catch {
        // The normal technician dashboard remains the fallback during a brief network outage.
      }
    };

    void poll();
    const timer = window.setInterval(() => void poll(), 20_000);
    return () => { stopped = true; window.clearInterval(timer); };
  }, [isTechnician, user]);

  if (!isTechnician || !alert) return null;

  const enableNotifications = async () => {
    if ('Notification' in window) await Notification.requestPermission();
  };

  return (
    <aside role="alertdialog" aria-live="assertive" className="fixed bottom-5 right-4 z-[100] w-[calc(100vw-2rem)] max-w-sm animate-in slide-in-from-bottom-5 rounded-2xl border border-blue-400/50 bg-slate-950 p-4 text-white shadow-2xl shadow-blue-500/30">
      <button type="button" onClick={() => setAlert(null)} aria-label="Dismiss ticket alert" className="absolute right-3 top-3 rounded-lg p-1 text-slate-300 hover:bg-white/10 hover:text-white"><X className="h-4 w-4" /></button>
      <div className="flex gap-3 pr-7"><span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-500/20 text-blue-300"><BellRing className="h-6 w-6 animate-pulse" /></span><div><p className="text-xs font-bold uppercase tracking-widest text-blue-300">New technician ticket</p><h2 className="mt-1 font-bold">{alert.ticket_number}</h2><p className="mt-1 text-sm text-slate-200">{alert.customer?.full_name || 'Customer'} · {alert.subject}</p>{alert.customer?.address && <p className="mt-1 text-xs text-slate-400">{alert.customer.address}</p>}</div></div>
      <div className="mt-4 flex flex-wrap gap-2"><Link to="/dashboard" onClick={() => setAlert(null)} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-500 px-3 py-2 text-sm font-bold hover:bg-blue-400">Open ticket <ExternalLink className="h-4 w-4" /></Link>{'Notification' in window && Notification.permission !== 'granted' && <button type="button" onClick={enableNotifications} className="rounded-lg border border-slate-600 px-3 py-2 text-sm font-semibold hover:bg-white/10">Enable phone alerts</button>}</div>
    </aside>
  );
}
