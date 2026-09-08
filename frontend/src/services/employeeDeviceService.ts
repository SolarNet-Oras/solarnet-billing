import { api } from './api';

export interface EmployeeDevice {
  id: string; name: string; employee_name: string; platform: 'windows'|'android';
  os_version?: string; agent_version: string; status: string; online: boolean;
  consent_accepted_at: string; last_seen_at?: string; revoked_at?: string;
}
export interface DeviceAudit { id:string; device_id?:string; event:string; metadata?:Record<string,unknown>; created_at:string }

export const employeeDeviceService = {
  async index(): Promise<{devices:EmployeeDevice[]; audits:DeviceAudit[]}> { return (await api.get('/employee-devices')).data.data; },
  async createEnrollment(): Promise<{code:string; expires_at:string}> { return (await api.post('/employee-devices/enrollment')).data.data; },
  async revoke(id:string): Promise<void> { await api.post(`/employee-devices/${id}/revoke`); },
};
