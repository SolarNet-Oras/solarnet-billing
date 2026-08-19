<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiTool;
use App\Services\FinancialMonitoringService;

/**
 * A bounded, read-only finance overview for the staff AI assistant.
 *
 * It intentionally exposes no create/update/delete path. The same service
 * powers the Financial Monitoring page, so the AI explanation and the page
 * start from the same deterministic numbers.
 */
class GetFinancialMonitoringTool implements AiTool
{
    public function __construct(private readonly FinancialMonitoringService $monitoring) {}

    public function name(): string
    {
        return 'get_financial_monitoring';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_financial_monitoring',
                'description' => 'Return a deterministic, read-only SolarNet finance summary for one calendar month: amounts invoiced, recognized collections by Cash/GCash/BPI/Landbank/Online, approved daily-operation expenses, net operational movement, current receivables, advance credit, and collector remittances awaiting review. Use this for collection, revenue, cash-flow, expense, or outstanding-balance overview questions. It cannot change any financial record.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'month' => [
                            'type' => 'string',
                            'description' => 'Calendar month in YYYY-MM format. Omit for the current month.',
                            'pattern' => '^\\d{4}-(0[1-9]|1[0-2])$',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'cashier', 'accounting']);
    }

    public function execute(User $user, array $arguments): array
    {
        $month = isset($arguments['month']) ? trim((string) $arguments['month']) : null;
        if ($month !== null && $month !== '' && !preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return ['error' => 'Month must use YYYY-MM format.'];
        }

        return $this->monitoring->summary($month ?: null);
    }
}
