/**
 * API Type Definitions
 * 
 * Centralized type definitions for API requests and responses
 */

import type { AxiosError, AxiosResponse } from 'axios';

// ============================================================================
// Base Types
// ============================================================================

export interface ApiResponse<T = unknown> {
  data: T;
  message?: string;
  status: string;
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  status: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    from: number;
    last_page: number;
    per_page: number;
    to: number;
    total: number;
  };
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
}

// ============================================================================
// Authentication Types
// ============================================================================

export interface LoginRequest {
  email: string;
  portal_password_change_required?: boolean;
  password: string;
  remember?: boolean;
}

export interface LoginResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  user: User;
}

export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface RegisterResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  user: User;
}

export interface RefreshTokenResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
}

export interface PasswordResetRequest {
  email: string;
}

export interface PasswordResetResponse {
  message: string;
}

// ============================================================================
// User Types
// ============================================================================

export interface User {
  id: string;
  name: string;
  email: string;
  email_verified_at: string | null;
  role: string;
  permissions: string[];
  created_at: string;
  updated_at: string;
}

export interface UpdateUserRequest {
  name?: string;
  email?: string;
  password?: string;
  password_confirmation?: string;
}

// ============================================================================
// Customer Types (ISP Subscribers)
// ============================================================================

export interface Customer {
  id: string;
  account_number: string;
  full_name: string;
  address: string;
  gps_coordinates: {
    latitude: number;
    longitude: number;
  } | null;
  location_status?: 'not_captured' | 'pending' | 'captured' | 'confirmed';
  location_source?: 'customer_device' | 'installer_device' | null;
  location_accuracy_meters?: number | null;
  location_captured_at?: string | null;
  location_confirmed_at?: string | null;
  portal_password_change_required?: boolean;
  contact_number: string;
  email: string;
  installation_date: string;
  status: 'active' | 'suspended' | 'expired' | 'pending';
  router_id: string | null;
  service_plan_id: string | null;
  service_plan?: {
    id: string;
    name: string;
    download_speed: number;
    upload_speed: number;
    price: number;
  };
  monthly_fee: number;
  mac_address: string | null;
  ip_address: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface CreateCustomerRequest {
  account_number: string;
  full_name: string;
  address: string;
  gps_coordinates?: {
    latitude: number;
    longitude: number;
  };
  contact_number: string;
  email: string;
  installation_date: string;
  router_id?: string;
  service_plan_id: string;
  monthly_fee: number;
  mac_address?: string;
  ip_address?: string;
  notes?: string;
}

// ============================================================================
// Router Types (MikroTik)
// ============================================================================

export interface Router {
  id: string;
  name: string;
  ip_address: string;
  username: string;
  port: number;
  status: 'online' | 'offline' | 'error';
  router_os_version: string | null;
  cpu_usage: number | null;
  memory_usage: number | null;
  uptime: string | null;
  last_check: string | null;
  created_at: string;
  updated_at: string;
}

export interface CreateRouterRequest {
  name: string;
  ip_address: string;
  username: string;
  password: string;
  port?: number;
}

export interface TestRouterConnectionResponse {
  success: boolean;
  message: string;
  router_os_version?: string;
  uptime?: string;
}

// ============================================================================
// Service Plan Types
// ============================================================================

export interface ServicePlan {
  id: string;
  name: string;
  monthly_price: number;
  download_speed: number;
  upload_speed: number;
  burst_download: number | null;
  burst_upload: number | null;
  burst_time: number | null;
  data_limit: number | null;
  priority: number;
  status: 'active' | 'inactive';
  created_at: string;
  updated_at: string;
}

export interface CreateServicePlanRequest {
  name: string;
  monthly_price: number;
  download_speed: number;
  upload_speed: number;
  burst_download?: number;
  burst_upload?: number;
  burst_time?: number;
  data_limit?: number;
  priority?: number;
}

// ============================================================================
// DHCP Lease Types
// ============================================================================

export interface DhcpLease {
  id: string;
  ip_address: string;
  mac_address: string;
  hostname: string | null;
  router_id: string;
  customer_id: string | null;
  status: 'bound' | 'expired' | 'released';
  lease_time: string;
  vlan: string | null;
  created_at: string;
  updated_at: string;
}

// ============================================================================
// Ticket Types
// ============================================================================

export interface TicketComment {
  id: string;
  ticket_id: string;
  user_id: string | null;
  customer_id: string | null;
  user?: User;
  customer?: Customer;
  comment: string;
  is_internal: boolean;
  created_at: string;
  updated_at: string;
}

export interface Ticket {
  id: string;
  ticket_number: string;
  customer_id: string;
  assigned_to: string | null;
  customer?: Customer;
  assignedTo?: User;
  subject: string;
  description: string;
  status: 'open' | 'in_progress' | 'resolved' | 'closed';
  priority: 'low' | 'medium' | 'high' | 'urgent';
  category: 'technical' | 'billing' | 'general' | 'network_issue';
  resolved_at: string | null;
  closed_at: string | null;
  comments?: TicketComment[];
  created_at: string;
  updated_at: string;
}

export interface CreateTicketRequest {
  customer_id: string;
  subject: string;
  description: string;
  priority?: 'low' | 'medium' | 'high' | 'urgent';
  category?: 'technical' | 'billing' | 'general' | 'network_issue';
}

export interface TicketStatistics {
  total: number;
  open: number;
  in_progress: number;
  resolved: number;
  closed: number;
  urgent: number;
  unassigned: number;
}

// ============================================================================
// Invoice & Payment Types
// ============================================================================

export interface InvoiceItem {
  id: string;
  invoice_id: string;
  description: string;
  quantity: number;
  unit_price: number;
  total: number;
  created_at: string;
  updated_at: string;
}

export interface Invoice {
  id: string;
  invoice_number: string;
  customer_id: string;
  customer?: Customer;
  issue_date: string;
  due_date: string;
  billing_period_start: string;
  billing_period_end: string;
  subtotal: number;
  tax: number;
  discount: number;
  total: number;
  paid_amount: number;
  balance: number;
  status: 'draft' | 'sent' | 'partial' | 'paid' | 'overdue' | 'cancelled';
  notes: string | null;
  sent_at: string | null;
  paid_at: string | null;
  items?: InvoiceItem[];
  payments?: Payment[];
  created_at: string;
  updated_at: string;
}

export interface CreateInvoiceRequest {
  customer_id: string;
  billing_period_start: string;
  billing_period_end: string;
  due_days?: number;
  discount?: number;
  notes?: string;
  additional_items?: {
    description: string;
    quantity?: number;
    unit_price: number;
  }[];
}

export interface Payment {
  id: string;
  payment_number: string;
  invoice_id: string;
  customer_id: string;
  invoice?: Invoice;
  customer?: Customer;
  amount: number;
  payment_method: 'cash' | 'bank_transfer' | 'credit_card' | 'debit_card' | 'mobile_money' | 'other';
  payment_date: string;
  transaction_id: string | null;
  reference: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface RecordPaymentRequest {
  amount: number;
  payment_method: 'cash' | 'bank_transfer' | 'credit_card' | 'debit_card' | 'mobile_money' | 'other';
  payment_date?: string;
  transaction_id?: string;
  reference?: string;
  notes?: string;
}

export interface InvoiceStatistics {
  total_invoices: number;
  total_amount: number;
  paid_amount: number;
  unpaid_amount: number;
  overdue_count: number;
  overdue_amount: number;
  status_breakdown: {
    status: string;
    count: number;
    total: number;
  }[];
}

export interface PaymentStatistics {
  total_payments: number;
  total_amount: number;
  method_breakdown: {
    payment_method: string;
    count: number;
    total: number;
  }[];
}

// ============================================================================
// Dashboard Metrics Types
// ============================================================================

export interface DashboardMetrics {
  active_subscribers: number;
  expired_subscribers: number;
  suspended_subscribers: number;
  online_users: number;
  offline_users: number;
  today_revenue: number;
  monthly_revenue: number;
  pending_tickets: number;
  router_status: {
    online: number;
    offline: number;
    error: number;
  };
}

// ============================================================================
// Axios Type Extensions
// ============================================================================

export type ApiAxiosResponse<T = unknown> = AxiosResponse<ApiResponse<T>>;
export type ApiAxiosError = AxiosError<ApiError>;
