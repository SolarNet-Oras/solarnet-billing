import api from './api';
import type { Customer, PaginatedResponse } from '../types/api';

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
};

export default customerService;
