import React, { useState } from 'react';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import FloatingAiAssistant from '@/components/ai/FloatingAiAssistant';

interface DashboardLayoutProps {
  children: React.ReactNode;
  headerTitle?: string;
  headerSubtitle?: string;
}

export const DashboardLayout: React.FC<DashboardLayoutProps> = ({ children, headerTitle, headerSubtitle }) => {
  const [sidebarOpen, setSidebarOpen] = useState<boolean>(false);

  return (
    <div className="min-h-screen bg-background flex">
      {/* Sidebar */}
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      {/* Main content */}
      <div className="flex min-h-screen flex-1 flex-col md:ml-64">
        {/* Header */}
          <Header onMenuClick={() => setSidebarOpen(true)} title={headerTitle} subtitle={headerSubtitle} />

        {/* Page content */}
        <main className="flex-1 p-4 md:p-6 lg:p-8 overflow-auto">
          {children}
        </main>
      </div>

      {/* Floating AI Assistant — appears on every authenticated page */}
      <FloatingAiAssistant />
    </div>
  );
};
