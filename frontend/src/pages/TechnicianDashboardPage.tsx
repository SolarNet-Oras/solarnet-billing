import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle, ClipboardList, MapPin, Navigation, Search, UserPlus, Wrench } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';

type Client = {
  id: string;
  account_number: string;
  full_name: string;
  address?: string;
  notes?: string | null;
  status: string;
  gps_coordinates: { latitude: number; longitude: number };
};

type TicketHistory = {
  id: string;
  action: string;
  previous_status?: string | null;
  new_status?: string | null;
  notes?: string | null;
  created_at: string;
  user?: { name: string } | null;
};

type Ticket = {
  id: string;
  ticket_number: string;
  subject: string;
  description: string;
  status: string;
  workflow_status: string;
  ticket_type: 'repair' | 'installation' | 'other';
  category: string;
  priority: string;
  assigned_to?: string | null;
  assigned_technician?: { id: string; name: string } | null;
  customer?: Client;
  client_notes?: string | null;
  installation_mac?: string | null;
  installation_notes?: string | null;
  return_reason?: string | null;
  resolution_notes?: string | null;
  repair_details?: Record<string, string> | null;
  created_at: string;
  updated_at: string;
  histories?: TicketHistory[];
};

type Monitor = {
  customer_id: string;
  full_name: string;
  customer_status: string;
  ip_address: string;
  lease_status: string;
  queue_name: string;
  queue_found: boolean;
  traffic: { download_bps: number | null; upload_bps: number | null };
  service_plan?: { name: string; download_speed: number; upload_speed: number };
};

type RepairForm = {
  resolution_notes: string;
  findings: string;
  actions_performed: string;
  equipment_replaced: string;
  materials_used: string;
};

type ServicePlan = {
  id: string;
  name: string;
  price: number;
  download_speed: number;
  upload_speed: number;
};

type RegistrationForm = {
  full_name: string;
  address: string;
  contact_number: string;
  email: string;
  installation_date: string;
  service_plan_id: string;
  mac_address: string;
  gps_coordinates: { latitude: number; longitude: number } | null;
  location_accuracy_meters: number | null;
};

type RegistrationMatch = {
  mac_address: string;
  ip_address: string;
  comment?: string | null;
  router?: string | null;
  score: number;
  match_type: 'exact' | 'fuzzy_90_plus' | 'last_character_correction';
};

type PendingRegistration = {
  id: string;
  account_number: string;
  full_name: string;
  address?: string;
  contact_number?: string;
  email?: string | null;
  mac_address: string;
  installation_date: string;
  mac_binding_status: 'waiting_for_match' | string;
  service_plan?: { name: string; download_speed: number; upload_speed: number; price: number } | null;
  created_at: string;
};

const emptyRepairForm: RepairForm = {
  resolution_notes: '', findings: '', actions_performed: '', equipment_replaced: '', materials_used: '',
};

const emptyRegistrationForm: RegistrationForm = {
  full_name: '', address: '', contact_number: '', email: '', installation_date: '', service_plan_id: '', mac_address: '', gps_coordinates: null, location_accuracy_meters: null,
};

const rate = (value: number | null) => value === null ? '—' : value >= 1_000_000 ? `${(value / 1_000_000).toFixed(1)} Mbps` : `${(value / 1_000).toFixed(1)} Kbps`;
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const isCompletedTicket = (ticket: Ticket) => ['closed', 'registered'].includes(ticket.workflow_status);

export default function TechnicianDashboardPage() {
  const [tab, setTab] = useState<'map' | 'traffic' | 'tickets' | 'application' | 'register'>('map');
  const [clients, setClients] = useState<Client[]>([]);
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [selected, setSelected] = useState<Client | null>(null);
  const [query, setQuery] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [monitor, setMonitor] = useState<Monitor[]>([]);
  const [checkedAt, setCheckedAt] = useState('');
  const [monitorQuery, setMonitorQuery] = useState('');
  const [ticketQuery, setTicketQuery] = useState('');
  const [ticketFilter, setTicketFilter] = useState('all');
  const [installationForms, setInstallationForms] = useState<Record<string, { mac_address: string; notes: string }>>({});
  const [repairForms, setRepairForms] = useState<Record<string, RepairForm>>({});
  const [ticketBusy, setTicketBusy] = useState<string | null>(null);
  const [servicePlans, setServicePlans] = useState<ServicePlan[]>([]);
  const [registrationForm, setRegistrationForm] = useState<RegistrationForm>(emptyRegistrationForm);
  const [registrationMatch, setRegistrationMatch] = useState<RegistrationMatch | null>(null);
  const [registrationBusy, setRegistrationBusy] = useState(false);
  const [pendingRegistrations, setPendingRegistrations] = useState<PendingRegistration[]>([]);
  const [registrationLocationBusy, setRegistrationLocationBusy] = useState(false);
  const [registrationLocationMessage, setRegistrationLocationMessage] = useState('');

  const loadWorkspace = async (): Promise<void> => {
    const response = await api.get('/dashboard/technician');
    setClients(response.data.clients || []);
    setTickets(response.data.tickets || []);
    setServicePlans(response.data.service_plans || []);
    setPendingRegistrations(response.data.pending_registrations || []);
    setSelected((current) => current || response.data.clients?.[0] || null);
  };

  useEffect(() => {
    void loadWorkspace().catch((requestError) => setError(requestError.response?.data?.message || 'Could not load field work.'));
  }, []);

  useEffect(() => {
    if (tab !== 'traffic') return;
    const load = () => {
      void api.get('/dashboard/technician-monitor').then((response) => {
        setMonitor(response.data.data || []);
        setCheckedAt(new Date().toLocaleTimeString('en-PH'));
      });
    };
    load();
    const timer = window.setInterval(load, 5000);
    return () => window.clearInterval(timer);
  }, [tab]);

  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return term ? clients.filter((client) => `${client.full_name} ${client.account_number} ${client.address || ''}`.toLowerCase().includes(term)) : clients;
  }, [clients, query]);

  const visibleMonitor = useMemo(() => {
    const term = monitorQuery.trim().toLowerCase();
    return term ? monitor.filter((item) => `${item.full_name} ${item.customer_id} ${item.ip_address} ${item.queue_name}`.toLowerCase().includes(term)) : monitor;
  }, [monitor, monitorQuery]);

  const visibleTickets = useMemo(() => {
    const term = ticketQuery.trim().toLowerCase();
    return tickets.filter((ticket) => {
      if (isCompletedTicket(ticket)) return false;
      const matchesText = !term || `${ticket.ticket_number} ${ticket.subject} ${ticket.customer?.full_name || ''} ${ticket.customer?.address || ''}`.toLowerCase().includes(term);
      const matchesFilter = ticketFilter === 'all'
        || (ticketFilter === 'pending' && ['open', 'unclaimed', 'claimed'].includes(ticket.workflow_status))
        || ticket.ticket_type === ticketFilter
        || ticket.workflow_status === ticketFilter;
      return matchesText && matchesFilter;
    });
  }, [tickets, ticketFilter, ticketQuery]);

  const completedTickets = useMemo(() => {
    const term = ticketQuery.trim().toLowerCase();
    return tickets.filter((ticket) => {
      if (!isCompletedTicket(ticket)) return false;
      const matchesText = !term || `${ticket.ticket_number} ${ticket.subject} ${ticket.customer?.full_name || ''} ${ticket.customer?.address || ''}`.toLowerCase().includes(term);
      const matchesFilter = ticketFilter === 'all' || ticket.workflow_status === ticketFilter;
      return matchesText && matchesFilter;
    });
  }, [tickets, ticketFilter, ticketQuery]);

  const mapUrl = selected ? `https://www.google.com/maps?q=${selected.gps_coordinates.latitude},${selected.gps_coordinates.longitude}&output=embed` : '';
  const directions = selected ? `https://www.google.com/maps/dir/?api=1&destination=${selected.gps_coordinates.latitude},${selected.gps_coordinates.longitude}` : '#';

  const runTicketAction = async (ticket: Ticket, endpoint: string, payload: Record<string, unknown> = {}, success = 'Ticket updated.'): Promise<void> => {
    setTicketBusy(ticket.id);
    setError('');
    setNotice('');
    try {
      await api.post(`/tickets/${ticket.id}/${endpoint}`, payload);
      await loadWorkspace();
      setNotice(success);
    } catch (requestError: any) {
      const errors = requestError.response?.data?.errors;
      const firstError = errors ? Object.values(errors).flat()[0] : null;
      setError(String(firstError || requestError.response?.data?.message || 'Could not update this ticket.'));
    } finally {
      setTicketBusy(null);
    }
  };

  const submitInstallation = (ticket: Ticket): Promise<void> => {
    const form = installationForms[ticket.id] || { mac_address: '', notes: '' };
    return runTicketAction(ticket, 'submit-installation', form, 'Installation sent to the administrator for validation.');
  };

  const resolveRepair = (ticket: Ticket): Promise<void> => {
    const form = repairForms[ticket.id] || emptyRepairForm;
    return runTicketAction(ticket, 'repair/resolve', form, 'Repair marked resolved. Close it after the final check.');
  };

  const registerClient = async (confirmFuzzyMatch = false): Promise<void> => {
    setRegistrationBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await api.post('/dashboard/technician/register-client', {
        ...registrationForm,
        confirm_fuzzy_match: confirmFuzzyMatch,
      });
      await loadWorkspace();
      const correction = response.data?.mac_correction;
      setRegistrationForm(emptyRegistrationForm);
      setRegistrationMatch(null);
      setNotice(correction?.applied
        ? `Client registered. SolarNet corrected the final MAC character and used the current MikroTik MAC: ${correction.mikrotik_mac}.`
        : response.data?.binding_status === 'waiting_for_match'
        ? 'Registration saved. Waiting for the exact MAC to appear in a current bound DHCP lease; it will bind automatically after the next sync.'
        : 'Client registered and matched to the current MikroTik DHCP lease.');
    } catch (requestError: any) {
      const body = requestError.response?.data;
      if (requestError.response?.status === 409 && body?.requires_confirmation && body.match) {
        setRegistrationMatch(body.match as RegistrationMatch);
      } else {
        const errors = body?.errors;
        const firstError = errors ? Object.values(errors).flat()[0] : null;
        setError(String(firstError || body?.message || 'Could not register this client.'));
      }
    } finally {
      setRegistrationBusy(false);
    }
  };

  const captureRegistrationLocation = (): void => {
    if (!navigator.geolocation) {
      setRegistrationLocationMessage('This device cannot provide GPS location. Registration requires coordinates.');
      return;
    }

    setRegistrationLocationBusy(true);
    setRegistrationLocationMessage('Stay at the client installation point while GPS is captured…');
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setRegistrationForm((current) => ({
          ...current,
          gps_coordinates: {
            latitude: Number(position.coords.latitude.toFixed(6)),
            longitude: Number(position.coords.longitude.toFixed(6)),
          },
          location_accuracy_meters: Number(position.coords.accuracy.toFixed(1)),
        }));
        setRegistrationLocationMessage(`Captured ${position.coords.latitude.toFixed(6)}, ${position.coords.longitude.toFixed(6)} · accuracy approximately ${Math.round(position.coords.accuracy)} m.`);
        setRegistrationLocationBusy(false);
      },
      () => {
        setRegistrationLocationMessage('Location permission was not granted or GPS could not be read. Capture coordinates before registering.');
        setRegistrationLocationBusy(false);
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  };

  return <DashboardLayout><main className="mx-auto max-w-7xl space-y-5 p-4 md:p-6">
    <header><p className="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Field workspace</p><h1 className="mt-1 text-2xl font-bold">Technician dashboard</h1><p className="mt-1 text-sm text-muted-foreground">Client map and live network reference, plus every available or assigned field ticket.</p></header>
    <div className="flex flex-wrap gap-2 border-b">
      <button onClick={() => setTab('map')} className={`px-4 py-2 text-sm font-semibold ${tab === 'map' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground'}`}><MapPin className="mr-1 inline h-4 w-4" />Client map</button>
      <button onClick={() => setTab('traffic')} className={`px-4 py-2 text-sm font-semibold ${tab === 'traffic' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground'}`}><Wrench className="mr-1 inline h-4 w-4" />Live traffic</button>
      <button onClick={() => setTab('tickets')} className={`px-4 py-2 text-sm font-semibold ${tab === 'tickets' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground'}`}><ClipboardList className="mr-1 inline h-4 w-4" />My tickets</button>
      <button onClick={() => setTab('register')} className={`px-4 py-2 text-sm font-semibold ${tab === 'register' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground'}`}><UserPlus className="mr-1 inline h-4 w-4" />Register client</button>
      <button onClick={() => setTab('application')} className={`px-4 py-2 text-sm font-semibold ${tab === 'application' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground'}`}><UserPlus className="mr-1 inline h-4 w-4" />New client application</button>
    </div>
    {error && <p className="rounded-xl bg-destructive/10 p-3 text-sm text-destructive">{error}</p>}
    {notice && <p className="rounded-xl bg-emerald-500/10 p-3 text-sm text-emerald-700">{notice}</p>}

    {tab === 'map' && <section className="grid gap-5 lg:grid-cols-[340px_1fr]"><article className="rounded-2xl border bg-card"><div className="border-b p-4"><h2 className="font-semibold">Client location map</h2><p className="mt-1 text-xs text-muted-foreground">Search a client, select their pin, then open Google Maps for directions.</p><div className="relative mt-4"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search client name or account" className="w-full rounded-lg border bg-background py-2 pl-9 pr-3 text-sm" /></div></div><div className="max-h-[540px] overflow-y-auto">{visible.map((client) => <button key={client.id} onClick={() => setSelected(client)} className={`w-full border-b p-4 text-left hover:bg-muted/50 ${selected?.id === client.id ? 'bg-primary/5' : ''}`}><p className="font-semibold">{client.full_name}</p><p className="text-xs text-muted-foreground">{client.account_number} · {client.address || 'To be updated'}</p><span className="mt-2 inline-flex rounded-full bg-muted px-2 py-1 text-[11px] font-semibold capitalize">{client.status}</span></button>)}{!visible.length && <p className="p-5 text-sm text-muted-foreground">No clients with saved coordinates found.</p>}</div></article><article className="overflow-hidden rounded-2xl border bg-card">{selected ? <><iframe title={`Google map for ${selected.full_name}`} src={mapUrl} className="h-[440px] w-full border-0" loading="lazy" /><div className="flex items-center justify-between gap-3 p-4"><div><p className="font-semibold">Selected: {selected.full_name}</p><p className="text-sm text-muted-foreground">{selected.gps_coordinates.latitude}, {selected.gps_coordinates.longitude}</p></div><a href={directions} target="_blank" rel="noreferrer" className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground"><Navigation className="mr-1 inline h-4 w-4" />Open Google Maps</a></div></> : <p className="p-8 text-sm text-muted-foreground">Select a client to open their location.</p>}</article></section>}

    {tab === 'tickets' && <section className="space-y-4">
      <div className="rounded-2xl border bg-card p-4"><div className="flex flex-col gap-3 md:flex-row"><label className="relative flex-1"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" /><input value={ticketQuery} onChange={(event) => setTicketQuery(event.target.value)} placeholder="Search ticket, client, or address" className="w-full rounded-lg border bg-background py-2 pl-9 pr-3 text-sm" /></label><select value={ticketFilter} onChange={(event) => setTicketFilter(event.target.value)} className="rounded-lg border bg-background px-3 py-2 text-sm"><option value="all">All tickets</option><option value="repair">Repair</option><option value="installation">New installation</option><option value="pending">Pending</option><option value="in_progress">In progress</option><option value="resolved">Resolved</option><option value="waiting_admin_approval">Waiting admin</option><option value="returned_for_correction">Returned</option><option value="registered">Registered</option><option value="closed">Closed</option></select></div></div>
      {visibleTickets.map((ticket) => {
        const installForm = installationForms[ticket.id] || { mac_address: ticket.installation_mac || '', notes: ticket.installation_notes || '' };
        const repairForm = repairForms[ticket.id] || emptyRepairForm;
        const busy = ticketBusy === ticket.id;
        return <article key={ticket.id} className="rounded-2xl border bg-card p-5 shadow-sm">
          <div className="flex flex-col justify-between gap-3 md:flex-row"><div><div className="flex flex-wrap items-center gap-2"><p className="text-xs font-bold text-primary">{ticket.ticket_number}</p><span className="rounded-full bg-muted px-2 py-1 text-[11px] font-semibold">{ticket.ticket_type === 'installation' ? 'NEW INSTALLATION' : ticket.ticket_type.toUpperCase()}</span><span className="rounded-full bg-primary/10 px-2 py-1 text-[11px] font-semibold text-primary">{label(ticket.workflow_status)}</span></div><h2 className="mt-2 font-semibold">{ticket.subject}</h2></div><div className="text-xs text-muted-foreground md:text-right"><p>Created {new Date(ticket.created_at).toLocaleString('en-PH')}</p><p>Updated {new Date(ticket.updated_at).toLocaleString('en-PH')}</p></div></div>
          <p className="mt-3 text-sm text-muted-foreground">{ticket.description}</p>
          <div className="mt-4 grid gap-2 rounded-xl bg-muted/40 p-4 text-sm md:grid-cols-3"><p><strong>Client:</strong> {ticket.customer?.full_name || 'No client'}</p><p><strong>Address:</strong> {ticket.customer?.address || 'No address'}</p><p><strong>Priority:</strong> <span className="capitalize">{ticket.priority}</span></p><p><strong>Technician:</strong> {ticket.assigned_technician?.name || 'Available to claim'}</p><p><strong>Type:</strong> {label(ticket.ticket_type)}</p><p><strong>Status:</strong> {label(ticket.workflow_status)}</p></div>

          <div className="mt-4 rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-blue-950"><h3 className="font-semibold">Client signup notes</h3><p className="mt-1 text-xs text-blue-900/70">Reference information entered when this client applied.</p><p className="mt-2 whitespace-pre-line text-sm">{ticket.client_notes || ticket.customer?.notes || 'No client signup notes recorded.'}</p></div>

          {ticket.ticket_type === 'repair' && ticket.workflow_status === 'open' && <button disabled={busy} onClick={() => void runTicketAction(ticket, 'repair/mark-in', {}, 'Repair claimed and marked in progress.')} className="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60">{busy ? 'Marking in…' : 'MARK IN'}</button>}
          {ticket.ticket_type === 'repair' && ticket.workflow_status === 'in_progress' && <div className="mt-4 grid gap-3 rounded-xl border bg-muted/20 p-4"><h3 className="font-semibold">Repair completion report</h3><label className="text-sm font-medium">Resolution notes *<textarea rows={3} value={repairForm.resolution_notes} onChange={(event) => setRepairForms((current) => ({ ...current, [ticket.id]: { ...repairForm, resolution_notes: event.target.value } }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" placeholder="Describe the completed repair and result" /></label><div className="grid gap-3 md:grid-cols-2"><label className="text-sm font-medium">Findings<textarea rows={2} value={repairForm.findings} onChange={(event) => setRepairForms((current) => ({ ...current, [ticket.id]: { ...repairForm, findings: event.target.value } }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label><label className="text-sm font-medium">Actions performed<textarea rows={2} value={repairForm.actions_performed} onChange={(event) => setRepairForms((current) => ({ ...current, [ticket.id]: { ...repairForm, actions_performed: event.target.value } }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label><label className="text-sm font-medium">Equipment replaced<textarea rows={2} value={repairForm.equipment_replaced} onChange={(event) => setRepairForms((current) => ({ ...current, [ticket.id]: { ...repairForm, equipment_replaced: event.target.value } }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label><label className="text-sm font-medium">Materials used<textarea rows={2} value={repairForm.materials_used} onChange={(event) => setRepairForms((current) => ({ ...current, [ticket.id]: { ...repairForm, materials_used: event.target.value } }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label></div><button disabled={busy || repairForm.resolution_notes.trim().length < 5} onClick={() => void resolveRepair(ticket)} className="justify-self-start rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{busy ? 'Saving…' : 'MARK RESOLVED'}</button></div>}
          {ticket.ticket_type === 'repair' && ticket.workflow_status === 'resolved' && <div className="mt-4"><p className="mb-3 rounded-lg bg-emerald-500/10 p-3 text-sm text-emerald-700"><CheckCircle className="mr-1 inline h-4 w-4" />Resolution: {ticket.resolution_notes}</p><button disabled={busy} onClick={() => { const notes = window.prompt('Optional final closure note:') ?? undefined; if (notes !== undefined) void runTicketAction(ticket, 'repair/close', { notes }, 'Repair ticket closed.'); }} className="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">CLOSE TICKET</button></div>}

          {ticket.ticket_type === 'installation' && ticket.workflow_status === 'unclaimed' && <button disabled={busy} onClick={() => void runTicketAction(ticket, 'claim-installation', {}, 'Installation claimed. Enter the MAC after completing the work.')} className="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60">{busy ? 'Claiming…' : 'CLAIM INSTALLATION'}</button>}
          {ticket.ticket_type === 'installation' && ['claimed', 'returned_for_correction'].includes(ticket.workflow_status) && <div className="mt-4 grid gap-3 rounded-xl border bg-muted/20 p-4">{ticket.return_reason && <p className="rounded-lg bg-amber-500/10 p-3 text-sm text-amber-800"><strong>Administrator correction:</strong> {ticket.return_reason}</p>}<label className="text-sm font-medium">ONU/router MAC address *<input value={installForm.mac_address} onChange={(event) => setInstallationForms((current) => ({ ...current, [ticket.id]: { ...installForm, mac_address: event.target.value } }))} placeholder="AA:BB:CC:DD:EE:FF" className="mt-1 w-full rounded-lg border bg-background px-3 py-2 font-mono text-sm" /></label><label className="text-sm font-medium">Installation notes *<textarea value={installForm.notes} onChange={(event) => setInstallationForms((current) => ({ ...current, [ticket.id]: { ...installForm, notes: event.target.value } }))} rows={3} className="mt-1 w-full rounded-lg border bg-background px-3 py-2 text-sm" placeholder="Installed equipment, signal/check result, and relevant details" /></label><button disabled={busy || !installForm.mac_address.trim() || !installForm.notes.trim()} onClick={() => void submitInstallation(ticket)} className="justify-self-start rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{busy ? 'Submitting…' : 'DONE — SUBMIT FOR ADMIN REVIEW'}</button></div>}
          {ticket.ticket_type === 'installation' && ticket.workflow_status === 'waiting_admin_approval' && <p className="mt-4 rounded-lg bg-blue-500/10 p-3 text-sm text-blue-700"><strong>Waiting for administrator approval.</strong> Submitted MAC: <span className="font-mono">{ticket.installation_mac}</span></p>}
          {['closed', 'registered'].includes(ticket.workflow_status) && <p className="mt-4 rounded-lg bg-emerald-500/10 p-3 text-sm text-emerald-700"><CheckCircle className="mr-1 inline h-4 w-4" />This ticket is complete and remains visible in your history.</p>}

          {!!ticket.histories?.length && <details className="mt-4 border-t pt-3"><summary className="cursor-pointer text-sm font-semibold">Audit history ({ticket.histories.length})</summary><ol className="mt-3 space-y-2">{ticket.histories.map((history) => <li key={history.id} className="border-l-2 border-primary/30 pl-3 text-xs"><p className="font-semibold">{label(history.action)} · {history.user?.name || 'System'}</p><p className="text-muted-foreground">{new Date(history.created_at).toLocaleString('en-PH')}{history.new_status ? ` · ${label(history.new_status)}` : ''}</p>{history.notes && <p className="mt-1">{history.notes}</p>}</li>)}</ol></details>}
        </article>;
      })}
      {!visibleTickets.length && !completedTickets.length && <p className="rounded-2xl border bg-card p-8 text-center text-sm text-muted-foreground">No active ticket matches this search and filter.</p>}
      {!!completedTickets.length && <article className="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-semibold text-emerald-950">Completed ticket history</h2><p className="mt-1 text-sm text-emerald-900/80">Completed tickets are archived here and removed from the active work list.</p></div><span className="rounded-full bg-emerald-200 px-2.5 py-1 text-xs font-bold text-emerald-950">{completedTickets.length}</span></div><div className="mt-4 space-y-2">{completedTickets.map((ticket) => <details key={ticket.id} className="rounded-xl border border-emerald-200 bg-background/80 p-3"><summary className="cursor-pointer list-none"><div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-xs font-bold text-primary">{ticket.ticket_number}</p><p className="font-semibold">{ticket.subject}</p><p className="text-xs text-muted-foreground">{ticket.customer?.full_name || 'No client'} · {new Date(ticket.updated_at).toLocaleString('en-PH')}</p></div><span className="inline-flex w-fit rounded-full bg-emerald-100 px-2 py-1 text-[11px] font-bold text-emerald-800">{label(ticket.workflow_status)}</span></div></summary><div className="mt-3 border-t border-emerald-100 pt-3 text-sm"><p><strong>Address:</strong> {ticket.customer?.address || 'No address'}</p>{ticket.resolution_notes && <p className="mt-1 whitespace-pre-line"><strong>Resolution:</strong> {ticket.resolution_notes}</p>}{ticket.installation_notes && <p className="mt-1 whitespace-pre-line"><strong>Installation notes:</strong> {ticket.installation_notes}</p>}{ticket.histories?.length ? <ol className="mt-3 space-y-2 border-l-2 border-emerald-200 pl-3">{ticket.histories.map((history) => <li key={history.id} className="text-xs"><p className="font-semibold">{label(history.action)} · {history.user?.name || 'System'}</p><p className="text-muted-foreground">{new Date(history.created_at).toLocaleString('en-PH')}{history.new_status ? ` · ${label(history.new_status)}` : ''}</p>{history.notes && <p className="mt-1">{history.notes}</p>}</li>)}</ol> : <p className="mt-2 text-xs text-muted-foreground">No audit history recorded.</p>}</div></details>)}</div></article>}
    </section>}

    {tab === 'application' && <section className="rounded-2xl border bg-card p-6"><UserPlus className="h-8 w-8 text-primary" /><h2 className="mt-3 text-lg font-semibold">New client application</h2><p className="mt-2 max-w-xl text-sm text-muted-foreground">Record a prospective client’s details and exact installation location. The application remains pending for office approval.</p><Link to="/signup" className="mt-5 inline-flex rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground">Start new application</Link></section>}

    {tab === 'traffic' && <section className="overflow-x-auto rounded-2xl border bg-card"><div className="flex flex-col gap-3 border-b p-4 md:flex-row md:items-center md:justify-between"><div><h2 className="font-semibold">Live queue & lease monitor</h2><p className="mt-1 text-xs text-muted-foreground">Read-only MikroTik Simple Queue traffic for all registered clients · last checked {checkedAt || '—'}.</p></div><label className="relative block md:w-80"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" /><input value={monitorQuery} onChange={(event) => setMonitorQuery(event.target.value)} placeholder="Search client, IP, or queue" className="w-full rounded-lg border bg-background py-2 pl-9 pr-3 text-sm" /></label></div><table className="w-full min-w-[820px] text-left text-sm"><thead className="bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="p-4">Client</th><th className="p-4">Lease</th><th className="p-4">Queue</th><th className="p-4">Plan</th><th className="p-4">Traffic</th><th className="p-4">Status</th></tr></thead><tbody>{visibleMonitor.map((item) => <tr key={item.customer_id} className="border-t"><td className="p-4 font-medium">{item.full_name}</td><td className="p-4"><p>{item.ip_address}</p><small className="text-muted-foreground">{item.lease_status}</small></td><td className="p-4"><p className="max-w-40 truncate">{item.queue_name}</p><small className="text-muted-foreground">{item.queue_found ? 'Queue found' : 'Queue unavailable'}</small></td><td className="p-4">{item.service_plan ? `${item.service_plan.name} · ${item.service_plan.download_speed}/${item.service_plan.upload_speed} Mbps` : 'No plan'}</td><td className="p-4"><p className="text-sky-600">↓ {rate(item.traffic.download_bps)}</p><p className="text-rose-600">↑ {rate(item.traffic.upload_bps)}</p></td><td className="p-4 capitalize">{item.customer_status}</td></tr>)}{!visibleMonitor.length && <tr><td colSpan={6} className="p-8 text-center text-muted-foreground">No registered client matches this search.</td></tr>}</tbody></table></section>}
    {tab === 'register' && <section className="space-y-5"><article className="rounded-2xl border bg-card p-6"><div className="flex items-start gap-3"><UserPlus className="mt-1 h-8 w-8 text-primary" /><div><h2 className="text-lg font-semibold">Add / register client</h2><p className="mt-1 max-w-2xl text-sm text-muted-foreground">Enter the installed ONU/router MAC and capture the client’s exact installation coordinates. GPS is required before registration. If the router has not shown the MAC yet, SolarNet saves the registration as <strong>Waiting for matching MAC</strong>. A unique current unregistered MikroTik lease with only its final MAC character different is corrected automatically; other 90%+ fuzzy matches still need your confirmation.</p></div></div><div className="mt-5 grid gap-4 md:grid-cols-2"><label className="text-sm font-medium">Client name *<input value={registrationForm.full_name} onChange={(event) => setRegistrationForm((current) => ({ ...current, full_name: event.target.value }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label><label className="text-sm font-medium">Address *<input value={registrationForm.address} onChange={(event) => setRegistrationForm((current) => ({ ...current, address: event.target.value }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label><label className="text-sm font-medium">Contact number *<input value={registrationForm.contact_number} onChange={(event) => setRegistrationForm((current) => ({ ...current, contact_number: event.target.value }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label><label className="text-sm font-medium">Email<input type="email" value={registrationForm.email} onChange={(event) => setRegistrationForm((current) => ({ ...current, email: event.target.value }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label><label className="text-sm font-medium">Installation date *<input type="date" value={registrationForm.installation_date} onChange={(event) => setRegistrationForm((current) => ({ ...current, installation_date: event.target.value }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label><label className="text-sm font-medium">Service plan *<select value={registrationForm.service_plan_id} onChange={(event) => setRegistrationForm((current) => ({ ...current, service_plan_id: event.target.value }))} className="mt-1 w-full rounded-lg border bg-background px-3 py-2"><option value="">Select plan</option>{servicePlans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name} · {plan.download_speed}/{plan.upload_speed} Mbps · ₱{Number(plan.price).toLocaleString('en-PH')}</option>)}</select></label><div className="md:col-span-2 rounded-xl border border-blue-200 bg-blue-50/60 p-4"><div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><p className="font-semibold text-blue-950"><MapPin className="mr-1 inline h-4 w-4" />Installation coordinates <span className="text-red-600">*</span></p><p className="mt-1 text-xs text-blue-900/80">You must be at the client’s exact installation point. Capture this device’s GPS; SolarNet stores one point and does not continuously track the technician.</p>{registrationForm.gps_coordinates && <p className="mt-2 font-mono text-xs text-blue-950">{registrationForm.gps_coordinates.latitude.toFixed(6)}, {registrationForm.gps_coordinates.longitude.toFixed(6)} · ±{Math.round(registrationForm.location_accuracy_meters || 0)} m</p>}</div><button type="button" onClick={captureRegistrationLocation} disabled={registrationLocationBusy} className="inline-flex shrink-0 items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60">{registrationLocationBusy ? 'Getting GPS…' : registrationForm.gps_coordinates ? 'Update coordinates' : 'Capture coordinates'}</button></div>{registrationForm.gps_coordinates && <a href={`https://www.google.com/maps/search/?api=1&query=${registrationForm.gps_coordinates.latitude},${registrationForm.gps_coordinates.longitude}`} target="_blank" rel="noreferrer" className="mt-3 inline-flex text-xs font-semibold text-blue-700 hover:underline">View captured point on Google Maps</a>}{registrationLocationMessage && <p className="mt-2 text-xs text-blue-900">{registrationLocationMessage}</p>}</div><label className="text-sm font-medium md:col-span-2">ONU/router MAC address *<input value={registrationForm.mac_address} onChange={(event) => { setRegistrationMatch(null); setRegistrationForm((current) => ({ ...current, mac_address: event.target.value })); }} placeholder="AA:BB:CC:DD:EE:FF" className="mt-1 w-full rounded-lg border bg-background px-3 py-2 font-mono" /><span className="mt-1 block text-xs text-muted-foreground">If one unique current unregistered MikroTik lease differs only in the final character, SolarNet automatically saves the router’s full MAC. Other fuzzy matches remain review-only.</span></label></div>{registrationMatch && <div className="mt-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"><p className="font-semibold">Confirm 90%+ MAC match before binding</p><p className="mt-1">Entered MAC: <span className="font-mono">{registrationForm.mac_address}</span></p><p>Lease MAC: <span className="font-mono">{registrationMatch.mac_address}</span> · {registrationMatch.score}% match</p><p>IP: {registrationMatch.ip_address} · Router: {registrationMatch.router || 'Unknown'}</p>{registrationMatch.comment && <p>Comment: {registrationMatch.comment}</p>}<button disabled={registrationBusy} onClick={() => void registerClient(true)} className="mt-3 rounded-lg bg-amber-600 px-4 py-2 font-semibold text-white disabled:opacity-60">{registrationBusy ? 'Binding…' : 'Confirm and bind this lease'}</button></div>}<div className="mt-5 flex flex-wrap gap-3"><button disabled={registrationBusy || !registrationForm.full_name.trim() || !registrationForm.address.trim() || !registrationForm.contact_number.trim() || !registrationForm.installation_date || !registrationForm.service_plan_id || !registrationForm.mac_address.trim() || !registrationForm.gps_coordinates} onClick={() => void registerClient(false)} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60">{registrationBusy ? 'Checking lease…' : registrationForm.gps_coordinates ? 'Register client' : 'Capture coordinates first'}</button><button type="button" onClick={() => { setRegistrationForm(emptyRegistrationForm); setRegistrationMatch(null); setError(''); setRegistrationLocationMessage(''); }} className="rounded-lg border px-4 py-2 text-sm font-semibold">Clear</button></div></article>{pendingRegistrations.length > 0 && <article className="rounded-2xl border border-amber-300/70 bg-amber-50/60 p-5"><div className="flex items-center justify-between gap-3"><div><h3 className="font-semibold text-amber-950">Waiting for matching MAC</h3><p className="mt-1 text-sm text-amber-900/80">These registrations are saved and will bind automatically after an exact MAC appears in an unregistered current bound DHCP lease.</p></div><span className="rounded-full bg-amber-200 px-2.5 py-1 text-xs font-bold text-amber-950">{pendingRegistrations.length}</span></div><div className="mt-4 space-y-3">{pendingRegistrations.map((pending) => <div key={pending.id} className="rounded-xl border border-amber-200 bg-background/80 p-4"><div className="flex flex-col justify-between gap-2 sm:flex-row"><div><p className="font-semibold">{pending.full_name}</p><p className="text-xs text-muted-foreground">{pending.account_number} · {pending.address || 'No address'} · {pending.service_plan?.name || 'Plan pending'}</p></div><span className="inline-flex h-fit rounded-full bg-amber-100 px-2 py-1 text-[11px] font-bold text-amber-900">WAITING FOR MATCHING MAC</span></div><p className="mt-2 font-mono text-sm">{pending.mac_address}</p><p className="mt-1 text-xs text-muted-foreground">Saved {new Date(pending.created_at).toLocaleString('en-PH')} · registration remains pending until an exact DHCP match.</p></div>)}</div></article>}</section>}
  </main></DashboardLayout>;
}
