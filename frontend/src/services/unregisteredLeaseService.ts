import { api } from './api';
import type { Router } from './routerService';
import type { ServicePlan } from './servicePlanService';

/**
 * A DHCP lease that has not yet been converted into a Customer.
 */
export interface UnregisteredLease {
  id: string;
  router_id: string;
  mac_address: string;
  ip_address: string;
  hostname: string | null;
  comment: string | null;
  rate_limit: string | null;
  is_dynamic: boolean;
  is_matched: boolean;
  status: string;
  server: string;
  last_seen_at: string;
  created_at: string;
  router?: Pick<Router, 'id' | 'name'>;
  /** Server-computed plan match based on rate_limit. Only present on staticCommented(). */
  suggested_plan?: {
    id: string;
    name: string;
    price: number;
    download_speed: number;
    upload_speed: number;
  } | null;
}

export interface QuickRegisterPayload {
  /** Links the current DHCP lease to an existing customer instead of creating one. */
  existing_customer_id?: string;
  full_name?: string;
  service_plan_id?: string;
  contact_number?: string;
  address?: string;
  email?: string;
  monthly_fee?: number;
}

export interface CustomerLinkCandidate {
  id: string;
  account_number: string;
  full_name: string;
  address: string;
  status: 'active' | 'suspended' | 'expired';
  service_plan_id: string | null;
  monthly_fee: number;
  mac_address: string | null;
  service_plan?: {
    id: string;
    name: string;
    price: number;
    download_speed: number;
    upload_speed: number;
  } | null;
}

export interface QuickRegisterResponse {
  success: boolean;
  message: string;
  data: any;
  portal_credentials: null | {
    email: string;
    password: string;
    portal_url: string;
    welcome_email_sent: boolean;
  };
}

export const unregisteredLeaseService = {
  /** Sync leases from all active routers into the DB (no auto-create). */
  async syncAll(): Promise<{ total_routers: number; success: number; failed: number; routers: any[] }> {
    const response = await api.post<{ success: boolean; data: any }>('/unregistered-leases/sync-all');
    return response.data.data;
  },

  /** Static leases WITH a MikroTik comment (1-click register candidates). */
  async listStaticCommented(): Promise<UnregisteredLease[]> {
    const response = await api.get<{ success: boolean; data: UnregisteredLease[] }>(
      '/unregistered-leases/static-commented'
    );
    return response.data.data;
  },

  /** Dynamic OR uncommented leases (manual add flow). */
  async listDynamic(): Promise<UnregisteredLease[]> {
    const response = await api.get<{ success: boolean; data: UnregisteredLease[] }>(
      '/unregistered-leases/dynamic'
    );
    return response.data.data;
  },

  /** Existing customer accounts that may receive an unregistered DHCP lease. */
  async customerLinkCandidates(): Promise<CustomerLinkCandidate[]> {
    const response = await api.get<{ success: boolean; data: CustomerLinkCandidate[] }>(
      '/unregistered-leases/customer-link-candidates'
    );
    return response.data.data;
  },

  /** One-click convert a static+commented lease into a customer. */
  async quickRegister(leaseId: string, payload: QuickRegisterPayload = {}): Promise<QuickRegisterResponse> {
    const response = await api.post<QuickRegisterResponse>(
      `/unregistered-leases/${leaseId}/quick-register`,
      payload
    );
    return response.data;
  },
};

export type { ServicePlan };
