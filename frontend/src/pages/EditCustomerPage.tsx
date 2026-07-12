import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { customerService } from '@/services/customerService';
import { servicePlanService, type ServicePlan } from '@/services/servicePlanService';
import { routerService, type Router } from '@/services/routerService';
import type { Customer } from '@/types/api';

interface FormData {
  account_number: string;
  full_name: string;
  address: string;
  contact_number: string;
  email: string;
  installation_date: string;
  service_plan_id: string;
  router_id: string;
  monthly_fee: string;
  mac_address: string;
  ip_address: string;
  vlan: string;
  status: 'active' | 'suspended' | 'expired' | 'pending';
  onu_information: string;
  olt_port: string;
  notes: string;
}

const EMPTY: FormData = {
  account_number: '', full_name: '', address: '', contact_number: '', email: '',
  installation_date: '', service_plan_id: '', router_id: '', monthly_fee: '0',
  mac_address: '', ip_address: '', vlan: '', status: 'active',
  onu_information: '', olt_port: '', notes: '',
};

const EditCustomerPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [loading, setLoading] = useState<boolean>(true);
  const [saving, setSaving] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [notice, setNotice] = useState<string>('');
  const [servicePlans, setServicePlans] = useState<ServicePlan[]>([]);
  const [routers, setRouters] = useState<Router[]>([]);
  const [formData, setFormData] = useState<FormData>(EMPTY);

  useEffect(() => {
    if (!id) return;
    void load();
  }, [id]);

  const load = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const [customer, plans, rs] = await Promise.all([
        customerService.getCustomer(id!),
        servicePlanService.getAll().catch(() => [] as ServicePlan[]),
        routerService.getAll().catch(() => [] as Router[]),
      ]);
      setServicePlans(plans.filter((p) => p.is_active));
      setRouters(rs.filter((r) => r.is_active));

      // API returns { data: { ... } } — customerService.getCustomer already unwraps to Customer
      const c = customer as Customer;
      setFormData({
        account_number: c.account_number ?? '',
        full_name: c.full_name ?? '',
        address: c.address ?? '',
        contact_number: c.contact_number ?? '',
        email: c.email ?? '',
        installation_date: (c.installation_date ?? '').split('T')[0],
        service_plan_id: c.service_plan_id ?? '',
        router_id: (c as any).router_id ?? '',
        monthly_fee: String(c.monthly_fee ?? '0'),
        mac_address: c.mac_address ?? '',
        ip_address: c.ip_address ?? '',
        vlan: (c as any).vlan ?? '',
        status: (c.status as FormData['status']) ?? 'active',
        onu_information: (c as any).onu_information ?? '',
        olt_port: (c as any).olt_port ?? '',
        notes: (c as any).notes ?? '',
      });
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Failed to load customer');
    } finally {
      setLoading(false);
    }
  };

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>
  ): void => {
    const { name, value } = e.target;
    setFormData((prev) => {
      const next = { ...prev, [name]: value } as FormData;
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
    if (!id) return;
    setSaving(true);
    setError('');
    setNotice('');

    // Strip empty-string keys so backend "nullable" rules apply cleanly
    const payload: Record<string, unknown> = Object.fromEntries(
      Object.entries(formData).filter(([, v]) => v !== '' && v !== undefined)
    );

    try {
      await api.put(`/customers/${id}`, payload);
      setNotice('Customer updated.');
      setTimeout(() => navigate('/customers'), 700);
    } catch (err: any) {
      const validation = err.response?.data?.errors;
      if (validation && typeof validation === 'object') {
        const firstMsg = Object.values(validation).flat()[0] as string | undefined;
        setError(firstMsg || err.response?.data?.message || 'Failed to update customer');
      } else {
        setError(err.response?.data?.message || 'Failed to update customer');
      }
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <DashboardLayout>
        <div className="p-12 text-center text-muted-foreground">Loading customer…</div>
      </DashboardLayout>
    );
  }

  return (
    <DashboardLayout>
      <div className="max-w-4xl mx-auto space-y-6">
        <div>
          <h1 className="text-3xl font-bold text-foreground">Edit Customer</h1>
          <p className="text-muted-foreground mt-1">Update subscriber details for {formData.account_number}</p>
        </div>

        {error && (
          <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4 text-sm text-red-800 dark:text-red-200" data-testid="edit-customer-error">
            {error}
          </div>
        )}
        {notice && (
          <div className="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-md p-4 text-sm text-emerald-800 dark:text-emerald-200" data-testid="edit-customer-notice">
            {notice}
          </div>
        )}

        <form onSubmit={handleSubmit} className="bg-card border border-border rounded-lg p-6 space-y-6" data-testid="edit-customer-form">
          {/* Basic Information */}
          <section>
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
                  pattern="\d{10}"
                  maxLength={10}
                  inputMode="numeric"
                  title="Account number must be exactly 10 digits (no letters)"
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary font-mono"
                  data-testid="edit-account-number"
                />
                <p className="text-xs text-muted-foreground mt-1">Exactly 10 digits, no letters.</p>
              </div>
              <Field label="Full Name *" name="full_name" value={formData.full_name} onChange={handleChange} required testId="edit-full-name" />
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-foreground mb-2">Address *</label>
                <textarea name="address" value={formData.address} onChange={handleChange} required rows={3}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="edit-address" />
              </div>
              <Field label="Contact Number *" name="contact_number" value={formData.contact_number} onChange={handleChange} type="tel" required testId="edit-contact-number" />
              <Field label="Email" name="email" value={formData.email} onChange={handleChange} type="email" testId="edit-email" />
            </div>
          </section>

          {/* Service Information */}
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-4">Service Information</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Field label="Installation Date *" name="installation_date" value={formData.installation_date} onChange={handleChange} type="date" required testId="edit-installation-date" />
              <Field label="Monthly Fee (₱) *" name="monthly_fee" value={formData.monthly_fee} onChange={handleChange} type="number" required testId="edit-monthly-fee" />

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Service Plan</label>
                <select name="service_plan_id" value={formData.service_plan_id} onChange={handleChange}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="edit-service-plan">
                  <option value="">No Plan Assigned</option>
                  {servicePlans.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} — {p.download_speed}/{p.upload_speed} Mbps — ₱{Number(p.price).toFixed(2)}/mo
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Router (MikroTik)</label>
                <select name="router_id" value={formData.router_id} onChange={handleChange}
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="edit-router">
                  <option value="">No Router Assigned</option>
                  {routers.map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.name} ({r.host})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Status *</label>
                <select name="status" value={formData.status} onChange={handleChange} required
                  className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  data-testid="edit-status">
                  <option value="pending">Pending</option>
                  <option value="active">Active</option>
                  <option value="suspended">Suspended</option>
                  <option value="expired">Expired</option>
                </select>
              </div>
            </div>
          </section>

          {/* Network Information */}
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-4">Network Information</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Field label="MAC Address" name="mac_address" value={formData.mac_address} onChange={handleChange} placeholder="00:00:00:00:00:00" testId="edit-mac-address" />
              <Field label="IP Address" name="ip_address" value={formData.ip_address} onChange={handleChange} placeholder="192.168.1.1" testId="edit-ip-address" />
              <Field label="VLAN" name="vlan" value={formData.vlan} onChange={handleChange} testId="edit-vlan" />
            </div>
          </section>

          {/* ONU / OLT */}
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-4">ONU/OLT Information (Fiber)</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Field label="ONU Information" name="onu_information" value={formData.onu_information} onChange={handleChange} testId="edit-onu-information" />
              <Field label="OLT Port" name="olt_port" value={formData.olt_port} onChange={handleChange} testId="edit-olt-port" />
            </div>
          </section>

          {/* Notes */}
          <div>
            <label className="block text-sm font-medium text-foreground mb-2">Notes</label>
            <textarea name="notes" value={formData.notes} onChange={handleChange} rows={4}
              className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
              data-testid="edit-notes" />
          </div>

          {/* Actions */}
          <div className="flex gap-4 pt-4">
            <button type="submit" disabled={saving}
              className="px-6 py-2 bg-primary text-primary-foreground rounded-md hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
              data-testid="submit-edit-customer">
              {saving ? 'Saving…' : 'Save Changes'}
            </button>
            <button type="button" onClick={() => navigate('/customers')}
              className="px-6 py-2 bg-secondary text-secondary-foreground rounded-md hover:opacity-90 transition-opacity"
              data-testid="cancel-edit-customer">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </DashboardLayout>
  );
};

interface FieldProps {
  label: string;
  name: string;
  value: string;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  type?: string;
  required?: boolean;
  placeholder?: string;
  testId?: string;
}
const Field: React.FC<FieldProps> = ({ label, name, value, onChange, type = 'text', required, placeholder, testId }) => (
  <div>
    <label className="block text-sm font-medium text-foreground mb-2">{label}</label>
    <input type={type} name={name} value={value} onChange={onChange} required={required} placeholder={placeholder}
      min={type === 'number' ? '0' : undefined}
      step={type === 'number' ? '0.01' : undefined}
      className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
      data-testid={testId} />
  </div>
);

export default EditCustomerPage;
