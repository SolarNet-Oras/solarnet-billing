import { useState, useEffect } from 'react';
import { type ServicePlan, type CreateServicePlanData } from '@/services/servicePlanService';
import { X, Info } from 'lucide-react';

interface ServicePlanFormModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSave: (data: CreateServicePlanData) => Promise<void>;
  plan?: ServicePlan | null;
}

export function ServicePlanFormModal({ isOpen, onClose, onSave, plan }: ServicePlanFormModalProps) {
  const [formData, setFormData] = useState<CreateServicePlanData>({
    name: '',
    price: 0,
    description: '',
    download_speed: 10,
    upload_speed: 10,
    burst_download: undefined,
    burst_upload: undefined,
    burst_threshold: undefined,
    burst_time: undefined,
    priority: 8,
    is_active: true,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (plan) {
      setFormData({
        name: plan.name,
        price: plan.price,
        description: plan.description || '',
        download_speed: plan.download_speed,
        upload_speed: plan.upload_speed,
        burst_download: plan.burst_download,
        burst_upload: plan.burst_upload,
        burst_threshold: plan.burst_threshold,
        burst_time: plan.burst_time,
        priority: plan.priority,
        is_active: plan.is_active,
      });
    } else {
      setFormData({
        name: '',
        price: 0,
        description: '',
        download_speed: 10,
        upload_speed: 10,
        burst_download: undefined,
        burst_upload: undefined,
        burst_threshold: undefined,
        burst_time: undefined,
        priority: 8,
        is_active: true,
      });
    }
    setError(null);
  }, [plan, isOpen]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSaving(true);

    try {
      await onSave(formData);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to save service plan');
    } finally {
      setSaving(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-card rounded-lg shadow-lg max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between p-6 border-b border-border">
          <h2 className="text-xl font-bold text-foreground">
            {plan ? 'Edit Service Plan' : 'Add New Service Plan'}
          </h2>
          <button
            onClick={onClose}
            className="p-2 hover:bg-secondary rounded transition-colors"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-6">
          {error && (
            <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
              <p className="text-sm text-red-800 dark:text-red-200">{error}</p>
            </div>
          )}

          {/* Basic Info */}
          <div className="space-y-4">
            <h3 className="text-lg font-semibold text-foreground">Basic Information</h3>
            
            <div className="grid grid-cols-2 gap-4">
              <div className="col-span-2">
                <label className="block text-sm font-medium text-foreground mb-1">
                  Plan Name <span className="text-red-600">*</span>
                </label>
                <input
                  type="text"
                  required
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="e.g., Gold 100Mbps"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-1">
                  Monthly Price <span className="text-red-600">*</span>
                </label>
                <div className="relative">
                  <span className="absolute left-3 top-2 text-muted-foreground">₱</span>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    value={formData.price}
                    onChange={(e) => setFormData({ ...formData, price: parseFloat(e.target.value) })}
                    className="w-full pl-8 pr-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="29.99"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-1">
                  Priority (1-8) <span className="text-red-600">*</span>
                </label>
                <select
                  required
                  value={formData.priority}
                  onChange={(e) => setFormData({ ...formData, priority: parseInt(e.target.value) })}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                >
                  {[1, 2, 3, 4, 5, 6, 7, 8].map((p) => (
                    <option key={p} value={p}>
                      Priority {p} {p <= 3 ? '(High)' : p <= 6 ? '(Medium)' : '(Low)'}
                    </option>
                  ))}
                </select>
                <p className="text-xs text-muted-foreground mt-1">Lower number = higher priority</p>
              </div>

              <div className="col-span-2">
                <label className="block text-sm font-medium text-foreground mb-1">
                  Description
                </label>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  rows={2}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="Optional description for this plan..."
                />
              </div>
            </div>
          </div>

          {/* Bandwidth Limits */}
          <div className="space-y-4 border-t border-border pt-4">
            <div className="flex items-center space-x-2">
              <h3 className="text-lg font-semibold text-foreground">Bandwidth Limits</h3>
              <Info className="h-4 w-4 text-muted-foreground" title="Regular speed limits" />
            </div>
            
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-foreground mb-1">
                  Download Speed (Mbps) <span className="text-red-600">*</span>
                </label>
                <input
                  type="number"
                  min="1"
                  required
                  value={formData.download_speed}
                  onChange={(e) => setFormData({ ...formData, download_speed: parseInt(e.target.value) })}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="100"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-1">
                  Upload Speed (Mbps) <span className="text-red-600">*</span>
                </label>
                <input
                  type="number"
                  min="1"
                  required
                  value={formData.upload_speed}
                  onChange={(e) => setFormData({ ...formData, upload_speed: parseInt(e.target.value) })}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="50"
                />
              </div>
            </div>
          </div>

          {/* Burst Configuration */}
          <div className="space-y-4 border-t border-border pt-4">
            <div className="flex items-center space-x-2">
              <h3 className="text-lg font-semibold text-foreground">Burst Configuration (Optional)</h3>
              <Info className="h-4 w-4 text-muted-foreground" title="Temporary speed boost for short downloads" />
            </div>
            
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-foreground mb-1">
                  Burst Download (Mbps)
                </label>
                <input
                  type="number"
                  min="0"
                  value={formData.burst_download || ''}
                  onChange={(e) => setFormData({ ...formData, burst_download: e.target.value ? parseInt(e.target.value) : undefined })}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder={`Default: ${formData.download_speed * 2}`}
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-1">
                  Burst Upload (Mbps)
                </label>
                <input
                  type="number"
                  min="0"
                  value={formData.burst_upload || ''}
                  onChange={(e) => setFormData({ ...formData, burst_upload: e.target.value ? parseInt(e.target.value) : undefined })}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder={`Default: ${formData.upload_speed * 2}`}
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-1">
                  Burst Threshold (Mbps)
                </label>
                <input
                  type="number"
                  min="0"
                  value={formData.burst_threshold || ''}
                  onChange={(e) => setFormData({ ...formData, burst_threshold: e.target.value ? parseInt(e.target.value) : undefined })}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="Average rate threshold"
                />
                <p className="text-xs text-muted-foreground mt-1">When avg rate exceeds this, burst ends</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-foreground mb-1">
                  Burst Time (seconds)
                </label>
                <input
                  type="number"
                  min="1"
                  value={formData.burst_time || ''}
                  onChange={(e) => setFormData({ ...formData, burst_time: e.target.value ? parseInt(e.target.value) : undefined })}
                  className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="16"
                />
                <p className="text-xs text-muted-foreground mt-1">Max burst duration</p>
              </div>
            </div>
          </div>

          {/* Status */}
          <div className="border-t border-border pt-4">
            <label className="flex items-center space-x-2">
              <input
                type="checkbox"
                checked={formData.is_active}
                onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                className="rounded border-input text-primary focus:ring-primary"
              />
              <span className="text-sm font-medium text-foreground">Plan is active</span>
            </label>
          </div>

          <div className="flex justify-end space-x-3 pt-4 border-t border-border">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 border border-border rounded-md text-foreground hover:bg-secondary transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className="px-4 py-2 bg-primary text-primary-foreground rounded-md hover:opacity-90 transition-opacity disabled:opacity-50"
            >
              {saving ? 'Saving...' : plan ? 'Update Plan' : 'Create Plan'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
