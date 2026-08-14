import React, { useState } from 'react';
import { Navigate } from 'react-router-dom';
import { Download, FileSpreadsheet, ShieldCheck, Upload } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { api, getErrorMessage } from '@/services/api';
import { useAuth } from '@/hooks/useAuth';

type PreviewRow = {
  row: number;
  record: Record<string, string | number | null>;
  match_status: string;
  requires_confirmation: boolean;
};

const isSuperAdmin = (user: ReturnType<typeof useAuth>['user']): boolean =>
  user?.role === 'super_admin' || user?.roles?.some((role) => typeof role === 'string' ? role === 'super_admin' : role.name === 'super_admin') || false;

const ClientMigrationPage: React.FC = () => {
  const { user, loading } = useAuth();
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<PreviewRow[]>([]);
  const [auditId, setAuditId] = useState<string>('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  if (!loading && !isSuperAdmin(user)) return <Navigate to="/dashboard" replace />;

  const downloadTemplate = async (): Promise<void> => {
    setError('');
    try {
      const response = await api.get('/super-admin/client-migrations/template', { responseType: 'blob' });
      const url = URL.createObjectURL(response.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'solarnet-client-migration-template.xlsx';
      link.click();
      URL.revokeObjectURL(url);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  };

  const generatePreview = async (): Promise<void> => {
    if (!file) return;
    setBusy(true);
    setError('');
    try {
      const form = new FormData();
      form.append('file', file);
      const response = await api.post('/super-admin/client-migrations/preview', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setPreview(response.data.rows as PreviewRow[]);
      setAuditId(response.data.audit_id as string);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
      setPreview([]);
    } finally {
      setBusy(false);
    }
  };

  return (
    <DashboardLayout headerTitle="Client record migration" headerSubtitle="Super Administrator · preview before import">
      <div className="mx-auto max-w-6xl space-y-6">
        <section className="rounded-2xl border border-sky-200 bg-sky-50 p-5 shadow-sm dark:border-sky-900/60 dark:bg-sky-950/30">
          <div className="flex gap-3">
            <ShieldCheck className="mt-0.5 h-6 w-6 shrink-0 text-sky-600" />
            <div>
              <h1 className="font-semibold text-foreground">Safe client migration</h1>
              <p className="mt-1 text-sm text-muted-foreground">This preview uses DHCP lease MAC addresses only. No client, lease, invoice, or MikroTik record is changed from this page.</p>
            </div>
          </div>
        </section>

        <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
          <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
              <h2 className="font-semibold text-foreground">1. Prepare the spreadsheet</h2>
              <p className="mt-1 text-sm text-muted-foreground">Use the exact template headers. Full MACs match automatically; a one-character-short MAC needs your confirmation.</p>
            </div>
            <button onClick={() => void downloadTemplate()} className="inline-flex items-center justify-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-medium text-foreground hover:bg-secondary">
              <Download className="h-4 w-4" /> Download template
            </button>
          </div>
          <div className="mt-5 flex flex-col gap-3 sm:flex-row">
            <label className="flex min-h-12 flex-1 cursor-pointer items-center gap-3 rounded-lg border border-dashed border-slate-300 px-4 text-sm text-muted-foreground hover:border-sky-500 dark:border-slate-700">
              <FileSpreadsheet className="h-5 w-5 text-sky-600" />
              <span className="truncate">{file?.name || 'Choose an XLSX, XLS, or CSV file'}</span>
              <input className="sr-only" type="file" accept=".xlsx,.xls,.csv" onChange={(event) => setFile(event.target.files?.[0] || null)} />
            </label>
            <button disabled={!file || busy} onClick={() => void generatePreview()} className="inline-flex items-center justify-center gap-2 rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-50">
              <Upload className="h-4 w-4" /> {busy ? 'Checking…' : 'Generate preview'}
            </button>
          </div>
          {error && <p className="mt-3 text-sm text-red-600">{error}</p>}
        </section>

        {preview.length > 0 && <section className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
          <div className="border-b border-border p-5">
            <h2 className="font-semibold text-foreground">2. MAC-only preview</h2>
            <p className="mt-1 text-sm text-muted-foreground">Audit {auditId}. Confirmation/import will be enabled in the next step after review.</p>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-muted/60 text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-4 py-3">Row</th><th className="px-4 py-3">Client</th><th className="px-4 py-3">MAC</th><th className="px-4 py-3">Match result</th><th className="px-4 py-3">Review</th></tr></thead>
              <tbody className="divide-y divide-border">
                {preview.map((item) => <tr key={item.row}><td className="px-4 py-3">{item.row}</td><td className="px-4 py-3 font-medium text-foreground">{item.record.Name}</td><td className="px-4 py-3 font-mono text-xs">{item.record.Leases}</td><td className="px-4 py-3">{item.match_status}</td><td className="px-4 py-3">{item.requires_confirmation ? <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Confirmation required</span> : <span className="rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Ready for review</span>}</td></tr>)}
              </tbody>
            </table>
          </div>
        </section>}
      </div>
    </DashboardLayout>
  );
};

export default ClientMigrationPage;
