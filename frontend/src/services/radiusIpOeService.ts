import api from './api';

export type RadiusAuthorizationStatus = 'active' | 'grace' | 'suspended' | 'disconnected' | 'pending' | 'conflict' | 'waiting_for_mac';

export interface RadiusConfigurationStatus {
  enabled: boolean;
  host_configured: boolean;
  auth_port: number;
  acct_port: number;
  coa_port: number;
  timeout: number;
  retries: number;
  external_bridge_installed: boolean;
  coa_available: boolean;
  freeradius_enabled: boolean;
  sql_sync_enabled: boolean;
  mode: 'staged_only' | 'freeradius_isolated' | 'freeradius_sql_bridge';
  safety_note: string;
}

export interface RadiusSubscriberRow {
  id: string;
  radius_username: string | null;
  mac_address: string | null;
  ip_address: string | null;
  authorization_status: RadiusAuthorizationStatus;
  billing_status: string | null;
  rate_limit: string | null;
  requires_captive_portal: boolean;
  mac_conflict: boolean;
  last_synced_at: string | null;
  customer: { id: string; account_number: string; full_name: string; status: string; service_plan?: { name: string } | null } | null;
  router: { id: string; name: string } | null;
}

export interface RadiusTestCandidate {
  id: string;
  account_number: string;
  full_name: string;
  status: string;
  mac_address: string;
  ip_address: string | null;
  router: { id: string; name: string };
  service_plan: { name: string; download_speed: number; upload_speed: number };
  rate_limit: string;
}

export interface RadiusRouterCandidate {
  id: string;
  name: string;
  suggested_source_ip: string | null;
  connection_status: string | null;
}

export interface RadiusNasClient {
  id: string;
  router_id: string | null;
  name: string;
  nas_address: string;
  shortname: string;
  enabled: boolean;
  test_mode: boolean;
  last_synced_at: string | null;
  last_error: string | null;
  router: { id: string; name: string } | null;
}

export interface RadiusNasInput {
  router_id: string | null;
  name: string;
  nas_address: string;
  shortname: string;
  shared_secret: string;
  enabled: boolean;
  test_mode: boolean;
  source_verified: boolean;
}

export const radiusIpOeService = {
  async status(): Promise<RadiusConfigurationStatus> {
    const response = await api.get<{ success: boolean; data: RadiusConfigurationStatus }>('/radius/status');
    return response.data.data;
  },

  async list(search = ''): Promise<RadiusSubscriberRow[]> {
    const response = await api.get<{ success: boolean; data: RadiusSubscriberRow[] }>('/radius/subscribers', { params: { search, per_page: 100 } });
    return response.data.data;
  },

  async testCandidates(): Promise<RadiusTestCandidate[]> {
    const response = await api.get<{ success: boolean; data: RadiusTestCandidate[] }>('/radius/test-candidates');
    return response.data.data;
  },

  async routerCandidates(): Promise<RadiusRouterCandidate[]> {
    const response = await api.get<{ success: boolean; data: RadiusRouterCandidate[] }>('/radius/router-candidates');
    return response.data.data;
  },

  async sync(customerId: string): Promise<void> {
    await api.post(`/radius/subscribers/${customerId}/sync`);
  },

  async test(customerId: string): Promise<void> {
    await api.post(`/radius/subscribers/${customerId}/test`);
  },

  async stageAll(): Promise<{ queued: number }> {
    const response = await api.post<{ success: boolean; data: { queued: number } }>('/radius/stage-all');
    return response.data.data;
  },

  async listNasClients(): Promise<RadiusNasClient[]> {
    const response = await api.get<{ success: boolean; data: RadiusNasClient[] }>('/radius/nas-clients');
    return response.data.data;
  },

  async createNasClient(input: RadiusNasInput): Promise<void> {
    await api.post('/radius/nas-clients', input);
  },

  async syncNasClient(id: string): Promise<void> {
    await api.post(`/radius/nas-clients/${id}/sync`);
  },
};
