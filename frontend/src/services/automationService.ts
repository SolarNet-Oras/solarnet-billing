import api from './api';

export type AutomationJob =
  | 'invoice_reminders'
  | 'auto_suspend'
  | 'db_backup'
  | 'update_overdue';

export type AutomationStatus = 'success' | 'partial' | 'error';

export interface AutomationLog {
  id: string;
  job: AutomationJob;
  status: AutomationStatus;
  summary: Record<string, unknown> | null;
  duration_ms: number;
  triggered_by: 'schedule' | 'manual';
  triggered_by_user_id: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
}

export interface AutomationJobInfo {
  job: AutomationJob;
  label: string;
  command: string;
  schedule: string;
  last_run: (Omit<AutomationLog, 'id' | 'created_at'> & { id: string }) | null;
}

interface PaginatedLogs {
  data: AutomationLog[];
  total: number;
  current_page: number;
  last_page: number;
  per_page: number;
}

export const automationService = {
  async jobs(): Promise<AutomationJobInfo[]> {
    const res = await api.get<{ success: boolean; data: AutomationJobInfo[] }>('/automation/jobs');
    return res.data.data;
  },
  async logs(page = 1, perPage = 25, job?: AutomationJob): Promise<PaginatedLogs> {
    const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
    if (job) params.set('job', job);
    const res = await api.get<{ success: boolean; data: PaginatedLogs }>(`/automation/logs?${params.toString()}`);
    return res.data.data;
  },
  async run(job: AutomationJob): Promise<{ success: boolean; log: AutomationLog }> {
    const res = await api.post<{ success: boolean; log: AutomationLog }>(`/automation/run/${job}`);
    return res.data;
  },
};
