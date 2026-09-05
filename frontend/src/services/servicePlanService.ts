import { api } from './api';

export interface ServicePlan {
  id: string;
  name: string;
  price: number;
  description?: string;
  download_speed: number;
  upload_speed: number;
  burst_download?: number;
  burst_upload?: number;
  burst_threshold?: number;
  burst_time?: number;
  priority: number;
  is_active: boolean;
  customers_count?: number;
  created_at: string;
  updated_at: string;
}

export interface CreateServicePlanData {
  name: string;
  price: number;
  description?: string;
  download_speed: number;
  upload_speed: number;
  burst_download?: number;
  burst_upload?: number;
  burst_threshold?: number;
  burst_time?: number;
  priority: number;
  is_active?: boolean;
}

export interface UpdateServicePlanData extends Partial<CreateServicePlanData> {}

export const servicePlanService = {
  async getAll(): Promise<ServicePlan[]> {
    const response = await api.get<{ success: boolean; data: ServicePlan[] }>('/service-plans');
    return response.data.data;
  },

  async getOptions(): Promise<ServicePlan[]> {
    const response = await api.get<{ success: boolean; data: ServicePlan[] }>('/service-plan-options');
    return response.data.data;
  },

  async getOne(id: string): Promise<ServicePlan> {
    const response = await api.get<{ success: boolean; data: ServicePlan }>(`/service-plans/${id}`);
    return response.data.data;
  },

  async create(data: CreateServicePlanData): Promise<ServicePlan> {
    const response = await api.post<{ success: boolean; data: ServicePlan }>('/service-plans', data);
    return response.data.data;
  },

  async update(id: string, data: UpdateServicePlanData): Promise<ServicePlan> {
    const response = await api.put<{ success: boolean; data: ServicePlan }>(`/service-plans/${id}`, data);
    return response.data.data;
  },

  async delete(id: string): Promise<void> {
    await api.delete(`/service-plans/${id}`);
  },
};
