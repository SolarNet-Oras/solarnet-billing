<?php

use App\Models\ActivityLog;
use App\Models\AutomationLog;
use App\Models\CollectionPlan;
use App\Models\Customer;
use App\Models\DailyCashCount;
use App\Models\DhcpLease;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\OltDevice;
use App\Models\OperationsMapAsset;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\Router;
use App\Models\ServicePlan;
use App\Models\Setting;
use App\Models\SmsAdvisoryCampaign;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffLeaveRequest;
use App\Models\StaffPayrollDisbursement;
use App\Models\Ticket;
use App\Models\WireguardPeer;

return [
    // Root ownership is only one stage. Keep tenant navigation unavailable
    // until every descendant/history table and API path is tenant-scoped.
    'workspace_release_blockers' => [
        'descendant_operational_records_not_yet_tenant_scoped',
        'operational_api_authorization_not_yet_verified',
    ],
    'modules' => [
        'dashboard' => ['roots' => []],
        'customers' => ['roots' => [['table' => 'customers', 'model' => Customer::class]]],
        'billing' => ['roots' => [
            ['table' => 'invoices', 'model' => Invoice::class],
            ['table' => 'payments', 'model' => Payment::class],
        ]],
        'remittances' => ['roots' => [['table' => 'remittances', 'model' => Remittance::class]]],
        'collection_monitoring' => ['roots' => [['table' => 'collection_plans', 'model' => CollectionPlan::class]]],
        'daily_operations' => ['roots' => [
            ['table' => 'financial_entries', 'model' => FinancialEntry::class],
            ['table' => 'daily_cash_counts', 'model' => DailyCashCount::class],
        ]],
        'attendance_payroll' => ['roots' => [
            ['table' => 'staff_attendance_records', 'model' => StaffAttendanceRecord::class],
            ['table' => 'staff_payroll_disbursements', 'model' => StaffPayrollDisbursement::class],
        ]],
        'leave_requests' => ['roots' => [['table' => 'staff_leave_requests', 'model' => StaffLeaveRequest::class]]],
        'financial_monitoring' => ['roots' => [['table' => 'financial_entries', 'model' => FinancialEntry::class]]],
        'service_plans' => ['roots' => [['table' => 'service_plans', 'model' => ServicePlan::class]]],
        'unregistered_devices' => ['roots' => [['table' => 'dhcp_leases', 'model' => DhcpLease::class]]],
        'tickets' => ['roots' => [['table' => 'tickets', 'model' => Ticket::class]]],
        'operations_map' => ['roots' => [['table' => 'operations_map_assets', 'model' => OperationsMapAsset::class]]],
        'sms_advisory' => ['roots' => [['table' => 'sms_advisory_campaigns', 'model' => SmsAdvisoryCampaign::class]]],
        'network_devices' => ['roots' => [
            ['table' => 'routers', 'model' => Router::class],
            ['table' => 'olt_devices', 'model' => OltDevice::class],
            ['table' => 'wireguard_peers', 'model' => WireguardPeer::class],
        ]],
        'logs_reports' => ['roots' => [
            ['table' => 'activity_logs', 'model' => ActivityLog::class],
            ['table' => 'automation_logs', 'model' => AutomationLog::class],
        ]],
        'install_app' => ['roots' => []],
        'settings' => ['roots' => [['table' => 'settings', 'model' => Setting::class]]],
    ],
    'membership_owned' => ['users', 'tenant_user'],
    'shared_platform_tables' => [
        'tenants', 'saas_subscription_plans', 'tenant_subscriptions',
        'tenant_subscription_payments', 'tenant_subscription_events',
        'saas_tenant_applications',
    ],
];
