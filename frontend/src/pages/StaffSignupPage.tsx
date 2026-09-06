import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import api from '@/services/api';

type SignupRole = { name: string; display_name: string; description?: string | null };

export default function StaffSignupPage(): JSX.Element {
  const [roles, setRoles] = useState<SignupRole[]>([]);
  const [form, setForm] = useState({ name: '', email: '', phone: '', role: '', password: '', password_confirmation: '' });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    void api.get('/auth/signup/roles').then((response) => setRoles(response.data.data || [])).catch(() => setError('Staff signup roles could not be loaded.'));
  }, []);

  const submit = async (event: FormEvent): Promise<void> => {
    event.preventDefault(); setBusy(true); setMessage(''); setError('');
    try {
      const response = await api.post('/auth/signup', form);
      setMessage(response.data.message);
      setForm({ name: '', email: '', phone: '', role: '', password: '', password_confirmation: '' });
    } catch (reason: any) {
      const errors = reason.response?.data?.errors;
      setError(errors ? Object.values(errors).flat().join(' ') : reason.response?.data?.message || 'Signup could not be submitted.');
    } finally { setBusy(false); }
  };

  const inputClass = 'mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-foreground';
  return <main className="grid min-h-screen place-items-center bg-background p-4"><section className="w-full max-w-lg rounded-xl border border-border bg-card p-7 shadow-lg"><h1 className="text-2xl font-bold text-foreground">Request a SolarNet staff account</h1><p className="mt-2 text-sm text-muted-foreground">Choose your actual work role. Administrator and Super Administrator access cannot be requested here. All new accounts require Super Administrator approval.</p>{message && <p className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">{message}</p>}{error && <p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/30 dark:text-rose-200">{error}</p>}<form onSubmit={submit} className="mt-5 grid gap-4 sm:grid-cols-2"><label className="text-sm font-medium text-foreground sm:col-span-2">Full name<input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} className={inputClass} /></label><label className="text-sm font-medium text-foreground">Email<input required type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} autoComplete="email" className={inputClass} /></label><label className="text-sm font-medium text-foreground">Phone (optional)<input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} className={inputClass} /></label><label className="text-sm font-medium text-foreground sm:col-span-2">Requested role<select required value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value })} className={inputClass}><option value="">Select your work role</option>{roles.map((role) => <option key={role.name} value={role.name}>{role.display_name}</option>)}</select></label><label className="text-sm font-medium text-foreground">Password<input required minLength={8} type="password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} autoComplete="new-password" className={inputClass} /></label><label className="text-sm font-medium text-foreground">Confirm password<input required minLength={8} type="password" value={form.password_confirmation} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} autoComplete="new-password" className={inputClass} /></label><button disabled={busy || roles.length === 0} className="rounded-lg bg-primary px-4 py-2.5 font-semibold text-primary-foreground disabled:opacity-50 sm:col-span-2">{busy ? 'Submitting…' : 'Submit for approval'}</button></form><p className="mt-5 text-center text-sm"><Link to="/login" className="text-primary hover:underline">Back to staff sign in</Link></p></section></main>;
}
