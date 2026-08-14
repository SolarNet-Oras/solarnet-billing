import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { servicePlanService, type ServicePlan } from '@/services/servicePlanService';
import { routerService, type Router } from '@/services/routerService';
import type { Customer } from '@/types/api';
import { Activity, Ban, CheckCircle2, Crosshair, MapPin, RefreshCw, Router as RouterIcon, Wifi } from 'lucide-react';

interface DhcpLease {
  id: string;
  mac_address: string;
  ip_address: string;
  hostname: string | null;
  rate_limit: string | null;
  status: string;
  server: string | null;
  is_dynamic: boolean;
  last_seen_at: string | null;
  router?: { id: string; name: string } | null;
}

interface CustomerDetailResponse {
  status: string;
  data: Customer;
  dhcp_lease?: DhcpLease | null;
}

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
  coordinates: string;
}

const EMPTY: FormData = {
  account_number: '', full_name: '', address: '', contact_number: '', email: '',
  installation_date: '', service_plan_id: '', router_id: '', monthly_fee: '0',
  mac_address: '', ip_address: '', vlan: '', status: 'active',
  onu_information: '', olt_port: '', notes: '', coordinates: '',
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
  const [dhcpLease, setDhcpLease] = useState<DhcpLease | null>(null);
  const [showLease, setShowLease] = useState<boolean>(false);
  const [networkAction, setNetworkAction] = useState<'suspend' | 'restore' | 'sync' | null>(null);

  useEffect(() => {
    if (!id) return;
    void load();
  }, [id]);

  const load = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const [customerResponse, plans, rs] = await Promise.all([
        api.get<CustomerDetailResponse>(`/customers/${id}`),
        servicePlanService.getAll().catch(() => [] as ServicePlan[]),
        routerService.getAll().catch(() => [] as Router[]),
      ]);
      setServicePlans(plans.filter((p) => p.is_active));
      setRouters(rs.filter((r) => r.is_active));

      // API returns { data: { ... } } — customerService.getCustomer already unwraps to Customer
      const c = customerResponse.data.data;
      setDhcpLease(customerResponse.data.dhcp_lease ?? null);
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
        coordinates: c.gps_coordinates ? `${c.gps_coordinates.latitude}, ${c.gps_coordinates.longitude}` : '',
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

  const useCurrentLocation = (): void => {
    if (!navigator.geolocation) {
      setError('This browser cannot provide location. Enter the coordinates manually.');
      return;
    }
    if (!window.confirm(`Proceed only when this device is at ${formData.full_name || 'the customer'}'s exact installation location. The captured point will be saved when you update the customer.`)) return;
    setNotice('Requesting this device location…');
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setFormData((previous) => ({
          ...previous,
          coordinates: `${position.coords.latitude.toFixed(6)}, ${position.coords.longitude.toFixed(6)}`,
        }));
        setNotice(`Coordinates filled from this device (accuracy approximately ${Math.round(position.coords.accuracy)} meters).`);
        setError('');
      },
      () => setError('Location permission was not granted. Enter the coordinates manually.'),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
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
    delete payload.coordinates;
    if (formData.coordinates.trim()) {
      const [latitude, longitude] = formData.coordinates.split(',').map((value) => Number(value.trim()));
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        setSaving(false);
        setError('Enter coordinates as Latitude, Longitude. Example: 11.123456, 125.123456');
        return;
      }
      payload.gps_coordinates = { latitude, longitude };
    }

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

  const runNetworkAction = async (action: 'suspend' | 'restore' | 'sync'): Promise<void> => {
    if (!id || networkAction) return;

    const confirmation = action === 'suspend'
      ? `Suspend internet for ${formData.full_name}? The customer will be throttled and shown the payment reminder page.`
      : action === 'restore'
        ? `Restore normal internet service for ${formData.full_name}?`
        : `Sync ${formData.full_name}'s billing status and MikroTik queue now?`;

    if (!window.confirm(confirmation)) return;

    setNetworkAction(action);
    setError('');
    setNotice('');
    try {
      const endpoint = action === 'sync' ? 'sync-network' : action;
      const response = await api.post<{ success?: boolean; message?: string }>(`/customers/${id}/${endpoint}`);
      if (response.data.success === false) {
        throw new Error(response.data.message || `Unable to ${action} internet.`);
      }
      setNotice(response.data.message || (action === 'suspend'
        ? 'Internet suspension was sent to MikroTik.'
        : action === 'restore'
          ? 'Internet restoration was sent to MikroTik.'
          : 'Billing and MikroTik status have been synchronized.'));
      await load();
    } catch (err: any) {
      setError(err?.response?.data?.message || err?.message || `Failed to ${action} internet.`);
    } finally {
      setNetworkAction(null);
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

        <section className="bg-card border border-border rounded-lg p-6" data-testid="customer-network-controls">
          <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
              <h2 className="text-xl font-semibold text-foreground">Internet Control</h2>
              <p className="text-sm text-muted-foreground mt-1">Manually manage this customer&apos;s MikroTik queue and payment restriction.</p>
            </div>
            <span className={`inline-flex w-fit items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${formData.status === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'}`}>
              <Activity className="w-3.5 h-3.5" /> {formData.status.toUpperCase()}
            </span>
          </div>

          <div className="mt-5 flex flex-wrap gap-3">
            <button type="button" onClick={() => void runNetworkAction('suspend')} disabled={networkAction !== null}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-red-600 text-white hover:bg-red-700 disabled:opacity-50"
              data-testid="suspend-internet-btn">
              <Ban className="w-4 h-4" /> {networkAction === 'suspend' ? 'Suspending…' : 'Suspend Internet'}
            </button>
            <button type="button" onClick={() => void runNetworkAction('restore')} disabled={networkAction !== null}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50"
              data-testid="restore-internet-btn">
              <CheckCircle2 className="w-4 h-4" /> {networkAction === 'restore' ? 'Restoring…' : 'Restore Internet'}
            </button>
            <button type="button" onClick={() => void runNetworkAction('sync')} disabled={networkAction !== null}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-secondary text-secondary-foreground hover:opacity-90 disabled:opacity-50"
              data-testid="sync-mikrotik-btn">
              <RefreshCw className={`w-4 h-4 ${networkAction === 'sync' ? 'animate-spin' : ''}`} /> {networkAction === 'sync' ? 'Syncing…' : 'Sync with MikroTik'}
            </button>
            <button type="button" onClick={() => setShowLease((visible) => !visible)}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-md border border-border bg-background text-foreground hover:bg-muted"
              data-testid="view-dhcp-lease-btn">
              <RouterIcon className="w-4 h-4" /> {showLease ? 'Hide DHCP Lease' : 'View DHCP Lease'}
            </button>
          </div>

          {showLease && (
            <div className="mt-5 rounded-lg border border-border bg-muted/30 p-4" data-testid="dhcp-lease-details">
              {dhcpLease ? (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                  <LeaseValue label="IP address" value={dhcpLease.ip_address} />
                  <LeaseValue label="MAC address" value={dhcpLease.mac_address} />
                  <LeaseValue label="Lease status" value={dhcpLease.status} />
                  <LeaseValue label="Router" value={dhcpLease.router?.name || 'Unknown'} />
                  <LeaseValue label="DHCP server" value={dhcpLease.server || 'default'} />
                  <LeaseValue label="Rate limit" value={dhcpLease.rate_limit || 'Not set'} />
                  <LeaseValue label="Lease type" value={dhcpLease.is_dynamic ? 'Dynamic' : 'Static'} />
                  <LeaseValue label="Last seen" value={dhcpLease.last_seen_at ? new Date(dhcpLease.last_seen_at).toLocaleString() : 'Not reported'} />
                  <LeaseValue label="Hostname" value={dhcpLease.hostname || 'Not reported'} />
                </div>
              ) : (
                <div className="flex items-center gap-2 text-sm text-muted-foreground"><Wifi className="w-4 h-4" /> No DHCP lease is currently linked to this customer.</div>
              )}
            </div>
          )}
        </section>

        <form onSubmit={handleSubmit} className="bg-card border border-border rounded-lg p-6 space-y-6" data-testid="edit-customer-form">
          {/* Basic Information */}
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-4">Installation Location</h2>
            <Field label="Coordinates" name="coordinates" value={formData.coordinates} onChange={handleChange} placeholder="11.123456, 125.123456" testId="edit-coordinates" />
            <p className="mt-2 text-xs text-muted-foreground">Enter latitude and longitude separated by a comma.</p>
            <div className="mt-3 flex flex-wrap gap-3">
              <button type="button" onClick={useCurrentLocation} className="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"><Crosshair className="h-4 w-4" /> Use this device location</button>
              {formData.coordinates.includes(',') && <a href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(formData.coordinates)}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"><MapPin className="h-4 w-4" /> Pin on Google Maps</a>}
            </div>
          </section>
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

const LeaseValue: React.FC<{ label: string; value: string }> = ({ label, value }) => (
  <div>
    <div className="text-xs text-muted-foreground">{label}</div>
    <div className="mt-1 font-medium text-foreground break-all">{value}</div>
  </div>
);

export default EditCustomerPage;
