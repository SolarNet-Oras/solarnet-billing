<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ServicePlan;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class CustomerRegistrationImportService
{
    private const HEADERS = [
        'installation_date' => ['installation date', 'installed date'],
        'name' => ['client name', 'customer name', 'full name', 'name'],
        'address' => ['address', 'home address', 'service address'],
        'due_date' => ['due date', 'billing due date', 'due day', 'billing cycle day'],
        'promo' => ['promo', 'promo rate', 'service plan', 'plan'],
    ];

    public function previewUploadedFile(UploadedFile $file): array
    {
        try {
            $sheetRows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable) {
            throw new InvalidArgumentException('SolarNet could not read this file. Upload a valid XLSX, XLS, or CSV spreadsheet.');
        }

        if ($sheetRows === []) {
            throw new InvalidArgumentException('The spreadsheet is empty.');
        }
        $columns = $this->mapColumns(array_shift($sheetRows) ?? []);
        if (count($sheetRows) > 2000) {
            throw new InvalidArgumentException('This spreadsheet exceeds 2,000 rows. Split it into smaller files for safe review.');
        }

        $plans = ServicePlan::query()->where('is_active', true)->get();
        $existing = Customer::withTrashed()->pluck('full_name')->map(fn ($name) => $this->normalizeName($name))->filter()->flip();
        $seen = [];
        $rows = [];
        $summary = ['total' => 0, 'ready' => 0, 'existing' => 0, 'duplicate_in_file' => 0, 'invalid' => 0];

        foreach ($sheetRows as $index => $values) {
            $record = [];
            foreach ($columns as $field => $column) {
                $record[$field] = $values[$column] ?? null;
            }
            if (collect($record)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $summary['total']++;
            $name = trim((string) $record['name']);
            $normalized = $this->normalizeName($name);
            $installationDate = $this->dateFromCell($record['installation_date']);
            $dueDay = $this->dueDayFromCell($record['due_date']);
            $address = trim((string) $record['address']);
            $promo = trim((string) $record['promo']);
            $plan = $this->resolvePlan($promo, $plans);
            $status = 'ready';
            $reason = 'New customer profile is ready to register without MAC or IP information.';

            if ($normalized === '' || $address === '' || ! $installationDate || ! $dueDay || ! $plan) {
                $status = 'invalid';
                $reason = ! $plan && $promo !== ''
                    ? 'Promo does not uniquely match an active service plan. Use the exact plan name or its Mbps.'
                    : 'Installation Date, Client Name, Address, Due Date, and Promo are required and must be valid.';
            } elseif (isset($existing[$normalized])) {
                $status = 'existing';
                $reason = 'A customer with this normalized name already exists. It will not be registered again.';
            } elseif (isset($seen[$normalized])) {
                $status = 'duplicate_in_file';
                $reason = 'This name appears more than once in the spreadsheet. Only the first valid row can be registered.';
            }

            if ($normalized !== '' && $status !== 'invalid') {
                $seen[$normalized] = true;
            }
            $summary[$status]++;
            $rows[] = [
                'row' => $index + 2, 'status' => $status, 'reason' => $reason,
                'client_name' => $name, 'address' => $address,
                'installation_date' => $installationDate, 'due_day' => $dueDay,
                'promo' => $promo, 'service_plan_id' => $plan?->id,
                'service_plan_name' => $plan?->name, 'monthly_fee' => $plan?->price,
                'normalized_name' => $normalized,
            ];
        }

        return ['source_label' => $file->getClientOriginalName() ?: 'uploaded spreadsheet', 'rows' => $rows, 'summary' => $summary];
    }

    public function apply(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $created = 0;
            $skipped = [];
            $known = Customer::withTrashed()->lockForUpdate()->pluck('full_name')->map(fn ($name) => $this->normalizeName($name))->filter()->flip();

            foreach ($rows as $row) {
                if (($row['status'] ?? null) !== 'ready') continue;
                $normalized = $this->normalizeName($row['client_name'] ?? '');
                $plan = ServicePlan::query()->whereKey($row['service_plan_id'] ?? null)->where('is_active', true)->first();
                if ($normalized === '' || isset($known[$normalized]) || ! $plan) {
                    $skipped[] = "Row {$row['row']}: name now exists or service plan is unavailable.";
                    continue;
                }

                Customer::create([
                    'account_number' => $this->uniqueAccountNumber(),
                    'full_name' => trim((string) $row['client_name']),
                    'address' => trim((string) $row['address']),
                    'contact_number' => 'N/A',
                    'installation_date' => $row['installation_date'],
                    'billing_cycle_day' => (int) $row['due_day'],
                    'service_plan_id' => $plan->id,
                    'monthly_fee' => $plan->price,
                    'status' => 'active',
                    'mac_binding_status' => 'waiting_for_match',
                    'notes' => 'Profile imported from customer registration spreadsheet; network identity pending.',
                ]);
                $known[$normalized] = true;
                $created++;
            }
            return compact('created', 'skipped');
        });
    }

    public function normalizeName(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii(trim((string) $value)))) ?: '';
    }

    public function dateFromCell(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        $text = trim((string) $value);
        if ($text === '') return null;
        try {
            if (is_numeric($value) && (float) $value >= 25569) return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            return Carbon::parse($text)->format('Y-m-d');
        } catch (Throwable) { return null; }
    }

    public function dueDayFromCell(mixed $value): ?int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{1,2}$/', $text) && (int) $text >= 1 && (int) $text <= 31) return (int) $text;
        $date = $this->dateFromCell($value);
        return $date ? (int) Carbon::parse($date)->day : null;
    }

    private function resolvePlan(string $promo, $plans): ?ServicePlan
    {
        if ($promo === '') return null;
        $normalized = $this->normalizeName($promo);
        $exact = $plans->filter(fn (ServicePlan $plan) => $this->normalizeName($plan->name) === $normalized);
        if ($exact->count() === 1) return $exact->first();
        if (preg_match('/(\d+)/', $promo, $match)) {
            $speed = (int) $match[1];
            $speedMatches = $plans->where('download_speed', $speed)->values();
            if ($speedMatches->count() === 1) return $speedMatches->first();
        }
        return null;
    }

    private function mapColumns(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $index => $header) $normalized[$this->normalizeName($header)] = $index;
        $columns = [];
        foreach (self::HEADERS as $field => $aliases) {
            foreach ($aliases as $alias) if (array_key_exists($this->normalizeName($alias), $normalized)) { $columns[$field] = $normalized[$this->normalizeName($alias)]; break; }
        }
        if (count($columns) !== count(self::HEADERS)) throw new InvalidArgumentException('Required headers: Installation Date, Client Name, Address, Due Date, Promo.');
        return $columns;
    }

    private function uniqueAccountNumber(): string
    {
        do { $number = (string) random_int(1000000000, 9999999999); }
        while (Customer::withTrashed()->where('account_number', $number)->exists());
        return $number;
    }
}
