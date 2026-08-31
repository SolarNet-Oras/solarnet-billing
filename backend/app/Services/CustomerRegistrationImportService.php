<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ServicePlan;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class CustomerRegistrationImportService
{
    private const IMPORT_INSTALLATION_DATE = '2026-08-01';

    private const HEADERS = [
        'installation_date' => ['installation date', 'installed date'],
        'name' => ['client name', 'customer name', 'full name', 'name'],
        'promo' => ['promo', 'promo rate', 'service plan', 'plan'],
        'due_date' => ['due date', 'billing due date', 'due day', 'billing cycle day'],
        'address' => ['address', 'home address', 'service address'],
        'mac_address' => ['mac address', 'mac'],
        'phone_number' => ['phone number', 'contact number', 'phone', 'mobile number'],
        'email' => ['gmail', 'email', 'email address'],
        'customer_status' => ['status', 'customer status'],
    ];

    public function previewUploadedFile(UploadedFile $file): array
    {
        return $this->previewPath($file->getRealPath(), $file->getClientOriginalName() ?: 'uploaded spreadsheet');
    }

    public function previewGoogleSheet(string $url): array
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host !== 'docs.google.com' || preg_match('#^/spreadsheets/d/([A-Za-z0-9_-]+)(?:/|$)#', $path, $matches) !== 1) {
            throw new InvalidArgumentException('Enter a Google Sheets URL beginning with https://docs.google.com/spreadsheets/d/...');
        }
        $response = Http::timeout(30)->get("https://docs.google.com/spreadsheets/d/{$matches[1]}/export", ['format' => 'xlsx']);
        if (! $response->successful() || $response->body() === '' || strlen($response->body()) > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Google could not export this sheet. Set sharing to “Anyone with the link can view” and keep it below 10 MB.');
        }
        $base = tempnam(sys_get_temp_dir(), 'customer-register-');
        if ($base === false) throw new InvalidArgumentException('The server could not prepare the Google Sheet preview.');
        $temporary = $base.'.xlsx';
        @unlink($base);
        try {
            file_put_contents($temporary, $response->body());
            return $this->previewPath($temporary, "Google Sheet {$matches[1]}");
        } finally { @unlink($temporary); }
    }

    private function previewPath(string $path, string $sourceLabel): array
    {
        try {
            $sheetRows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
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
        $existingMacs = Customer::withTrashed()->whereNotNull('mac_address')->pluck('mac_address')->map(fn ($mac) => $this->normalizeMac($mac))->filter()->flip();
        $seen = [];
        $seenMacs = [];
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
            // This controlled historical onboarding batch uses one agreed
            // installation date. The sheet column remains present as a clear
            // audit/reference field, but cannot silently change the batch date.
            $installationDate = self::IMPORT_INSTALLATION_DATE;
            $dueDay = $this->strictDueDayFromCell($record['due_date']);
            $address = trim((string) $record['address']);
            $promo = trim((string) $record['promo']);
            $plan = $this->resolvePlan($promo, $plans);
            $macInput = trim((string) $record['mac_address']);
            $mac = $macInput === '' ? null : $this->normalizeMac($macInput);
            $phone = trim((string) $record['phone_number']);
            $email = trim((string) $record['email']);
            $customerStatus = strtolower(trim((string) $record['customer_status'])) ?: 'active';
            $status = 'ready';
            $reason = $mac ? 'New customer profile and supplied MAC are ready to register.' : 'New customer profile is ready; MAC can be linked later.';

            if ($normalized === '' || $address === '' || ! $dueDay || ! $plan) {
                $status = 'invalid';
                $reason = ! $plan && $promo !== ''
                    ? 'Promo does not uniquely match an active service plan. Use the exact plan name or its Mbps.'
                    : 'Name, Promo, Address, and a Due Date day from 1 through 31 are required. Installation Date is fixed to August 1, 2026.';
            } elseif ($macInput !== '' && ! $mac) {
                $status = 'invalid';
                $reason = 'MAC Address is optional, but when supplied it must contain exactly 12 hexadecimal characters.';
            } elseif ($mac && isset($existingMacs[$mac])) {
                $status = 'invalid';
                $reason = 'This MAC address is already assigned to another customer.';
            } elseif ($mac && isset($seenMacs[$mac])) {
                $status = 'invalid';
                $reason = 'This MAC address appears more than once in the spreadsheet.';
            } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $status = 'invalid';
                $reason = 'Gmail/email is optional, but the supplied value is not a valid email address.';
            } elseif ($phone !== '' && preg_match('/^[0-9+() .-]{7,25}$/', $phone) !== 1) {
                $status = 'invalid';
                $reason = 'Phone Number is optional, but the supplied value has an invalid format.';
            } elseif (! in_array($customerStatus, ['active', 'pending', 'suspended', 'expired'], true)) {
                $status = 'invalid';
                $reason = 'Status must be Active, Pending, Suspended, or Expired. A blank status defaults to Active.';
            } elseif (isset($existing[$normalized])) {
                $status = 'existing';
                $reason = 'A customer with this normalized name already exists. It will not be registered again.';
            } elseif (isset($seen[$normalized])) {
                $status = 'duplicate_in_file';
                $reason = 'This name appears more than once in the spreadsheet. Only the first valid row can be registered.';
            }

            if ($normalized !== '' && $status !== 'invalid') {
                $seen[$normalized] = true;
                if ($mac) $seenMacs[$mac] = true;
            }
            $summary[$status]++;
            $rows[] = [
                'row' => $index + 2, 'status' => $status, 'reason' => $reason,
                'client_name' => $name, 'address' => $address,
                'installation_date' => $installationDate, 'due_day' => $dueDay,
                'promo' => $promo, 'service_plan_id' => $plan?->id,
                'service_plan_name' => $plan?->name, 'monthly_fee' => $plan?->price,
                'mac_address' => $mac, 'phone_number' => $phone, 'email' => $email,
                'customer_status' => $customerStatus,
                'normalized_name' => $normalized,
            ];
        }

        return ['source_label' => $sourceLabel, 'rows' => $rows, 'summary' => $summary];
    }

    public function apply(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $created = 0;
            $skipped = [];
            $known = Customer::withTrashed()->lockForUpdate()->pluck('full_name')->map(fn ($name) => $this->normalizeName($name))->filter()->flip();
            $knownMacs = Customer::withTrashed()->whereNotNull('mac_address')->pluck('mac_address')->map(fn ($mac) => $this->normalizeMac($mac))->filter()->flip();

            foreach ($rows as $row) {
                if (($row['status'] ?? null) !== 'ready') continue;
                $normalized = $this->normalizeName($row['client_name'] ?? '');
                $mac = $this->normalizeMac($row['mac_address'] ?? null);
                $plan = ServicePlan::query()->whereKey($row['service_plan_id'] ?? null)->where('is_active', true)->first();
                if ($normalized === '' || isset($known[$normalized]) || ($mac && isset($knownMacs[$mac])) || ! $plan) {
                    $skipped[] = "Row {$row['row']}: name or MAC now exists, or the service plan is unavailable.";
                    continue;
                }

                Customer::create([
                    'account_number' => $this->uniqueAccountNumber(),
                    'full_name' => trim((string) $row['client_name']),
                    'address' => trim((string) $row['address']),
                    'contact_number' => trim((string) ($row['phone_number'] ?? '')) ?: 'N/A',
                    'email' => trim((string) ($row['email'] ?? '')) ?: null,
                    'installation_date' => $row['installation_date'],
                    'billing_cycle_day' => (int) $row['due_day'],
                    'service_plan_id' => $plan->id,
                    'monthly_fee' => $plan->price,
                    'mac_address' => $mac,
                    'status' => $row['customer_status'] ?? 'active',
                    'mac_binding_status' => 'waiting_for_match',
                    'notes' => 'Profile imported from customer registration spreadsheet; network identity pending.',
                ]);
                $known[$normalized] = true;
                if ($mac) $knownMacs[$mac] = true;
                $created++;
            }
            return compact('created', 'skipped');
        });
    }

    public function normalizeName(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii(trim((string) $value)))) ?: '';
    }

    public function normalizeMac(?string $value): ?string
    {
        $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string) $value) ?? '');
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
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

    public function strictDueDayFromCell(mixed $value): ?int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{1,2}$/', $text) !== 1) return null;

        $day = (int) $text;
        return $day >= 1 && $day <= 31 ? $day : null;
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
        if (count($columns) !== count(self::HEADERS)) throw new InvalidArgumentException('Required headers: Installation Date, Name, Promo, Due Date, Address, MAC Address, Phone Number, Gmail, Status. MAC, phone, Gmail, and status cells may be blank.');
        return $columns;
    }

    private function uniqueAccountNumber(): string
    {
        do { $number = (string) random_int(1000000000, 9999999999); }
        while (Customer::withTrashed()->where('account_number', $number)->exists());
        return $number;
    }
}
