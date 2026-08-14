import React, { createContext, useState, useEffect, useCallback } from 'react';
import { getCurrentUser, login as loginService, logout as logoutService, register as registerService } from '@/services/authService';
import type { User, LoginRequest, RegisterRequest } from '@/types/api';
import { tokenStorage } from '@/lib/tokenStorage';
import { logger } from '@/lib/logger';

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
      const token = tokenStorage.getToken();
      
      if (!token) {
        setUser(null);
        setLoading(false);
        return;
      }

      const userData = await getCurrentUser();
      setUser(userData);
    } catch (error) {
      logger.error('Failed to fetch user', error);
      setUser(null);
      tokenStorage.clearAll();
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
      await logoutService();
      setUser(null);
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
