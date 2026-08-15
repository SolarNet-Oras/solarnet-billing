import api from './api';

export const HISTORICAL_CLEANUP_CONFIRMATION = 'DELETE HISTORICAL DATA';

export type HistoricalCleanupModule = 'daily_operations' | 'invoices' | 'tickets' | 'liquidations' | 'installation_applications';

export interface CleanupPreview {
  preview_token: string;
  expires_in_seconds: number;
  warning: string;
  customer_records_deleted: number;
  modules: Record<HistoricalCleanupModule, { label: string; eligible: number; blocked: number }>;
}

export const historicalCleanupService = {
  async preview(input: { from_date: string; to_date: string; modules: HistoricalCleanupModule[] }): Promise<CleanupPreview> {
    const response = await api.post<{ data: CleanupPreview }>('/super-admin/historical-cleanup/preview', input);
    return response.data.data;
  },
  async execute(preview_token: string, confirmation: string): Promise<void> {
    await api.post('/super-admin/historical-cleanup/execute', { preview_token, confirmation });
  },
};
