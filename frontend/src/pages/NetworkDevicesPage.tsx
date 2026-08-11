import { useState } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { MikroTikRouters } from '@/components/network/MikroTikRouters';
import { OltCloudAccess } from '@/components/network/OltCloudAccess';

export function NetworkDevicesPage() {
  const [activeTab, setActiveTab] = useState<'mikrotik' | 'olt'>('mikrotik');

  return (
    <DashboardLayout>
      <div className="space-y-6">
        <div>
          <h1 className="text-3xl font-bold text-foreground">Network Devices</h1>
          <p className="text-muted-foreground mt-2">
            Manage MikroTik routers and OLT devices
          </p>
        </div>

        {/* Tabs */}
        <div className="border-b border-border">
          <div className="flex space-x-8">
            <button
              onClick={() => setActiveTab('mikrotik')}
              className={`pb-4 px-1 border-b-2 font-medium text-sm transition-colors ${
                activeTab === 'mikrotik'
                  ? 'border-primary text-primary'
                  : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
              }`}
            >
              MikroTik Routers
            </button>
            <button
              onClick={() => setActiveTab('olt')}
              className={`pb-4 px-1 border-b-2 font-medium text-sm transition-colors ${
                activeTab === 'olt'
                  ? 'border-primary text-primary'
                  : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
              }`}
            >
              OLT Devices
              <span className="ml-2 text-xs bg-primary/10 text-primary px-2 py-0.5 rounded">Cloud access</span>
            </button>
          </div>
        </div>

        {/* Tab Content */}
        <div className="mt-6">
          {activeTab === 'mikrotik' && <MikroTikRouters />}
          {activeTab === 'olt' && <OltCloudAccess />}
        </div>
      </div>
    </DashboardLayout>
  );
}
