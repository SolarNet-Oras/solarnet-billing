import { api } from './api';

export interface EmployeeDevice {
  id: string; name: string; employee_name: string; platform: 'windows'|'android';
  os_version?: string; agent_version: string; status: string; online: boolean;
  consent_accepted_at: string; last_seen_at?: string; revoked_at?: string;
  posture_checked_at?: string; security_posture?: { firewall_enabled?:boolean|null; defender_enabled?:boolean|null; realtime_protection_enabled?:boolean|null; bitlocker_enabled?:boolean|null; secure_boot_enabled?:boolean|null; pending_reboot?:boolean|null; antivirus_signature_updated_at?:string|null };
}
export interface DeviceAudit { id:string; device_id?:string; event:string; metadata?:Record<string,unknown>; created_at:string }
export interface DeviceCommand { id:string; device_id:string; command:'message'|'lock'|'restart'; message?:string; reason:string; status:string; result_message?:string; created_at:string; responded_at?:string }

export const employeeDeviceService = {
  async index(): Promise<{devices:EmployeeDevice[]; audits:DeviceAudit[]; commands:DeviceCommand[]}> { return (await api.get('/employee-devices')).data.data; },
  async createEnrollment(): Promise<{code:string; expires_at:string}> { return (await api.post('/employee-devices/enrollment')).data.data; },
  async revoke(id:string): Promise<void> { await api.post(`/employee-devices/${id}/revoke`); },
  async requestCommand(id:string, input:{command:'message'|'lock'|'restart';message?:string;reason:string;confirmation:string}): Promise<void> { await api.post(`/employee-devices/${id}/commands`,input); },
  async downloadWindowsAgent(): Promise<void> {
    const response = await api.get('/employee-devices/agent/windows', { responseType: 'blob' });
    const url = URL.createObjectURL(response.data); const link = document.createElement('a');
    link.href=url; link.download='SolarNet-Windows-Agent.zip'; link.click(); URL.revokeObjectURL(url);
  },
};
