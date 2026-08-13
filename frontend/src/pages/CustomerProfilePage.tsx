import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, KeyRound, Loader2, UserRound, Wifi } from 'lucide-react';
import customerPortalService, { type CustomerProfileChangeRequest } from '@/services/customerPortalService';
import { formatPHP } from '@/lib/currency';
import type { Customer } from '@/types/api';

interface ServicePlanOption { id: string; name: string; price: number; download_speed: number; upload_speed: number; }

export default function CustomerProfilePage(): React.JSX.Element {
  const navigate = useNavigate();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [plans, setPlans] = useState<ServicePlanOption[]>([]);
  const [requests, setRequests] = useState<CustomerProfileChangeRequest[]>([]);
  const [fullName, setFullName] = useState('');
  const [servicePlanId, setServicePlanId] = useState('');
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = async (): Promise<void> => {
    setLoading(true);
    try {
      const [dashboard, plansResponse, changes] = await Promise.all([
        customerPortalService.getDashboard(),
        fetch(`${import.meta.env.VITE_API_URL || ''}/api/v1/customer-portal/service-plans`).then((response) => response.json()),
        customerPortalService.getProfileChangeRequests(),
      ]);
      if (dashboard.customer?.portal_password_change_required) {
        navigate('/customer/change-password', { replace: true });
        return;
      }
      setCustomer(dashboard.customer);
      setFullName(dashboard.customer.full_name || '');
      setServicePlanId(dashboard.customer.service_plan_id || '');
      setPlans(plansResponse.data || plansResponse || []);
      setRequests(changes.data || []);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not load your profile. Please sign in again.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const submit = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    setError(''); setMessage('');
    const payload: { full_name?: string; service_plan_id?: string } = {};
    if (fullName.trim() && fullName.trim() !== customer?.full_name) payload.full_name = fullName.trim();
    if (servicePlanId && servicePlanId !== customer?.service_plan_id) payload.service_plan_id = servicePlanId;
    if (!payload.full_name && !payload.service_plan_id) {
      setError('Change your name or service plan before submitting a request.');
      return;
    }
    setSaving(true);
    try {
      const response = await customerPortalService.requestProfileChange(payload);
      setMessage(response.message);
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not submit your request.');
    } finally { setSaving(false); }
  };

  if (loading) return <div className="flex min-h-screen items-center justify-center text-slate-600"><Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading profile…</div>;

  const pending = requests.find((item) => item.status === 'pending');
  return <div className="min-h-screen bg-slate-50 p-4 sm:p-8">
    <main className="mx-auto max-w-3xl">
      <Link to="/customer/dashboard" className="inline-flex items-center gap-2 text-sm font-medium text-blue-700 hover:text-blue-800"><ArrowLeft className="h-4 w-4" /> Back to dashboard</Link>
      <div className="mt-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <div className="flex items-start gap-4"><div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-blue-600 text-white"><UserRound /></div><div><h1 className="text-2xl font-bold text-slate-900">Update profile</h1><p className="mt-1 text-sm text-slate-600">Name and subscription changes are reviewed by SolarNet before they take effect.</p></div></div>
        {error && <p className="mt-5 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
        {message && <p className="mt-5 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{message}</p>}
        {pending && <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Approval pending.</strong> SolarNet is reviewing the changes you submitted on {pending.created_at ? new Date(pending.created_at).toLocaleDateString() : 'today'}.</div>}
        <form onSubmit={submit} className="mt-6 space-y-5">
          <label className="block"><span className="text-sm font-semibold text-slate-800">Full name</span><input value={fullName} onChange={(e) => setFullName(e.target.value)} required className="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500" /></label>
          <label className="block"><span className="flex items-center gap-2 text-sm font-semibold text-slate-800"><Wifi className="h-4 w-4" /> Requested subscription</span><select value={servicePlanId} onChange={(e) => setServicePlanId(e.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500"><option value="">Select a service plan</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name} — {plan.download_speed}/{plan.upload_speed} Mbps — {formatPHP(plan.price)}/month</option>)}</select></label>
          <button type="submit" disabled={saving || Boolean(pending)} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"><CheckCircle2 className="h-4 w-4" /> {saving ? 'Submitting…' : pending ? 'Request awaiting approval' : 'Send for approval'}</button>
        </form>
        <div className="mt-8 border-t border-slate-200 pt-6"><h2 className="flex items-center gap-2 font-semibold text-slate-900"><KeyRound className="h-4 w-4" /> Password</h2><p className="mt-1 text-sm text-slate-600">Your password is private. You can change it immediately after confirming your current password.</p><Link to="/customer/change-password" className="mt-3 inline-flex rounded-lg border border-blue-200 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">Change password</Link></div>
      </div>
      {requests.length > 0 && <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 className="font-semibold text-slate-900">Request history</h2><div className="mt-3 divide-y divide-slate-100">{requests.map((item) => <div key={item.id} className="py-3 text-sm"><div className="flex justify-between gap-3"><span className="font-medium text-slate-800">{item.requested_full_name || ''}{item.requested_full_name && item.requested_service_plan ? ' · ' : ''}{item.requested_service_plan?.name || ''}</span><span className="capitalize text-slate-600">{item.status}</span></div>{item.review_notes && <p className="mt-1 text-slate-600">{item.review_notes}</p>}</div>)}</div></section>}
    </main>
  </div>;
}
