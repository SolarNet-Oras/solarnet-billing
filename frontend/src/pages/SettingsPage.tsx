import React, { useEffect, useMemo, useState } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { settingsService, type SettingItem } from '@/services/settingsService';
import { AutomationPanel } from '@/components/automation/AutomationPanel';
import { Settings as SettingsIcon, Save, CheckCircle2 } from 'lucide-react';

const GROUP_META: Record<string, { label: string; description: string }> = {
  company:    { label: 'Company',     description: 'Business branding shown on invoices, welcome emails, and the customer portal.' },
  billing:    { label: 'Billing',     description: 'Defaults for invoice generation, VAT, and suspension.' },
  ai:         { label: 'AI Assistant',description: 'Controls the floating AI chat. The OpenAI API key stays on the server — you never enter it here.' },
  suspension: { label: 'Internet Suspension & Payment Reminder', description: 'Controls when overdue accounts are restricted, their reduced speed, and the reminder page they can still access.' },
  automation: { label: 'Automation',  description: 'Toggles for the nightly scheduled jobs. When the master switch is off, no jobs run.' },
};

const SettingsPage: React.FC = () => {
  const [items, setItems] = useState<SettingItem[]>([]);
  const [dirty, setDirty] = useState<Record<string, any>>({});
  const [loading, setLoading] = useState<boolean>(true);
  const [saving, setSaving] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [notice, setNotice] = useState<string>('');

  useEffect(() => { void load(); }, []);

  const load = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const rows = await settingsService.list();
      setItems(rows);
      setDirty({});
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to load settings');
    } finally { setLoading(false); }
  };

  const grouped = useMemo(() => {
    const g: Record<string, SettingItem[]> = {};
    for (const it of items) {
      (g[it.group] ||= []).push(it);
    }
    return g;
  }, [items]);

  const handleChange = (key: string, val: any): void => {
    setDirty((d) => ({ ...d, [key]: val }));
    setNotice('');
  };

  const handleSave = async (): Promise<void> => {
    const payload = Object.entries(dirty).map(([key, value]) => ({ key, value }));
    if (payload.length === 0) return;
    setSaving(true);
    setError('');
    try {
      const res = await settingsService.saveMany(payload);
      setNotice(`Saved ${res.keys.length} setting${res.keys.length === 1 ? '' : 's'}.`);
      setDirty({});
      await load();
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to save settings');
    } finally { setSaving(false); }
  };

  const currentValue = (it: SettingItem) => (Object.prototype.hasOwnProperty.call(dirty, it.key) ? dirty[it.key] : it.value);
  const hasChanges = Object.keys(dirty).length > 0;

  return (
    <DashboardLayout>
      <div className="max-w-4xl mx-auto space-y-6">
        <div className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-slate-500 to-slate-700 flex items-center justify-center shadow">
            <SettingsIcon className="w-6 h-6 text-white" />
          </div>
          <div>
            <h1 className="text-3xl font-bold text-foreground">Settings</h1>
            <p className="text-muted-foreground mt-0.5">Company, billing, and AI configuration.</p>
          </div>
        </div>

        {error && (
          <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-3 text-sm text-red-800 dark:text-red-200" data-testid="settings-error">
            {error}
          </div>
        )}
        {notice && (
          <div className="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-md p-3 text-sm text-emerald-800 dark:text-emerald-200 flex items-center gap-2" data-testid="settings-notice">
            <CheckCircle2 className="w-4 h-4" /> {notice}
          </div>
        )}

        {loading ? (
          <div className="p-12 text-center text-muted-foreground">Loading settings…</div>
        ) : (
          Object.entries(grouped).map(([group, rows]) => (
            <section key={group} className="bg-card border border-border rounded-lg p-6" data-testid={`settings-section-${group}`}>
              <h2 className="text-xl font-semibold text-foreground">{GROUP_META[group]?.label || group}</h2>
              {GROUP_META[group]?.description && (
                <p className="text-sm text-muted-foreground mt-1 mb-5">{GROUP_META[group].description}</p>
              )}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {rows.map((it) => (
                  <SettingField
                    key={it.key}
                    setting={it}
                    value={currentValue(it)}
                    onChange={(v) => handleChange(it.key, v)}
                  />
                ))}
              </div>
            </section>
          ))
        )}

        {!loading && <AutomationPanel />}

        <div className="sticky bottom-4 flex justify-end">
          <button
            type="button"
            onClick={handleSave}
            disabled={!hasChanges || saving}
            className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-primary-foreground rounded-lg shadow-lg hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed transition-opacity"
            data-testid="settings-save-btn"
          >
            <Save className="w-4 h-4" />
            {saving ? 'Saving…' : hasChanges ? `Save ${Object.keys(dirty).length} change${Object.keys(dirty).length === 1 ? '' : 's'}` : 'Saved'}
          </button>
        </div>
      </div>
    </DashboardLayout>
  );
};

interface FieldProps {
  setting: SettingItem;
  value: any;
  onChange: (v: any) => void;
}

const SettingField: React.FC<FieldProps> = ({ setting, value, onChange }) => {
  const disabled = setting.is_readonly;

  if (setting.cast === 'bool') {
    return (
      <label className="flex items-center gap-3 col-span-1 md:col-span-2 py-1" data-testid={`setting-${setting.key}`}>
        <input
          type="checkbox"
          checked={Boolean(value)}
          onChange={(e) => onChange(e.target.checked)}
          disabled={disabled}
          className="w-4 h-4 rounded border-input cursor-pointer accent-primary disabled:cursor-not-allowed"
        />
        <div>
          <div className="text-sm font-medium text-foreground">{setting.label}</div>
          <div className="text-xs text-muted-foreground">{disabled ? 'read-only' : setting.key}</div>
        </div>
      </label>
    );
  }

  return (
    <div data-testid={`setting-${setting.key}`}>
      <label className="block text-sm font-medium text-foreground mb-2">{setting.label}</label>
      <input
        type={setting.cast === 'int' || setting.cast === 'float' ? 'number' : 'text'}
        step={setting.cast === 'float' ? '0.01' : setting.cast === 'int' ? '1' : undefined}
        value={value ?? ''}
        onChange={(e) => onChange(setting.cast === 'int' ? parseInt(e.target.value, 10) : setting.cast === 'float' ? parseFloat(e.target.value) : e.target.value)}
        disabled={disabled}
        className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-60 disabled:cursor-not-allowed"
      />
      <p className="text-xs text-muted-foreground mt-1 font-mono">{setting.key}{disabled ? ' · read-only' : ''}</p>
    </div>
  );
};

export default SettingsPage;
