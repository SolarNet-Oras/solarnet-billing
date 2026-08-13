import api from './api';
import type { Customer, Invoice, Payment, PaginatedResponse } from '../types/api';

export const customerPortalService = {
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

  changePassword: async (data: { current_password: string; password: string; password_confirmation: string }): Promise<{ status: string; message: string }> => {
    const response = await api.put('/customer-portal/password', data);
    return response.data;
  },
};

export default customerPortalService;
