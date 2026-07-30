import React, { useEffect, useState } from 'react';
import { Play, RefreshCw, CheckCircle2, AlertTriangle, XCircle, Clock } from 'lucide-react';
import {
  automationService,
  type AutomationJob,
  type AutomationJobInfo,
  type AutomationLog,
} from '@/services/automationService';

const STATUS_STYLES: Record<string, string> = {
  success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200',
  partial: 'bg-amber-100  text-amber-800  dark:bg-amber-900/30  dark:text-amber-200',
  error:   'bg-red-100    text-red-800    dark:bg-red-900/30    dark:text-red-200',
};

const STATUS_ICON: Record<string, React.ReactNode> = {
  success: <CheckCircle2 className="w-3.5 h-3.5" />,
  partial: <AlertTriangle className="w-3.5 h-3.5" />,
  error:   <XCircle className="w-3.5 h-3.5" />,
};

const formatDate = (iso: string | null): string => {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString();
};

export const AutomationPanel: React.FC = () => {
  const [jobs, setJobs] = useState<AutomationJobInfo[]>([]);
  const [logs, setLogs] = useState<AutomationLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [running, setRunning] = useState<AutomationJob | null>(null);
  const [error, setError] = useState('');

  const load = async (): Promise<void> => {
    setError('');
    try {
      const [j, l] = await Promise.all([automationService.jobs(), automationService.logs(1, 10)]);
      setJobs(j);
      setLogs(l.data);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to load automation data');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const handleRun = async (job: AutomationJob): Promise<void> => {
    setRunning(job);
    setError('');
    try {
      await automationService.run(job);
      await load();
    } catch (err: any) {
      const msg = err?.response?.data?.message || err?.message || 'Run failed';
      setError(`${job}: ${msg}`);
    } finally {
      setRunning(null);
    }
  };

  return (
    <section className="bg-card border border-border rounded-lg p-6" data-testid="settings-section-automation-panel">
      <div className="flex items-start justify-between mb-1">
        <div>
          <h2 className="text-xl font-semibold text-foreground">Scheduled Automations</h2>
          <p className="text-sm text-muted-foreground mt-1">
            Background jobs run daily on the Asia/Manila timezone. Toggle the
            master switch in the <span className="font-mono">automation</span> section above to pause everything.
          </p>
        </div>
        <button
          type="button"
          onClick={() => { setLoading(true); void load(); }}
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
          data-testid="automation-refresh-btn"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} /> Refresh
        </button>
      </div>

      {error && (
        <div className="mt-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-3 text-sm text-red-800 dark:text-red-200" data-testid="automation-error">
          {error}
        </div>
      )}

      {/* Jobs grid */}
      <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
        {jobs.map((j) => (
          <div key={j.job} className="border border-border rounded-md p-4" data-testid={`automation-job-${j.job}`}>
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="font-medium text-foreground truncate">{j.label}</div>
                <div className="text-xs text-muted-foreground font-mono mt-0.5">{j.command}</div>
                <div className="flex items-center gap-1.5 text-xs text-muted-foreground mt-2">
                  <Clock className="w-3.5 h-3.5" /> {j.schedule}
                </div>
              </div>
              <button
                type="button"
                onClick={() => handleRun(j.job)}
                disabled={running === j.job}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm bg-primary text-primary-foreground rounded-md hover:opacity-90 disabled:opacity-40 shrink-0"
                data-testid={`automation-run-${j.job}`}
              >
                <Play className="w-3.5 h-3.5" />
                {running === j.job ? 'Running…' : 'Run now'}
              </button>
            </div>

            {j.last_run ? (
              <div className="mt-3 pt-3 border-t border-border">
                <div className="flex items-center gap-2">
                  <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_STYLES[j.last_run.status] || ''}`}>
                    {STATUS_ICON[j.last_run.status]} {j.last_run.status}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {formatDate(j.last_run.finished_at)} · {j.last_run.duration_ms}ms · {j.last_run.triggered_by}
                  </span>
                </div>
                <SummaryPreview summary={j.last_run.summary as Record<string, unknown> | null} />
              </div>
            ) : (
              <div className="mt-3 pt-3 border-t border-border text-xs text-muted-foreground">Never run yet.</div>
            )}
          </div>
        ))}
      </div>

      {/* Recent logs */}
      <div className="mt-6">
        <h3 className="text-sm font-semibold text-foreground mb-2">Recent runs</h3>
        <div className="border border-border rounded-md overflow-hidden">
          <table className="w-full text-sm" data-testid="automation-logs-table">
            <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
              <tr>
                <th className="text-left px-3 py-2">Job</th>
                <th className="text-left px-3 py-2">Status</th>
                <th className="text-left px-3 py-2">When</th>
                <th className="text-left px-3 py-2">Duration</th>
                <th className="text-left px-3 py-2">Trigger</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 && (
                <tr><td colSpan={5} className="text-center px-3 py-6 text-muted-foreground">No runs yet.</td></tr>
              )}
              {logs.map((row) => (
                <tr key={row.id} className="border-t border-border" data-testid={`automation-log-row-${row.id}`}>
                  <td className="px-3 py-2 font-mono text-xs">{row.job}</td>
                  <td className="px-3 py-2">
                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_STYLES[row.status] || ''}`}>
                      {STATUS_ICON[row.status]} {row.status}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-muted-foreground">{formatDate(row.started_at)}</td>
                  <td className="px-3 py-2 text-muted-foreground">{row.duration_ms}ms</td>
                  <td className="px-3 py-2 text-muted-foreground">{row.triggered_by}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </section>
  );
};

/**
 * Render a small human summary of the most useful numeric fields.
 * Falls back to a raw JSON preview if nothing matches.
 */
const SummaryPreview: React.FC<{ summary: Record<string, unknown> | null }> = ({ summary }) => {
  if (!summary) return null;

  const keysOfInterest = [
    'sent', 'candidates', 'suspended', 'flipped_to_overdue',
    'size_human', 'file', 'skipped', 'reason', 'error',
  ];
  const chips = keysOfInterest
    .filter((k) => summary[k] !== undefined && summary[k] !== null && summary[k] !== '')
    .map((k) => ({ k, v: String(summary[k]) }));

  if (chips.length === 0) return null;

  return (
    <div className="mt-2 flex flex-wrap gap-1.5">
      {chips.map((c) => (
        <span key={c.k} className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted text-xs text-muted-foreground">
          <span className="font-mono opacity-70">{c.k}:</span>
          <span className="text-foreground truncate max-w-[16rem]">{c.v}</span>
        </span>
      ))}
    </div>
  );
};

export default AutomationPanel;
