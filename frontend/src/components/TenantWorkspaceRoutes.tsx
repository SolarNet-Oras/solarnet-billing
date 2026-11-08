import { Navigate, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { ProtectedRoute } from '@/components/ProtectedRoute';
import SaasAppPage from '@/pages/SaasAppPage';
import NewDashboardPage from '@/pages/NewDashboardPage';
import CustomersPage from '@/pages/CustomersPage';
import CreateCustomerPage from '@/pages/CreateCustomerPage';
import EditCustomerPage from '@/pages/EditCustomerPage';
import CustomerDetailPage from '@/pages/CustomerDetailPage';
import InvoicesPage from '@/pages/InvoicesPage';
import OperationsLedgerPage from '@/pages/OperationsLedgerPage';
import FinancialMonitoringPage from '@/pages/FinancialMonitoringPage';
import { ServicePlansPage } from '@/pages/ServicePlansPage';
import TicketsPage from '@/pages/TicketsPage';
import ReportsPage from '@/pages/ReportsPage';
import SettingsPage from '@/pages/SettingsPage';
import StaffAppInstallPage from '@/pages/StaffAppInstallPage';
import TenantStaffAccessPage from '@/pages/TenantStaffAccessPage';
import { SAAS_TENANT_TOKEN_KEY } from '@/services/saasTenantService';

const protect = (page: ReactNode): React.JSX.Element => <ProtectedRoute>{page}</ProtectedRoute>;

export default function TenantWorkspaceRoutes(): React.JSX.Element {
  const signedIn = Boolean(sessionStorage.getItem(SAAS_TENANT_TOKEN_KEY));
  return <Routes>
    <Route path="/" element={signedIn ? <Navigate to="/dashboard" replace /> : <SaasAppPage />} />
    <Route path="/dashboard" element={protect(<NewDashboardPage />)} />
    <Route path="/customers" element={protect(<CustomersPage />)} />
    <Route path="/customers/new" element={protect(<CreateCustomerPage />)} />
    <Route path="/customers/create" element={protect(<CreateCustomerPage />)} />
    <Route path="/customers/:id" element={protect(<CustomerDetailPage />)} />
    <Route path="/customers/:id/edit" element={protect(<EditCustomerPage />)} />
    <Route path="/billing" element={protect(<InvoicesPage />)} />
    <Route path="/operations" element={protect(<OperationsLedgerPage />)} />
    <Route path="/financial-monitoring" element={protect(<FinancialMonitoringPage />)} />
    <Route path="/service-plans" element={protect(<ServicePlansPage />)} />
    <Route path="/tickets" element={protect(<TicketsPage />)} />
    <Route path="/reports" element={protect(<ReportsPage />)} />
    <Route path="/settings" element={protect(<SettingsPage />)} />
    <Route path="/tenant-staff" element={protect(<TenantStaffAccessPage />)} />
    <Route path="/install-staff-app" element={protect(<StaffAppInstallPage />)} />
    <Route path="*" element={<Navigate to={signedIn ? '/dashboard' : '/'} replace />} />
  </Routes>;
}
