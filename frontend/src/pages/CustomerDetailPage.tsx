import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, BellRing, MapPin, Pencil, ReceiptText, Router, ShieldCheck, Wallet } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import type { Customer } from '@/types/api';
import { formatPHP } from '@/lib/currency';
import { useAuth } from '@/hooks/useAuth';

type Detail = {
  data: Customer;
  invoices: Array<{ id: string; invoice_number: string; issue_date: string; due_date: string; total: number; paid_amount: number; balance: number; status: string }>;
  payments: Array<{ id: string; amount: number; payment_method: string; payment_date: string; reference?: string; transaction_id?: string; invoice?: { invoice_number: string } }>;
  location_events: Array<{ id: string; source: string; action: string; accuracy_meters?: number; created_at: string }>;
  dhcp_lease: { ip_address: string; mac_address: string; hostname?: string | null; status: string; is_current: boolean; last_seen_at?: string | null; router?: { name: string } | null; match_source?: string | null; match_note?: string | null } | null;
  notification_logs: Array<{ id: string; notification_type: string; title: string; status: string; sent_at?: string | null; clicked_at?: string | null; created_at: string; subscription?: { platform?: string | null; browser?: string | null; revoked_at?: string | null } | null }>;
};

export default function CustomerDetailPage(): React.JSX.Element {
  const { id } = useParams<{ id: string }>();
  const [detail, setDetail] = useState<Detail | null>(null);
  const [error, setError] = useState('');
  const [signature, setSignature] = useState<{ signature?: string; captured_at?: string; has_signature: boolean } | null>(null);
  const [signatureBusy, setSignatureBusy] = useState(false);
  const { user } = useAuth();
  const canManageSignature = user?.role === 'super_admin' || user?.role === 'admin' || user?.roles?.some((role) => typeof role === 'string' ? ['super_admin', 'admin'].includes(role) : ['super_admin', 'admin'].includes(role.name));

  useEffect(() => {
    if (!id) return;
    api.get<Detail>(`/customers/${id}`).then((response) => setDetail(response.data)).catch((requestError) => setError(requestError.response?.data?.message || 'Could not load this customer.'));
    if (canManageSignature) api.get(`/customers/${id}/cash-signature`).then((response) => setSignature(response.data)).catch(() => setSignature(null));
  }, [id, canManageSignature]);

  if (error) return <DashboardLayout><p className="p-6 text-red-600">{error}</p></DashboardLayout>;
  if (!detail) return <DashboardLayout><p className="p-6 text-muted-foreground">Loading customer profile...</p></DashboardLayout>;

  const customer = detail.data;
  const coordinates = customer.gps_coordinates;
  const mapUrl = coordinates ? `https://www.google.com/maps/dir/?api=1&destination=${coordinates.latitude},${coordinates.longitude}` : null;
  const leaseOnline = detail.dhcp_lease?.status === 'bound' && detail.dhcp_lease.is_current;
  const resetSignature = async (): Promise<void> => {
    if (!id || !window.confirm(`Reset ${customer.full_name}'s cash signature reference? The next client-signed cash payment will create the replacement reference.`)) return;
    setSignatureBusy(true);
    try {
      const response = await api.delete(`/customers/${id}/cash-signature`);
      window.alert(response.data?.message || 'Signature reference reset.');
      setSignature({ has_signature: false });
    } catch (requestError: any) {
      window.alert(requestError.response?.data?.message || 'Could not reset the signature reference.');
    } finally {
      setSignatureBusy(false);
    }
  };

  return <DashboardLayout><main className="mx-auto max-w-6xl space-y-6">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><Link to="/customers" className="inline-flex items-center gap-1 text-sm text-primary hover:underline"><ArrowLeft className="h-4 w-4" /> Customers</Link><h1 className="mt-2 text-3xl font-bold text-foreground">{customer.full_name}</h1><p className="mt-1 text-muted-foreground">Account {customer.account_number} · <span className="capitalize">{customer.status}</span></p></div>
      <div className="flex gap-2"><Link to={`/customers/${customer.id}/edit`} className="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted"><Pencil className="h-4 w-4" /> Edit</Link>{mapUrl && <a href={mapUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"><MapPin className="h-4 w-4" /> Navigate</a>}</div>
    </div>

    <section className="grid gap-5 md:grid-cols-3">
      <article className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Customer details</h2><dl className="mt-4 space-y-2 text-sm"><div><dt className="text-muted-foreground">Contact</dt><dd>{customer.contact_number || 'Not recorded'}<br />{customer.email || 'Not recorded'}</dd></div><div><dt className="text-muted-foreground">Address</dt><dd>{customer.address || 'Not recorded'}</dd></div><div><dt className="text-muted-foreground">Plan</dt><dd>{customer.service_plan?.name || 'No plan'} · {formatPHP(customer.monthly_fee)}</dd></div></dl></article>
      <article className="rounded-2xl border border-border bg-card p-5"><h2 className="flex items-center gap-2 font-semibold text-foreground"><MapPin className="h-4 w-4 text-primary" /> Installation location</h2>{coordinates ? <dl className="mt-4 space-y-2 text-sm"><div><dt className="text-muted-foreground">Coordinates</dt><dd>{coordinates.latitude}, {coordinates.longitude}</dd></div><div><dt className="text-muted-foreground">Accuracy</dt><dd>{customer.location_accuracy_meters ? `${Math.round(customer.location_accuracy_meters)} meters` : 'Not recorded'}</dd></div><div><dt className="text-muted-foreground">Status</dt><dd className="capitalize">{customer.location_status || 'confirmed'} · {(customer.location_source || 'existing record').replace('_', ' ')}</dd></div></dl> : <p className="mt-4 text-sm text-muted-foreground">No installation coordinates recorded yet.</p>}</article>
      <article className="rounded-2xl border border-border bg-card p-5"><h2 className="flex items-center gap-2 font-semibold text-foreground"><Router className="h-4 w-4 text-primary" /> Service binding</h2><dl className="mt-4 space-y-2 text-sm"><div><dt className="text-muted-foreground">ONU reference</dt><dd>{(customer as any).onu_information || 'Not recorded'}</dd></div><div><dt className="text-muted-foreground">Router</dt><dd>{detail.dhcp_lease?.router?.name || (customer as any).router?.name || 'Not assigned'}</dd></div><div><dt className="text-muted-foreground">Lease status</dt><dd className={leaseOnline ? 'text-emerald-700' : 'text-muted-foreground'}>{leaseOnline ? 'Online' : 'Offline / no current bound lease'}</dd></div><div><dt className="text-muted-foreground">IP / MAC</dt><dd>{detail.dhcp_lease?.ip_address || customer.ip_address || 'Not recorded'}<br />{detail.dhcp_lease?.mac_address || customer.mac_address || 'Not recorded'}</dd></div><div><dt className="text-muted-foreground">Hostname / last seen</dt><dd>{detail.dhcp_lease?.hostname || 'Not recorded'}<br />{detail.dhcp_lease?.last_seen_at ? new Date(detail.dhcp_lease.last_seen_at).toLocaleString('en-PH') : 'Not recorded'}</dd></div></dl></article>
    </section>

    <section className="rounded-2xl border border-border bg-card p-5"><h2 className="flex items-center gap-2 font-semibold text-foreground"><ReceiptText className="h-4 w-4 text-primary" /> Invoice history</h2><div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead className="text-xs uppercase text-muted-foreground"><tr><th className="pb-2">Invoice</th><th className="pb-2">Issued</th><th className="pb-2">Status</th><th className="pb-2">Balance</th></tr></thead><tbody>{detail.invoices.length ? detail.invoices.map((invoice) => <tr key={invoice.id} className="border-t border-border"><td className="py-2">{invoice.invoice_number}</td><td>{invoice.issue_date}</td><td className="capitalize">{invoice.status}</td><td>{formatPHP(invoice.balance)}</td></tr>) : <tr><td colSpan={4} className="py-5 text-muted-foreground">No invoices yet.</td></tr>}</tbody></table></div></section>

    <section className="rounded-2xl border border-border bg-card p-5"><h2 className="flex items-center gap-2 font-semibold text-foreground"><Wallet className="h-4 w-4 text-primary" /> Transaction log</h2><div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead className="text-xs uppercase text-muted-foreground"><tr><th className="pb-2">Date</th><th className="pb-2">Invoice</th><th className="pb-2">Method</th><th className="pb-2">Reference</th><th className="pb-2">Amount</th></tr></thead><tbody>{detail.payments.length ? detail.payments.map((payment) => <tr key={payment.id} className="border-t border-border"><td className="py-2">{payment.payment_date}</td><td>{payment.invoice?.invoice_number || 'Not recorded'}</td><td className="capitalize">{payment.payment_method}</td><td>{payment.reference || payment.transaction_id || 'Not recorded'}</td><td>{formatPHP(payment.amount)}</td></tr>) : <tr><td colSpan={5} className="py-5 text-muted-foreground">No payments recorded yet.</td></tr>}</tbody></table></div></section>

    <section className="rounded-2xl border border-border bg-card p-5"><h2 className="flex items-center gap-2 font-semibold text-foreground"><BellRing className="h-4 w-4 text-primary" /> Customer notification audit</h2><p className="mt-1 text-sm text-muted-foreground">Push endpoints and encryption keys stay private. Web Push reports send acceptance; it cannot prove a phone visibly displayed an alert.</p><div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead className="text-xs uppercase text-muted-foreground"><tr><th className="pb-2">Created</th><th className="pb-2">Event</th><th className="pb-2">Device</th><th className="pb-2">Status</th><th className="pb-2">Clicked</th></tr></thead><tbody>{detail.notification_logs.length ? detail.notification_logs.map((log) => <tr key={log.id} className="border-t border-border"><td className="py-2">{new Date(log.created_at).toLocaleString('en-PH')}</td><td>{log.notification_type.replaceAll('_', ' ')}</td><td>{[log.subscription?.platform, log.subscription?.browser].filter(Boolean).join(' · ') || 'Unknown browser'}</td><td className="capitalize">{log.status}</td><td>{log.clicked_at ? new Date(log.clicked_at).toLocaleString('en-PH') : 'Not recorded'}</td></tr>) : <tr><td colSpan={5} className="py-5 text-muted-foreground">No customer push notifications logged yet.</td></tr>}</tbody></table></div></section>

    {canManageSignature && <section className="rounded-2xl border border-border bg-card p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="flex items-center gap-2 font-semibold text-foreground"><ShieldCheck className="h-4 w-4 text-primary" /> Cash signature reference</h2><p className="mt-1 text-sm text-muted-foreground">Restricted to administrators. Used to verify future client-signed cash payments.</p></div>{signature?.has_signature && <button disabled={signatureBusy} onClick={() => void resetSignature()} className="rounded-lg border border-destructive/40 px-3 py-2 text-sm font-semibold text-destructive hover:bg-destructive/10 disabled:opacity-50">Reset signature</button>}</div>{signature?.has_signature && signature.signature ? <><div className="mt-4 rounded-xl border bg-white"><img src={signature.signature} alt={`${customer.full_name} cash signature reference`} className="h-32 w-full object-contain" /></div><p className="mt-2 text-xs text-muted-foreground">Captured {signature.captured_at ? new Date(signature.captured_at).toLocaleString('en-PH') : 'previously'} · client signatures require a 50% match.</p></> : <p className="mt-4 rounded-xl bg-muted p-4 text-sm text-muted-foreground">No client signature reference yet. The next cash payment signed by the client will create it.</p>}</section>}
  </main></DashboardLayout>;
}
