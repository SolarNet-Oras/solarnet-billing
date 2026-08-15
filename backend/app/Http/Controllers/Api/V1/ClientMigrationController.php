<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientMigrationAudit;
use App\Models\ServicePlan;
use App\Services\ClientMigrationMatcher;
use Illuminate\Http\Request;
use Carbon\Carbon;
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

    public function preview(Request $request, ClientMigrationMatcher $matcher)
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
            $record['Installation Date'] = $this->normaliseDate($record['Installation Date']);
            $record['Due Date'] = $this->normaliseDate($record['Due Date']);
            $record['Previous balance'] = $this->normaliseAmount($record['Previous balance']);
            $record['Current balance'] = $this->normaliseAmount($record['Current balance']);
            $match = $matcher->find((string) $record['Leases']);
            $plan = $this->findPlan((string) $record['Promo rates (Mbps)'])
                ?? $this->findPlanByLeaseRate($match['lease']?->rate_limit);
            $exclusionReasons = [];
            if ($match['status'] !== 'EXACT MAC MATCH') $exclusionReasons[] = 'A full exact DHCP lease MAC match is required.';

            $preview[] = [
                'row' => $index + 2,
                'record' => $record,
                'match_status' => $match['status'],
                'lease_id' => $match['lease']?->id,
                'candidate_lease_ids' => $match['candidates']->pluck('id')->values(),
                'requires_confirmation' => $match['requires_confirmation'] ?? false,
                'registration_eligible' => $exclusionReasons === [],
                'exclusion_reasons' => $exclusionReasons,
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

    private function normaliseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        try {
            if (is_numeric($value)) return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) { return null; }
    }

    private function normaliseAmount(mixed $value): float
    {
        return max(0, (float) preg_replace('/[^0-9.\-]/', '', (string) $value));
    }
}
