import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import {
  ClipboardList,
  LayoutDashboard,
  Moon,
  Network,
  Package,
  PhilippinePeso,
  WalletCards,
  Banknote,
  Settings,
  Sun,
  Ticket,
  UserRound,
  Users,
  Upload,
  Wifi,
  X,
  type LucideIcon,
} from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';
import customerPortalService from '@/services/customerPortalService';

interface SidebarProps {
  isOpen: boolean;
  onClose: () => void;
}

interface NavItem {
  name: string;
  path: string;
  icon: LucideIcon;
  permission?: string;
  roles?: string[];
}

const navItems: NavItem[] = [
  { name: 'Dashboard', path: '/dashboard', icon: LayoutDashboard },
  { name: 'Customers', path: '/customers', icon: UserRound, permission: 'view-customers' },
  { name: 'Billing', path: '/billing', icon: PhilippinePeso, permission: 'view-invoices' },
  { name: 'Remittances', path: '/remittances', icon: Banknote, roles: ['collector', 'super_admin', 'admin', 'office_admin'] },
  { name: 'Daily Operations', path: '/operations', icon: WalletCards, permission: 'view-payments' },
  { name: 'Service Plans', path: '/service-plans', icon: Package, permission: 'view-service-plans' },
  { name: 'Unregistered', path: '/unregistered-clients', icon: Wifi, permission: 'view-customers' },
  { name: 'Tickets', path: '/tickets', icon: Ticket, permission: 'view-tickets' },
  { name: 'Network Devices', path: '/network-devices', icon: Network, permission: 'view-routers' },
  { name: 'Logs & Reports', path: '/reports', icon: ClipboardList, permission: 'view-reports' },
  { name: 'Users', path: '/users', icon: Users, permission: 'view-users' },
  { name: 'Client Migration', path: '/super-admin/client-migrations', icon: Upload, roles: ['super_admin'] },
  { name: 'Settings', path: '/settings', icon: Settings, permission: 'view-settings' },
];

export const Sidebar: React.FC<SidebarProps> = ({ isOpen, onClose }) => {
  const location = useLocation();
  const { user } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const [branding, setBranding] = React.useState({ name: 'Solarnet Internet', logo_url: '' });

  React.useEffect(() => {
    void customerPortalService.getBranding().then(setBranding).catch(() => undefined);
  }, []);

  const hasRole = (role: string): boolean => user?.role === role || user?.roles?.some((item) => typeof item === 'string' ? item === role : item.name === role) || false;
  const hasPermission = (permission?: string, roles?: string[]): boolean =>
    (!permission || user?.permissions?.includes(permission) || false) && (!roles || roles.some(hasRole));

  return (
    <>
      {isOpen && <div className="fixed inset-0 z-40 bg-black/50 backdrop-blur-[1px] lg:hidden" onClick={onClose} />}

      <aside
        className={`fixed top-0 left-0 z-50 flex h-full w-[min(18rem,calc(100vw-1rem))] flex-col border-r border-border bg-card shadow-2xl transition-transform duration-300 ease-in-out lg:w-64 lg:shadow-none ${
          isOpen ? 'translate-x-0' : '-translate-x-full'
        } lg:fixed lg:translate-x-0`}
      >
        <div className="flex h-16 items-center justify-between border-b border-border px-4">
          <Link to="/dashboard" className="flex min-w-0 items-center gap-3">
            <img src={branding.logo_url || '/solarnet-mark.svg'} alt={branding.name} className="h-9 w-9 shrink-0 object-contain" />
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold tracking-tight text-foreground">{branding.name}</p>
              <p className="text-[10px] font-medium uppercase tracking-[0.18em] text-muted-foreground">Billing</p>
            </div>
          </Link>
          <button onClick={onClose} className="rounded-md p-2 hover:bg-secondary lg:hidden" aria-label="Close navigation">
            <X className="h-4 w-4" />
          </button>
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto overscroll-contain p-3 sm:p-4">
          {navItems.filter((item) => !hasRole('technician') || item.path === '/dashboard').map((item) => {
            if (!hasPermission(item.permission, item.roles)) return null;
            const Icon = item.icon;
            const active = location.pathname === item.path || location.pathname.startsWith(`${item.path}/`);

            return (
              <Link
                key={item.path}
                to={item.path}
                onClick={onClose}
                className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${
                  active
                    ? 'bg-sky-600 text-white shadow-sm hover:bg-sky-700 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400'
                    : 'text-slate-800 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-100 dark:hover:bg-slate-800 dark:hover:text-white'
                }`}
              >
                <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${
                  active ? 'bg-white/20 dark:bg-slate-950/15' : 'bg-slate-100 dark:bg-slate-800'
                }`}>
                  <Icon className="h-4 w-4" strokeWidth={1.8} />
                </span>
                <span>{item.name}</span>
              </Link>
            );
          })}
        </nav>

        <div className="space-y-2 border-t border-border p-4">
          <button
            onClick={toggleTheme}
            className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-secondary"
          >
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-secondary/70">
              {theme === 'dark' ? <Moon className="h-4 w-4" strokeWidth={1.8} /> : <Sun className="h-4 w-4" strokeWidth={1.8} />}
            </span>
            <span>{theme === 'dark' ? 'Dark Mode' : 'Light Mode'}</span>
          </button>

          <div className="px-3 py-2 text-sm text-muted-foreground">
            <p className="truncate font-medium text-foreground">{user?.name}</p>
            <p className="truncate">{user?.email}</p>
          </div>
        </div>
      </aside>
    </>
  );
};
