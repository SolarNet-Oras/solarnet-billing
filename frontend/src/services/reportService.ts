import api from './api';

export const reportService = {
  getOperationsLog: async (params?: { page?: number; per_page?: number; status?: string; job?: string }): Promise<any> => {
    const response = await api.get('/reports/logs', { params });
    return response.data;
  },
  getRevenueReport: async (startDate?: string, endDate?: string): Promise<any> => {
    const response = await api.get('/reports/revenue', {
      params: { start_date: startDate, end_date: endDate },
    });
    return response.data;
  },

  getCustomerGrowth: async (startDate?: string, endDate?: string): Promise<any> => {
    const response = await api.get('/reports/customer-growth', {
      params: { start_date: startDate, end_date: endDate },
    });
    return response.data;
  },

  getPaymentMethods: async (startDate?: string, endDate?: string): Promise<any> => {
    const response = await api.get('/reports/payment-methods', {
      params: { start_date: startDate, end_date: endDate },
    });
    return response.data;
  },

  getServicePlanPopularity: async (): Promise<any> => {
    const response = await api.get('/reports/service-plans');
    return response.data;
  },

  getTicketsOverview: async (startDate?: string, endDate?: string): Promise<any> => {
    const response = await api.get('/reports/tickets', {
      params: { start_date: startDate, end_date: endDate },
    });
    return response.data;
  },
};

export default reportService;
