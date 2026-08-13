import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AlertTriangle, CreditCard, Headphones, WifiOff } from 'lucide-react';
import customerPortalService from '@/services/customerPortalService';
import { formatPHP } from '@/lib/currency';

type ReminderData = {
  customer_id: string;
  account_number: string;
  full_name: string;
  status: string;
  due_date: string | null;
  balance: number;
  payment_url: string;
  suspended_speed_kbps: number;
  service_plan?: {
    name: string;
    download_speed: number;
    upload_speed: number;
  } | null;
};

const PaymentRequiredPage: React.FC = () => {
  const { customerId } = useParams();
  const navigate = useNavigate();
  const [reminder, setReminder] = useState<ReminderData | null>(null);
  const [loading, setLoading] = useState(true);
  const [showSignIn, setShowSignIn] = useState(false);
  const [credentials, setCredentials] = useState({ email: '', password: '' });
  const [signingIn, setSigningIn] = useState(false);
  const [signInError, setSignInError] = useState('');

  const continueToPayment = (): void => {
    try {
      const customer = JSON.parse(localStorage.getItem('customer_data') || 'null');
      if (customer?.id && customer.id === customerId && localStorage.getItem('customer_token')) {
        navigate('/customer/billing');
        return;
      }
    } catch { /* show the inline sign-in form */ }
    setSignInError('');
    setShowSignIn(true);
  };

  const signInForPayment = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    setSigningIn(true); setSignInError('');
    try {
      const result = await customerPortalService.login(credentials.email, credentials.password);
      if (customerId && result.customer.id !== customerId) {
        setSignInError('Use the portal email for the suspended account shown on this page.');
        return;
      }
      localStorage.setItem('customer_token', result.access_token);
      localStorage.setItem('customer_data', JSON.stringify(result.customer));
      navigate(result.customer.portal_password_change_required ? '/customer/change-password' : '/customer/billing');
    } catch (error: any) {
      setSignInError(error.response?.data?.message || 'Unable to verify your customer portal account.');
    } finally { setSigningIn(false); }
  };

  useEffect(() => {
    const load = async (): Promise<void> => {
      if (!customerId) {
        setLoading(false);
        return;
      }

      try {
        const response = await customerPortalService.getPaymentReminder(customerId);
        setReminder(response.data);
      } catch (error) {
        console.error('Failed to load payment reminder', error);
      } finally {
        setLoading(false);
      }
    };

    void load();
  }, [customerId]);

  return (
    <div className="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(15,23,42,0.96),_rgba(2,6,23,1))] text-slate-100">
      <div className="mx-auto flex min-h-screen max-w-5xl items-center px-6 py-12">
        <div className="grid w-full gap-8 lg:grid-cols-[1.1fr_0.9fr]">
          <div className="space-y-6">
            <div className="inline-flex items-center gap-2 rounded-full border border-amber-400/30 bg-amber-400/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-amber-200">
              <WifiOff className="h-4 w-4" />
              Internet temporarily suspended
            </div>
            <div>
              <h1 className="text-4xl font-semibold tracking-tight text-white sm:text-5xl">
                Payment reminder for your SolarNet account
              </h1>
              <p className="mt-4 max-w-xl text-base leading-7 text-slate-300">
                Your connection is still recognized by the network, but browsing is paused until the outstanding balance is settled.
                You can review the account details below and complete payment from the secure SolarNet portal.
              </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <Feature label="Low-speed access" value={`${reminder?.suspended_speed_kbps ?? 128} kbps`} />
              <Feature label="Account status" value={reminder?.status?.toUpperCase() ?? 'SUSPENDED'} />
              <Feature label="Customer ID" value={reminder?.account_number ?? 'Loading...'} />
            </div>

            <div className="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
              <div className="flex items-start gap-4">
                <div className="rounded-2xl bg-amber-400/15 p-3 text-amber-300">
                  <AlertTriangle className="h-6 w-6" />
                </div>
                <div className="space-y-2">
                  <h2 className="text-xl font-semibold text-white">What you can still do</h2>
                  <p className="text-sm leading-6 text-slate-300">
                    Reach the SolarNet payment portal, contact support, and review the bill. Internet traffic outside the approved
                    payment path remains restricted while the account is overdue.
                  </p>
                </div>
              </div>
            </div>

            <div className="flex flex-wrap gap-3">
              <button
                type="button"
                onClick={continueToPayment}
                className="inline-flex items-center gap-2 rounded-xl bg-amber-400 px-5 py-3 font-semibold text-slate-950 transition hover:bg-amber-300"
              >
                <CreditCard className="h-4 w-4" />
                Pay now
              </button>
              <a
                href="mailto:support@solarnetinternet.com"
                className="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/5 px-5 py-3 font-semibold text-white transition hover:bg-white/10"
              >
                <Headphones className="h-4 w-4" />
                Contact support
              </a>
            </div>
            {showSignIn && <form onSubmit={signInForPayment} className="max-w-md rounded-2xl border border-white/15 bg-white/5 p-5 backdrop-blur"><h2 className="font-semibold text-white">Verify your account to pay</h2><p className="mt-1 text-sm text-slate-300">Sign in here to open your invoice and GCash payment securely. You will not be sent to the login page.</p>{signInError && <p className="mt-3 rounded-lg bg-rose-500/15 p-3 text-sm text-rose-100">{signInError}</p>}<div className="mt-4 space-y-3"><input required type="email" value={credentials.email} onChange={(e) => setCredentials({ ...credentials, email: e.target.value })} placeholder="Customer email" className="w-full rounded-xl border border-white/15 bg-slate-950/60 px-4 py-3 text-white placeholder:text-slate-500" /><input required type="password" value={credentials.password} onChange={(e) => setCredentials({ ...credentials, password: e.target.value })} placeholder="Portal password" className="w-full rounded-xl border border-white/15 bg-slate-950/60 px-4 py-3 text-white placeholder:text-slate-500" /><button disabled={signingIn} className="rounded-xl bg-sky-400 px-4 py-3 text-sm font-semibold text-slate-950 hover:bg-sky-300 disabled:opacity-50">{signingIn ? 'Verifying…' : 'Continue to payment'}</button></div></form>}
          </div>

          <div className="rounded-[2rem] border border-white/10 bg-slate-950/70 p-6 shadow-2xl shadow-black/30 backdrop-blur">
            <div className="mb-6 flex items-center gap-3">
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-800 text-sky-300">
                <AlertTriangle className="h-6 w-6" />
              </div>
              <div>
                <h2 className="text-lg font-semibold text-white">Account summary</h2>
                <p className="text-sm text-slate-400">Generated from the latest billing and network state.</p>
              </div>
            </div>

            {loading ? (
              <div className="rounded-2xl border border-white/10 bg-white/5 p-8 text-sm text-slate-300">
                Loading account details...
              </div>
            ) : (
              <div className="space-y-4">
                <SummaryRow label="Name" value={reminder?.full_name ?? 'Unknown'} />
                <SummaryRow label="Account number" value={reminder?.account_number ?? 'Unknown'} />
                <SummaryRow label="Due date" value={reminder?.due_date ?? 'No current invoice'} />
                <SummaryRow label="Amount due" value={formatPHP(reminder?.balance ?? 0)} />
                <SummaryRow label="Plan" value={reminder?.service_plan?.name ?? 'No active plan'} />
                <SummaryRow
                  label="Normal speed"
                  value={reminder?.service_plan ? `${reminder.service_plan.download_speed} / ${reminder.service_plan.upload_speed} Mbps` : 'N/A'}
                />
              </div>
            )}

            <div className="mt-6 rounded-2xl border border-sky-400/20 bg-sky-400/10 p-4 text-sm text-sky-100">
              The reminder page stays on your SolarNet web app, so you can safely continue payments without leaving the portal.
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

function Feature({ label, value }: { label: string; value: string }): React.JSX.Element {
  return (
    <div className="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur">
      <div className="text-xs uppercase tracking-[0.24em] text-slate-400">{label}</div>
      <div className="mt-2 text-lg font-semibold text-white">{value}</div>
    </div>
  );
}

function SummaryRow({ label, value }: { label: string; value: string }): React.JSX.Element {
  return (
    <div className="flex items-center justify-between gap-4 rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
      <span className="text-sm text-slate-400">{label}</span>
      <span className="text-sm font-medium text-white text-right">{value}</span>
    </div>
  );
}

export default PaymentRequiredPage;
