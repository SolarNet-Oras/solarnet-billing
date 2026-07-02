import { type ServicePlan } from '@/services/servicePlanService';
import { Edit, Trash2, Users, TrendingUp, TrendingDown, Zap } from 'lucide-react';
import { formatPHP } from '@/lib/currency';

interface ServicePlansTableProps {
  plans: ServicePlan[];
  onEdit: (plan: ServicePlan) => void;
  onDelete: (id: string, name: string) => void;
}

export function ServicePlansTable({ plans, onEdit, onDelete }: ServicePlansTableProps) {
  if (plans.length === 0) {
    return (
      <div className="bg-card border border-border rounded-lg p-12 text-center">
        <Zap className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
        <h3 className="text-lg font-semibold text-foreground mb-2">No service plans configured</h3>
        <p className="text-muted-foreground">
          Create your first service plan to start offering bandwidth packages.
        </p>
      </div>
    );
  }

  return (
    <div className="bg-card border border-border rounded-lg overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead className="bg-muted">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Plan Name
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Speed
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Price
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Priority
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Customers
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Status
              </th>
              <th className="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Actions
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {plans.map((plan) => (
              <tr key={plan.id} className="hover:bg-muted/50 transition-colors">
                <td className="px-6 py-4">
                  <div className="text-sm font-medium text-foreground">{plan.name}</div>
                  {plan.description && (
                    <div className="text-xs text-muted-foreground mt-1">{plan.description}</div>
                  )}
                </td>
                <td className="px-6 py-4">
                  <div className="flex items-center space-x-2">
                    <div className="flex items-center text-sm text-foreground">
                      <TrendingDown className="h-4 w-4 mr-1 text-blue-600" />
                      {plan.download_speed} Mbps
                    </div>
                    <span className="text-muted-foreground">/</span>
                    <div className="flex items-center text-sm text-foreground">
                      <TrendingUp className="h-4 w-4 mr-1 text-green-600" />
                      {plan.upload_speed} Mbps
                    </div>
                  </div>
                  {(plan.burst_download || plan.burst_upload) && (
                    <div className="text-xs text-muted-foreground mt-1">
                      Burst: {plan.burst_download || plan.download_speed}/{plan.burst_upload || plan.upload_speed} Mbps
                    </div>
                  )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="text-sm font-semibold text-foreground">{formatPHP(plan.price)}/mo</div>
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <span className={`px-2 py-1 rounded text-xs font-medium ${
                    plan.priority <= 3 
                      ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                      : plan.priority <= 6
                      ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
                      : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                  }`}>
                    P{plan.priority}
                  </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="flex items-center text-sm text-muted-foreground">
                    <Users className="h-4 w-4 mr-1" />
                    {plan.customers_count || 0}
                  </div>
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <span className={`px-2 py-1 rounded text-xs font-medium ${
                    plan.is_active 
                      ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                      : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200'
                  }`}>
                    {plan.is_active ? 'Active' : 'Inactive'}
                  </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div className="flex items-center justify-end space-x-2">
                    <button
                      onClick={() => onEdit(plan)}
                      data-testid="plan-edit-btn"
                      className="p-2 text-foreground hover:bg-secondary rounded transition-colors"
                      title="Edit Plan"
                      aria-label="Edit Plan"
                    >
                      <Edit className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => onDelete(plan.id, plan.name)}
                      data-testid="plan-delete-btn"
                      className="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition-colors"
                      title="Delete Plan"
                      aria-label="Delete Plan"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
