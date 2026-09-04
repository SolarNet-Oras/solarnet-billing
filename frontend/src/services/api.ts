/**
 * API Service with Security Best Practices
 * 
 * Features:
 * - Full TypeScript type coverage
 * - Secure token storage (sessionStorage instead of localStorage)
 * - HTTP status constants instead of magic numbers
 * - Request/response interceptors
 * - Automatic token refresh (prepared)
 * - Error handling
 */

import axios, { type AxiosRequestConfig, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios';
import type { AxiosInstance } from 'axios';
import { tokenStorage } from '@/lib/tokenStorage';
import { HTTP_STATUS } from '@/lib/constants';
import type { ApiResponse, ApiError } from '@/types/api';

type ActionRequestConfig = InternalAxiosRequestConfig & {
  _solarnetActionButton?: HTMLButtonElement;
};

let lastActionButton: { button: HTMLButtonElement; at: number } | null = null;

if (typeof document !== 'undefined' && !document.documentElement.dataset.apiButtonProgressInstalled) {
  document.documentElement.dataset.apiButtonProgressInstalled = 'true';
  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('button') as HTMLButtonElement | null : null;
    if (!button || button.disabled) return;
    if (button.dataset.apiLoading === 'true') {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    lastActionButton = { button, at: Date.now() };
  }, true);
  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    const submitter = event.submitter instanceof HTMLButtonElement
      ? event.submitter
      : form?.querySelector<HTMLButtonElement>('button[type="submit"]');
    if (submitter && !submitter.disabled) lastActionButton = { button: submitter, at: Date.now() };
  }, true);
}

const startButtonProgress = (config: ActionRequestConfig): void => {
  const candidate = lastActionButton;
  if (!candidate || Date.now() - candidate.at > 1500 || !candidate.button.isConnected || candidate.button.dataset.manualLoading === 'true') return;
  config._solarnetActionButton = candidate.button;
  const active = Number(candidate.button.dataset.apiLoadingCount || 0) + 1;
  candidate.button.dataset.apiLoadingCount = String(active);
  candidate.button.dataset.apiLoading = 'true';
  candidate.button.setAttribute('aria-busy', 'true');
};

const finishButtonProgress = (config?: ActionRequestConfig): void => {
  const button = config?._solarnetActionButton;
  if (!button) return;
  const active = Math.max(0, Number(button.dataset.apiLoadingCount || 1) - 1);
  if (active > 0) {
    button.dataset.apiLoadingCount = String(active);
    return;
  }
  delete button.dataset.apiLoadingCount;
  delete button.dataset.apiLoading;
  button.removeAttribute('aria-busy');
};

// ============================================================================
// Configuration
// ============================================================================

// Production serves the same SPA from both the customer and staff domains.
// Keeping API requests same-origin prevents cross-domain token and cookie
// problems while still allowing local Vite development on port 8001.
const API_BASE_URL: string = import.meta.env.VITE_API_URL
  || (typeof window !== 'undefined' ? window.location.origin : 'http://localhost:8001');
const API_VERSION: string = 'v1';
const API_TIMEOUT: number = 30000; // 30 seconds

// ============================================================================
// API Instance Creation
// ============================================================================

export const api: AxiosInstance = axios.create({
  baseURL: `${API_BASE_URL}/api/${API_VERSION}`,
  timeout: API_TIMEOUT,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// ============================================================================
// Request Interceptor
// ============================================================================

/**
 * Adds authentication token to requests
 * 
 * SECURITY NOTE:
 * - Token retrieved from sessionStorage (better than localStorage)
 * - For production: Consider using httpOnly cookies set by backend
 * - Token automatically removed on tab close
 */
api.interceptors.request.use(
  (config: InternalAxiosRequestConfig): InternalAxiosRequestConfig => {
    startButtonProgress(config as ActionRequestConfig);
    // Customer portal requests use their own token (stored at portal login)
    // Match only the actual customer portal API namespace. Admin endpoints
    // such as /customer-portal-accounts must continue using the staff token.
    const isCustomerPortal = (config.url ?? '').startsWith('/customer-portal/');
    const token = isCustomerPortal
      ? localStorage.getItem('customer_token')
      : tokenStorage.getToken();
    
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    
    return config;
  },
  (error: unknown): Promise<never> => {
    return Promise.reject(error);
  }
);

// ============================================================================
// Response Interceptor
// ============================================================================

/**
 * Handles responses and errors globally
 * 
 * Features:
 * - Automatic logout on 401 (unauthorized)
 * - Token refresh preparation
 * - Centralized error handling
 */
api.interceptors.response.use(
  (response: AxiosResponse): AxiosResponse => {
    finishButtonProgress(response.config as ActionRequestConfig);
    return response;
  },
  async (error: unknown): Promise<never> => {
    if (!axios.isAxiosError(error)) {
      return Promise.reject(error);
    }

    finishButtonProgress(error.config as ActionRequestConfig | undefined);

    const originalRequest = error.config as AxiosRequestConfig & { _retry?: boolean };

    // Handle 401 Unauthorized - User session expired
    if (error.response?.status === HTTP_STATUS.UNAUTHORIZED) {
      const isCustomerPortal = (originalRequest?.url ?? '').startsWith('/customer-portal/');

      if (isCustomerPortal) {
        // Customer portal session expired - send back to the portal login
        localStorage.removeItem('customer_token');
        localStorage.removeItem('customer_data');
        if (window.location.pathname !== '/customer/login') {
          const requestedPath = window.location.pathname + window.location.search;
          const next = requestedPath.startsWith('/customer/') ? `?next=${encodeURIComponent(requestedPath)}` : '';
          window.location.href = `/customer/login${next}`;
        }
        return Promise.reject(error);
      }

      // Clear authentication data
      tokenStorage.clearAll();
      
      // Redirect to login page
      // Only redirect if not already on login page
      if (window.location.pathname !== '/login') {
        window.location.href = '/login';
      }
      
      return Promise.reject(error);
    }

    // Handle 403 Forbidden - User doesn't have permission
    if (error.response?.status === HTTP_STATUS.FORBIDDEN) {
      console.error('Access forbidden: Insufficient permissions');
    }

    // Handle 404 Not Found
    if (error.response?.status === HTTP_STATUS.NOT_FOUND) {
      console.error('Resource not found');
    }

    // Handle 422 Unprocessable Entity - Validation errors
    if (error.response?.status === HTTP_STATUS.UNPROCESSABLE_ENTITY) {
      console.error('Validation error:', error.response.data);
    }

    // Handle 429 Too Many Requests - Rate limiting
    if (error.response?.status === HTTP_STATUS.TOO_MANY_REQUESTS) {
      console.error('Rate limit exceeded. Please try again later.');
    }

    // Handle 500+ Server Errors
    if (error.response?.status && error.response.status >= HTTP_STATUS.INTERNAL_SERVER_ERROR) {
      console.error('Server error. Please try again later.');
    }

    return Promise.reject(error);
  }
);

// ============================================================================
// Typed API Methods
// ============================================================================

/**
 * GET request with type safety
 */
export const apiGet = async <T = unknown>(
  url: string,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> => {
  const response = await api.get<ApiResponse<T>>(url, config);
  return response.data;
};

/**
 * POST request with type safety
 */
export const apiPost = async <T = unknown, D = unknown>(
  url: string,
  data?: D,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> => {
  const response = await api.post<ApiResponse<T>>(url, data, config);
  return response.data;
};

/**
 * PUT request with type safety
 */
export const apiPut = async <T = unknown, D = unknown>(
  url: string,
  data?: D,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> => {
  const response = await api.put<ApiResponse<T>>(url, data, config);
  return response.data;
};

/**
 * PATCH request with type safety
 */
export const apiPatch = async <T = unknown, D = unknown>(
  url: string,
  data?: D,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> => {
  const response = await api.patch<ApiResponse<T>>(url, data, config);
  return response.data;
};

/**
 * DELETE request with type safety
 */
export const apiDelete = async <T = unknown>(
  url: string,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> => {
  const response = await api.delete<ApiResponse<T>>(url, config);
  return response.data;
};

// ============================================================================
// Authentication Helper Functions
// ============================================================================

/**
 * Set authentication token
 * 
 * SECURITY: Uses sessionStorage instead of localStorage
 * Token is automatically cleared when tab/browser is closed
 */
export const setAuthToken = (token: string): void => {
  tokenStorage.setToken(token);
};

/**
 * Get current authentication token
 */
export const getAuthToken = (): string | null => {
  return tokenStorage.getToken();
};

/**
 * Remove authentication token and clear session
 */
export const clearAuth = (): void => {
  tokenStorage.clearAll();
};

/**
 * Check if user is authenticated
 */
export const isAuthenticated = (): boolean => {
  return !!tokenStorage.getToken();
};

// ============================================================================
// Error Handler Utility
// ============================================================================

/**
 * Extract error message from API error response
 */
export const getErrorMessage = (error: unknown): string => {
  if (axios.isAxiosError(error)) {
    const apiError = error.response?.data as ApiError;
    return apiError?.message || error.message || 'An unexpected error occurred';
  }
  
  if (error instanceof Error) {
    return error.message;
  }
  
  return 'An unexpected error occurred';
};

/**
 * Extract validation errors from 422 response
 */
export const getValidationErrors = (error: unknown): Record<string, string[]> | null => {
  if (axios.isAxiosError(error) && error.response?.status === HTTP_STATUS.UNPROCESSABLE_ENTITY) {
    const apiError = error.response.data as ApiError;
    return apiError?.errors || null;
  }
  return null;
};

// ============================================================================
// Export
// ============================================================================

export default api;
