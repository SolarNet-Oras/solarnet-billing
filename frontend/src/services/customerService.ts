import api from './api';
import type { Customer, PaginatedResponse } from '../types/api';

export type ClientSetupAction = 'installation_date' | 'billing_due_date' | 'previous_balance' | 'discount' | 'status' | 'address_updates';

export interface BulkClientSetupPayload {
  customer_ids: string[];
  action: ClientSetupAction;
  installation_date?: string;
  due_date?: string;
  billing_cycle_day?: number;
  update_open_invoices?: boolean;
  previous_balance?: number;
  previous_balance_due_date?: string;
  discount_amount?: number;
  status?: 'active' | 'suspended' | 'expired' | 'pending';
  address_updates?: Array<{ customer_id: string; address: string }>;
}

export interface BulkClientSetupResponse {
  status: string;
  message: string;
  data: {
    updatedCustomers: number;
    updatedInvoices: number;
    skipped: string[];
    results: Array<{
      customer_id: string;
      account_number: string;
      status: string;
      message: string;
    }>;
  };
}

export type CustomerUpdateImportRowStatus = 'ready' | 'unchanged' | 'no_match' | 'ambiguous' | 'pending' | 'invalid';

export interface CustomerUpdateImportRow {
  row: number;
  status: CustomerUpdateImportRowStatus;
  reason: string;
  client_name: string;
  address: string;
  due_date: string;
  due_day: number | null;
  customer_id: string | null;
  account_number: string | null;
  matched_full_name: string | null;
  match_type: 'exact' | 'name_variation' | 'typo_variation' | null;
  current_address: string | null;
  current_due_day: number | null;
}

export interface CustomerUpdateImportPreview {
  status: 'success';
  message: string;
  preview_token: string;
  expires_in_minutes: number;
  source_label: string;
  rows: CustomerUpdateImportRow[];
  summary: {
    total: number;
    ready: number;
    unchanged: number;
    no_match: number;
    ambiguous: number;
    pending: number;
    invalid: number;
  };
}

export interface CustomerUpdateImportApplyResponse {
  status: 'success';
  message: string;
  data: {
    updated: number;
    unchanged: number;
    skipped: string[];
  };
}

export const customerService = {
  /** Get all customers with optional filters */
  getCustomers: async (params?: {
    search?: string;
    status?: string;
    page?: number;
    per_page?: number;
  }): Promise<PaginatedResponse<Customer>> => {
    const response = await api.get('/customers', { params });
    return response.data;
  },

  /** Get a single customer by ID (unwraps { data: Customer }) */
  getCustomer: async (id: string): Promise<Customer> => {
    const response = await api.get<{ data: Customer } | Customer>(`/customers/${id}`);
    const body = response.data as { data?: Customer } & Customer;
    return (body.data ?? body) as Customer;
  },

  /** Update an existing customer (PUT /customers/{id}). */
  updateCustomer: async (id: string, payload: Partial<Customer> & Record<string, unknown>): Promise<Customer> => {
    const response = await api.put<{ data: Customer } | Customer>(`/customers/${id}`, payload);
    const body = response.data as { data?: Customer } & Customer;
    return (body.data ?? body) as Customer;
  },

  /** Soft-delete a customer (DELETE /customers/{id}). */
  deleteCustomer: async (id: string): Promise<{ status: string; message: string }> => {
    const response = await api.delete<{ status: string; message: string }>(`/customers/${id}`);
    return response.data;
  },

  /** Bulk soft-delete customers. */
  bulkDeleteCustomers: async (ids: string[]): Promise<{ status: string; message: string; deleted: number }> => {
    const response = await api.post<{ status: string; message: string; deleted: number }>(
      '/customers/bulk-delete',
      { customer_ids: ids }
    );
    return response.data;
  },

  /** Download a read-only PDF register for the current Customers-page filters. */
  downloadCustomersPdf: async (params?: { search?: string; status?: string }): Promise<Blob> => {
    const response = await api.get('/customers/pdf', {
      params,
      responseType: 'blob',
    });
    return response.data;
  },

  /** Controlled migration/setup changes for selected existing subscribers. */
  bulkSetupCustomers: async (payload: BulkClientSetupPayload): Promise<BulkClientSetupResponse> => {
    const response = await api.post<BulkClientSetupResponse>('/customers/bulk-setup', payload);
    return response.data;
  },

  /** Preview exact name matches before changing only address and due day. */
  previewCustomerUpdateImport: async (source: { file?: File; googleSheetUrl?: string }): Promise<CustomerUpdateImportPreview> => {
    const form = new FormData();
    if (source.file) form.append('file', source.file);
    if (source.googleSheetUrl?.trim()) form.append('google_sheet_url', source.googleSheetUrl.trim());
    const response = await api.post<CustomerUpdateImportPreview>('/customers/update-import/preview', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      // XLSX parsing and a public Google Sheet export can legitimately take
      // longer than the normal interactive API timeout. This override applies
      // only to the preview; no update is made by this request.
      timeout: 90000,
    });
    return response.data;
  },

  /** Apply the reviewed preview. The server rechecks the name match first. */
  applyCustomerUpdateImport: async (previewToken: string): Promise<CustomerUpdateImportApplyResponse> => {
    const response = await api.post<CustomerUpdateImportApplyResponse>('/customers/update-import/apply', {
      preview_token: previewToken,
    });
    return response.data;
  },
};

export default customerService;
