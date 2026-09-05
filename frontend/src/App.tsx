import React from 'react';
import './index.css';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from '@/context/AuthContext';
import { ThemeProvider } from '@/context/ThemeContext';
import { ProtectedRoute } from '@/components/ProtectedRoute';
import LoginPage from '@/pages/LoginPage';
import NewDashboardPage from '@/pages/NewDashboardPage';
import CustomersPage from '@/pages/CustomersPage';
import CreateCustomerPage from '@/pages/CreateCustomerPage';
import EditCustomerPage from '@/pages/EditCustomerPage';
import CustomerDetailPage from '@/pages/CustomerDetailPage';
import { NetworkDevicesPage } from '@/pages/NetworkDevicesPage';
import { ServicePlansPage } from '@/pages/ServicePlansPage';
import InvoicesPage from '@/pages/InvoicesPage';
import OperationsLedgerPage from '@/pages/OperationsLedgerPage';
import TicketsPage from '@/pages/TicketsPage';
import ReportsPage from '@/pages/ReportsPage';
import CustomerLoginPage from '@/pages/CustomerLoginPage';
import CustomerDashboardPage from '@/pages/CustomerDashboardPage';
import CustomerBillingPage from '@/pages/CustomerBillingPage';
import CustomerChangePasswordPage from '@/pages/CustomerChangePasswordPage';
import CustomerProfilePage from '@/pages/CustomerProfilePage';
import PaymentRequiredPage from '@/pages/PaymentRequiredPage';
import SignupPage from '@/pages/SignupPage';
import UsersPage from '@/pages/UsersPage';
import UnregisteredLeasesPage from '@/pages/UnregisteredLeasesPage';
import SettingsPage from '@/pages/SettingsPage';
import SuspendedAccountPage from '@/pages/SuspendedAccountPage';
import RemittancesPage from '@/pages/RemittancesPage';
import ClientMigrationPage from '@/pages/ClientMigrationPage';
import RadiusIpOePage from '@/pages/RadiusIpOePage';
import FinancialMonitoringPage from '@/pages/FinancialMonitoringPage';
import OperationsMapPage from '@/pages/OperationsMapPage';
import CyberSecurityPage from '@/pages/CyberSecurityPage';
import FacebookAutomationPage from '@/pages/FacebookAutomationPage';
import WireguardPage from '@/pages/WireguardPage';
import StaffAppInstallPage from '@/pages/StaffAppInstallPage';
import LegalPage from '@/pages/LegalPage';
import SmsAdvisoryPage from '@/pages/SmsAdvisoryPage';
import InvoiceQuickPayPage from '@/pages/InvoiceQuickPayPage';

// ============================================================================
// Main App Component
// ============================================================================

const App: React.FC = (): JSX.Element => {
  return (
    <ThemeProvider>
      <Router>
        <AuthProvider>
          <div className="App min-h-screen bg-background">
            <Routes>
              {/* Public Routes */}
              <Route path="/login" element={<LoginPage />} />
              <Route path="/suspended" element={<SuspendedAccountPage />} />
              <Route path="/privacy-policy" element={<LegalPage />} />
              <Route path="/terms" element={<LegalPage />} />
              <Route path="/data-deletion" element={<LegalPage />} />
              
              {/* Protected Routes */}
              <Route
                path="/dashboard"
                element={
                  <ProtectedRoute>
                    <NewDashboardPage />
                  </ProtectedRoute>
                }
              />
              <Route
                path="/customers"
                element={
                  <ProtectedRoute>
                    <CustomersPage />
                  </ProtectedRoute>
                }
              />
              <Route
                path="/customers/create"
                element={
                  <ProtectedRoute>
                    <CreateCustomerPage />
                  </ProtectedRoute>
                }
              />
              {/* Alias for /customers/new so links from the Unregistered Leases page work */}
              <Route
                path="/customers/new"
                element={
                  <ProtectedRoute>
                    <CreateCustomerPage />
                  </ProtectedRoute>
                }
              />
              <Route
                path="/customers/:id"
                element={<ProtectedRoute><CustomerDetailPage /></ProtectedRoute>}
              />
              <Route
                path="/customers/:id/edit"
                element={
                  <ProtectedRoute>
                    <EditCustomerPage />
                  </ProtectedRoute>
                }
              />
              <Route
                path="/unregistered-clients"
                element={
                  <ProtectedRoute>
                    <UnregisteredLeasesPage />
                  </ProtectedRoute>
                }
              />
              <Route
                path="/settings"
                element={
                  <ProtectedRoute>
                    <SettingsPage />
                  </ProtectedRoute>
                }
              />
              <Route
                path="/network-devices"
                element={
                  <ProtectedRoute>
                    <NetworkDevicesPage />
                  </ProtectedRoute>
                }
              />
              <Route
                path="/service-plans"
                element={
                  <ProtectedRoute>
                    <ServicePlansPage />
                  </ProtectedRoute>
                }
              />
              <Route
                path="/billing"
                element={
                  <ProtectedRoute>
                    <InvoicesPage />
                  </ProtectedRoute>
                }
              />
              
              <Route
                path="/operations"
                element={<ProtectedRoute><OperationsLedgerPage /></ProtectedRoute>}
              />
              <Route
                path="/financial-monitoring"
                element={<ProtectedRoute allowedRoles={['super_admin', 'admin', 'cashier', 'accounting']}><FinancialMonitoringPage /></ProtectedRoute>}
              />
              <Route
                path="/operations-map"
                element={<ProtectedRoute allowedRoles={['super_admin', 'admin', 'office_admin', 'technician', 'collector', 'noc']}><OperationsMapPage /></ProtectedRoute>}
              />
              <Route
                path="/cybersecurity"
                element={<ProtectedRoute allowedRoles={['super_admin', 'admin', 'noc']}><CyberSecurityPage /></ProtectedRoute>}
              />
              <Route
                path="/facebook-automation"
                element={<ProtectedRoute allowedRoles={['super_admin', 'admin', 'office_admin']}><FacebookAutomationPage /></ProtectedRoute>}
              />
              <Route path="/sms-advisories" element={<ProtectedRoute allowedRoles={['super_admin', 'admin', 'office_admin']}><SmsAdvisoryPage /></ProtectedRoute>} />
              <Route path="/remittances" element={<ProtectedRoute><RemittancesPage /></ProtectedRoute>} />
              <Route path="/super-admin/client-migrations" element={<ProtectedRoute><ClientMigrationPage /></ProtectedRoute>} />
              <Route path="/radius-ipoe" element={<ProtectedRoute allowedRoles={['super_admin', 'admin']}><RadiusIpOePage /></ProtectedRoute>} />
              <Route path="/wireguard" element={<ProtectedRoute allowedRoles={['super_admin']}><WireguardPage /></ProtectedRoute>} />
              <Route path="/install-staff-app" element={<ProtectedRoute allowedRoles={['super_admin', 'admin', 'cashier', 'office_admin', 'collector', 'technician', 'noc', 'accounting', 'viewer']}><StaffAppInstallPage /></ProtectedRoute>} />
              <Route
                path="/tickets"
                element={
                  <ProtectedRoute>
                    <TicketsPage />
                  </ProtectedRoute>
                }
              />
              
              <Route
                path="/reports"
                element={
                  <ProtectedRoute>
                    <ReportsPage />
                  </ProtectedRoute>
                }
              />
              
              {/* Customer Portal Routes */}
              <Route path="/customer/login" element={<CustomerLoginPage />} />
              <Route path="/pay/:token" element={<InvoiceQuickPayPage />} />
              <Route path="/customer/dashboard" element={<CustomerDashboardPage />} />
              <Route path="/customer/billing" element={<CustomerBillingPage />} />
              <Route path="/customer/change-password" element={<CustomerChangePasswordPage />} />
              <Route path="/customer/profile" element={<CustomerProfilePage />} />
              <Route path="/payment-required/:customerId" element={<PaymentRequiredPage />} />
              <Route path="/signup" element={<SignupPage />} />

              {/* Staff user management */}
              <Route
                path="/users"
                element={
                  <ProtectedRoute allowedRoles={['super_admin']}>
                    <UsersPage />
                  </ProtectedRoute>
                }
              />

              {/* Default Route */}
              <Route path="/" element={<Navigate to="/dashboard" replace />} />
              
              {/* Catch all - redirect to dashboard */}
              <Route path="*" element={<Navigate to="/dashboard" replace />} />
            </Routes>
          </div>
        </AuthProvider>
      </Router>
    </ThemeProvider>
  );
};

export default App;
