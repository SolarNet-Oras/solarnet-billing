<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerPortalController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HsgqOltController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RouterController;
use App\Http\Controllers\Api\V1\ServicePlanController;
use App\Http\Controllers\Api\V1\TicketController;
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
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        
        // Protected auth routes
        Route::middleware('auth:api')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Protected routes (require authentication)
    Route::middleware('auth:api')->group(function () {
        // Dashboard routes
        Route::get('dashboard/metrics', [DashboardController::class, 'metrics']);
        Route::get('dashboard/quick-stats', [DashboardController::class, 'quickStats']);
        
        // User routes (admin only)
        Route::middleware(['role:admin|super_admin'])->group(function () {
            Route::apiResource('users', UserController::class);
            Route::post('users/{user}/roles', [UserController::class, 'assignRoles']);
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
        Route::post('customers/bulk-sync-queues', [CustomerController::class, 'bulkSyncQueues'])->middleware('permission:edit-customers');
        
        // Router routes (MikroTik) - require permission
        Route::apiResource('routers', RouterController::class)->only(['index', 'show'])->middleware('permission:view-routers');
        Route::apiResource('routers', RouterController::class)->only(['store'])->middleware('permission:create-routers');
        Route::apiResource('routers', RouterController::class)->only(['update'])->middleware('permission:edit-routers');
        Route::apiResource('routers', RouterController::class)->only(['destroy'])->middleware('permission:delete-routers');
        Route::middleware(['permission:manage-routers'])->group(function () {
            Route::post('routers/{id}/test-connection', [RouterController::class, 'testConnection']);
            Route::post('routers/{id}/sync', [RouterController::class, 'sync']);
            Route::get('routers/{id}/setup-script', [RouterController::class, 'generateSetupScript']);
            Route::post('routers/preview-script', [RouterController::class, 'previewSetupScript']);
            Route::get('routers/scripts/queue-management', [RouterController::class, 'getQueueScript']);
        });
        // Network info: any authenticated user can view (needed to render the "Add Router" wizard)
        Route::get('system/network-info', [RouterController::class, 'networkInfo']);
        Route::post('routers/{id}/sync-dhcp', [RouterController::class, 'syncDhcpLeases'])->middleware('permission:sync-dhcp');
        Route::get('routers/{id}/unmatched-leases', [RouterController::class, 'getUnmatchedLeases'])->middleware('permission:view-dhcp');
        
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
        Route::post('tickets/{id}/comments', [TicketController::class, 'addComment'])->middleware('permission:edit-tickets');
        Route::patch('tickets/{id}/status', [TicketController::class, 'updateStatus'])->middleware('permission:edit-tickets|close-tickets');
        
        // Report routes (require permission)
        Route::middleware(['permission:view-reports'])->group(function () {
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
        Route::post('login',  [CustomerPortalController::class, 'login']);
        Route::post('signup', [CustomerPortalController::class, 'signup']);
        // Public list of active plans (for the signup page dropdown)
        Route::get('service-plans', [ServicePlanController::class, 'publicIndex']);

        // Protected customer routes
        Route::middleware('api')->group(function () {
            Route::get('dashboard', [CustomerPortalController::class, 'dashboard']);
            Route::get('invoices', [CustomerPortalController::class, 'invoices']);
            Route::get('invoices/{id}', [CustomerPortalController::class, 'invoice']);
            Route::get('payments', [CustomerPortalController::class, 'payments']);
            Route::put('profile', [CustomerPortalController::class, 'updateProfile']);
        });
    });
});
