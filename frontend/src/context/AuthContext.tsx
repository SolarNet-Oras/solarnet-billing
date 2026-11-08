import React, { createContext, useState, useEffect, useCallback } from 'react';
import { getCurrentUser, login as loginService, logout as logoutService, register as registerService } from '@/services/authService';
import type { User, LoginRequest, RegisterRequest } from '@/types/api';
import { tokenStorage } from '@/lib/tokenStorage';
import { logger } from '@/lib/logger';
import saasTenantService, { SAAS_TENANT_TOKEN_KEY } from '@/services/saasTenantService';

const isTenantApp = (): boolean => typeof window !== 'undefined' && window.location.hostname.toLowerCase() === 'app.solarnetportal.com';
const tenantAdminPermissions = ['view-customers','create-customers','edit-customers','view-invoices','create-invoices','edit-invoices','download-invoices','view-payments','create-payments','view-service-plans','create-service-plans','edit-service-plans','view-tickets','create-tickets','edit-tickets','assign-tickets','close-tickets','view-routers','create-routers','edit-routers','delete-routers','manage-routers','view-dhcp','sync-dhcp','manage-dhcp','view-reports','view-settings','edit-settings'];

// ============================================================================
// Types
// ============================================================================

interface AuthContextType {
  user: User | null;
  loading: boolean;
  isAuthenticated: boolean;
  login: (credentials: LoginRequest) => Promise<User>;
  register: (data: RegisterRequest) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

// ============================================================================
// Context
// ============================================================================

export const AuthContext = createContext<AuthContextType | undefined>(undefined);

// ============================================================================
// Provider
// ============================================================================

interface AuthProviderProps {
  children: React.ReactNode;
}

export const AuthProvider: React.FC<AuthProviderProps> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState<boolean>(true);

  /**
   * Fetch current user from API
   */
  const fetchUser = useCallback(async (): Promise<void> => {
    try {
      const token = isTenantApp() ? sessionStorage.getItem(SAAS_TENANT_TOKEN_KEY) : tokenStorage.getToken();
      
      if (!token) {
        setUser(null);
        setLoading(false);
        return;
      }

      const userData = isTenantApp()
        ? await saasTenantService.me().then((result) => ({
            ...result.user,
            role: result.membership.membership_role,
            roles: result.membership.membership_role === 'tenant_admin'
              ? [result.membership.membership_role, 'admin', 'office_admin', 'cashier', 'accounting', 'viewer']
              : [result.membership.membership_role, 'viewer'],
            permissions: result.membership.membership_role === 'tenant_admin' ? tenantAdminPermissions : tenantAdminPermissions.filter((permission) => permission.startsWith('view-') || permission === 'download-invoices' || permission === 'create-tickets'),
            email_verified_at: null, created_at: '', updated_at: '',
          } as User))
        : await getCurrentUser();
      setUser(userData);
    } catch (error) {
      logger.error('Failed to fetch user', error);
      setUser(null);
      if (isTenantApp()) saasTenantService.logout(); else tokenStorage.clearAll();
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Initialize auth state on mount
   */
  useEffect(() => {
    fetchUser();
  }, [fetchUser]);

  /**
   * Login handler
   */
  const login = useCallback(async (credentials: LoginRequest): Promise<User> => {
    setLoading(true);
    try {
      const response = await loginService(credentials);
      setUser(response.user);
      return response.user;
    } catch (error) {
      throw error;
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Register handler
   */
  const register = useCallback(async (data: RegisterRequest): Promise<void> => {
    setLoading(true);
    try {
      const response = await registerService(data);
      setUser(response.user);
    } catch (error) {
      throw error;
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Logout handler
   */
  const logout = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      if (isTenantApp()) saasTenantService.logout(); else await logoutService();
      setUser(null);
      if (isTenantApp()) window.location.href = '/';
    } catch (error) {
      logger.error('Logout error', error);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Refresh user data
   */
  const refreshUser = useCallback(async (): Promise<void> => {
    await fetchUser();
  }, [fetchUser]);

  const value: AuthContextType = {
    user,
    loading,
    isAuthenticated: !!user,
    login,
    register,
    logout,
    refreshUser,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
