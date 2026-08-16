import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { KeyRound, ShieldCheck } from 'lucide-react';
import customerPortalService from '@/services/customerPortalService';

export default function CustomerChangePasswordPage(): React.JSX.Element {
  const navigate = useNavigate();
  const location = useLocation();
  const [form, setForm] = useState({ current_password: 'Solarnet123', password: '', password_confirmation: '' });
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    if (form.password !== form.password_confirmation) {
      setError('The new password and confirmation do not match.');
      return;
    }
    setSaving(true);
    try {
      await customerPortalService.changePassword(form);
      const raw = localStorage.getItem('customer_data');
      if (raw) {
        const customer = JSON.parse(raw);
        customer.portal_password_change_required = false;
        localStorage.setItem('customer_data', JSON.stringify(customer));
      }
      const requested = new URLSearchParams(location.search).get('next');
      navigate(requested?.startsWith('/customer/') ? requested : '/customer/dashboard', { replace: true });
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not change your password.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 p-4">
      <form onSubmit={submit} className="w-full max-w-md rounded-3xl border border-white/10 bg-white p-8 shadow-2xl">
        <div className="mb-6 flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-600 text-white"><KeyRound /></div>
        <h1 className="text-2xl font-bold text-slate-900">Create your portal password</h1>
        <p className="mt-2 text-sm leading-6 text-slate-600">For your security, replace the temporary password before viewing your account, bills, or payment reminder.</p>
        {error && <p className="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
        <div className="mt-6 space-y-4">
          <input type="password" required minLength={10} autoFocus value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })}
            placeholder="New password (10+ characters)" className="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500" />
          <input type="password" required minLength={10} value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
            placeholder="Confirm new password" className="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500" />
          <button disabled={saving} className="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
            <ShieldCheck className="h-4 w-4" /> {saving ? 'Saving…' : 'Save and continue'}
          </button>
        </div>
      </form>
    </div>
  );
}
