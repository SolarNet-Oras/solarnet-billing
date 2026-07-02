import React from 'react';
import { TrendingUp, TrendingDown, Minus, type LucideIcon } from 'lucide-react';

interface MetricCardProps {
  title: string;
  value: string | number;
  change?: string;
  trend?: 'up' | 'down' | 'stable';
  /** Lucide icon component. Legacy string emoji still accepted for backward compat. */
  icon?: LucideIcon | string;
  /** Tailwind color class for the icon tile (e.g. "bg-primary/10 text-primary") */
  accentClass?: string;
  loading?: boolean;
}

export const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  change,
  trend = 'stable',
  icon: Icon,
  accentClass = 'bg-primary/10 text-primary',
  loading = false,
}) => {
  const trendColor =
    trend === 'up'
      ? 'text-emerald-600 dark:text-emerald-400'
      : trend === 'down'
      ? 'text-rose-600 dark:text-rose-400'
      : 'text-muted-foreground';

  const TrendIcon = trend === 'up' ? TrendingUp : trend === 'down' ? TrendingDown : Minus;

  if (loading) {
    return (
      <div className="bg-card border border-border rounded-xl p-6 shadow-sm animate-pulse">
        <div className="h-4 bg-secondary rounded w-1/2 mb-4"></div>
        <div className="h-8 bg-secondary rounded w-3/4 mb-2"></div>
        <div className="h-3 bg-secondary rounded w-1/4"></div>
      </div>
    );
  }

  return (
    <div className="group bg-card border border-border rounded-xl p-6 shadow-sm hover:shadow-lg hover:border-primary/30 transition-all">
      <div className="flex items-start justify-between gap-4">
        <div className="flex-1 min-w-0">
          <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">{title}</p>
          <h3 className="text-3xl font-bold text-foreground mb-2 tabular-nums truncate">{value}</h3>
          {change && (
            <p className={`text-sm font-medium flex items-center gap-1 ${trendColor}`}>
              <TrendIcon className="h-3.5 w-3.5" />
              <span className="truncate">{change}</span>
            </p>
          )}
        </div>
        {Icon && (
          <div className={`h-12 w-12 rounded-xl flex items-center justify-center shrink-0 ${accentClass} group-hover:scale-105 transition-transform`}>
            {typeof Icon === 'string' ? (
              <span className="text-2xl">{Icon}</span>
            ) : (
              <Icon className="h-6 w-6" />
            )}
          </div>
        )}
      </div>
    </div>
  );
};
