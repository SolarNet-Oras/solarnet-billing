import React, { useState } from 'react';
import { customerService, type CustomerRegistrationImportPreview } from '@/services/customerService';

export function CustomerRegistrationImportModal({ open, onClose, onApplied }: { open: boolean; onClose: () => void; onApplied: (message: string) => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<CustomerRegistrationImportPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  if (!open) return null;

  const close = () => { if (!busy) { setFile(null); setPreview(null); setError(''); onClose(); } };
  const prepare = async () => {
    if (!file) { setError('Choose an XLSX, XLS, or CSV file first.'); return; }
    setBusy(true); setError('');
    try { setPreview(await customerService.previewCustomerRegistrationImport(file)); }
    catch (e: any) { setError(e?.response?.data?.message || 'Unable to read the registration spreadsheet.'); }
    finally { setBusy(false); }
  };
  const apply = async () => {
    if (!preview) return;
    setBusy(true); setError('');
    try { const result = await customerService.applyCustomerRegistrationImport(preview.preview_token); setBusy(false); setFile(null); setPreview(null); onApplied(result.message); }
    catch (e: any) { setError(e?.response?.data?.message || 'Unable to register the reviewed customers.'); setBusy(false); }
  };

  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-3" role="dialog" aria-modal="true">
    <div className="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-border bg-card shadow-2xl">
      <div className="border-b border-border px-5 py-4"><h2 className="text-xl font-semibold text-foreground">Register multiple customers from Excel</h2><p className="mt-1 text-sm text-muted-foreground">Required headers: Installation Date, Client Name, Address, Due Date, Promo. XLSX, XLS, and CSV are accepted.</p></div>
      <div className="flex-1 overflow-auto p-5">
        {error && <div className="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-200">{error}</div>}
        {!preview ? <div className="rounded-lg border border-dashed border-border p-6">
          <input type="file" accept=".xlsx,.xls,.csv" onChange={(event) => setFile(event.target.files?.[0] || null)} className="block w-full text-sm text-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-primary-foreground" />
          <p className="mt-3 text-xs text-muted-foreground">New profiles are created as Active with no MAC or IP. They wait safely for a later network match. Promo must match an active service plan, and existing customer names are skipped.</p>
        </div> : <div className="space-y-4">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">{Object.entries(preview.summary).map(([key, value]) => <div key={key} className="rounded-md border border-border bg-background p-3"><p className="text-[11px] uppercase text-muted-foreground">{key.replaceAll('_', ' ')}</p><p className="text-lg font-semibold text-foreground">{value}</p></div>)}</div>
          <div className="max-h-[52vh] overflow-auto rounded-lg border border-border"><table className="w-full min-w-[900px] text-sm"><thead className="sticky top-0 bg-secondary text-left"><tr><th className="p-3">Row</th><th className="p-3">Client</th><th className="p-3">Installation</th><th className="p-3">Address</th><th className="p-3">Due</th><th className="p-3">Promo / fee</th><th className="p-3">Result</th></tr></thead><tbody className="divide-y divide-border">{preview.rows.map(row => <tr key={`${row.row}-${row.client_name}`}><td className="p-3">{row.row}</td><td className="p-3 font-medium">{row.client_name || '—'}</td><td className="p-3">{row.installation_date || '—'}</td><td className="p-3">{row.address || '—'}</td><td className="p-3">{row.due_day ? `Every ${row.due_day}` : '—'}</td><td className="p-3">{row.service_plan_name || row.promo || '—'}{row.monthly_fee != null && <span className="block text-xs text-muted-foreground">₱{Number(row.monthly_fee).toLocaleString()}</span>}</td><td className="p-3"><span className={`rounded-full px-2 py-1 text-xs font-medium ${row.status === 'ready' ? 'bg-emerald-100 text-emerald-800' : row.status === 'invalid' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'}`}>{row.status.replaceAll('_', ' ')}</span><span className="mt-1 block max-w-xs text-xs text-muted-foreground">{row.reason}</span></td></tr>)}</tbody></table></div>
        </div>}
      </div>
      <div className="flex justify-end gap-2 border-t border-border p-4"><button onClick={close} disabled={busy} className="rounded-md bg-secondary px-4 py-2 text-sm text-secondary-foreground">Cancel</button>{!preview ? <button onClick={prepare} disabled={busy || !file} className="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">{busy ? 'Reading…' : 'Preview registrations'}</button> : <><button onClick={() => setPreview(null)} disabled={busy} className="rounded-md border border-input px-4 py-2 text-sm text-foreground">Choose another file</button><button onClick={apply} disabled={busy || preview.summary.ready === 0} className="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">{busy ? 'Registering…' : `Register ${preview.summary.ready} new client(s)`}</button></>}</div>
    </div>
  </div>;
}
