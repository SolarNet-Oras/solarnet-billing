<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientMigrationAudit;
use App\Models\ClientMigrationDateCorrection;
use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\ServicePlan;
use App\Services\HistoricalInstallationDate;
use App\Services\ClientMigrationMatcher;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ClientMigrationController extends Controller
{
    private const HEADERS = [
        'Name', 'Address', 'Installation Date', 'Due Date',
        'Previous balance', 'Current balance', 'Leases', 'Promo rates (Mbps)',
    ];

    public function template()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->fromArray([
            'Example Client', 'Barangay, Municipality', '2026-08-01', '2026-09-01',
            0, 800, 'AA:BB:CC:DD:EE:FF', 50,
        ], null, 'A2');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        }, 'solarnet-client-migration-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function preview(Request $request, ClientMigrationMatcher $matcher, HistoricalInstallationDate $historicalDate)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $file = $request->file('file');
        $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        $headers = array_map('trim', array_shift($rows) ?? []);

        if (! in_array('Leases', $headers, true)) {
            return response()->json([
                'message' => 'The spreadsheet must include a Leases column containing the client MAC address. All other migration columns are optional.',
            ], 422);
        }

        $preview = [];
        foreach ($rows as $index => $row) {
            if (! array_filter($row, static fn ($value) => $value !== null && $value !== '')) {
                continue;
            }

            $record = array_replace(
                array_fill_keys(self::HEADERS, null),
                array_combine($headers, array_pad($row, count($headers), null)),
            );
            $rawInstallationDate = $record['Installation Date'];
            $record['Installation Date'] = $historicalDate->parse($rawInstallationDate);
            $record['Due Date'] = $historicalDate->parse($record['Due Date']);
            $record['Previous balance'] = $this->normaliseAmount($record['Previous balance']);
            $record['Current balance'] = $this->normaliseAmount($record['Current balance']);
            $match = $matcher->find((string) $record['Leases']);
            $existingCustomer = $match['lease']?->customer;
            $plan = $this->findPlan((string) $record['Promo rates (Mbps)'])
                ?? $this->findPlanByLeaseRate($match['lease']?->rate_limit);
            $exclusionReasons = [];
            if ($match['status'] !== 'EXACT MAC MATCH') $exclusionReasons[] = 'A full exact DHCP lease MAC match is required.';
            if (! $record['Installation Date']) $exclusionReasons[] = 'Historical Installation Date is missing or invalid. Requires review; migration will not assign today.';

            $preview[] = [
                'row' => $index + 2,
                'record' => $record,
                'match_status' => $match['status'],
                'lease_id' => $match['lease']?->id,
                'candidate_lease_ids' => $match['candidates']->pluck('id')->values(),
                'requires_confirmation' => $match['requires_confirmation'] ?? false,
                'registration_eligible' => $exclusionReasons === [] && ! $existingCustomer,
                'update_eligible' => $exclusionReasons === [] && (bool) $existingCustomer,
                'exclusion_reasons' => $exclusionReasons,
                'historical_installation_date_valid' => (bool) $record['Installation Date'],
                'raw_installation_date' => $rawInstallationDate,
                'action' => $exclusionReasons !== [] ? 'REQUIRES REVIEW' : ($existingCustomer ? 'UPDATE' : 'REGISTER'),
                'existing_customer' => $existingCustomer ? [
                    'id' => $existingCustomer->id,
                    'account_number' => $existingCustomer->account_number,
                    'installation_date' => $existingCustomer->installation_date?->toDateString(),
                ] : null,
                'service_plan' => $plan ? [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'price' => $plan->price,
                    'download_speed' => $plan->download_speed,
                    'upload_speed' => $plan->upload_speed,
                ] : null,
            ];
        }

        $audit = ClientMigrationAudit::create([
            'user_id' => $request->user()->id,
            'filename' => $file->getClientOriginalName(),
            'total_rows' => count($preview),
            'preview' => $preview,
            'summary' => ['preview_only' => true],
        ]);

        return response()->json(['audit_id' => $audit->id, 'rows' => $preview]);
    }

    /** Apply spreadsheet-supplied profile details to an already matched customer. */
    public function updateExisting(Request $request, Customer $customer, HistoricalInstallationDate $historicalDate)
    {
        $validated = $request->validate([
            'full_name' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'installation_date' => 'required',
            'due_date' => 'nullable',
            'service_plan_id' => 'nullable|exists:service_plans,id',
            'monthly_fee' => 'nullable|numeric|min:0',
            'lease_id' => 'required|exists:dhcp_leases,id',
            'audit_id' => 'required|exists:client_migration_audits,id',
        ]);

        $installationDate = $historicalDate->parse($validated['installation_date']);
        if (! $installationDate) {
            return response()->json(['message' => 'Historical Installation Date is missing or invalid. The migration did not change this customer.'], 422);
        }

        $lease = DhcpLease::query()->whereKey($validated['lease_id'])->where('is_current', true)->firstOrFail();
        if ($lease->customer_id !== $customer->id) {
            return response()->json(['message' => 'The selected current DHCP lease is not linked to this customer.'], 422);
        }
        $audit = ClientMigrationAudit::query()->whereKey($validated['audit_id'])->where('user_id', $request->user()->id)->firstOrFail();

        $updates = [];
        foreach (['full_name', 'address', 'service_plan_id', 'monthly_fee'] as $field) {
            if ($request->filled($field)) {
                $updates[$field] = $validated[$field];
            }
        }
        // Excel is authoritative for historical migration dates, even when different from created_at.
        $updates['installation_date'] = $installationDate;
        $migrationDueDate = $historicalDate->parse($validated['due_date'] ?? null);
        if ($migrationDueDate) {
            $updates['billing_cycle_day'] = \Carbon\Carbon::parse($migrationDueDate)->day;
        }

        $oldInstallationDate = $customer->installation_date?->toDateString();

        $customer->update($updates);
        if ($oldInstallationDate !== $installationDate) {
            ClientMigrationDateCorrection::create([
                'client_migration_audit_id' => $audit->id,
                'customer_id' => $customer->id,
                'user_id' => $request->user()->id,
                'customer_name' => $customer->full_name,
                'old_installation_date' => $oldInstallationDate,
                'new_installation_date' => $installationDate,
                'source' => 'Excel migration',
            ]);
        }

        return response()->json([
            'message' => 'Imported client details updated.',
            'customer' => $customer->fresh(['servicePlan']),
        ]);
    }

    private function findPlan(string $promoRate): ?ServicePlan
    {
        $rate = (int) preg_replace('/\D+/', '', $promoRate);
        if ($rate <= 0) {
            return null;
        }

        return ServicePlan::query()
            ->where('is_active', true)
            ->where('download_speed', $rate)
            ->orderByDesc('upload_speed')
            ->first();
    }

    private function findPlanByLeaseRate(?string $rateLimit): ?ServicePlan
    {
        if (! $rateLimit || ! preg_match('/^([\d.]+)\s*([KMGkmg]?)\s*\//', $rateLimit, $match)) return null;
        $speed = (float) $match[1];
        $unit = strtoupper($match[2]);
        if ($unit === 'K') $speed /= 1000;
        if ($unit === 'G') $speed *= 1000;
        return ServicePlan::query()->where('is_active', true)->where('download_speed', round($speed))->orderByDesc('upload_speed')->first();
    }

    private function normaliseAmount(mixed $value): float
    {
        return max(0, (float) preg_replace('/[^0-9.\-]/', '', (string) $value));
    }
}
