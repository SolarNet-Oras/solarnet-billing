import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import api from '@/services/api';

export default function StaffPasswordResetPage({ request = false }: { request?: boolean }): JSX.Element {
  const location = useLocation();
  const navigate = useNavigate();
  const query = new URLSearchParams(location.search);
  const [email, setEmail] = useState(query.get('email') || '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const submit = async (event: FormEvent): Promise<void> => {
    event.preventDefault(); setBusy(true); setMessage(''); setError('');
    try {
      const response = request
        ? await api.post('/auth/forgot-password', { email })
        : await api.post('/auth/reset-password', { token: query.get('token'), email, password, password_confirmation: confirmation });
      setMessage(response.data.message);
      if (!request) window.setTimeout(() => navigate('/login'), 1500);
    } catch (reason: any) {
      const errors = reason.response?.data?.errors;
      setError(errors ? Object.values(errors).flat().join(' ') : reason.response?.data?.message || 'Request failed.');
    } finally { setBusy(false); }
  };

  return <main className="grid min-h-screen place-items-center bg-background p-4"><section className="w-full max-w-md rounded-xl border border-border bg-card p-7 shadow-lg"><h1 className="text-2xl font-bold text-foreground">{request ? 'Reset staff password' : 'Choose a new password'}</h1><p className="mt-2 text-sm text-muted-foreground">{request ? 'Enter your assigned staff email. If it is active, SolarNet will email a secure reset link.' : 'This link is single-use and expires according to the server password-reset policy.'}</p>{message && <p className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">{message}</p>}{error && <p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/30 dark:text-rose-200">{error}</p>}<form onSubmit={submit} className="mt-5 space-y-4"><label className="block text-sm font-medium text-foreground">Staff email<input required type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="email" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2" /></label>{!request && <><label className="block text-sm font-medium text-foreground">New password<input required minLength={8} type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2" /></label><label className="block text-sm font-medium text-foreground">Confirm new password<input required minLength={8} type="password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} autoComplete="new-password" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2" /></label></>}<button disabled={busy} className="w-full rounded-lg bg-primary px-4 py-2.5 font-semibold text-primary-foreground disabled:opacity-50">{busy ? 'Processing…' : request ? 'Send reset link' : 'Reset password'}</button></form><p className="mt-5 text-center text-sm"><Link to="/login" className="text-primary hover:underline">Back to staff sign in</Link></p></section></main>;
}
