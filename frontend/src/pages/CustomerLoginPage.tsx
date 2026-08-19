import React, { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { LogIn } from 'lucide-react';
import customerPortalService from '../services/customerPortalService';

const CustomerLoginPage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const [branding, setBranding] = useState({ name: 'Solarnet Internet', logo_url: '', email: '', facebook_url: '' });
  const [formData, setFormData] = useState({
    email: '',
    password: '',
  });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { void customerPortalService.getBranding().then(setBranding).catch(() => undefined); }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    try {
      const result = await customerPortalService.login(formData.email, formData.password);
      
      // Store token and customer data
      localStorage.setItem('customer_token', result.access_token);
      localStorage.setItem('customer_data', JSON.stringify(result.customer));
      
      // Temporary-password users must set a personal password before they can
      // open the dashboard or suspended-account payment page.
      const requested = new URLSearchParams(location.search).get('next');
      const next = requested?.startsWith('/customer/') ? requested : '/customer/dashboard';
      navigate(result.customer.portal_password_change_required
        ? `/customer/change-password?next=${encodeURIComponent(next)}`
        : next);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Login failed. Please check your credentials.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-background via-secondary/45 to-primary/10 flex items-center justify-center p-4">
      <div className="max-w-md w-full">
        {/* Logo/Brand */}
        <div className="text-center mb-8">
          <img src={branding.logo_url || '/solarnet-mark.svg'} alt={branding.name} className="w-16 h-16 mx-auto mb-4 object-contain" />
          <h1 className="text-3xl font-bold text-foreground">{branding.name}</h1>
          <p className="text-muted-foreground mt-2">Customer Portal</p>
        </div>

        {/* Login Card */}
        <div className="bg-card border border-border rounded-2xl shadow-xl p-8">
          <h2 className="text-2xl font-bold text-foreground mb-6">Sign In</h2>

          {error && (
            <div className="mb-4 p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive text-sm" role="alert">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-foreground mb-2">
                Email Address
              </label>
              <input
                type="email"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                className="w-full px-4 py-3 border border-input rounded-lg bg-card text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                placeholder="your.email@example.com"
                required
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-foreground mb-2">
                Password
              </label>
              <input
                type="password"
                value={formData.password}
                onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                className="w-full px-4 py-3 border border-input rounded-lg bg-card text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                placeholder="Your portal password"
                required
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full bg-primary hover:bg-primary/90 text-primary-foreground font-medium py-3 px-4 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              {loading ? (
                <>
                  <div className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                  Signing in...
                </>
              ) : (
                <>
                  <LogIn className="w-5 h-5" />
                  Sign In
                </>
              )}
            </button>
          </form>

            <p className="mt-4 text-center text-xs text-muted-foreground">
            New accounts use a temporary password and must change it after their first sign-in.
          </p>
            <p className="mt-2 text-center text-xs text-muted-foreground">
            If you have not created your own password yet, use <strong>Solarnet123</strong>, then change it immediately.
          </p>

          <div className="mt-6 pt-6 border-t border-border space-y-3">
            <p className="text-center text-sm text-muted-foreground">
              New to Solarnet?{' '}
              <a href="/signup" className="text-primary hover:text-primary/80 font-medium" data-testid="customer-login-signup-link">
                Sign up for service
              </a>
            </p>
            <p className="text-center text-sm text-muted-foreground">
              Need help? {branding.email && <><a href={`mailto:${branding.email}`} className="text-primary hover:text-primary/80">Email customer support</a>{branding.facebook_url && ' or '}</>}
              {branding.facebook_url && <a href={branding.facebook_url} target="_blank" rel="noreferrer" className="text-primary hover:text-primary/80">message us on Facebook</a>}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default CustomerLoginPage;
