import api from './api';

export interface SettingItem {
  key: string;
  value: string | number | boolean | null;
  cast: 'string' | 'int' | 'float' | 'bool' | 'json';
  group: string;
  label: string;
  is_readonly: boolean;
}

export const settingsService = {
  async list(): Promise<SettingItem[]> {
    const res = await api.get<{ success: boolean; data: SettingItem[] }>('/settings');
    return res.data.data;
  },
  async saveMany(items: Array<{ key: string; value: any }>): Promise<{ keys: string[] }> {
    const res = await api.put<{ status: string; keys: string[] }>('/settings', { settings: items });
    return { keys: res.data.keys };
  },
  async uploadCompanyLogo(file: File): Promise<{ logo_url: string; message: string }> {
    const form = new FormData();
    form.append('logo', file);
    const res = await api.post('/settings/company-logo', form, { headers: { 'Content-Type': 'multipart/form-data' } });
    return res.data;
  },
  async removeCompanyLogo(): Promise<{ message: string }> {
    const res = await api.delete('/settings/company-logo');
    return res.data;
  },
};
