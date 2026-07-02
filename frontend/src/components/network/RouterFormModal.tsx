import { useState, useEffect } from 'react';
import { type Router, type CreateRouterData, routerService } from '@/services/routerService';
import { X, Copy, Check, Terminal, ShieldCheck, ArrowRight, ArrowLeft, Loader2, ExternalLink } from 'lucide-react';

interface RouterFormModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSave: (data: CreateRouterData) => Promise<void>;
  router?: Router | null;
}

type Step = 'details' | 'mikrotik' | 'save';

const emptyForm: CreateRouterData = {
  name: '',
  host: '',
  port: 8728,
  username: 'billing_api',
  password: '',
  location: '',
  notes: '',
  dhcp_pool_name: '',
  is_active: true,
};

export function RouterFormModal({ isOpen, onClose, onSave, router }: RouterFormModalProps) {
  const isEdit = !!router;
  const [step, setStep] = useState<Step>('details');
  const [formData, setFormData] = useState<CreateRouterData>(emptyForm);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Wizard state
  const [billingIp, setBillingIp] = useState<string | null>(null);
  const [ipLoading, setIpLoading] = useState(false);
  const [script, setScript] = useState<string>('');
  const [scriptLoading, setScriptLoading] = useState(false);
  const [copied, setCopied] = useState(false);
  const [pastedConfirmed, setPastedConfirmed] = useState(false);

  useEffect(() => {
    if (!isOpen) return;

    if (router) {
      setFormData({
        name: router.name,
        host: router.host,
        port: router.port,
        username: router.username,
        password: '',
        location: router.location || '',
        notes: router.notes || '',
        dhcp_pool_name: router.dhcp_pool_name || '',
        is_active: router.is_active,
      });
    } else {
      setFormData(emptyForm);
    }
    setStep('details');
    setError(null);
    setScript('');
    setCopied(false);
    setPastedConfirmed(false);

    // Auto-fetch billing IP so we can show it in the wizard
    setIpLoading(true);
    routerService
      .getNetworkInfo()
      .then((info) => setBillingIp(info.billing_system_ip))
      .catch(() => setBillingIp(null))
      .finally(() => setIpLoading(false));
  }, [router, isOpen]);

  const canGenerateScript =
    formData.name.trim().length > 0 &&
    formData.username.trim().length > 0 &&
    (formData.password?.length ?? 0) >= 6;

  const generateScript = async () => {
    setError(null);
    setScriptLoading(true);
    try {
      const res = await routerService.previewSetupScript({
        name: formData.name,
        host: formData.host || undefined,
        port: formData.port,
        username: formData.username,
        password: formData.password || '',
      });
      setScript(res.script);
      if (res.billing_system_ip) setBillingIp(res.billing_system_ip);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to generate setup script');
    } finally {
      setScriptLoading(false);
    }
  };

  const copyScript = async () => {
    if (!script) return;
    await navigator.clipboard.writeText(script);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleSubmit = async () => {
    setError(null);
    setSaving(true);
    try {
      const dataToSave = { ...formData };
      if (router && !dataToSave.password) delete (dataToSave as any).password;
      await onSave(dataToSave);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to save router');
    } finally {
      setSaving(false);
    }
  };

  const nextFromDetails = async () => {
    if (!formData.name.trim() || !formData.host.trim() || !formData.username.trim()) {
      setError('Router Name, Host/IP and Username are required.');
      return;
    }
    if (!isEdit && (!formData.password || formData.password.length < 6)) {
      setError('Password is required and must be at least 6 characters.');
      return;
    }
    setError(null);
    if (isEdit) {
      // Editing skips the setup wizard
      await handleSubmit();
      return;
    }
    setStep('mikrotik');
    if (!script) await generateScript();
  };

  if (!isOpen) return null;

  const StepIndicator = () => (
    <div className="flex items-center gap-2 text-xs" data-testid="router-wizard-steps">
      {(['details', 'mikrotik', 'save'] as Step[]).map((s, i) => {
        const active = s === step;
        const done = (['details', 'mikrotik', 'save'] as Step[]).indexOf(step) > i;
        return (
          <div key={s} className="flex items-center gap-2">
            <div
              className={
                'w-6 h-6 rounded-full flex items-center justify-center font-bold ' +
                (active
                  ? 'bg-primary text-primary-foreground'
                  : done
                  ? 'bg-green-500 text-white'
                  : 'bg-secondary text-muted-foreground')
              }
            >
              {done ? <Check className="h-3.5 w-3.5" /> : i + 1}
            </div>
            <span className={active ? 'font-semibold text-foreground' : 'text-muted-foreground'}>
              {s === 'details' ? 'Router Details' : s === 'mikrotik' ? 'Setup MikroTik' : 'Save & Test'}
            </span>
            {i < 2 && <ArrowRight className="h-3.5 w-3.5 text-muted-foreground" />}
          </div>
        );
      })}
    </div>
  );

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
      <div
        className="bg-card rounded-lg shadow-2xl max-w-3xl w-full max-h-[92vh] overflow-hidden flex flex-col"
        onClick={(e) => e.stopPropagation()}
        data-testid="router-form-modal"
      >
        <div className="flex items-center justify-between p-5 border-b border-border">
          <div>
            <h2 className="text-xl font-bold text-foreground flex items-center gap-2">
              {isEdit ? (
                <>Edit Router</>
              ) : (
                <><Terminal className="h-5 w-5 text-primary" /> Connect a MikroTik Router</>
              )}
            </h2>
            {!isEdit && <div className="mt-2"><StepIndicator /></div>}
          </div>
          <button
            onClick={onClose}
            className="p-2 hover:bg-secondary rounded transition-colors"
            data-testid="router-modal-close-btn"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-6">
          {error && (
            <div className="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
              <p className="text-sm text-red-800 dark:text-red-200">{error}</p>
            </div>
          )}

          {/* ============================================ STEP 1 ============================================ */}
          {step === 'details' && (
            <div className="space-y-4" data-testid="wizard-step-details">
              {!isEdit && (
                <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 flex gap-3">
                  <ShieldCheck className="h-5 w-5 text-blue-600 dark:text-blue-300 shrink-0 mt-0.5" />
                  <div className="text-sm text-blue-900 dark:text-blue-200">
                    <strong>Before you start:</strong> fill in a name and pick credentials that the billing system will
                    use to talk to this MikroTik. On the next step we'll give you a one-paste terminal script that sets
                    everything up on the router (user, API service, firewall rule).
                  </div>
                </div>
              )}

              <div className="grid grid-cols-2 gap-4">
                <div className="col-span-2">
                  <label className="block text-sm font-medium text-foreground mb-1">
                    Router Name <span className="text-red-600">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="Main POP Router"
                    data-testid="router-name-input"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-foreground mb-1">
                    Host / IP Address <span className="text-red-600">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.host}
                    onChange={(e) => setFormData({ ...formData, host: e.target.value })}
                    className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="e.g. 203.0.113.42 or router.myisp.net"
                    data-testid="router-host-input"
                  />
                  <p className="text-xs text-muted-foreground mt-1">
                    Public IP or DDNS hostname of <strong>your MikroTik</strong>.
                  </p>
                </div>

                <div>
                  <label className="block text-sm font-medium text-foreground mb-1">
                    API Port <span className="text-red-600">*</span>
                  </label>
                  <input
                    type="number"
                    required
                    value={formData.port}
                    onChange={(e) => setFormData({ ...formData, port: parseInt(e.target.value) || 8728 })}
                    className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    data-testid="router-port-input"
                  />
                  <p className="text-xs text-muted-foreground mt-1">Default: 8728 (plain) or 8729 (SSL)</p>
                </div>

                <div>
                  <label className="block text-sm font-medium text-foreground mb-1">
                    API Username <span className="text-red-600">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.username}
                    onChange={(e) => setFormData({ ...formData, username: e.target.value })}
                    className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="billing_api"
                    data-testid="router-username-input"
                  />
                  <p className="text-xs text-muted-foreground mt-1">The setup script will create this user.</p>
                </div>

                <div>
                  <label className="block text-sm font-medium text-foreground mb-1">
                    API Password {!isEdit && <span className="text-red-600">*</span>}
                  </label>
                  <input
                    type="password"
                    required={!isEdit}
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder={isEdit ? 'Leave blank to keep current' : 'Min 6 characters'}
                    data-testid="router-password-input"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-foreground mb-1">Location</label>
                  <input
                    type="text"
                    value={formData.location}
                    onChange={(e) => setFormData({ ...formData, location: e.target.value })}
                    className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="Building A, Floor 2"
                    data-testid="router-location-input"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-foreground mb-1">DHCP Pool Name</label>
                  <input
                    type="text"
                    value={formData.dhcp_pool_name}
                    onChange={(e) => setFormData({ ...formData, dhcp_pool_name: e.target.value })}
                    className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="dhcp-pool-1"
                    data-testid="router-dhcp-pool-input"
                  />
                </div>

                <div className="col-span-2">
                  <label className="block text-sm font-medium text-foreground mb-1">Notes</label>
                  <textarea
                    value={formData.notes}
                    onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                    rows={2}
                    className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="Optional notes..."
                    data-testid="router-notes-input"
                  />
                </div>

                <div className="col-span-2">
                  <label className="flex items-center space-x-2">
                    <input
                      type="checkbox"
                      checked={formData.is_active}
                      onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                      className="rounded border-input text-primary focus:ring-primary"
                      data-testid="router-active-checkbox"
                    />
                    <span className="text-sm font-medium text-foreground">Router is active</span>
                  </label>
                </div>
              </div>
            </div>
          )}

          {/* ============================================ STEP 2 ============================================ */}
          {step === 'mikrotik' && (
            <div className="space-y-4" data-testid="wizard-step-mikrotik">
              <div className="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg p-4">
                <div className="flex items-center gap-2 mb-2">
                  <ShieldCheck className="h-5 w-5 text-emerald-600" />
                  <h3 className="font-semibold text-emerald-900 dark:text-emerald-200">Your Billing Server IP</h3>
                </div>
                <div className="flex items-center gap-3">
                  {ipLoading ? (
                    <span className="text-sm text-muted-foreground">Detecting…</span>
                  ) : billingIp ? (
                    <>
                      <code className="text-lg font-mono font-bold text-emerald-800 dark:text-emerald-100 bg-white dark:bg-black/30 px-3 py-1 rounded" data-testid="billing-server-ip">
                        {billingIp}
                      </code>
                      <button
                        onClick={() => navigator.clipboard.writeText(billingIp)}
                        className="text-xs text-emerald-700 dark:text-emerald-300 hover:underline flex items-center gap-1"
                        data-testid="copy-billing-ip-btn"
                      >
                        <Copy className="h-3 w-3" /> Copy
                      </button>
                    </>
                  ) : (
                    <span className="text-sm text-red-700">Could not auto-detect. Provide manually if needed.</span>
                  )}
                </div>
                <p className="text-xs text-emerald-800 dark:text-emerald-300 mt-2">
                  The MikroTik setup script below whitelists this IP on your router's firewall so only this billing
                  server can talk to the API.
                </p>
              </div>

              <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                <h3 className="font-semibold text-amber-900 dark:text-amber-200 mb-2 flex items-center gap-2">
                  <Terminal className="h-4 w-4" />
                  Paste this on your MikroTik router
                </h3>
                <ol className="text-sm text-amber-900 dark:text-amber-200 space-y-1 list-decimal list-inside">
                  <li>Open <strong>Winbox</strong> → connect to your router → <strong>New Terminal</strong>. (Or SSH into the router.)</li>
                  <li>Click <strong>Copy Script</strong> below.</li>
                  <li>Paste into the terminal and hit <strong>Enter</strong>.</li>
                  <li>Wait for the "<em>Setup Complete</em>" line, then come back here and click <strong>Next</strong>.</li>
                </ol>
              </div>

              <div className="relative">
                <div className="absolute top-2 right-2 flex gap-2 z-10">
                  <button
                    onClick={generateScript}
                    disabled={scriptLoading || !canGenerateScript}
                    className="px-3 py-1.5 bg-secondary text-foreground rounded-md hover:bg-secondary/80 text-xs flex items-center gap-1 disabled:opacity-50"
                    data-testid="regenerate-script-btn"
                  >
                    {scriptLoading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : null}
                    Regenerate
                  </button>
                  <button
                    onClick={copyScript}
                    disabled={!script}
                    className="px-3 py-1.5 bg-primary text-primary-foreground rounded-md hover:opacity-90 text-xs flex items-center gap-1 disabled:opacity-50"
                    data-testid="copy-script-btn"
                  >
                    {copied ? (
                      <><Check className="h-3.5 w-3.5" /> Copied!</>
                    ) : (
                      <><Copy className="h-3.5 w-3.5" /> Copy Script</>
                    )}
                  </button>
                </div>
                <pre
                  className="bg-gray-950 text-gray-100 p-4 pt-12 rounded-lg overflow-x-auto text-[11px] font-mono max-h-[380px] leading-relaxed"
                  data-testid="setup-script-preview"
                >
                  <code>
                    {scriptLoading
                      ? '# Generating setup script...'
                      : script || '# Click "Regenerate" to build a setup script for this router.'}
                  </code>
                </pre>
              </div>

              <label className="flex items-center gap-2 cursor-pointer bg-secondary/40 border border-border rounded-lg p-3">
                <input
                  type="checkbox"
                  checked={pastedConfirmed}
                  onChange={(e) => setPastedConfirmed(e.target.checked)}
                  className="rounded border-input text-primary focus:ring-primary"
                  data-testid="pasted-confirmed-checkbox"
                />
                <span className="text-sm font-medium text-foreground">
                  I've pasted the script on my MikroTik and I saw "<em>Setup Complete</em>"
                </span>
              </label>

              <details className="text-xs text-muted-foreground">
                <summary className="cursor-pointer hover:text-foreground">
                  Troubleshooting: script didn't work? <ExternalLink className="inline h-3 w-3" />
                </summary>
                <ul className="mt-2 ml-4 space-y-1 list-disc">
                  <li>Ensure your MikroTik has internet access and RouterOS ≥ 6.45.</li>
                  <li>If you're behind a home router / ISP CGNAT, port-forward <code>{formData.port}</code> to the MikroTik LAN IP first.</li>
                  <li>The API service (<code>/ip service</code>) must be enabled — the script does this automatically.</li>
                  <li>If a user named <code>{formData.username}</code> already exists, the script updates its password. This is safe.</li>
                </ul>
              </details>
            </div>
          )}

          {/* ============================================ STEP 3 ============================================ */}
          {step === 'save' && (
            <div className="space-y-4" data-testid="wizard-step-save">
              <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <h3 className="font-semibold text-blue-900 dark:text-blue-200 mb-1">Ready to save</h3>
                <p className="text-sm text-blue-800 dark:text-blue-300">
                  We'll save the router in the app. Then use the <strong>Test Connection</strong> button on the routers
                  page to verify everything's wired up.
                </p>
              </div>

              <div className="grid grid-cols-2 gap-2 text-sm bg-secondary/40 border border-border rounded-lg p-4">
                <div className="text-muted-foreground">Name</div>
                <div className="font-medium text-foreground text-right">{formData.name}</div>
                <div className="text-muted-foreground">Host</div>
                <div className="font-mono text-foreground text-right">{formData.host}:{formData.port}</div>
                <div className="text-muted-foreground">API User</div>
                <div className="font-mono text-foreground text-right">{formData.username}</div>
                <div className="text-muted-foreground">Billing IP whitelisted</div>
                <div className="font-mono text-foreground text-right">{billingIp || '—'}</div>
                {formData.location && (<>
                  <div className="text-muted-foreground">Location</div>
                  <div className="text-foreground text-right">{formData.location}</div>
                </>)}
              </div>
            </div>
          )}
        </div>

        {/* Footer / navigation */}
        <div className="flex items-center justify-between px-6 py-4 border-t border-border bg-secondary/20">
          <button
            onClick={
              step === 'details'
                ? onClose
                : () => {
                    setError(null);
                    setStep(step === 'save' ? 'mikrotik' : 'details');
                  }
            }
            className="px-4 py-2 border border-border rounded-md text-foreground hover:bg-secondary transition-colors flex items-center gap-2"
            data-testid="wizard-back-btn"
          >
            {step === 'details' ? 'Cancel' : (<><ArrowLeft className="h-4 w-4" /> Back</>)}
          </button>

          {step === 'details' && (
            <button
              onClick={nextFromDetails}
              disabled={saving}
              className="px-4 py-2 bg-primary text-primary-foreground rounded-md hover:opacity-90 flex items-center gap-2 disabled:opacity-50"
              data-testid="wizard-next-btn"
            >
              {isEdit ? (saving ? 'Saving…' : 'Update Router') : (<>Next: Setup MikroTik <ArrowRight className="h-4 w-4" /></>)}
            </button>
          )}

          {step === 'mikrotik' && (
            <button
              onClick={() => setStep('save')}
              disabled={!pastedConfirmed}
              className="px-4 py-2 bg-primary text-primary-foreground rounded-md hover:opacity-90 flex items-center gap-2 disabled:opacity-50"
              data-testid="wizard-next-btn"
            >
              Next: Save & Test <ArrowRight className="h-4 w-4" />
            </button>
          )}

          {step === 'save' && (
            <button
              onClick={handleSubmit}
              disabled={saving}
              className="px-4 py-2 bg-emerald-600 text-white rounded-md hover:opacity-90 flex items-center gap-2 disabled:opacity-50"
              data-testid="wizard-save-btn"
            >
              {saving ? (<><Loader2 className="h-4 w-4 animate-spin" /> Saving…</>) : (<><Check className="h-4 w-4" /> Save Router</>)}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
