import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Home,
  FileText,
  CreditCard,
  User,
  LogOut,
  DollarSign,
  Calendar,
  AlertCircle,
  KeyRound,
  Wifi,
} from 'lucide-react';
import customerPortalService from '../services/customerPortalService';
import type { Customer } from '../types/api';
import { formatPHP } from '../lib/currency';
import CustomerAppInstallCard from '../components/customer/CustomerAppInstallCard';

const CustomerDashboardPage: React.FC = () => {
  const navigate = useNavigate();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [showPasswordForm, setShowPasswordForm] = useState(false);
  const [passwordForm, setPasswordForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [passwordMessage, setPasswordMessage] = useState('');
  const [passwordError, setPasswordError] = useState('');
  const [passwordSaving, setPasswordSaving] = useState(false);

  useEffect(() => {
    fetchDashboardData();
  }, []);

  const fetchDashboardData = async () => {
    try {
      const data = await customerPortalService.getDashboard();
      if (data.status === 'payment_required' && data.payment_required) {
        navigate(`/payment-required/${data.payment_required.customer_id}`);
        return;
      }
      setCustomer(data.customer);
      if (data.customer?.portal_password_change_required) setShowPasswordForm(true);
      setStats(data.stats);
    } catch (error) {
      console.error('Error fetching dashboard:', error);
      // If unauthorized, redirect to login
      try {
        const raw = localStorage.getItem('customer_data');
        const parsed = raw ? JSON.parse(raw) : null;
        if (parsed?.id) {
          navigate(`/payment-required/${parsed.id}`);
          return;
        }
      } catch {
        // fall through to login redirect
      }
      localStorage.removeItem('customer_token');
      localStorage.removeItem('customer_data');
      navigate('/customer/login');
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = () => {
    localStorage.removeItem('customer_token');
    localStorage.removeItem('customer_data');
    navigate('/customer/login');
  };

  const changePassword = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    setPasswordMessage('');
    setPasswordError('');
    setPasswordSaving(true);
    try {
      const response = await customerPortalService.changePassword(passwordForm);
      setPasswordMessage(response.message);
      setPasswordForm({ current_password: '', password: '', password_confirmation: '' });
      setShowPasswordForm(false);
    } catch (error: any) {
      setPasswordError(error.response?.data?.message || 'Could not change your password.');
    } finally {
      setPasswordSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-center">
          <div className="w-16 h-16 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-4" />
          <p className="text-gray-600">Loading your dashboard...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <header className="bg-white shadow-sm border-b border-gray-200">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between items-center h-16">
            <div className="flex items-center gap-3">
              <img src="/solarnet-mark.svg" alt="Solarnet" className="w-10 h-10" />
              <div>
                <h1 className="text-xl font-bold text-gray-900">Solarnet Internet</h1>
                <p className="text-xs text-gray-500">Customer Portal</p>
              </div>
            </div>
            <button
              onClick={handleLogout}
              className="flex items-center gap-2 px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
            >
              <LogOut className="w-4 h-4" />
              <span className="hidden sm:inline">Logout</span>
            </button>
          </div>
        </div>
      </header>

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Welcome Section */}
        <div className="mb-8">
          <h2 className="text-3xl font-bold text-gray-900 mb-2">
            Welcome back, {customer?.full_name}!
          </h2>
          <p className="text-gray-600">Account: {customer?.account_number}</p>
        </div>
        {customer?.portal_password_change_required && (
          <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <strong>Change your temporary password now.</strong> This account is using its initial portal password. Open “Change portal password” below before continuing.
          </div>
        )}

        {/* Stats Cards */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
          <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
            <div className="flex items-center justify-between mb-4">
              <div className="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <FileText className="w-6 h-6 text-blue-600" />
              </div>
              <span className="text-2xl font-bold text-gray-900">{stats?.total_invoices || 0}</span>
            </div>
            <h3 className="text-sm font-medium text-gray-600">Total Invoices</h3>
          </div>

          <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
            <div className="flex items-center justify-between mb-4">
              <div className="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                <AlertCircle className="w-6 h-6 text-yellow-600" />
              </div>
              <span className="text-2xl font-bold text-gray-900">{stats?.unpaid_invoices || 0}</span>
            </div>
            <h3 className="text-sm font-medium text-gray-600">Unpaid Invoices</h3>
          </div>

          <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
            <div className="flex items-center justify-between mb-4">
              <div className="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                <DollarSign className="w-6 h-6 text-red-600" />
              </div>
              <span className="text-2xl font-bold text-gray-900">
                {formatPHP(stats?.total_outstanding)}
              </span>
            </div>
            <h3 className="text-sm font-medium text-gray-600">Outstanding Balance</h3>
          </div>

          <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
            <div className="flex items-center justify-between mb-4">
              <div className="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <CreditCard className="w-6 h-6 text-green-600" />
              </div>
              <span className="text-2xl font-bold text-gray-900">
                {stats?.last_payment?.amount != null ? formatPHP(stats.last_payment.amount) : '-'}
              </span>
            </div>
            <h3 className="text-sm font-medium text-gray-600">Last Payment</h3>
            {stats?.last_payment && (
              <p className="text-xs text-gray-500 mt-1">{stats.last_payment.date}</p>
            )}
          </div>
        </div>

        {/* Account Information */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
          {/* Service Details */}
          <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
            <h3 className="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
              <Wifi className="w-5 h-5 text-blue-600" />
              Service Plan
            </h3>
            <div className="space-y-3">
              <div className="flex justify-between py-2 border-b border-gray-100">
                <span className="text-sm text-gray-600">Plan Name</span>
                <span className="text-sm font-medium text-gray-900">
                  {customer?.service_plan?.name || 'N/A'}
                </span>
              </div>
              <div className="flex justify-between py-2 border-b border-gray-100">
                <span className="text-sm text-gray-600">Download Speed</span>
                <span className="text-sm font-medium text-gray-900">
                  {customer?.service_plan?.download_speed || 0} Mbps
                </span>
              </div>
              <div className="flex justify-between py-2 border-b border-gray-100">
                <span className="text-sm text-gray-600">Upload Speed</span>
                <span className="text-sm font-medium text-gray-900">
                  {customer?.service_plan?.upload_speed || 0} Mbps
                </span>
              </div>
              <div className="flex justify-between py-2">
                <span className="text-sm text-gray-600">Monthly Fee</span>
                <span className="text-sm font-bold text-blue-600">
                  {formatPHP(customer?.service_plan?.price)}
                </span>
              </div>
              <div className="pt-2">
                <button type="button" onClick={() => { setShowPasswordForm((current) => !current); setPasswordError(''); }}
                  className="inline-flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-700">
                  <KeyRound className="h-4 w-4" /> Change portal password
                </button>
                {passwordMessage && <p className="mt-2 text-xs text-green-700">{passwordMessage}</p>}
                {showPasswordForm && (
                  <form onSubmit={changePassword} className="mt-3 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <input type="password" required value={passwordForm.current_password} placeholder="Current password"
                      onChange={(e) => setPasswordForm({ ...passwordForm, current_password: e.target.value })}
                      className="w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                    <input type="password" required minLength={10} value={passwordForm.password} placeholder="New password (10+ characters)"
                      onChange={(e) => setPasswordForm({ ...passwordForm, password: e.target.value })}
                      className="w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                    <input type="password" required minLength={10} value={passwordForm.password_confirmation} placeholder="Confirm new password"
                      onChange={(e) => setPasswordForm({ ...passwordForm, password_confirmation: e.target.value })}
                      className="w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                    {passwordError && <p className="text-xs text-red-700">{passwordError}</p>}
                    <button type="submit" disabled={passwordSaving} className="rounded bg-blue-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">
                      {passwordSaving ? 'Saving…' : 'Save new password'}
                    </button>
                  </form>
                )}
              </div>
            </div>
          </div>

          {/* Account Details */}
          <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
            <h3 className="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
              <User className="w-5 h-5 text-blue-600" />
              Account Details
            </h3>
            <div className="space-y-3">
              <div className="flex justify-between py-2 border-b border-gray-100">
                <span className="text-sm text-gray-600">Status</span>
                <span className={`text-sm font-medium px-2 py-1 rounded ${
                  customer?.status === 'active' 
                    ? 'bg-green-100 text-green-800' 
                    : 'bg-red-100 text-red-800'
                }`}>
                  {customer?.status?.toUpperCase() || 'N/A'}
                </span>
              </div>
              <div className="flex justify-between py-2 border-b border-gray-100">
                <span className="text-sm text-gray-600">Email</span>
                <span className="text-sm font-medium text-gray-900">{customer?.email}</span>
              </div>
              <div className="flex justify-between py-2 border-b border-gray-100">
                <span className="text-sm text-gray-600">Phone</span>
                <span className="text-sm font-medium text-gray-900">
                  {customer?.contact_number || 'N/A'}
                </span>
              </div>
              <div className="flex justify-between py-2">
                <span className="text-sm text-gray-600">IP Address</span>
                <span className="text-sm font-medium text-gray-900">
                  {customer?.ip_address || 'N/A'}
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* Quick Actions */}
        <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
          <h3 className="text-lg font-bold text-gray-900 mb-4">Quick Actions</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <button
              onClick={() => navigate('/customer/invoices')}
              className="flex items-center gap-3 p-4 border-2 border-gray-200 rounded-lg hover:border-blue-500 hover:bg-blue-50 transition-colors"
            >
              <FileText className="w-6 h-6 text-blue-600" />
              <div className="text-left">
                <p className="font-medium text-gray-900">View Invoices</p>
                <p className="text-sm text-gray-500">See all your bills</p>
              </div>
            </button>

            <button
              onClick={() => navigate('/customer/payments')}
              className="flex items-center gap-3 p-4 border-2 border-gray-200 rounded-lg hover:border-blue-500 hover:bg-blue-50 transition-colors"
            >
              <CreditCard className="w-6 h-6 text-green-600" />
              <div className="text-left">
                <p className="font-medium text-gray-900">Payment History</p>
                <p className="text-sm text-gray-500">Track your payments</p>
              </div>
            </button>

            <button
              onClick={() => navigate('/customer/profile')}
              className="flex items-center gap-3 p-4 border-2 border-gray-200 rounded-lg hover:border-blue-500 hover:bg-blue-50 transition-colors"
            >
              <User className="w-6 h-6 text-purple-600" />
              <div className="text-left">
                <p className="font-medium text-gray-900">Update Profile</p>
                <p className="text-sm text-gray-500">Edit your details</p>
              </div>
            </button>
          </div>
          <CustomerAppInstallCard />
        </div>
      </div>
    </div>
  );
};

export default CustomerDashboardPage;
