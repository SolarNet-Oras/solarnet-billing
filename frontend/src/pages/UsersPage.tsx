import { useEffect, useState, type FormEvent } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { Users as UsersIcon, Plus, Search, Loader2, Shield, X, CheckCircle2, XCircle, Mail, Phone, KeyRound, RotateCcw } from 'lucide-react';

interface Role {
  id: string;
  name: string;
  display_name: string;
}
interface UserRow {
  id: string;
  name: string;
  email: string;
  phone?: string | null;
  is_active: boolean;
  roles: Role[];
  created_at: string;
  last_login_at?: string | null;
}
interface UserForm {
  name: string;
  email: string;
  phone: string;
  password: string;
  password_confirmation: string;
  roles: string[];
  is_active: boolean;
}
interface ClientPortalAccount {
  id: string;
  account_number: string;
  full_name: string;
  email: string | null;
  contact_number: string | null;
  status: string;
  password_status: 'not_set' | 'temporary_change_required' | 'customer_set';
  password_set_at: string | null;
}

const emptyForm: UserForm = {
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  roles: [],
  is_active: true,
};

export default function UsersPage() {
  const [users, setUsers] = useState<UserRow[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [clientAccounts, setClientAccounts] = useState<ClientPortalAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editing, setEditing] = useState<UserRow | null>(null);
  const [form, setForm] = useState<UserForm>(emptyForm);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    setLoading(true);
    try {
      const [uRes, rRes, cRes] = await Promise.all([
        api.get('/users', { params: { search: search || undefined, per_page: 50 } }),
        api.get('/roles').catch(() => ({ data: { data: [] } })),
        api.get('/customer-portal-accounts').catch(() => ({ data: { data: [] } })),
      ]);
      setUsers(uRes.data?.data || []);
      setRoles(rRes.data?.data || []);
      setClientAccounts(cRes.data?.data || []);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to load users');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setError(null);
    setShowModal(true);
  };
  const openEdit = (u: UserRow) => {
    setEditing(u);
    setForm({
      name: u.name,
      email: u.email,
      phone: u.phone || '',
      password: '',
      password_confirmation: '',
      roles: u.roles.map((r) => r.name),
      is_active: u.is_active,
    });
    setError(null);
    setShowModal(true);
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!editing && form.password !== form.password_confirmation) {
      setError('Passwords do not match.');
      return;
    }
    if (form.roles.length === 0) {
      setError('Please select at least one role.');
      return;
    }
    setSaving(true);
    try {
      if (editing) {
        const payload: any = {
          name: form.name,
          email: form.email,
          phone: form.phone || null,
          roles: form.roles,
          is_active: form.is_active,
        };
        if (form.password) {
          payload.password = form.password;
          payload.password_confirmation = form.password_confirmation;
        }
        await api.put(`/users/${editing.id}`, payload);
      } else {
        await api.post('/users', {
          name: form.name,
          email: form.email,
          phone: form.phone || null,
          password: form.password,
          password_confirmation: form.password_confirmation,
          roles: form.roles,
          is_active: form.is_active,
        });
      }
      setShowModal(false);
      await load();
    } catch (err: any) {
      const errs = err.response?.data?.errors;
      setError(errs ? Object.values(errs).flat().join(' ') : err.response?.data?.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const del = async (u: UserRow) => {
    if (!confirm(`Delete user ${u.name}? This cannot be undone.`)) return;
    try {
      await api.delete(`/users/${u.id}`);
      await load();
    } catch (err: any) {
      alert(err.response?.data?.message || 'Delete failed');
    }
  };

  const resetClientPassword = async (client: ClientPortalAccount): Promise<void> => {
    if (!client.email) {
      alert('This client has no registered email. Add an email on the customer record first.');
      return;
    }
    if (!confirm(`Reset ${client.full_name}'s customer portal password? They will have to use the temporary password and create a new one.`)) return;
    try {
      await api.post(`/customer-portal-accounts/${client.id}/reset-password`);
      await load();
      alert('Portal password reset. The client must use the temporary password and change it after sign-in.');
    } catch (err: any) {
      alert(err.response?.data?.message || 'Could not reset the portal password.');
    }
  };

  const passwordStatus = (client: ClientPortalAccount): JSX.Element => {
    const labels = {
      not_set: 'Not set',
      temporary_change_required: 'Temporary — change required',
      customer_set: 'Customer password set',
    };
    const classes = {
      not_set: 'text-rose-600',
      temporary_change_required: 'text-amber-700',
      customer_set: 'text-emerald-700',
    };
    return <span className={`text-xs font-medium ${classes[client.password_status]}`}>{labels[client.password_status]}</span>;
  };

  const filtered = users.filter(
    (u) =>
      !search ||
      u.name.toLowerCase().includes(search.toLowerCase()) ||
      u.email.toLowerCase().includes(search.toLowerCase()),
  );

  return (
    <DashboardLayout>
      <div className="space-y-6" data-testid="users-page">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-foreground flex items-center gap-2">
              <UsersIcon className="h-7 w-7 text-primary" /> Staff Users
            </h1>
            <p className="text-muted-foreground mt-1">Manage admin and staff accounts with role-based permissions.</p>
          </div>
          <button
            onClick={openCreate}
            className="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:opacity-90 flex items-center gap-2"
            data-testid="add-user-btn"
          >
            <Plus className="h-4 w-4" /> Add Staff User
          </button>
        </div>

        <div className="relative max-w-md">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name or email…"
            className="w-full pl-9 pr-3 py-2 bg-background border border-input rounded-lg text-sm focus:ring-2 focus:ring-primary"
            data-testid="user-search-input"
          />
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-16 text-muted-foreground">
            <Loader2 className="h-6 w-6 animate-spin mr-2" /> Loading users…
          </div>
        ) : (
          <div className="bg-card border border-border rounded-xl overflow-hidden shadow-sm">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left font-semibold">Name</th>
                  <th className="px-4 py-3 text-left font-semibold">Contact</th>
                  <th className="px-4 py-3 text-left font-semibold">Roles</th>
                  <th className="px-4 py-3 text-left font-semibold">Status</th>
                  <th className="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {filtered.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">
                      No users found.
                    </td>
                  </tr>
                )}
                {filtered.map((u) => (
                  <tr key={u.id} className="hover:bg-muted/20" data-testid={`user-row-${u.id}`}>
                    <td className="px-4 py-3">
                      <div className="font-medium text-foreground">{u.name}</div>
                      <div className="text-xs text-muted-foreground">{u.email}</div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Mail className="h-3 w-3" /> {u.email}
                      </div>
                      {u.phone && (
                        <div className="flex items-center gap-1 text-xs text-muted-foreground">
                          <Phone className="h-3 w-3" /> {u.phone}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        {u.roles.map((r) => (
                          <span key={r.id} className="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full bg-primary/10 text-primary font-medium">
                            <Shield className="h-3 w-3" /> {r.display_name || r.name}
                          </span>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      {u.is_active ? (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
                          <CheckCircle2 className="h-3.5 w-3.5" /> Active
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-rose-600">
                          <XCircle className="h-3.5 w-3.5" /> Disabled
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        onClick={() => openEdit(u)}
                        className="text-primary hover:underline text-sm mr-3"
                        data-testid={`edit-user-${u.id}`}
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => del(u)}
                        className="text-rose-600 hover:underline text-sm"
                        data-testid={`delete-user-${u.id}`}
                      >
                        Delete
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <section className="pt-4" data-testid="client-portal-accounts">
          <div className="mb-3 flex items-center gap-2">
            <KeyRound className="h-5 w-5 text-primary" />
            <div>
              <h2 className="font-semibold text-foreground">Client Portal Accounts</h2>
              <p className="text-sm text-muted-foreground">Customer sign-in emails and password status. Customer-created passwords cannot be viewed; reset access returns to the temporary password.</p>
            </div>
          </div>
          <div className="overflow-x-auto rounded-xl border border-border bg-card shadow-sm">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr><th className="px-4 py-3 text-left">Client</th><th className="px-4 py-3 text-left">Email</th><th className="px-4 py-3 text-left">Password status</th><th className="px-4 py-3 text-left">Temporary password</th><th className="px-4 py-3 text-right">Actions</th></tr>
              </thead>
              <tbody className="divide-y divide-border">
                {clientAccounts.map((client) => (
                  <tr key={client.id} className="hover:bg-muted/20">
                    <td className="px-4 py-3"><div className="font-medium">{client.full_name}</div><div className="text-xs text-muted-foreground">{client.account_number} · {client.status}</div></td>
                    <td className="px-4 py-3">{client.email || <span className="text-rose-600">No email</span>}</td>
                    <td className="px-4 py-3">{passwordStatus(client)}</td>
                    <td className="px-4 py-3"><code className="rounded bg-muted px-2 py-1 text-xs">Solarnet123</code></td>
                    <td className="px-4 py-3 text-right"><button onClick={() => void resetClientPassword(client)} className="inline-flex items-center gap-1 text-sm text-primary hover:underline"><RotateCcw className="h-3.5 w-3.5" /> Reset</button></td>
                  </tr>
                ))}
                {clientAccounts.length === 0 && <tr><td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">No client portal accounts found.</td></tr>}
              </tbody>
            </table>
          </div>
        </section>
      </div>

      {/* Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={() => setShowModal(false)}>
          <form
            onSubmit={submit}
            onClick={(e) => e.stopPropagation()}
            className="bg-card border border-border rounded-xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto"
            data-testid="user-modal"
          >
            <div className="flex items-center justify-between p-5 border-b border-border">
              <h2 className="text-lg font-bold text-foreground">
                {editing ? 'Edit Staff User' : 'Add Staff User'}
              </h2>
              <button type="button" onClick={() => setShowModal(false)} className="p-1 hover:bg-secondary rounded">
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="p-5 space-y-4">
              {error && (
                <div className="bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-200 text-sm rounded-lg p-3">
                  {error}
                </div>
              )}

              <TextField label="Full Name" value={form.name} onChange={(v) => setForm({ ...form, name: v })} required testid="user-name-input" />
              <TextField label="Email" type="email" value={form.email} onChange={(v) => setForm({ ...form, email: v })} required testid="user-email-input" />
              <TextField label="Phone (optional)" value={form.phone} onChange={(v) => setForm({ ...form, phone: v })} testid="user-phone-input" />

              <TextField
                label={editing ? 'New Password (leave blank to keep current)' : 'Password'}
                type="password"
                value={form.password}
                onChange={(v) => setForm({ ...form, password: v })}
                required={!editing}
                minLength={editing ? undefined : 8}
                testid="user-password-input"
              />
              <TextField
                label="Confirm Password"
                type="password"
                value={form.password_confirmation}
                onChange={(v) => setForm({ ...form, password_confirmation: v })}
                required={!editing && !!form.password}
                testid="user-password-confirm-input"
              />

              <div>
                <label className="block text-sm font-medium text-foreground mb-2">Roles</label>
                <div className="space-y-2 max-h-40 overflow-y-auto border border-input rounded-lg p-3">
                  {roles.length === 0 && <p className="text-sm text-muted-foreground">No roles defined.</p>}
                  {roles.map((r) => (
                    <label key={r.id} className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={form.roles.includes(r.name)}
                        onChange={(e) =>
                          setForm((f) => ({
                            ...f,
                            roles: e.target.checked ? [...f.roles, r.name] : f.roles.filter((rn) => rn !== r.name),
                          }))
                        }
                        className="rounded border-input text-primary"
                        data-testid={`role-checkbox-${r.name}`}
                      />
                      <span className="text-sm text-foreground">
                        <Shield className="inline h-3 w-3 mr-1 text-primary" />
                        {r.display_name || r.name}
                      </span>
                    </label>
                  ))}
                </div>
              </div>

              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                  className="rounded border-input text-primary"
                  data-testid="user-active-checkbox"
                />
                <span className="text-sm font-medium text-foreground">Account is active</span>
              </label>
            </div>

            <div className="flex items-center justify-end gap-2 px-5 py-4 border-t border-border bg-muted/20">
              <button
                type="button"
                onClick={() => setShowModal(false)}
                className="px-4 py-2 border border-border rounded-lg text-foreground hover:bg-secondary"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:opacity-90 flex items-center gap-2 disabled:opacity-50"
                data-testid="user-save-btn"
              >
                {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                {editing ? 'Update User' : 'Create User'}
              </button>
            </div>
          </form>
        </div>
      )}
    </DashboardLayout>
  );
}

interface TextFieldProps {
  label: string;
  value: string;
  onChange: (v: string) => void;
  type?: string;
  required?: boolean;
  minLength?: number;
  testid?: string;
}
function TextField({ label, value, onChange, type = 'text', required, minLength, testid }: TextFieldProps) {
  return (
    <div>
      <label className="block text-sm font-medium text-foreground mb-1">{label}</label>
      <input
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        required={required}
        minLength={minLength}
        data-testid={testid}
        className="w-full px-3 py-2 border border-input rounded-lg text-sm bg-background text-foreground focus:ring-2 focus:ring-primary"
      />
    </div>
  );
}
