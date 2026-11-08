import { useEffect, useState, type FormEvent } from 'react';
import { Building2, CheckCircle2, X } from 'lucide-react';
import FiberOpticBackground from '@/components/FiberOpticBackground';
import service, { saasError, type TenantPlan } from '@/services/saasTenantService';

const field = 'w-full rounded-md border border-input bg-background px-4 py-2 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary';

export default function SaasAppPage(): React.JSX.Element {
  const query = new URLSearchParams(location.search);
  const resetToken = query.get('token');
  const [mode, setMode] = useState<'login' | 'register' | 'forgot' | 'staff'>(resetToken ? 'forgot' : 'login');
  const [showAdvertisement, setShowAdvertisement] = useState(true);
  const [plans, setPlans] = useState<TenantPlan[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [login, setLogin] = useState({ tenant: '', email: '', password: '' });
  const [recovery, setRecovery] = useState({ tenant: query.get('tenant') || '', email: query.get('email') || '', password: '', password_confirmation: '' });
  const [staff, setStaff] = useState({ tenant: '', name: '', email: '', phone: '', password: '', password_confirmation: '' });
  const [form, setForm] = useState({ company_name: '', tenant_slug: '', owner_name: '', owner_email: '', owner_phone: '', password: '', password_confirmation: '', business_address: '', business_registration_number: '', plan: 'monthly-3499', terms_accepted: false });

  useEffect(() => {
    const advertisementTimer = window.setTimeout(() => setShowAdvertisement(false), 5000);
    const token = new URLSearchParams(location.search).get('token');
    if (token) {
      setBusy(true);
      service.verify(token).then((result) => setNotice(result.message)).catch((requestError) => setError(saasError(requestError))).finally(() => setBusy(false));
    }
    service.plans().then(setPlans).catch((requestError) => setError(saasError(requestError)));
    return () => window.clearTimeout(advertisementTimer);
  }, []);

  async function register(event: FormEvent): Promise<void> {
    event.preventDefault(); setBusy(true); setError('');
    try {
      const result = await service.register(form);
      setNotice(result.message);
      setForm({ ...form, password: '', password_confirmation: '' });
      setMode('login');
    } catch (requestError) { setError(saasError(requestError)); } finally { setBusy(false); }
  }

  async function signIn(event: FormEvent): Promise<void> {
    event.preventDefault(); setBusy(true); setError('');
    try {
      const result = await service.login(login.tenant, login.email, login.password);
      if (result.workspace_activation?.allowed) { window.location.assign('/dashboard'); return; }
      service.logout();
      setNotice(`Identity verified for ${result.tenant.name}. Operational workspace access remains staged.`);
    } catch (requestError) { setError(saasError(requestError)); } finally { setBusy(false); }
  }

  async function recover(event: FormEvent): Promise<void> {
    event.preventDefault(); setBusy(true); setError('');
    try {
      const result = resetToken
        ? await service.resetPassword({ ...recovery, token: resetToken })
        : await service.forgotPassword(recovery.tenant, recovery.email);
      setNotice(result.message);
      if (resetToken) window.setTimeout(() => window.location.assign('/'), 1500);
    } catch (requestError) { setError(saasError(requestError)); } finally { setBusy(false); }
  }

  async function requestStaffAccess(event: FormEvent): Promise<void> {
    event.preventDefault(); setBusy(true); setError('');
    try {
      const result = await service.requestStaffAccess(staff);
      setNotice(result.message); setStaff({ ...staff, name: '', email: '', phone: '', password: '', password_confirmation: '' });
    } catch (requestError) { setError(saasError(requestError)); } finally { setBusy(false); }
  }

  return <main className="relative flex min-h-screen items-center justify-center overflow-hidden p-4">
    <FiberOpticBackground />
    <button type="button" onClick={() => setMode(mode === 'register' ? 'login' : 'register')} className="fixed right-4 top-4 z-30 rounded-full border border-white/50 bg-slate-950/85 px-4 py-2 text-xs font-bold text-white shadow-lg backdrop-blur hover:bg-slate-900 sm:right-7 sm:top-6">
      {mode === 'register' ? 'Tenant sign in' : 'Apply as an ISP'}
    </button>

    {showAdvertisement && mode === 'login' && <section className="fixed inset-x-4 top-20 z-40 mx-auto max-w-lg rounded-2xl border border-cyan-300/70 bg-slate-950/95 p-5 text-white shadow-2xl backdrop-blur-xl animate-in fade-in slide-in-from-top-4">
      <button type="button" onClick={() => setShowAdvertisement(false)} aria-label="Close advertisement" className="absolute right-3 top-3 rounded-full p-1 text-slate-300 hover:bg-white/10 hover:text-white"><X className="h-4 w-4" /></button>
      <p className="text-xs font-bold uppercase tracking-[.2em] text-cyan-200">SolarNet ISP Billing</p>
      <h2 className="mt-2 text-2xl font-black">Your company. Your isolated workspace.</h2>
      <div className="mt-4 grid gap-2 text-sm sm:grid-cols-2">{['Separate tenant data', 'Approval before access', 'No financial record during application', 'PHP 3,499 monthly after trial'].map((text) => <p key={text} className="flex items-center gap-2"><CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-300" />{text}</p>)}</div>
    </section>}

    <div className={`relative z-10 w-full ${mode === 'login' ? 'max-w-md' : 'max-w-2xl'}`}>
      <header className="mb-8 text-center">
        <img src="/solarnet-company-logo-192.png?v=20261015-official" alt="SolarNet" className="mx-auto mb-4 h-16 w-16 object-contain" />
        <h1 className="mb-2 text-3xl font-bold text-white">SolarNet ISP Billing</h1>
        <p className="text-slate-200">{mode === 'login' ? 'Sign in to your company workspace' : mode === 'register' ? 'Apply for an isolated ISP workspace' : mode === 'forgot' ? 'Recover your company workspace access' : 'Request access to an existing company'}</p>
      </header>

      <section className="rounded-2xl border border-white/40 bg-card/95 p-6 text-foreground shadow-2xl backdrop-blur-xl sm:p-8">
        {error && <p role="alert" className="mb-5 rounded-md border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-100">{error}</p>}
        {notice && <p className="mb-5 rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm font-medium text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">{notice}</p>}

        {mode === 'login' ? <form onSubmit={signIn} className="space-y-6">
          <label className="block text-sm font-medium">Company login ID<input required autoComplete="organization" className={`${field} mt-2`} placeholder="example-isp" value={login.tenant} onChange={(event) => setLogin({ ...login, tenant: event.target.value.toLowerCase() })} /></label>
          <label className="block text-sm font-medium">Email Address<input required type="email" autoComplete="username" className={`${field} mt-2`} placeholder="you@company.com" value={login.email} onChange={(event) => setLogin({ ...login, email: event.target.value })} /></label>
          <label className="block text-sm font-medium">Password<input required type="password" autoComplete="current-password" className={`${field} mt-2`} placeholder="••••••••" value={login.password} onChange={(event) => setLogin({ ...login, password: event.target.value })} /></label>
          <button disabled={busy} className="w-full rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"><Building2 className="mr-2 inline h-5 w-5" />{busy ? 'Signing in...' : 'Sign in'}</button>
          <div className="text-center text-sm">
            <button type="button" onClick={() => { setMode('forgot'); setNotice(''); setError(''); }} className="font-medium text-primary hover:underline">Forgot your password?</button>
            <p className="mt-2 text-xs text-muted-foreground">Need a staff account? <button type="button" onClick={() => { setMode('staff'); setNotice(''); setError(''); }} className="font-medium text-primary hover:underline">Submit a signup request</button>.</p>
          </div>
          <p className="rounded-lg border border-border bg-muted/35 p-3 text-xs text-muted-foreground">The subscribing company owner is the Tenant Super Administrator. Staff share the company subscription and can access only after that administrator grants membership.</p>
        </form> : mode === 'forgot' ? <form onSubmit={recover} className="space-y-4">
          <label className="block text-sm font-medium">Company login ID<input required className={`${field} mt-2`} value={recovery.tenant} onChange={(event) => setRecovery({ ...recovery, tenant: event.target.value.toLowerCase() })} /></label>
          <label className="block text-sm font-medium">Email Address<input required type="email" className={`${field} mt-2`} value={recovery.email} onChange={(event) => setRecovery({ ...recovery, email: event.target.value })} /></label>
          {resetToken && <><label className="block text-sm font-medium">New password<input required minLength={10} type="password" className={`${field} mt-2`} value={recovery.password} onChange={(event) => setRecovery({ ...recovery, password: event.target.value })} /></label><label className="block text-sm font-medium">Confirm password<input required minLength={10} type="password" className={`${field} mt-2`} value={recovery.password_confirmation} onChange={(event) => setRecovery({ ...recovery, password_confirmation: event.target.value })} /></label></>}
          <button disabled={busy} className="w-full rounded-md bg-primary p-2.5 font-semibold text-primary-foreground">{busy ? 'Processing...' : resetToken ? 'Reset password' : 'Send reset link'}</button>
          <button type="button" onClick={() => setMode('login')} className="w-full text-sm text-primary hover:underline">Back to tenant sign in</button>
        </form> : mode === 'staff' ? <form onSubmit={requestStaffAccess} className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-medium sm:col-span-2">Company login ID<input required className={`${field} mt-2`} value={staff.tenant} onChange={(event) => setStaff({ ...staff, tenant: event.target.value.toLowerCase() })} /></label>
          <label className="text-sm font-medium">Full name<input required className={`${field} mt-2`} value={staff.name} onChange={(event) => setStaff({ ...staff, name: event.target.value })} /></label>
          <label className="text-sm font-medium">Email<input required type="email" className={`${field} mt-2`} value={staff.email} onChange={(event) => setStaff({ ...staff, email: event.target.value })} /></label>
          <label className="text-sm font-medium sm:col-span-2">Phone (optional)<input className={`${field} mt-2`} value={staff.phone} onChange={(event) => setStaff({ ...staff, phone: event.target.value })} /></label>
          <label className="text-sm font-medium">Password<input required minLength={10} type="password" className={`${field} mt-2`} value={staff.password} onChange={(event) => setStaff({ ...staff, password: event.target.value })} /></label>
          <label className="text-sm font-medium">Confirm password<input required minLength={10} type="password" className={`${field} mt-2`} value={staff.password_confirmation} onChange={(event) => setStaff({ ...staff, password_confirmation: event.target.value })} /></label>
          <button disabled={busy} className="rounded-md bg-primary p-2.5 font-semibold text-primary-foreground sm:col-span-2">{busy ? 'Submitting...' : 'Submit for company approval'}</button>
          <button type="button" onClick={() => setMode('login')} className="text-sm text-primary hover:underline sm:col-span-2">Back to tenant sign in</button>
        </form> : <form onSubmit={register} className="grid gap-4 sm:grid-cols-2">
          <input required aria-label="ISP company name" className={field} placeholder="ISP company name" value={form.company_name} onChange={(event) => setForm({ ...form, company_name: event.target.value })} />
          <div><input required aria-label="Company login ID" className={field} placeholder="Company login ID" value={form.tenant_slug} onChange={(event) => setForm({ ...form, tenant_slug: event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })} /><p className="mt-1 text-xs text-muted-foreground">Lowercase letters, numbers, and hyphens.</p></div>
          <input required aria-label="Owner full name" className={field} placeholder="Owner full name" value={form.owner_name} onChange={(event) => setForm({ ...form, owner_name: event.target.value })} />
          <input required aria-label="Owner email" type="email" className={field} placeholder="Owner email" value={form.owner_email} onChange={(event) => setForm({ ...form, owner_email: event.target.value })} />
          <input required aria-label="Owner phone" className={field} placeholder="Owner phone" value={form.owner_phone} onChange={(event) => setForm({ ...form, owner_phone: event.target.value })} />
          <input aria-label="DTI or SEC registration" className={field} placeholder="DTI/SEC registration (optional)" value={form.business_registration_number} onChange={(event) => setForm({ ...form, business_registration_number: event.target.value })} />
          <textarea required aria-label="Business address" className={`${field} sm:col-span-2`} placeholder="Business address" value={form.business_address} onChange={(event) => setForm({ ...form, business_address: event.target.value })} />
          <select required aria-label="Subscription plan" className={`${field} sm:col-span-2`} value={form.plan} onChange={(event) => setForm({ ...form, plan: event.target.value })}>{plans.map((plan) => <option key={plan.id} value={plan.code}>{plan.name} — PHP {Number(plan.amount).toLocaleString()} monthly — {plan.trial_days}-day trial</option>)}</select>
          <input required minLength={10} aria-label="Password" type="password" className={field} placeholder="Password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} />
          <input required minLength={10} aria-label="Confirm password" type="password" className={field} placeholder="Confirm password" value={form.password_confirmation} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} />
          <label className="flex gap-2 text-sm sm:col-span-2"><input required type="checkbox" className="h-5 w-5 accent-primary" checked={form.terms_accepted} onChange={(event) => setForm({ ...form, terms_accepted: event.target.checked })} />I confirm the company information is accurate and agree to administrator review.</label>
          <button disabled={busy || plans.length === 0} className="rounded-md bg-primary p-3 font-bold text-primary-foreground disabled:opacity-50 sm:col-span-2">{busy ? 'Submitting...' : 'Submit application and verify email'}</button>
        </form>}
      </section>
      <p className="mt-8 text-center text-xs text-slate-300">Secure tenant authentication and isolated company access</p>
    </div>
  </main>;
}
