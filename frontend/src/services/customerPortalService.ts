import api from './api';
import type { Customer, Invoice, Payment, PaginatedResponse } from '../types/api';
import type { BrowserPushSubscriptionPayload } from '../lib/webPush';

export const customerPortalService = {
  getBranding: async (): Promise<{ name: string; logo_url: string; email: string; facebook_url: string }> => {
    const response = await api.get('/customer-portal/branding');
    const branding = response.data.data as { name: string; logo_url: string; email: string; facebook_url: string };
    // The favicon is a normal DOM link so the browser tab uses the same logo
    // selected by the administrator, rather than the compiled default mark.
    const icon = document.querySelector<HTMLLinkElement>('#company-favicon')
      ?? document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (icon && branding.logo_url) {
      icon.href = branding.logo_url;
      icon.removeAttribute('type');
    }
    return branding;
  },
  /**
   * Customer login
   */
  login: async (email: string, password?: string, accountNumber?: string): Promise<{
    customer: Customer;
    access_token: string;
    token_type: string;
  }> => {
    const response = await api.post('/customer-portal/login', {
      email,
      password,
      account_number: accountNumber,
    });
    return response.data.data;
  },

  /**
   * Get customer dashboard data
   */
  getDashboard: async (): Promise<{
    customer: Customer;
    stats?: {
      total_invoices: number;
      unpaid_invoices: number;
      total_outstanding: number;
      advance_credit: {
        available_credit: number;
        covered_cycles: Array<{
          cycle_date: string | null;
          amount: number;
          remaining_amount: number;
          status: string;
        }>;
      };
      last_payment: {
        amount: number;
        date: string;
        method: string;
      } | null;
    };
    status?: 'payment_required';
    message?: string;
    payment_required?: {
      customer_id: string;
      account_number: string;
      full_name: string;
      status: string;
      due_date: string | null;
      balance: number;
      payment_url: string;
      suspended_speed_kbps: number;
      service_plan?: {
        name: string;
        download_speed: number;
        upload_speed: number;
      } | null;
    };
  }> => {
    const response = await api.get('/customer-portal/dashboard');
    return response.data;
  },

  getPaymentReminder: async (customerId: string): Promise<{
    status: string;
    data: {
      customer_id: string;
      account_number: string;
      full_name: string;
      status: string;
      due_date: string | null;
      balance: number;
      payment_url: string;
      suspended_speed_kbps: number;
      service_plan?: {
        name: string;
        download_speed: number;
        upload_speed: number;
      } | null;
    };
  }> => {
    const response = await api.get(`/customer-portal/payment-reminder/${customerId}`);
    return response.data;
  },

  resolvePaymentReminder: async (data: {
    ip_address?: string;
    mac_address?: string;
    router_id?: string;
  }): Promise<{
    status: string;
    data: {
      customer_id: string;
      resolver_data: {
        customer_id: string;
        account_number: string;
        full_name: string;
        status: string;
        due_date: string | null;
        balance: number;
        payment_url: string;
        suspended_speed_kbps: number;
      };
      redirect_url: string;
    };
  }> => {
    const response = await api.post('/customer-portal/payment-reminder/resolve', data);
    return response.data;
  },

  /**
   * Get customer invoices
   */
  getInvoices: async (params?: {
    status?: string;
    page?: number;
    per_page?: number;
  }): Promise<PaginatedResponse<Invoice>> => {
    const response = await api.get('/customer-portal/invoices', { params });
    return response.data;
  },

  /**
   * Get single invoice
   */
  getInvoice: async (id: string): Promise<Invoice> => {
    const response = await api.get(`/customer-portal/invoices/${id}`);
    return response.data;
  },

  /**
   * Get customer payments
   */
  getPayments: async (params?: {
    page?: number;
    per_page?: number;
  }): Promise<PaginatedResponse<Payment>> => {
    const response = await api.get('/customer-portal/payments', { params });
    return response.data;
  },

  /**
   * Update customer profile
   */
  updateProfile: async (data: {
    contact_number?: string;
    address?: string;
    gps_coordinates?: { latitude: number; longitude: number };
  }): Promise<{ customer: Customer }> => {
    const response = await api.put('/customer-portal/profile', data);
    return response.data;
  },

  startGcashCheckout: async (invoiceId: string): Promise<{
    checkout_url: string;
    reference_number: string;
    account_number: string;
    customer_name: string;
    invoice_number: string;
    temporary_payment_access?: { success: boolean; granted: boolean; message: string };
  }> => {
    const response = await api.post(`/customer-portal/invoices/${invoiceId}/gcash-checkout`);
    return response.data.data;
  },

  reconcileLatestGcashCheckout: async (): Promise<{ found: boolean; paid: boolean }> => {
    const response = await api.post('/customer-portal/gcash-checkouts/reconcile-latest');
    return response.data.data;
  },

  getProfileChangeRequests: async (): Promise<{ data: CustomerProfileChangeRequest[] }> => {
    const response = await api.get('/customer-portal/profile-change-requests');
    return response.data;
  },

  requestProfileChange: async (data: { full_name?: string; service_plan_id?: string }): Promise<{ status: string; message: string; data: CustomerProfileChangeRequest }> => {
    const response = await api.post('/customer-portal/profile-change-requests', data);
    return response.data;
  },

  changePassword: async (data: { current_password: string; password: string; password_confirmation: string }): Promise<{ status: string; message: string }> => {
    const response = await api.put('/customer-portal/password', data);
    return response.data;
  },

  getPushNotificationStatus: async (): Promise<{
    enabled: boolean;
    subscribed: boolean;
    reason: string | null;
    public_key: string | null;
  }> => {
    const response = await api.get('/customer-portal/push-notifications/status');
    return response.data.data;
  },

  subscribePushNotifications: async (subscription: BrowserPushSubscriptionPayload): Promise<{ status: string; message: string }> => {
    const response = await api.post('/customer-portal/push-notifications/subscribe', subscription);
    return response.data;
  },

  unsubscribePushNotifications: async (endpoint: string): Promise<{ status: string; message: string }> => {
    const response = await api.delete('/customer-portal/push-notifications/subscribe', { data: { endpoint } });
    return response.data;
  },

  startLocationCapture: async (): Promise<{ token: string; expires_at: string; onu_reference: string }> => {
    const response = await api.post('/customer-portal/location-capture/start');
    return response.data.data;
  },

  captureLocation: async (data: { token: string; latitude: number; longitude: number; accuracy: number }): Promise<{ success: boolean; latitude: number; longitude: number; accuracy: number; message?: string }> => {
    const response = await api.post('/customer-portal/location-capture/capture', data);
    return response.data.data;
  },

  confirmLocationCapture: async (token: string): Promise<{ success: boolean; customer?: Customer; message?: string }> => {
    const response = await api.post('/customer-portal/location-capture/confirm', { token });
    return response.data.data;
  },
};

export interface CustomerProfileChangeRequest {
  id: string;
  requested_full_name: string | null;
  requested_service_plan: { id: string; name: string; price: number } | null;
  status: 'pending' | 'approved' | 'rejected';
  review_notes: string | null;
  created_at: string | null;
  reviewed_at: string | null;
}

export default customerPortalService;
