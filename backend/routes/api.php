<?php

use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ClientMigrationController;
use App\Http\Controllers\Api\V1\CustomerPortalController;
use App\Http\Controllers\Api\V1\CustomerTroubleshootingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HsgqOltController;
use App\Http\Controllers\Api\V1\HistoricalCleanupController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\FinancialEntryController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RemittanceController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RouterController;
use App\Http\Controllers\Api\V1\ServicePlanController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\UnregisteredLeaseController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'ISP Billing API is running',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// V1 API Routes
Route::prefix('v1')->group(function () {
    // Public routes
    Route::get('/status', function () {
        return response()->json([
            'message' => 'API v1 is operational',
            'laravel_version' => app()->version(),
        ]);
    });

    // Authentication routes (public)
    Route::prefix('auth')->group(function () {
        // Staff accounts are created by an administrator; customer self-service
        // signup lives under the customer-portal routes below.
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        
        // Protected auth routes
        Route::middleware('auth:api')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/me', [AuthController::class, 'me']);
        });

        Route::middleware(['auth:api', 'role:admin|super_admin'])->group(function () {
            Route::post('/register', [AuthController::class, 'register']);
        });
    });

    // Protected routes (require authentication)
    Route::middleware('auth:api')->group(function () {
        // Dashboard routes
        Route::get('dashboard/metrics', [DashboardController::class, 'metrics'])->middleware('permission:view-dashboard');
        Route::get('dashboard/client-monitor', [DashboardController::class, 'clientMonitor'])->middleware('permission:view-dashboard');
        Route::get('dashboard/quick-stats', [DashboardController::class, 'quickStats'])->middleware('permission:view-dashboard');
        Route::get('dashboard/technician', [DashboardController::class, 'technicianWorkspace'])->middleware('role:technician');
        Route::get('dashboard/technician-monitor', [DashboardController::class, 'technicianMonitor'])->middleware('role:technician');
        Route::post('dashboard/technician/register-client', [UnregisteredLeaseController::class, 'technicianRegister'])->middleware('role:technician');
        
        // Staff-user administration is reserved for the Super Administrator.
        // Keep this separate from customer portal-account support, which office
        // administrators may still need for a customer password reset.
        Route::middleware('role:super_admin')->group(function () {
            Route::apiResource('users', UserController::class);
            Route::post('users/{user}/roles', [UserController::class, 'assignRoles']);
        });
        Route::middleware(['role:admin|super_admin'])->group(function () {
            Route::get('customer-portal-accounts', [CustomerController::class, 'portalAccounts']);
            Route::post('customer-portal-accounts/{id}/reset-password', [CustomerController::class, 'resetPortalPassword']);
            Route::get('customer-profile-change-requests', [CustomerController::class, 'profileChangeRequests']);
            Route::post('customer-profile-change-requests/{id}/approve', [CustomerController::class, 'approveProfileChangeRequest']);
            Route::post('customer-profile-change-requests/{id}/reject', [CustomerController::class, 'rejectProfileChangeRequest']);
        });
        Route::get('super-admin/client-migrations/template', [ClientMigrationController::class, 'template'])->middleware('role:super_admin');
        Route::post('super-admin/client-migrations/preview', [ClientMigrationController::class, 'preview'])->middleware('role:super_admin');
        Route::patch('super-admin/client-migrations/customers/{customer}', [ClientMigrationController::class, 'updateExisting'])->middleware('role:super_admin');
        Route::middleware('role:super_admin')->prefix('super-admin/historical-cleanup')->group(function () {
            Route::get('audits', [HistoricalCleanupController::class, 'index']);
            Route::post('preview', [HistoricalCleanupController::class, 'preview']);
            Route::post('execute', [HistoricalCleanupController::class, 'execute']);
        });
        
        // Role routes (admin only)
        Route::middleware(['role:admin|super_admin'])->group(function () {
            Route::apiResource('roles', RoleController::class);
            Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
            Route::get('permissions', [RoleController::class, 'permissions']);
        });
        
        // Customer routes (require permission)
        Route::get('customers-statistics', [CustomerController::class, 'statistics'])->middleware('permission:view-customers');
        Route::apiResource('customers', CustomerController::class)->only(['index', 'show'])->middleware('permission:view-customers');
        Route::apiResource('customers', CustomerController::class)->only(['store'])->middleware('permission:create-customers');
        Route::apiResource('customers', CustomerController::class)->only(['update'])->middleware('permission:edit-customers');
        Route::apiResource('customers', CustomerController::class)->only(['destroy'])->middleware('permission:delete-customers');
        Route::post('customers/{id}/sync-queue', [CustomerController::class, 'syncQueue'])->middleware('permission:edit-customers');
        Route::post('customers/{id}/sync-network', [CustomerController::class, 'syncNetwork'])->middleware('permission:edit-customers');
        Route::post('customers/{id}/suspend', [CustomerController::class, 'suspend'])->middleware('permission:edit-customers');
        Route::post('customers/{id}/restore', [CustomerController::class, 'restore'])->middleware('permission:edit-customers');
        Route::get('customers/{id}/cash-signature', [CustomerController::class, 'cashSignature'])->middleware('role:super_admin|admin');
        Route::delete('customers/{id}/cash-signature', [CustomerController::class, 'resetCashSignature'])->middleware('role:super_admin|admin');
        Route::post('customers/bulk-sync-queues', [CustomerController::class, 'bulkSyncQueues'])->middleware('permission:edit-customers');
        Route::post('customers/bulk-delete', [CustomerController::class, 'bulkDestroy'])->middleware('permission:delete-customers');
        
        // Router routes (MikroTik) - require permission
        Route::apiResource('routers', RouterController::class)->only(['index', 'show'])->middleware('permission:view-routers');
        Route::apiResource('routers', RouterController::class)->only(['store'])->middleware('permission:create-routers');
        Route::apiResource('routers', RouterController::class)->only(['update'])->middleware('permission:edit-routers');
        Route::apiResource('routers', RouterController::class)->only(['destroy'])->middleware('permission:delete-routers');
        Route::middleware(['permission:manage-routers'])->group(function () {
            Route::post('routers/{id}/test-connection', [RouterController::class, 'testConnection']);
            Route::get('routers/{id}/monitoring', [RouterController::class, 'monitoring']);
            Route::post('routers/{id}/threat-scan', [RouterController::class, 'scanThreatFeed']);
            Route::get('routers/{id}/threat-observations', [RouterController::class, 'threatObservations']);
            Route::post('routers/{id}/sync', [RouterController::class, 'sync']);
            Route::post('routers/{id}/billing-access/install', [RouterController::class, 'installBillingAccess']);
            Route::get('routers/{id}/billing-access', [RouterController::class, 'billingAccessStatus']);
            Route::get('routers/{id}/billing-access/audit', [RouterController::class, 'billingAccessAudit']);
            Route::delete('routers/{id}/billing-access', [RouterController::class, 'removeBillingAccess']);
            Route::post('routers/{id}/console/script', [RouterController::class, 'runConsoleScript']);
            Route::post('routers/{id}/console/ping', [RouterController::class, 'consolePing']);
            Route::get('routers/{id}/setup-script', [RouterController::class, 'generateSetupScript']);
            Route::post('routers/preview-script', [RouterController::class, 'previewSetupScript']);
            Route::get('routers/scripts/queue-management', [RouterController::class, 'getQueueScript']);
        });
        Route::post('routers/{id}/threat-observations/{observation}/review', [RouterController::class, 'reviewThreatObservation'])->middleware('role:super_admin|admin');
        Route::middleware('role:super_admin|admin')->group(function () {
            // Guarded internal DNS branding. Discovery, planning, backup,
            // approval, apply, verification, and rollback are intentionally
            // separate; no other router configuration is part of this flow.
            Route::post('routers/dns-branding/scan-all', [RouterController::class, 'dnsBrandingScanAll']);
            Route::post('routers/{id}/dns-branding/discover', [RouterController::class, 'dnsBrandingDiscover']);
            Route::post('routers/{id}/dns-branding/preview', [RouterController::class, 'dnsBrandingPreview']);
            Route::post('routers/{id}/dns-branding/backup', [RouterController::class, 'dnsBrandingBackup']);
            Route::post('routers/{id}/dns-branding/test', [RouterController::class, 'dnsBrandingTest']);
            Route::post('routers/{id}/dns-branding/apply', [RouterController::class, 'dnsBrandingApply']);
            Route::post('routers/{id}/dns-branding/rollback', [RouterController::class, 'dnsBrandingRollback']);
            Route::post('routers/{id}/provisioning/discover', [RouterController::class, 'provisioningDiscover']);
            Route::post('routers/{id}/provisioning/preview', [RouterController::class, 'provisioningPreview']);
            Route::post('routers/{id}/provisioning/apply', [RouterController::class, 'provisioningApply']);
            Route::get('routers/{id}/qos/status', [RouterController::class, 'qosStatus']);
            Route::get('routers/{id}/qos/config', [RouterController::class, 'qosConfig']);
            Route::get('routers/{id}/qos/clients', [RouterController::class, 'qosClients']);
            Route::post('routers/{id}/qos/preview', [RouterController::class, 'qosPreview']);
            Route::post('routers/{id}/qos/safe/preview', [RouterController::class, 'qosSafePreview']);
            Route::post('routers/{id}/qos/safe/start-test', [RouterController::class, 'qosSafeStartTest']);
            Route::post('routers/{id}/qos/safe/apply', [RouterController::class, 'qosSafeApply']);
            Route::get('routers/{id}/qos/metrics', [RouterController::class, 'qosMetrics']);
            Route::post('routers/{id}/qos/test', [RouterController::class, 'qosTest']);
            Route::post('routers/{id}/qos/apply', [RouterController::class, 'qosApply']);
            Route::post('routers/{id}/qos/rollback', [RouterController::class, 'qosRollback']);
            Route::post('routers/{id}/qos/disable', [RouterController::class, 'qosDisable']);
        });
        Route::get('system/network-info', [RouterController::class, 'networkInfo'])->middleware('permission:view-routers');
        Route::post('routers/{id}/sync-dhcp', [RouterController::class, 'syncDhcpLeases'])->middleware('permission:sync-dhcp');
        Route::get('routers/{id}/unmatched-leases', [RouterController::class, 'getUnmatchedLeases'])->middleware('permission:view-dhcp');

        // Unregistered DHCP leases (converts leases into customers)
        Route::prefix('unregistered-leases')->group(function () {
            Route::post('sync-all',                 [UnregisteredLeaseController::class, 'syncAll'])->middleware('permission:sync-dhcp');
            Route::get('static-commented',          [UnregisteredLeaseController::class, 'staticCommented'])->middleware('permission:view-dhcp');
            Route::get('dynamic',                   [UnregisteredLeaseController::class, 'dynamic'])->middleware('permission:view-dhcp');
            Route::post('{id}/quick-register',      [UnregisteredLeaseController::class, 'quickRegister'])->middleware('permission:create-customers|manage-dhcp');
        });

        // AI Assistant (floating chat). Keep it available on every employee
        // dashboard, including Collector and Technician. Individual tools
        // still enforce their own read/write permissions, and the customer
        // role is intentionally excluded from this staff API.
        Route::prefix('ai')->middleware('role:super_admin|admin|cashier|office_admin|collector|technician|noc|accounting|viewer')->group(function () {
            Route::post('chat',                              [AiController::class, 'chat']);
            Route::get('conversations',                      [AiController::class, 'listConversations']);
            Route::get('conversations/{id}/messages',        [AiController::class, 'messages']);
            Route::delete('conversations/{id}',              [AiController::class, 'destroyConversation']);
        });

        // General settings (super_admin or view-settings/edit-settings permissions)
        Route::get('settings',  [SettingsController::class, 'index'])->middleware('permission:view-settings');
        Route::put('settings',  [SettingsController::class, 'update'])->middleware('permission:edit-settings');
        Route::post('settings/company-logo', [SettingsController::class, 'uploadCompanyLogo'])->middleware('permission:edit-settings');
        Route::delete('settings/company-logo', [SettingsController::class, 'removeCompanyLogo'])->middleware('permission:edit-settings');

        // Scheduled Automations (view logs = view-settings, manual trigger = super_admin only)
        Route::prefix('automation')->group(function () {
            Route::get('jobs',      [AutomationController::class, 'jobs'])->middleware('permission:view-settings');
            Route::get('logs',      [AutomationController::class, 'index'])->middleware('permission:view-settings');
            Route::post('run/{job}',[AutomationController::class, 'run'])->middleware('role:super_admin');
        });
        
        // Service Plans routes (require permission)
        Route::apiResource('service-plans', ServicePlanController::class)->only(['index', 'show'])->middleware('permission:view-service-plans');
        Route::apiResource('service-plans', ServicePlanController::class)->only(['store'])->middleware('permission:create-service-plans');
        Route::apiResource('service-plans', ServicePlanController::class)->only(['update'])->middleware('permission:edit-service-plans');
        Route::apiResource('service-plans', ServicePlanController::class)->only(['destroy'])->middleware('permission:delete-service-plans');
        
        // Invoice routes (require permission)
        Route::middleware(['permission:view-invoices'])->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices-statistics', [InvoiceController::class, 'statistics']);
            Route::get('invoices/{id}', [InvoiceController::class, 'show']);
        });
        Route::get('invoices/{id}/pdf', [InvoiceController::class, 'downloadPdf'])->middleware('permission:download-invoices');
        Route::post('invoices', [InvoiceController::class, 'store'])->middleware('permission:create-invoices');
        Route::post('invoices/generate-recurring', [InvoiceController::class, 'generateRecurring'])->middleware('permission:create-invoices');
        Route::put('invoices/{id}', [InvoiceController::class, 'update'])->middleware('permission:edit-invoices');
        Route::post('invoices/{id}/mark-sent', [InvoiceController::class, 'markAsSent'])->middleware('permission:edit-invoices');
        Route::delete('invoices/{id}', [InvoiceController::class, 'destroy'])->middleware('permission:delete-invoices');
        Route::post('invoices/{id}/payments', [InvoiceController::class, 'recordPayment'])->middleware('permission:create-payments');
        
        // Payment routes (require permission)
        Route::middleware(['permission:view-payments'])->group(function () {
            Route::get('payments', [PaymentController::class, 'index']);
            Route::get('payments-statistics', [PaymentController::class, 'statistics']);
        Route::get('payments/{id}', [PaymentController::class, 'show']);
        });
        Route::post('payments/advance', [PaymentController::class, 'recordAdvance'])->middleware('permission:create-payments');
        // Collector workspace: only due invoices and collection/remittance actions.
        Route::middleware(['role:collector'])->prefix('collector')->group(function () {
            Route::get('dashboard', [RemittanceController::class, 'collectorDashboard']);
            Route::get('locations', [RemittanceController::class, 'collectorLocations']);
            Route::get('clients', [RemittanceController::class, 'collectorClients']);
            Route::put('clients/{id}/location', [RemittanceController::class, 'updateCollectorLocation']);
            Route::post('clients/{id}/plan-change-request', [RemittanceController::class, 'requestCollectorPlanChange']);
            Route::post('clients/{id}/early-invoice', [RemittanceController::class, 'createCollectorEarlyInvoice']);
            Route::post('invoices/{id}/collect', [RemittanceController::class, 'collect']);
            Route::post('invoices/{id}/gcash-checkout', [RemittanceController::class, 'startGcashCheckout']);
            Route::post('invoices/{id}/gcash-checkouts/{checkoutId}/reconcile', [RemittanceController::class, 'reconcileGcashCheckout']);
            Route::post('invoices/{id}/qr-ph', [RemittanceController::class, 'startQrPhPayment']);
            Route::post('invoices/{id}/qr-ph/{checkoutId}/attach', [RemittanceController::class, 'attachQrPhPayment']);
            Route::post('invoices/{id}/qr-ph/{checkoutId}/reconcile', [RemittanceController::class, 'reconcileQrPhPayment']);
            Route::post('remittances', [RemittanceController::class, 'submit']);
        });
        // Office staff verify what a collector declares against cash, bank, or GCash received.
        Route::middleware(['role:super_admin|admin|office_admin'])->prefix('remittances')->group(function () {
            Route::get('/', [RemittanceController::class, 'index']);
            Route::post('{id}/liquidate', [RemittanceController::class, 'liquidate']);
            Route::post('{id}/receive', [RemittanceController::class, 'receive']);
        });
        Route::get('financial-entries', [FinancialEntryController::class, 'index'])->middleware('permission:view-payments');
        Route::get('transaction-definitions', [FinancialEntryController::class, 'definitions'])->middleware('permission:view-payments');
        Route::post('financial-entries', [FinancialEntryController::class, 'store'])->middleware('permission:create-payments');
        Route::post('transaction-definitions', [FinancialEntryController::class, 'createDefinition'])->middleware('permission:edit-settings');
        Route::delete('transaction-definitions/{transactionDefinition}', [FinancialEntryController::class, 'deactivateDefinition'])->middleware('permission:edit-settings');
        
        // Ticket routes (require permission)
        Route::middleware(['permission:view-tickets'])->group(function () {
            Route::get('tickets', [TicketController::class, 'index']);
            Route::get('tickets-statistics', [TicketController::class, 'statistics']);
            Route::get('tickets/{id}', [TicketController::class, 'show']);
        });
        Route::post('tickets', [TicketController::class, 'store'])->middleware('permission:create-tickets');
        Route::put('tickets/{id}', [TicketController::class, 'update'])->middleware('permission:edit-tickets');
        Route::delete('tickets/{id}', [TicketController::class, 'destroy'])->middleware('permission:delete-tickets');
        Route::post('tickets/{id}/assign', [TicketController::class, 'assign'])->middleware('permission:assign-tickets');
        Route::post('tickets/{id}/claim-installation', [TicketController::class, 'claimInstallation'])->middleware('role:technician');
        Route::post('tickets/{id}/submit-installation', [TicketController::class, 'submitInstallation'])->middleware('role:technician');
        Route::post('tickets/{id}/repair/mark-in', [TicketController::class, 'markRepairIn'])->middleware('role:technician');
        Route::post('tickets/{id}/repair/resolve', [TicketController::class, 'resolveRepair'])->middleware('role:technician');
        Route::post('tickets/{id}/repair/close', [TicketController::class, 'closeRepair'])->middleware('role:technician');
        Route::post('tickets/{id}/installation/approve', [TicketController::class, 'approveInstallation'])->middleware('role:super_admin|admin');
        Route::post('tickets/{id}/installation/return', [TicketController::class, 'returnInstallation'])->middleware('role:super_admin|admin');
        Route::post('tickets/{id}/comments', [TicketController::class, 'addComment'])->middleware('permission:edit-tickets');
        Route::patch('tickets/{id}/status', [TicketController::class, 'updateStatus'])->middleware('permission:edit-tickets|close-tickets');
        
        // Report routes (require permission)
        Route::middleware(['permission:view-reports'])->group(function () {
            Route::get('reports/logs', [ReportController::class, 'operationsLog']);
            Route::get('reports/revenue', [ReportController::class, 'revenue']);
            Route::get('reports/customer-growth', [ReportController::class, 'customerGrowth']);
            Route::get('reports/payment-methods', [ReportController::class, 'paymentMethods']);
            Route::get('reports/service-plans', [ReportController::class, 'servicePlanPopularity']);
            Route::get('reports/tickets', [ReportController::class, 'ticketsOverview']);
        });
        
        // HSGQ OLT routes (require permission)
        Route::middleware(['permission:view-routers'])->group(function () {
            Route::get('hsgq-olt', [HsgqOltController::class, 'index']);
            Route::get('hsgq-olt/{oltId}/onts', [HsgqOltController::class, 'getOnts']);
            Route::get('hsgq-olt/{oltId}/onts/{ontId}/statistics', [HsgqOltController::class, 'getOntStatistics']);
        });
        Route::middleware(['permission:manage-routers'])->group(function () {
            Route::post('hsgq-olt/{oltId}/discover', [HsgqOltController::class, 'discoverOnts']);
            Route::post('hsgq-olt/{oltId}/onts/{ontId}/authorize', [HsgqOltController::class, 'authorizeOnt']);
            Route::post('hsgq-olt/{oltId}/onts/{ontId}/reboot', [HsgqOltController::class, 'rebootOnt']);
        });
    });

    // Customer Portal Routes (separate auth)
    Route::prefix('customer-portal')->group(function () {
        // Public customer login + self-signup
        Route::post('login',  [CustomerPortalController::class, 'login'])->middleware('throttle:5,1');
        Route::post('signup', [CustomerPortalController::class, 'signup'])->middleware('throttle:3,1');
        Route::get('payment-reminder/{customerId}', [CustomerPortalController::class, 'paymentReminder']);
        Route::post('payment-reminder/resolve', [CustomerPortalController::class, 'resolvePaymentReminder']);
        Route::post('paymongo/webhook', [CustomerPortalController::class, 'paymongoWebhook']);
        // Public list of active plans (for the signup page dropdown)
        Route::get('service-plans', [ServicePlanController::class, 'publicIndex']);
        Route::get('branding', [SettingsController::class, 'publicBranding']);
        Route::get('manifest.webmanifest', [SettingsController::class, 'publicManifest']);

        // Protected customer routes
        Route::middleware('api')->group(function () {
            Route::get('dashboard', [CustomerPortalController::class, 'dashboard']);
            Route::get('invoices', [CustomerPortalController::class, 'invoices']);
            Route::get('invoices/{id}', [CustomerPortalController::class, 'invoice']);
            Route::get('payments', [CustomerPortalController::class, 'payments']);
            Route::post('invoices/{id}/gcash-checkout', [CustomerPortalController::class, 'startGcashCheckout']);
            Route::post('gcash-checkouts/{id}/reconcile', [CustomerPortalController::class, 'reconcileGcashCheckout']);
            Route::post('gcash-checkouts/reconcile-latest', [CustomerPortalController::class, 'reconcileLatestGcashCheckout']);
            Route::post('invoices/{id}/qr-ph', [CustomerPortalController::class, 'startQrPhPayment']);
            Route::post('qr-ph/{id}/attach', [CustomerPortalController::class, 'attachQrPhPayment']);
            Route::post('qr-ph/{id}/reconcile', [CustomerPortalController::class, 'reconcileQrPhPayment']);
            Route::put('profile', [CustomerPortalController::class, 'updateProfile']);
            Route::post('location-capture/start', [CustomerPortalController::class, 'startLocationCapture'])->middleware('throttle:3,1');
            Route::post('location-capture/capture', [CustomerPortalController::class, 'captureLocation'])->middleware('throttle:6,1');
            Route::post('location-capture/confirm', [CustomerPortalController::class, 'confirmLocationCapture'])->middleware('throttle:3,1');
            Route::get('profile-change-requests', [CustomerPortalController::class, 'profileChangeRequests']);
            Route::post('profile-change-requests', [CustomerPortalController::class, 'submitProfileChangeRequest']);
            Route::put('password', [CustomerPortalController::class, 'changePassword']);
            Route::get('push-notifications/status', [CustomerPortalController::class, 'pushNotificationStatus']);
            Route::post('push-notifications/subscribe', [CustomerPortalController::class, 'subscribePushNotifications'])->middleware('throttle:6,1');
            Route::delete('push-notifications/subscribe', [CustomerPortalController::class, 'unsubscribePushNotifications'])->middleware('throttle:6,1');
            Route::post('push-notifications/{notificationId}/clicked', [CustomerPortalController::class, 'markPushNotificationClicked'])->middleware('throttle:30,1');
            Route::post('troubleshooting/sessions', [CustomerTroubleshootingController::class, 'start'])->middleware('throttle:10,1');
            Route::post('troubleshooting/sessions/{id}/messages', [CustomerTroubleshootingController::class, 'message'])->middleware('throttle:30,1');
            Route::post('troubleshooting/sessions/{id}/escalate', [CustomerTroubleshootingController::class, 'escalate'])->middleware('throttle:5,1');
        });
    });
});
