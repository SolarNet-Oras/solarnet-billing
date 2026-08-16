import { api } from './api';

export interface Router {
  id: string;
  name: string;
  host: string;
  port: number;
  username: string;
  location?: string;
  notes?: string;
  dhcp_pool_name?: string;
  is_active: boolean;
  connection_status: 'online' | 'offline' | 'unknown';
  routeros_version?: string;
  last_connected_at?: string;
  last_sync_at?: string;
  created_at: string;
  updated_at: string;
}

export interface CreateRouterData {
  name: string;
  host: string;
  port: number;
  username: string;
  password: string;
  location?: string;
  notes?: string;
  dhcp_pool_name?: string;
  is_active?: boolean;
}

export interface UpdateRouterData extends Partial<CreateRouterData> {}

export interface TestConnectionResponse {
  success: boolean;
  message: string;
  data?: {
    version: string;
    uptime: string;
    cpu_load: string;
    free_memory: string;
    total_memory: string;
    board_name: string;
  };
}

export interface RouterMonitoringSnapshot {
  cpu_load: number;
  free_memory: number;
  total_memory: number;
  uptime: string | null;
  running_interfaces: number;
  rx_bps: number | null;
  tx_bps: number | null;
  traffic_sampled: boolean;
  threat_status: 'protected' | 'monitoring';
  firewall_drop_rules: number;
  threat_signal_rules: number;
  threat_address_list_entries: number;
  threat_blocked_packets: number;
  scanned_at: string;
}

export interface RouterThreatObservation {
  id: string;
  router_id: string;
  feed_name: string;
  remote_ip: string;
  connection_directions: string[] | null;
  status: 'pending' | 'dismissed' | 'blocked';
  first_observed_at: string;
  last_observed_at: string;
  reviewed_at: string | null;
  review_note: string | null;
  blocked_at: string | null;
  reviewer?: { id: string; name: string; email: string } | null;
}

export interface RouterQosInspection {
  routeros_version: string | null;
  board_name: string | null;
  architecture: string | null;
  cpu_load: number;
  free_memory: number;
  total_memory: number;
  uptime: string | null;
  interfaces: Array<{ name: string; type: string | null; running: boolean; disabled: boolean }>;
  bridge_interfaces: string[];
  vlan_interfaces: string[];
  client_interfaces: string[];
  client_subnets: Array<{ interface: string; address: string }>;
  wan_candidates: Array<{ gateway: string | null; interface: string | null; distance: string | null; routing_table: string }>;
  multi_wan_detected: boolean;
  fasttrack: { enabled: boolean; count: number };
  existing_queues: { simple_total: number; billing_customer_queues: number; other_simple_queues: number; queue_tree_total: number; solarnet_qos_trees: number };
  queue_capabilities: { cake: string[]; fq_codel: string[]; pcq: string[] };
  mangle_rule_count: number;
  firewall_filter_count: number;
  firewall_nat_count: number;
  routing_rule_count: number;
  // Deliberately not polled: enumerating a large firewall connection table
  // can degrade a busy router. It is not needed for QoS safety decisions.
  active_connections: number | null;
  dhcp_lease_count: number;
  ethernet_interface_count: number;
  wireguard_interface_count: number;
  warnings: string[];
  inspection_errors: Array<{ path: string; message: string }>;
  inspected_at: string;
}

export interface RouterQosDeployment {
  id: string;
  router_id: string;
  configuration_version: number;
  status: 'previewed' | 'refused' | 'applying' | 'active' | 'failed' | 'rolled_back' | 'disabled';
  strategy: string | null;
  queue_type: string | null;
  configuration: Record<string, unknown> | null;
  backup_filename: string | null;
  backup_verified_at: string | null;
  verification: Record<string, unknown> | null;
  failure_reason: string | null;
  created_at: string;
  applied_at: string | null;
}

export interface RouterQosMetrics extends RouterMonitoringSnapshot {
  active_connections: number | null;
  queue_tree_count: number;
  queue_drops: number;
  queue_drop_delta: number | null;
  memory_used_percent: number | null;
  latency_ms: number | null;
  packet_loss_percent: number | null;
  latency_note: string;
  warnings: string[];
  freshness: 'live' | 'stale';
  measured_at: string;
}

export interface RouterQosPreview {
  ready: boolean;
  errors: string[];
  warnings: string[];
  configuration: { download_limit: string; upload_limit: string; queue_type: string | null; strategy: string | null; [key: string]: unknown };
  recommendation: { strategy: string | null; queue_type: string | null; cake_available_but_not_selected: string[]; reason: string };
  preservation: { customer_simple_queues_preserved: number; administrator_simple_queues_preserved: number; firewall_rules_changed: number; mangle_rules_changed: number; queue_types_created: number; queue_trees_to_create: number };
  risk: 'low' | 'medium' | 'blocked';
}

export const routerService = {
  async getAll(): Promise<Router[]> {
    const response = await api.get<{ success: boolean; data: Router[] }>('/routers');
    return response.data.data;
  },

  async getOne(id: string): Promise<Router> {
    const response = await api.get<{ success: boolean; data: Router }>(`/routers/${id}`);
    return response.data.data;
  },

  async create(data: CreateRouterData): Promise<Router> {
    const response = await api.post<{ success: boolean; data: Router }>('/routers', data);
    return response.data.data;
  },

  async update(id: string, data: UpdateRouterData): Promise<Router> {
    const response = await api.put<{ success: boolean; data: Router }>(`/routers/${id}`, data);
    return response.data.data;
  },

  async delete(id: string): Promise<void> {
    await api.delete(`/routers/${id}`);
  },

  async testConnection(id: string): Promise<TestConnectionResponse> {
    const response = await api.post<TestConnectionResponse>(`/routers/${id}/test-connection`);
    return response.data;
  },

  async sync(id: string): Promise<{ success: boolean; message: string }> {
    const response = await api.post<{ success: boolean; message: string }>(`/routers/${id}/sync`);
    return response.data;
  },

  async monitoring(id: string): Promise<RouterMonitoringSnapshot> {
    const response = await api.get<{ success: boolean; data: RouterMonitoringSnapshot }>(`/routers/${id}/monitoring`);
    return response.data.data;
  },

  async scanThreatFeed(id: string): Promise<{ message: string; data: { indicators_loaded: number; connections_checked: number; matches: RouterThreatObservation[]; scanned_at: string } }> {
    const response = await api.post<{ success: boolean; message: string; data: { indicators_loaded: number; connections_checked: number; matches: RouterThreatObservation[]; scanned_at: string } }>(`/routers/${id}/threat-scan`);
    return { message: response.data.message, data: response.data.data };
  },

  async threatObservations(id: string): Promise<RouterThreatObservation[]> {
    const response = await api.get<{ success: boolean; data: RouterThreatObservation[] }>(`/routers/${id}/threat-observations`);
    return response.data.data;
  },

  async reviewThreatObservation(routerId: string, observationId: string, decision: 'approve_block' | 'dismiss'): Promise<{ message: string; data: RouterThreatObservation }> {
    const response = await api.post<{ success: boolean; message: string; data: RouterThreatObservation }>(`/routers/${routerId}/threat-observations/${observationId}/review`, { decision });
    return { message: response.data.message, data: response.data.data };
  },

  async qosStatus(id: string): Promise<{ inspection: RouterQosInspection; active_deployment: RouterQosDeployment | null }> {
    const response = await api.get<{ success: boolean; data: { inspection: RouterQosInspection; active_deployment: RouterQosDeployment | null } }>(`/routers/${id}/qos/status`);
    return response.data.data;
  },

  async qosConfig(id: string): Promise<RouterQosDeployment[]> {
    const response = await api.get<{ success: boolean; data: RouterQosDeployment[] }>(`/routers/${id}/qos/config`);
    return response.data.data;
  },

  async qosClients(id: string): Promise<{ data: Array<{ customer_id: string; account_number: string; full_name: string; ip_address: string | null; mac_address: string | null; status: string; plan: { name: string; download_speed: number; upload_speed: number; priority: number; qos_priority_level: 'Critical' | 'High' | 'Normal' | 'Low' } | null; queue: { name: string; max_limit: string | null; rate: string | null; dropped: string | null; disabled: boolean } | null }>; queue_read_warning: string | null }> {
    const response = await api.get<{ success: boolean; data: Array<any>; queue_read_warning: string | null }>(`/routers/${id}/qos/clients`);
    return { data: response.data.data, queue_read_warning: response.data.queue_read_warning };
  },

  async qosPreview(id: string, data: { download_capacity_mbps: number; upload_capacity_mbps: number; ceiling_percent: number; download_parent: string; upload_parent: string; mode?: 'production' | 'test' }): Promise<{ message: string; data: { deployment: RouterQosDeployment; preview: RouterQosPreview } }> {
    const response = await api.post<{ success: boolean; message: string; data: { deployment: RouterQosDeployment; preview: RouterQosPreview } }>(`/routers/${id}/qos/preview`, data);
    return { message: response.data.message, data: response.data.data };
  },

  async qosApply(id: string, deploymentId: string): Promise<{ message: string; data: RouterQosDeployment }> {
    const response = await api.post<{ success: boolean; message: string; data: RouterQosDeployment }>(`/routers/${id}/qos/apply`, { deployment_id: deploymentId, confirm_apply: true });
    return { message: response.data.message, data: response.data.data };
  },

  async qosRollback(id: string, deploymentId: string): Promise<{ message: string; data: RouterQosDeployment }> {
    const response = await api.post<{ success: boolean; message: string; data: RouterQosDeployment }>(`/routers/${id}/qos/rollback`, { deployment_id: deploymentId, confirm_rollback: true });
    return { message: response.data.message, data: response.data.data };
  },

  async qosDisable(id: string): Promise<{ message: string; data: RouterQosDeployment }> {
    const response = await api.post<{ success: boolean; message: string; data: RouterQosDeployment }>(`/routers/${id}/qos/disable`, { confirm_disable: true });
    return { message: response.data.message, data: response.data.data };
  },

  async qosMetrics(id: string): Promise<RouterQosMetrics> {
    const response = await api.get<{ success: boolean; data: RouterQosMetrics }>(`/routers/${id}/qos/metrics`);
    return response.data.data;
  },

  async qosTest(id: string, target: string): Promise<{ target: string; sent: number; received: number; packet_loss_percent: number; latency_ms: number | null; minimum_latency_ms: number | null; maximum_latency_ms: number | null; tested_at: string }> {
    const response = await api.post<{ success: boolean; data: { target: string; sent: number; received: number; packet_loss_percent: number; latency_ms: number | null; minimum_latency_ms: number | null; maximum_latency_ms: number | null; tested_at: string } }>(`/routers/${id}/qos/test`, { target });
    return response.data.data;
  },

  async installBillingAccess(id: string): Promise<{ success: boolean; message: string; rules_installed?: number }> {
    const response = await api.post(`/routers/${id}/billing-access/install`);
    return response.data;
  },

  async billingAccessStatus(id: string): Promise<{ success: boolean; installed: boolean; rule_count: number; message?: string }> {
    const response = await api.get(`/routers/${id}/billing-access`);
    return response.data;
  },

  async billingAccessAudit(id: string): Promise<{ success: boolean; audit: { dhcp_server_count: number; customer_interfaces: Array<{ interface: string; gateway: string | null }>; hotspot_count: number; hotspot_interfaces: string[]; recommended_mode: string; hotspot_change_required: boolean; safety_note: string } }> {
    const response = await api.get(`/routers/${id}/billing-access/audit`);
    return response.data;
  },

  async removeBillingAccess(id: string): Promise<{ success: boolean; message: string; removed?: number }> {
    const response = await api.delete(`/routers/${id}/billing-access`);
    return response.data;
  },

  async runConsoleScript(id: string, script: string): Promise<{ success: boolean; message: string; result?: unknown }> {
    const response = await api.post(`/routers/${id}/console/script`, { script });
    return response.data;
  },

  async consolePing(id: string, address: string, count: number = 4): Promise<{ success: boolean; message: string; rows?: unknown[] }> {
    const response = await api.post(`/routers/${id}/console/ping`, { address, count });
    return response.data;
  },

  async generateSetupScript(id: string, billingSystemIp?: string): Promise<{ script: string; billing_system_ip?: string | null }> {
    const params = billingSystemIp ? { billing_system_ip: billingSystemIp } : {};
    const response = await api.get<{ success: boolean; data: { script: string; billing_system_ip?: string | null } }>(`/routers/${id}/setup-script`, { params });
    return response.data.data;
  },

  async previewSetupScript(data: {
    name: string;
    host?: string;
    port?: number;
    username: string;
    password: string;
    billing_system_ip?: string;
  }): Promise<{ script: string; billing_system_ip: string | null }> {
    const response = await api.post<{ success: boolean; data: { script: string; billing_system_ip: string | null } }>(
      '/routers/preview-script',
      data
    );
    return response.data.data;
  },

  async getNetworkInfo(): Promise<{ billing_system_ip: string | null; default_api_port: number; default_ssl_port: number }> {
    const response = await api.get<{ success: boolean; data: { billing_system_ip: string | null; default_api_port: number; default_ssl_port: number } }>(
      '/system/network-info'
    );
    return response.data.data;
  },

  async syncDhcp(id: string, autoCreateCustomers: boolean = true): Promise<any> {
    const response = await api.post<{ success: boolean; data: any }>(`/routers/${id}/sync-dhcp`, { 
      auto_create_customers: autoCreateCustomers 
    });
    return response.data.data;
  },

  async getUnmatchedLeases(id: string): Promise<any[]> {
    const response = await api.get<{ success: boolean; data: any[] }>(`/routers/${id}/unmatched-leases`);
    return response.data.data;
  },
};
