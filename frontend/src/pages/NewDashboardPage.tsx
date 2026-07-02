import React, { useEffect, useState, useCallback } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { MetricCard } from '@/components/ui/MetricCard';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';
import { logger } from '@/lib/logger';

interface DashboardMetrics {
  active_subscribers: number;
  expired_subscribers: number;
  suspended_subscribers: number;
  total_subscribers: number;
  subscribers_change_pct: number | null;
  online_users: number;
  offline_users: number;
  today_revenue: number;
  monthly_revenue: number;
  revenue_change_pct: number | null;
  pending_payments: number;
  overdue_invoices: number;
  open_tickets: number;
  pending_tickets: number;
  resolved_today: number;
  router_status: {
    online: number;
    offline: number;
    error: number;
    total: number;
  };
  total_users: number;
  active_users: number;
  users_online: number;
}

const fmtPct = (pct: number | null | undefined): string => {
  if (pct === null || pct === undefined) return 'No prior data';
  const sign = pct > 0 ? '+' : '';
  return `${sign}${pct}% vs last month`;
};
const pctTrend = (pct: number | null | undefined): 'up' | 'down' | 'stable' => {
  if (pct === null || pct === undefined || Math.abs(pct) < 0.1) return 'stable';
  return pct > 0 ? 'up' : 'down';
};

const NewDashboardPage: React.FC = () => {
  const { user } = useAuth();
  const [metrics, setMetrics] = useState<DashboardMetrics | null>(null);
  const [loading, setLoading] = useState<boolean>(true);

  const fetchMetrics = useCallback(async (): Promise<void> => {
    try {
      const response = await api.get<{ data: DashboardMetrics }>('/dashboard/metrics');
      setMetrics(response.data.data);
    } catch (error) {
      logger.error('Failed to fetch metrics', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchMetrics();
    // Refresh metrics every 30 seconds
    const interval = setInterval(fetchMetrics, 30000);
    return () => clearInterval(interval);
  }, [fetchMetrics]);

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Welcome Section */}
        <div>
          <h1 className="text-3xl font-bold text-foreground mb-2">
            Welcome back, {user?.name}! 👋
          </h1>
          <p className="text-muted-foreground">
            Here's what's happening with your ISP network today.
          </p>
        </div>

        {/* Quick Stats */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <MetricCard
            title="Total Subscribers"
            value={metrics?.total_subscribers || 0}
            change={fmtPct(metrics?.subscribers_change_pct)}
            trend={pctTrend(metrics?.subscribers_change_pct)}
            icon="👥"
            loading={loading}
          />
          <MetricCard
            title="Active Subscribers"
            value={metrics?.active_subscribers || 0}
            change={`${metrics?.suspended_subscribers ?? 0} suspended`}
            trend={
              (metrics?.active_subscribers ?? 0) > 0 && (metrics?.suspended_subscribers ?? 0) === 0
                ? 'up'
                : (metrics?.suspended_subscribers ?? 0) > 0
                ? 'down'
                : 'stable'
            }
            icon="✅"
            loading={loading}
          />
          <MetricCard
            title="Monthly Revenue"
            value={`₱${(metrics?.monthly_revenue ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
            change={fmtPct(metrics?.revenue_change_pct)}
            trend={pctTrend(metrics?.revenue_change_pct)}
            icon="💰"
            loading={loading}
          />
          <MetricCard
            title="Online Users"
            value={metrics?.online_users ?? 0}
            change={`${metrics?.active_subscribers ?? 0} active subs`}
            trend={(metrics?.online_users ?? 0) > 0 ? 'up' : 'stable'}
            icon="🌐"
            loading={loading}
          />
        </div>

        {/* Network Status */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Subscriber Status */}
          <div className="bg-card border border-border rounded-lg p-6 shadow-sm">
            <h2 className="text-xl font-semibold text-foreground mb-4">
              Subscriber Status
            </h2>
            <div className="space-y-3">
              <div className="flex justify-between items-center">
                <span className="text-muted-foreground">Active</span>
                <span className="font-semibold text-green-600 dark:text-green-400">
                  {metrics?.active_subscribers || 0}
                </span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-muted-foreground">Suspended</span>
                <span className="font-semibold text-yellow-600 dark:text-yellow-400">
                  {metrics?.suspended_subscribers || 0}
                </span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-muted-foreground">Expired</span>
                <span className="font-semibold text-red-600 dark:text-red-400">
                  {metrics?.expired_subscribers || 0}
                </span>
              </div>
              <hr className="border-border" />
              <div className="flex justify-between items-center font-semibold">
                <span className="text-foreground">Total</span>
                <span className="text-foreground">
                  {metrics?.total_subscribers || 0}
                </span>
              </div>
            </div>
          </div>

          {/* Router Status */}
          <div className="bg-card border border-border rounded-lg p-6 shadow-sm">
            <h2 className="text-xl font-semibold text-foreground mb-4">
              Router Status
            </h2>
            <div className="space-y-3">
              <div className="flex justify-between items-center">
                <span className="text-muted-foreground">Online</span>
                <span className="font-semibold text-green-600 dark:text-green-400">
                  {metrics?.router_status.online || 0}
                </span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-muted-foreground">Offline</span>
                <span className="font-semibold text-red-600 dark:text-red-400">
                  {metrics?.router_status.offline || 0}
                </span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-muted-foreground">Error</span>
                <span className="font-semibold text-yellow-600 dark:text-yellow-400">
                  {metrics?.router_status.error || 0}
                </span>
              </div>
              <hr className="border-border" />
              <div className="flex justify-between items-center font-semibold">
                <span className="text-foreground">Total</span>
                <span className="text-foreground">
                  {metrics?.router_status.total || 0}
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* Financial & Support */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <MetricCard
            title="Today's Revenue"
            value={`₱${(metrics?.today_revenue ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
            icon="💵"
            loading={loading}
          />
          <MetricCard
            title="Pending Payments"
            value={metrics?.pending_payments || 0}
            icon="⏳"
            loading={loading}
          />
          <MetricCard
            title="Open Tickets"
            value={metrics?.open_tickets || 0}
            change={`${metrics?.resolved_today || 0} resolved today`}
            trend="down"
            icon="🎫"
            loading={loading}
          />
        </div>
      </div>
    </DashboardLayout>
  );
};

export default NewDashboardPage;
