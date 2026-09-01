import { api } from './api';
import type { Router } from './routerService';
import type { ServicePlan } from './servicePlanService';

// An all-router DHCP refresh may inspect hundreds of leases through a VPN.
// This applies only to the deliberate refresh action, not normal page calls.
const DHCP_ALL_ROUTER_SYNC_TIMEOUT = 180_000;

/**
 * A DHCP lease shown in the DHCP review workspace. Dynamic rows may already
 * be customer-owned; those rows include a read-only identity hint.
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
  is_current: boolean;
  is_matched: boolean;
  status: string;
  server: string;
  last_seen_at: string;
  created_at: string;
  router?: Pick<Router, 'id' | 'name'>;
  /** Read-only identity hint when this DHCP MAC already belongs to a customer profile. */
  known_customer_identity?: {
    status: 'known_customer' | 'ambiguous';
    customer_count: number;
    message: string;
    customer?: {
      id: string;
      account_number: string;
      full_name: string;
      status: string;
      same_router: boolean;
      can_push_to_router: boolean;
    };
    customers?: Array<{
      id: string;
      account_number: string;
      full_name: string;
      status: string;
      same_router: boolean;
      can_push_to_router: boolean;
    }>;
  } | null;
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
  /** Required when deliberately moving a MAC already owned by Client A to Client B. */
  confirm_mac_reassignment?: boolean;
  /** Required when moving an exact known customer MAC to its current DHCP router. */
  confirm_current_client_reassignment?: boolean;
  /** Required when choosing the one real owner of a MAC found on multiple profiles. */
  confirm_duplicate_mac_resolution?: boolean;
}

export interface CustomerLinkCandidate {
  id: string;
  account_number: string;
  full_name: string;
  address: string;
  status: 'active' | 'suspended' | 'expired' | 'pending' | string;
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

/** Server-backed groups used by the Unregistered Clients workspace. */
export type UnregisteredLeaseVariant = 'all' | 'static_commented' | 'dynamic';

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
    const response = await api.post<{ success: boolean; data: any }>(
      '/unregistered-leases/sync-all',
      undefined,
      { timeout: DHCP_ALL_ROUTER_SYNC_TIMEOUT },
    );
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

  /**
   * Unified, explicit variant loader. The backend still owns which lease
   * belongs in each group; "all" combines both safe read-only endpoints.
   */
  async listByVariant(variant: UnregisteredLeaseVariant): Promise<UnregisteredLease[]> {
    if (variant === 'static_commented') return this.listStaticCommented();
    if (variant === 'dynamic') return this.listDynamic();

    const [staticRows, dynamicRows] = await Promise.all([
      this.listStaticCommented(),
      this.listDynamic(),
    ]);
    const seen = new Set<string>();

    return [...staticRows, ...dynamicRows].filter((lease) => {
      if (seen.has(lease.id)) return false;
      seen.add(lease.id);
      return true;
    });
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

  async deleteInactive(leaseId: string, confirmationMac: string): Promise<{ success: boolean; message: string }> {
    const response = await api.delete<{ success: boolean; message: string }>(`/unregistered-leases/${leaseId}/inactive`, {
      data: { confirmation_mac: confirmationMac },
    });
    return response.data;
  },
};

export type { ServicePlan };
