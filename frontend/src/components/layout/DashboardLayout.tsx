import React, { useState } from 'react';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import FloatingAiAssistant from '@/components/ai/FloatingAiAssistant';
import MandatoryFieldLocation from '@/components/staff/MandatoryFieldLocation';

interface DashboardLayoutProps {
  children: React.ReactNode;
  headerTitle?: string;
  headerSubtitle?: string;
}

export const DashboardLayout: React.FC<DashboardLayoutProps> = ({ children, headerTitle, headerSubtitle }) => {
  const [sidebarOpen, setSidebarOpen] = useState<boolean>(false);

  return (
    <div className="flex min-h-screen min-w-0 bg-background">
      {/* Sidebar */}
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      {/* Main content */}
      <div className="flex min-h-screen min-w-0 flex-1 flex-col lg:ml-64">
        {/* Header */}
          <Header onMenuClick={() => setSidebarOpen(true)} title={headerTitle} subtitle={headerSubtitle} />

        {/* Page content */}
        <main className="min-w-0 flex-1 overflow-x-hidden p-3 sm:p-4 md:p-5 lg:p-7 xl:p-8">
          {children}
        </main>
      </div>

      {/* Floating AI Assistant — appears on every authenticated page */}
      <FloatingAiAssistant />
      <MandatoryFieldLocation />
    </div>
  );
};
