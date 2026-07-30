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
};
