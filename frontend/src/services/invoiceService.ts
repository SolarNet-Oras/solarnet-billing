import api from './api';
import type {
  Invoice,
  CreateInvoiceRequest,
  RecordPaymentRequest,
  Payment,
  InvoiceStatistics,
  PaginatedResponse,
} from '../types/api';

export const invoiceService = {
  /**
   * Get all invoices with optional filters
   */
  getInvoices: async (params?: {
    customer_id?: string;
    status?: string;
    from_date?: string;
    to_date?: string;
    overdue?: boolean;
    unpaid?: boolean;
    page?: number;
    per_page?: number;
  }): Promise<PaginatedResponse<Invoice>> => {
    const response = await api.get('/invoices', { params });
    return response.data;
  },

  /**
   * Get a single invoice by ID
   */
  getInvoice: async (id: string): Promise<Invoice> => {
    const response = await api.get(`/invoices/${id}`);
    return response.data;
  },

  /**
   * Create/Generate a new invoice
   */
  createInvoice: async (data: CreateInvoiceRequest): Promise<{ message: string; invoice: Invoice }> => {
    const response = await api.post('/invoices', data);
    return response.data;
  },

  /**
   * Update invoice details
   */
  updateInvoice: async (
    id: string,
    data: {
      status?: string;
      due_date?: string;
      discount?: number;
      notes?: string;
    }
  ): Promise<{ message: string; invoice: Invoice }> => {
    const response = await api.put(`/invoices/${id}`, data);
    return response.data;
  },

  /**
   * Delete/Cancel an invoice
   */
  deleteInvoice: async (id: string): Promise<{ message: string }> => {
    const response = await api.delete(`/invoices/${id}`);
    return response.data;
  },

  /**
   * Mark invoice as sent
   */
  markAsSent: async (id: string): Promise<{ message: string; invoice: Invoice }> => {
    const response = await api.post(`/invoices/${id}/mark-sent`);
    return response.data;
  },

  /**
   * Record a payment for an invoice
   */
  recordPayment: async (
    invoiceId: string,
    data: RecordPaymentRequest
  ): Promise<{ message: string; payment: Payment; invoice: Invoice }> => {
    const response = await api.post(`/invoices/${invoiceId}/payments`, data);
    return response.data;
  },

  recordAdvancePayment: async (
    data: RecordPaymentRequest & { customer_id: string }
  ): Promise<{ message: string; payment: Payment }> => {
    const response = await api.post('/payments/advance', data);
    return response.data;
  },

  /**
   * Download invoice PDF
   */
  downloadPdf: async (id: string): Promise<Blob> => {
    const response = await api.get(`/invoices/${id}/pdf`, {
      responseType: 'blob',
    });
    return response.data;
  },

  /**
   * Generate recurring invoices for all active customers
   */
  generateRecurring: async (billingDate?: string): Promise<{
    message: string;
    results: {
      total: number;
      generated: number;
      skipped: number;
      errors: any[];
    };
  }> => {
    const response = await api.post('/invoices/generate-recurring', {
      billing_date: billingDate,
    });
    return response.data;
  },

  /**
   * Get invoice statistics
   */
  getStatistics: async (params?: {
    from_date?: string;
    to_date?: string;
  }): Promise<InvoiceStatistics> => {
    const response = await api.get('/invoices-statistics', { params });
    return response.data;
  },
};

export default invoiceService;
