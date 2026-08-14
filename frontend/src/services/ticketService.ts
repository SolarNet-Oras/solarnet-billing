import api from './api';
import type { Ticket, CreateTicketRequest, TicketStatistics, PaginatedResponse } from '../types/api';

export const ticketService = {
  getTickets: async (params?: {
    status?: string;
    priority?: string;
    category?: string;
    ticket_type?: string;
    assigned_to?: string;
    unassigned?: boolean;
    page?: number;
    per_page?: number;
  }): Promise<PaginatedResponse<Ticket>> => {
    const response = await api.get('/tickets', { params });
    return response.data;
  },

  getTicket: async (id: string): Promise<Ticket> => {
    const response = await api.get(`/tickets/${id}`);
    return response.data;
  },

  createTicket: async (data: CreateTicketRequest): Promise<{ message: string; ticket: Ticket }> => {
    const response = await api.post('/tickets', data);
    return response.data;
  },

  assignTicket: async (ticketId: string, userId: string): Promise<{ message: string; ticket: Ticket }> => {
    const response = await api.post(`/tickets/${ticketId}/assign`, { user_id: userId });
    return response.data;
  },

  addComment: async (ticketId: string, comment: string, isInternal: boolean = false): Promise<any> => {
    const response = await api.post(`/tickets/${ticketId}/comments`, {
      comment,
      is_internal: isInternal,
    });
    return response.data;
  },

  updateStatus: async (ticketId: string, status: string): Promise<{ message: string; ticket: Ticket }> => {
    const response = await api.patch(`/tickets/${ticketId}/status`, { status });
    return response.data;
  },

  getStatistics: async (): Promise<TicketStatistics> => {
    const response = await api.get('/tickets-statistics');
    return response.data;
  },
};

export default ticketService;
