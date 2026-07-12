import React, { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { servicePlanService, type ServicePlan } from '@/services/servicePlanService';
import { routerService, type Router } from '@/services/routerService';

interface CustomerFormData {
  account_number: string;
  full_name: string;
  address: string;
  gps_coordinates?: { latitude: number; longitude: number };
  contact_number: string;
  email?: string;
  installation_date: string;
  service_plan_id?: string;
  router_id?: string;
  monthly_fee: string;
  mac_address?: string;
  ip_address?: string;
  vlan?: string;
  status: 'active' | 'suspended' | 'expired' | 'pending';
  onu_information?: string;
  olt_port?: string;
  technician_id?: string;
  notes?: string;
}

const CreateCustomerPage: React.FC = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [servicePlans, setServicePlans] = useState<ServicePlan[]>([]);
  const [routers, setRouters] = useState<Router[]>([]);
  const [portalCredentials, setPortalCredentials] = useState<{
    email: string;
    password: string;
    portal_url: string;
    welcome_email_sent: boolean;
  } | null>(null);

  const [formData, setFormData] = useState<CustomerFormData>({
    account_number: `ACC${Date.now().toString().slice(-8)}`,
    full_name: '',
    address: '',
    contact_number: '',
    installation_date: new Date().toISOString().split('T')[0],
    monthly_fee: '0',
    status: 'pending',
    mac_address: searchParams.get('mac') || '',
    ip_address: searchParams.get('ip') || '',
    router_id: searchParams.get('router') || '',
  });

  useEffect(() => {
    void loadReferenceData();
  }, []);

  const loadReferenceData = async (): Promise<void> => {
    try {
      const [plans, rs] = await Promise.all([
        servicePlanService.getAll().catch(() => [] as ServicePlan[]),
        routerService.getAll().catch(() => [] as Router[]),
      ]);
      setServicePlans(plans.filter((p) => p.is_active));
      setRouters(rs.filter((r) => r.is_active));
    } catch (err) {
      // Non-fatal — user can still fill in the form manually.
    }
  };

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>
  ): void => {
    const { name, value } = e.target;
    setFormData((prev) => {
      const next: CustomerFormData = { ...prev, [name]: value } as CustomerFormData;
      // Auto-fill monthly_fee when a service plan is picked
      if (name === 'service_plan_id') {
        const plan = servicePlans.find((p) => p.id === value);
        if (plan) next.monthly_fee = String(plan.price);
      }
      return next;
    });
    setError('');
  };

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();
    setError('');
    setLoading(true);

    // Strip empty-string keys so backend "nullable" rules apply cleanly
    const payload: Record<string, unknown> = Object.fromEntries(
      Object.entries(formData).filter(([, v]) => v !== '' && v !== undefined)
    );
    payload.send_welcome_email = true;
    payload.sync_queue = Boolean(formData.router_id && formData.service_plan_id);

    try {
      const response = await api.post('/customers', payload);
      const creds = response.data?.portal_credentials;
      const queueMsg = response.data?.queue_sync;
      if (creds?.password) {
        setPortalCredentials(creds);
      } else {
        navigate('/customers');
      }
      if (queueMsg && !String(queueMsg).startsWith('synced')) {
        setError(`Customer created, but MikroTik queue sync said: ${queueMsg}`);
      }
    } catch (err: any) {
      const validation = err.response?.data?.errors;
      if (validation && typeof validation === 'object') {
        const firstMsg = Object.values(validation).flat()[0] as string | undefined;
        setError(firstMsg || err.response?.data?.message || 'Failed to create customer');
      } else {
        setError(err.response?.data?.message || 'Failed to create customer');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <DashboardLayout>
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header */}
        <div>
          <h1 className="text-3xl font-bold text-foreground">Add New Customer</h1>
          <p className="text-muted-foreground mt-1">Create a new ISP subscriber account</p>
        </div>

        {/* Error Alert */}
        {error && (
          <div
            className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4"
            data-testid="create-customer-error"
          >
            <p className="text-sm text-red-800 dark:text-red-200">{error}</p>
          </div>
        )}

        {/* Success: show one-time portal credentials */}
        {portalCredentials && (
          <div
            className="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg p-6"
            data-testid="portal-credentials-card"
          >
            <h2 className="text-lg font-bold text-emerald-900 dark:text-emerald-100 mb-2">
              Customer created — portal credentials
            </h2>
            <p className="text-sm text-emerald-800 dark:text-emerald-200 mb-4">
              Share these credentials with the customer. This password is shown <strong>once</strong> and
              is not recoverable.
              {portalCredentials.welcome_email_sent && ' A welcome email was also queued.'}
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
              <div>
                <div className="text-xs uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                  Email
                </div>
                <div className="font-mono text-emerald-900 dark:text-emerald-100 break-all">
                  {portalCredentials.email}
                </div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                  Password
                </div>
                <div className="flex items-center gap-2">
                  <code
                    className="font-mono font-bold text-emerald-900 dark:text-emerald-100 bg-white dark:bg-black/40 px-2 py-1 rounded"
                    data-testid="portal-password"
                  >
                    {portalCredentials.password}
                  </code>
                  <button
                    type="button"
                    onClick={() => navigator.clipboard.writeText(portalCredentials.password)}
                    className="text-xs text-emerald-700 dark:text-emerald-300 hover:underline"
                    data-testid="copy-portal-password"
                  >
                    Copy
                  </button>
                </div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                  Portal URL
                </div>
                <a
                  href={portalCredentials.portal_url}
                  className="font-mono text-emerald-900 dark:text-emerald-100 hover:underline break-all"
                >
                  {portalCredentials.portal_url}
                </a>
              </div>
            </div>
            <div className="mt-5 flex gap-3">
              <button
                type="button"
                onClick={() => navigate('/customers')}
                className="px-4 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700"
                data-testid="portal-creds-go-list"
              >
                Go to Customers
              </button>
              <button
                type="button"
                onClick={() => setPortalCredentials(null)}
                className="px-4 py-2 border border-emerald-300 text-emerald-800 dark:text-emerald-200 rounded-md hover:bg-emerald-100 dark:hover:bg-emerald-900/40"
              >
                Add Another Customer
              </button>
            </div>
          </div>
        )}

        {/* Form */}
        <form
          onSubmit={handleSubmit}
          className="bg-card border border-border rounded-lg p-6 space-y-6"
          data-testid="create-customer-form"
        >
          {/* Basic Information */}
          <div>
            <h2 className="text-xl font-semibold text-foreground mb-4">Basic Information</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Account Number *</label>
                <input
                  type="text"
                  name="account_number"
                  value={formData.account_number}
                  onChange={handleChange}
                  required
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-account-number"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Full Name *</label>
                <input
                  type="text"
                  name="full_name"
                  value={formData.full_name}
                  onChange={handleChange}
                  required
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-full-name"
                />
              </div>

              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-foreground mb-2">Address *</label>
                <textarea
                  name="address"
                  value={formData.address}
                  onChange={handleChange}
                  required
                  rows={3}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-address"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Contact Number *</label>
                <input
                  type="tel"
                  name="contact_number"
                  value={formData.contact_number}
                  onChange={handleChange}
                  required
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-contact-number"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Email</label>
                <input
                  type="email"
                  name="email"
                  value={formData.email || ''}
                  onChange={handleChange}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-email"
                />
              </div>
            </div>
          </div>

          {/* Service Information */}
          <div>
            <h2 className="text-xl font-semibold text-foreground mb-4">Service Information</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Installation Date *</label>
                <input
                  type="date"
                  name="installation_date"
                  value={formData.installation_date}
                  onChange={handleChange}
                  required
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-installation-date"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Monthly Fee (₱) *</label>
                <input
                  type="number"
                  name="monthly_fee"
                  value={formData.monthly_fee}
                  onChange={handleChange}
                  required
                  min="0"
                  step="0.01"
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-monthly-fee"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Service Plan</label>
                <select
                  name="service_plan_id"
                  value={formData.service_plan_id || ''}
                  onChange={handleChange}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="select-service-plan"
                >
                  <option value="">No Plan Assigned</option>
                  {servicePlans.map((plan) => (
                    <option key={plan.id} value={plan.id}>
                      {plan.name} — {plan.download_speed}/{plan.upload_speed} Mbps — ₱{Number(plan.price).toFixed(2)}/mo
                    </option>
                  ))}
                </select>
                <p className="text-xs text-muted-foreground mt-1">
                  Select a bandwidth plan for this customer. Monthly fee will auto-fill.
                </p>
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Router (MikroTik)</label>
                <select
                  name="router_id"
                  value={formData.router_id || ''}
                  onChange={handleChange}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="select-router"
                >
                  <option value="">No Router Assigned</option>
                  {routers.map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.name} ({r.host})
                    </option>
                  ))}
                </select>
                <p className="text-xs text-muted-foreground mt-1">
                  Required for MikroTik queue provisioning.
                </p>
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Status *</label>
                <select
                  name="status"
                  value={formData.status}
                  onChange={handleChange}
                  required
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="select-status"
                >
                  <option value="pending">Pending</option>
                  <option value="active">Active</option>
                  <option value="suspended">Suspended</option>
                  <option value="expired">Expired</option>
                </select>
              </div>
            </div>
          </div>

          {/* Network Information */}
          <div>
            <h2 className="text-xl font-semibold text-foreground mb-4">Network Information</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-foreground mb-2">MAC Address</label>
                <input
                  type="text"
                  name="mac_address"
                  value={formData.mac_address || ''}
                  onChange={handleChange}
                  placeholder="00:00:00:00:00:00"
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-mac-address"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">IP Address</label>
                <input
                  type="text"
                  name="ip_address"
                  value={formData.ip_address || ''}
                  onChange={handleChange}
                  placeholder="192.168.1.1"
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-ip-address"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">VLAN</label>
                <input
                  type="text"
                  name="vlan"
                  value={formData.vlan || ''}
                  onChange={handleChange}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-vlan"
                />
              </div>
            </div>
          </div>

          {/* ONU/OLT Information */}
          <div>
            <h2 className="text-xl font-semibold text-foreground mb-4">ONU/OLT Information (Fiber)</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-foreground mb-2">ONU Information</label>
                <input
                  type="text"
                  name="onu_information"
                  value={formData.onu_information || ''}
                  onChange={handleChange}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-onu-information"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">OLT Port</label>
                <input
                  type="text"
                  name="olt_port"
                  value={formData.olt_port || ''}
                  onChange={handleChange}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="input-olt-port"
                />
              </div>
            </div>
          </div>

          {/* Additional Notes */}
          <div>
            <label className="block text-sm font-medium text-foreground mb-2">Notes</label>
            <textarea
              name="notes"
              value={formData.notes || ''}
              onChange={handleChange}
              rows={4}
              className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
              placeholder="Additional notes about this customer..."
              data-testid="input-notes"
            />
          </div>

          {/* Actions */}
          <div className="flex gap-4 pt-4">
            <button
              type="submit"
              disabled={loading}
              className="px-6 py-2 bg-primary text-primary-foreground rounded-md hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
              data-testid="submit-create-customer"
            >
              {loading ? 'Creating…' : 'Create Customer'}
            </button>
            <button
              type="button"
              onClick={() => navigate('/customers')}
              className="px-6 py-2 bg-secondary text-secondary-foreground rounded-md hover:opacity-90 transition-opacity"
              data-testid="cancel-create-customer"
            >
              Cancel
            </button>
          </div>
        </form>
      </div>
    </DashboardLayout>
  );
};

export default CreateCustomerPage;
