import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import customerPortalService from '@/services/customerPortalService';

const LoginPage: React.FC = () => {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [loginType, setLoginType] = useState<'staff' | 'client'>('staff');
  
  const [formData, setFormData] = useState({
    email: '',
    password: '',
  });
  const [error, setError] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(false);

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
      if (loginType === 'staff') {
        await login(formData);
        navigate('/dashboard');
      } else {
        const result = await customerPortalService.login(formData.email, formData.password);
        localStorage.setItem('customer_token', result.access_token);
        localStorage.setItem('customer_data', JSON.stringify(result.customer));
        navigate(result.customer.portal_password_change_required ? '/customer/change-password' : '/customer/dashboard');
      }
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
          <h1 className="text-3xl font-bold text-foreground mb-2">
            ISP Billing System
          </h1>
          <p className="text-muted-foreground">
            One secure sign-in for staff and clients
          </p>
        </div>

        {/* Login Form */}
        <div className="bg-card border border-border rounded-lg p-8 shadow-sm">
          <div className="mb-6 grid grid-cols-2 rounded-lg bg-muted p-1 text-sm font-medium">
            <button type="button" onClick={() => { setLoginType('staff'); setError(''); }} className={`rounded-md px-3 py-2 ${loginType === 'staff' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground'}`}>Staff</button>
            <button type="button" onClick={() => { setLoginType('client'); setError(''); }} className={`rounded-md px-3 py-2 ${loginType === 'client' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground'}`}>Client</button>
          </div>
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
                placeholder={loginType === 'staff' ? 'admin@solarnet.com' : 'your.email@example.com'}
                autoComplete="email"
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

            {/* Submit Button */}
            <button
              type="submit"
              disabled={loading}
              className="w-full bg-primary text-primary-foreground py-2 px-4 rounded-md font-medium hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? 'Signing in...' : `Sign in as ${loginType === 'staff' ? 'staff' : 'client'}`}
            </button>
          </form>

          {/* Register Link */}
          {loginType === 'staff' && <div className="mt-6 text-center">
            <p className="text-sm text-muted-foreground">
              Don't have an account?{' '}
              <Link to="/register" className="text-primary hover:underline font-medium">
                Register here
              </Link>
            </p>
          </div>}
          {loginType === 'client' && <p className="mt-6 text-center text-xs text-muted-foreground">First sign-in: use your email and temporary password, then create a private password.</p>}
        </div>

        {/* Footer */}
        <div className="mt-8 text-center">
          <p className="text-xs text-muted-foreground">
            Phase 2: Authentication & RBAC System
          </p>
        </div>
      </div>
    </div>
  );
};

export default LoginPage;
