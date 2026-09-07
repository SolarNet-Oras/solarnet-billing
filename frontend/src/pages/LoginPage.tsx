import React, { useEffect, useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import customerPortalService from '@/services/customerPortalService';

const REMEMBERED_STAFF_EMAIL_KEY = 'solarnet-remembered-staff-email';

const LoginPage: React.FC = () => {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [branding, setBranding] = useState({ name: 'Solarnet Internet', logo_url: '' });
  
  const [formData, setFormData] = useState({
    email: '',
    password: '',
  });
  const [error, setError] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(false);
  const [rememberSignIn, setRememberSignIn] = useState<boolean>(false);

  useEffect(() => {
    void customerPortalService.getBranding().then(setBranding).catch(() => undefined);
    const rememberedEmail = window.localStorage.getItem(REMEMBERED_STAFF_EMAIL_KEY);
    if (rememberedEmail) {
      setFormData((current) => ({ ...current, email: rememberedEmail }));
      setRememberSignIn(true);
    }
  }, []);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>): void => {
    setFormData({
      ...formData,
      [e.target.name]: e.target.value,
    });
    setError('');
  };

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const signedInUser = await login(formData);
      if (rememberSignIn) window.localStorage.setItem(REMEMBERED_STAFF_EMAIL_KEY, formData.email.trim().toLowerCase());
      else window.localStorage.removeItem(REMEMBERED_STAFF_EMAIL_KEY);
      const isCollector = signedInUser.role === 'collector' || signedInUser.roles?.some((role) => typeof role === 'string' ? role === 'collector' : role.name === 'collector');
      navigate(isCollector ? '/remittances' : '/dashboard');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <div className="w-full max-w-md">
        {/* Header */}
        <div className="text-center mb-8">
          <img src={branding.logo_url || '/solarnet-mark.svg'} alt={branding.name} className="mx-auto mb-4 h-16 w-16 object-contain" />
          <h1 className="text-3xl font-bold text-foreground mb-2">{branding.name}</h1>
          <p className="text-muted-foreground">
            Sign in to your account
          </p>
        </div>

        {/* Login Form */}
        <div className="bg-card border border-border rounded-lg p-8 shadow-sm">
          <form onSubmit={handleSubmit} className="space-y-6">
            {/* Error Alert */}
            {error && (
              <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4">
                <p className="text-sm text-red-800 dark:text-red-200">{error}</p>
              </div>
            )}

            {/* Email Field */}
            <div>
              <label htmlFor="email" className="block text-sm font-medium text-foreground mb-2">
                Email Address
              </label>
              <input
                type="email"
                id="email"
                name="email"
                value={formData.email}
                onChange={handleChange}
                required
                className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                placeholder="you@company.com"
                autoComplete="username"
              />
            </div>

            {/* Password Field */}
            <div>
              <label htmlFor="password" className="block text-sm font-medium text-foreground mb-2">
                Password
              </label>
              <input
                type="password"
                id="password"
                name="password"
                value={formData.password}
                onChange={handleChange}
                required
                className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                placeholder="••••••••"
                autoComplete="current-password"
              />
            </div>

            <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/35 p-3 text-sm text-foreground">
              <input
                type="checkbox"
                name="remember"
                checked={rememberSignIn}
                onChange={(event) => setRememberSignIn(event.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-input accent-primary"
              />
              <span>
                <span className="block font-medium">Remember sign-in details on this device</span>
                <span className="mt-0.5 block text-xs text-muted-foreground">SolarNet remembers your email. Your browser or device password manager securely handles the password.</span>
              </span>
            </label>

            {/* Submit Button */}
            <button
              type="submit"
              disabled={loading}
              className="w-full bg-primary text-primary-foreground py-2 px-4 rounded-md font-medium hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? 'Signing in...' : 'Sign In'}
            </button>
          </form>

          <div className="mt-6 text-center">
            <Link to="/forgot-password" className="text-sm font-medium text-primary hover:underline">Forgot your password?</Link>
            <p className="mt-2 text-xs text-muted-foreground">Need a staff account? <Link to="/staff-signup" className="font-medium text-primary hover:underline">Submit a signup request</Link>.</p>
          </div>
        </div>

        {/* Footer */}
        <div className="mt-8 text-center">
          <p className="text-xs text-muted-foreground">
            Secure staff authentication and role-based access
          </p>
        </div>
      </div>
    </div>
  );
};

export default LoginPage;
