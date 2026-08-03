import React, { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';

interface SidebarProps {
  isOpen: boolean;
  onClose: () => void;
}

interface NavItem {
  name: string;
  path: string;
  icon: string;
  permission?: string;
}

export const Sidebar: React.FC<SidebarProps> = ({ isOpen, onClose }) => {
  const location = useLocation();
  const { user } = useAuth();
  const { theme, toggleTheme } = useTheme();

  const navItems: NavItem[] = [
    { name: 'Dashboard', path: '/dashboard', icon: '📊' },
    { name: 'Users', path: '/users', icon: '👥', permission: 'view-users' },
    { name: 'Customers', path: '/customers', icon: '👤', permission: 'view-customers' },
    { name: 'Unregistered', path: '/unregistered-clients', icon: '📶', permission: 'view-customers' },
    { name: 'Network Devices', path: '/network-devices', icon: '🌐', permission: 'view-routers' },
    { name: 'Service Plans', path: '/service-plans', icon: '📦', permission: 'view-service-plans' },
    { name: 'Billing', path: '/billing', icon: '💰', permission: 'view-invoices' },
    { name: 'Tickets', path: '/tickets', icon: '🎫', permission: 'view-tickets' },
    { name: 'Reports', path: '/reports', icon: '📈', permission: 'view-reports' },
    { name: 'Settings', path: '/settings', icon: '⚙️', permission: 'view-settings' },
  ];

  const hasPermission = (permission?: string): boolean => {
    if (!permission) return true;
    return user?.permissions?.includes(permission) || false;
  };

  const isActive = (path: string): boolean => {
    return location.pathname === path;
  };

  return (
    <>
      {/* Mobile overlay */}
      {isOpen && (
        <div
          className="fixed inset-0 bg-black/50 z-40 md:hidden"
          onClick={onClose}
        />
      )}

      {/* Sidebar */}
      <aside
        className={`
          fixed top-0 left-0 z-50 h-full w-64 bg-card border-r border-border
          transform transition-transform duration-300 ease-in-out
          ${isOpen ? 'translate-x-0' : '-translate-x-full'}
          md:translate-x-0 md:static
        `}
      >
        {/* Logo */}
        <div className="h-16 flex items-center justify-between px-4 border-b border-border">
          <Link to="/dashboard" className="flex items-center gap-3 min-w-0">
            <img src="/solarnet-mark.svg" alt="" className="h-9 w-9 shrink-0" />
            <div className="min-w-0">
              <p className="text-sm font-semibold tracking-tight text-foreground">SOLARNET</p>
              <p className="text-[10px] font-medium uppercase tracking-[0.18em] text-muted-foreground">Billing</p>
            </div>
          </Link>
          <button
            onClick={onClose}
            className="md:hidden p-2 hover:bg-secondary rounded-md"
          >
            ✕
          </button>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto p-4 space-y-1">
          {navItems.map((item) => {
            if (!hasPermission(item.permission)) return null;

            return (
              <Link
                key={item.path}
                to={item.path}
                onClick={onClose}
                className={`
                  flex items-center gap-3 px-4 py-3 rounded-md
                  transition-colors duration-200
                  ${
                    isActive(item.path)
                      ? 'bg-primary text-primary-foreground'
                      : 'text-foreground hover:bg-secondary'
                  }
                `}
              >
                <span className="text-xl">{item.icon}</span>
                <span className="font-medium">{item.name}</span>
              </Link>
            );
          })}
        </nav>

        {/* Footer */}
        <div className="p-4 border-t border-border space-y-2">
          {/* Theme Toggle */}
          <button
            onClick={toggleTheme}
            className="w-full flex items-center gap-3 px-4 py-3 rounded-md hover:bg-secondary text-foreground transition-colors"
          >
            <span className="text-xl">{theme === 'dark' ? '🌙' : '☀️'}</span>
            <span className="font-medium">
              {theme === 'dark' ? 'Dark Mode' : 'Light Mode'}
            </span>
          </button>

          {/* User Info */}
          <div className="px-4 py-2 text-sm text-muted-foreground">
            <p className="font-medium text-foreground truncate">{user?.name}</p>
            <p className="truncate">{user?.email}</p>
          </div>
        </div>
      </aside>
    </>
  );
};
