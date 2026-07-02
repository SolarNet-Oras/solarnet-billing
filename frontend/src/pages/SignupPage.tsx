import { useState, useEffect, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import axios from 'axios';
import { CheckCircle2, Loader2, Wifi, User, Mail, Phone, MapPin, ClipboardCopy } from 'lucide-react';
import { formatPHP } from '@/lib/currency';

const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:8001';

interface ServicePlanOption {
  id: string;
  name: string;
  description?: string | null;
  download_speed: number;
  upload_speed: number;
  price: number;
}

interface SignupSuccess {
  account_number: string;
  email: string;
  password: string;
  portal_url: string;
}

export default function SignupPage() {
  const navigate = useNavigate();
  const [plans, setPlans] = useState<ServicePlanOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState<SignupSuccess | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState({
    full_name: '',
    email: '',
    contact_number: '',
    address: '',
    service_plan_id: '',
    notes: '',
  });

  useEffect(() => {
    axios
      .get(`${API_BASE}/api/v1/customer-portal/service-plans`)
      .then((r) => setPlans(r.data?.data || []))
      .catch(() => setPlans([]));
  }, []);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const res = await axios.post(`${API_BASE}/api/v1/customer-portal/signup`, form);
      setSuccess(res.data?.data);
    } catch (err: any) {
      const errs = err.response?.data?.errors;
      if (errs) {
        setError(Object.values(errs).flat().join(' '));
      } else {
        setError(err.response?.data?.message || 'Signup failed. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-slate-100 flex items-center justify-center p-6">
        <div className="bg-white text-slate-900 rounded-2xl shadow-2xl max-w-lg w-full p-8" data-testid="signup-success-card">
          <div className="flex items-center gap-3 mb-4">
            <div className="h-12 w-12 rounded-full bg-emerald-100 flex items-center justify-center">
              <CheckCircle2 className="h-7 w-7 text-emerald-600" />
            </div>
            <div>
              <h1 className="text-2xl font-bold">Application received</h1>
              <p className="text-sm text-slate-500">We'll be in touch shortly to schedule installation.</p>
            </div>
          </div>

          <div className="bg-slate-50 border border-slate-200 rounded-lg p-4 space-y-3 my-6 text-sm">
            <div>
              <div className="text-xs uppercase tracking-wider text-slate-500">Application ID</div>
              <div className="font-mono font-bold" data-testid="signup-success-account">{success.account_number}</div>
            </div>
            <div>
              <div className="text-xs uppercase tracking-wider text-slate-500">Portal Login</div>
              <div className="font-mono">{success.email}</div>
            </div>
            <div>
              <div className="text-xs uppercase tracking-wider text-slate-500">Temporary Password</div>
              <div className="flex items-center gap-2">
                <code className="font-mono font-bold bg-white border border-slate-300 px-2 py-1 rounded">{success.password}</code>
                <button
                  onClick={() => navigator.clipboard.writeText(success.password)}
                  className="text-xs text-slate-600 hover:text-slate-900 flex items-center gap-1"
                  data-testid="signup-copy-password-btn"
                >
                  <ClipboardCopy className="h-3 w-3" /> Copy
                </button>
              </div>
            </div>
          </div>

          <p className="text-xs text-slate-500 mb-4">
            Save these credentials — this password will not be shown again. You can change it after your first login.
          </p>

          <div className="flex gap-3">
            <button
              onClick={() => (window.location.href = success.portal_url)}
              className="flex-1 py-3 rounded-lg bg-slate-900 text-white font-semibold hover:bg-slate-800"
              data-testid="signup-goto-portal-btn"
            >
              Go to Customer Portal
            </button>
            <button
              onClick={() => navigate('/')}
              className="px-4 py-3 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100"
            >
              Home
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-slate-100">
      <div className="max-w-5xl mx-auto px-6 py-10">
        {/* Header */}
        <div className="flex items-center justify-between mb-10">
          <div className="flex items-center gap-3">
            <div className="h-10 w-10 rounded-lg bg-blue-500/20 flex items-center justify-center">
              <Wifi className="h-6 w-6 text-blue-400" />
            </div>
            <div>
              <h1 className="text-xl font-bold">Solarnet Internet</h1>
              <p className="text-xs text-slate-400">Sign up for service</p>
            </div>
          </div>
          <div className="text-sm text-slate-400">
            Already have an account?{' '}
            <Link to="/customer/login" className="text-blue-400 hover:text-blue-300 underline">
              Log in
            </Link>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Left column: value prop */}
          <div className="lg:col-span-1 space-y-6">
            <div>
              <h2 className="text-4xl font-bold tracking-tight leading-tight">
                Fast fiber<br /> internet for your <span className="text-blue-400">home & business.</span>
              </h2>
              <p className="mt-4 text-slate-300">
                Sign up in 60 seconds. Our team will contact you to schedule installation.
              </p>
            </div>
            <div className="space-y-3 text-sm">
              {['Symmetric fiber speeds', 'Static IPs available', '24/7 local support', 'No installation fee this month'].map((t) => (
                <div key={t} className="flex items-center gap-2 text-slate-300">
                  <CheckCircle2 className="h-4 w-4 text-emerald-400" />
                  <span>{t}</span>
                </div>
              ))}
            </div>
          </div>

          {/* Right column: form */}
          <div className="lg:col-span-2">
            <form onSubmit={submit} className="bg-white text-slate-900 rounded-2xl shadow-2xl p-8" data-testid="signup-form">
              <h3 className="text-lg font-bold mb-6">Your details</h3>

              {error && (
                <div className="mb-4 p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-sm" data-testid="signup-error">
                  {error}
                </div>
              )}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field
                  icon={User}
                  label="Full Name"
                  value={form.full_name}
                  onChange={(v) => setForm({ ...form, full_name: v })}
                  placeholder="Juan Dela Cruz"
                  required
                  testid="signup-name"
                />
                <Field
                  icon={Mail}
                  label="Email"
                  type="email"
                  value={form.email}
                  onChange={(v) => setForm({ ...form, email: v })}
                  placeholder="you@example.com"
                  required
                  testid="signup-email"
                />
                <Field
                  icon={Phone}
                  label="Contact Number"
                  value={form.contact_number}
                  onChange={(v) => setForm({ ...form, contact_number: v })}
                  placeholder="+63 912 345 6789"
                  required
                  testid="signup-phone"
                />
                <div>
                  <label className="block text-xs font-medium text-slate-600 uppercase tracking-wider mb-1">Preferred Plan</label>
                  <select
                    value={form.service_plan_id}
                    onChange={(e) => setForm({ ...form, service_plan_id: e.target.value })}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    data-testid="signup-plan"
                  >
                    <option value="">— Recommend one for me —</option>
                    {plans.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name} — {p.download_speed}/{p.upload_speed} Mbps — {formatPHP(p.price)}/mo
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="mt-4">
                <label className="block text-xs font-medium text-slate-600 uppercase tracking-wider mb-1">
                  <MapPin className="inline h-3 w-3 mr-1" />
                  Installation Address
                </label>
                <textarea
                  value={form.address}
                  onChange={(e) => setForm({ ...form, address: e.target.value })}
                  rows={2}
                  required
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Street, barangay, city, province"
                  data-testid="signup-address"
                />
              </div>

              <div className="mt-4">
                <label className="block text-xs font-medium text-slate-600 uppercase tracking-wider mb-1">Notes (optional)</label>
                <textarea
                  value={form.notes}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  rows={2}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Best time to reach you, landmarks, etc."
                  data-testid="signup-notes"
                />
              </div>

              <button
                type="submit"
                disabled={loading}
                className="mt-6 w-full py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold flex items-center justify-center gap-2 disabled:opacity-50 transition-colors"
                data-testid="signup-submit-btn"
              >
                {loading ? (<><Loader2 className="h-4 w-4 animate-spin" /> Submitting…</>) : ('Sign up')}
              </button>

              <p className="mt-4 text-xs text-slate-500 text-center">
                By signing up you agree to Solarnet's terms of service. We will only use your details to contact you about installation.
              </p>
            </form>
          </div>
        </div>
      </div>
    </div>
  );
}

/* --- shared field --- */
interface FieldProps {
  icon: any;
  label: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  required?: boolean;
  type?: string;
  testid?: string;
}
function Field({ icon: Icon, label, value, onChange, placeholder, required, type = 'text', testid }: FieldProps) {
  return (
    <div>
      <label className="block text-xs font-medium text-slate-600 uppercase tracking-wider mb-1">
        <Icon className="inline h-3 w-3 mr-1" />
        {label}
      </label>
      <input
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        required={required}
        data-testid={testid}
        className="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
      />
    </div>
  );
}
