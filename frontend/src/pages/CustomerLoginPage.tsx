import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LogIn, CreditCard } from 'lucide-react';
import customerPortalService from '../services/customerPortalService';

const CustomerLoginPage: React.FC = () => {
  const navigate = useNavigate();
  const [branding, setBranding] = useState({ name: 'Solarnet Internet', logo_url: '' });
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
      navigate(result.customer.portal_password_change_required ? '/customer/change-password' : '/customer/dashboard');
    } catch (err: any) {
      setError(err.response?.data?.message || 'Login failed. Please check your credentials.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex items-center justify-center p-4">
      <div className="max-w-md w-full">
        {/* Logo/Brand */}
        <div className="text-center mb-8">
          <img src={branding.logo_url || '/solarnet-mark.svg'} alt={branding.name} className="w-16 h-16 mx-auto mb-4 object-contain" />
          <h1 className="text-3xl font-bold text-gray-900">{branding.name}</h1>
          <p className="text-gray-600 mt-2">Customer Portal</p>
        </div>

        {/* Login Card */}
        <div className="bg-white rounded-2xl shadow-xl p-8">
          <h2 className="text-2xl font-bold text-gray-900 mb-6">Sign In</h2>

          {error && (
            <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Email Address
              </label>
              <input
                type="email"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="your.email@example.com"
                required
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Password
              </label>
              <input
                type="password"
                value={formData.password}
                onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Your portal password"
                required
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
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

          <p className="mt-4 text-center text-xs text-gray-500">
            New accounts use a temporary password and must change it after their first sign-in.
          </p>
          <p className="mt-2 text-center text-xs text-gray-500">
            If you have not created your own password yet, use <strong>Solarnet123</strong>, then change it immediately.
          </p>

          <div className="mt-6 pt-6 border-t border-gray-200 space-y-3">
            <p className="text-center text-sm text-gray-600">
              New to Solarnet?{' '}
              <a href="/signup" className="text-blue-600 hover:text-blue-700 font-medium" data-testid="customer-login-signup-link">
                Sign up for service
              </a>
            </p>
            <p className="text-center text-sm text-gray-600">
              Need help? Contact support at{' '}
              <a href="mailto:support@solarnetinternet.com" className="text-blue-600 hover:text-blue-700">
                support@solarnetinternet.com
              </a>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default CustomerLoginPage;
