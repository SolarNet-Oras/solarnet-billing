<?php

namespace App\Services;

use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Safely imports historical customer-profile corrections.
 *
 * This service deliberately has a small scope: it matches existing customer
 * records by an exact or safely unique name variation, then changes only name,
 * address, and the monthly billing cycle day. Network identity and financial
 * records stay untouched.
 */
class CustomerUpdateImportService
{
    private const HEADER_ALIASES = [
        'name' => ['client name', 'customer name', 'full name', 'name'],
        'address' => ['address', 'client address', 'service address'],
        'due_date' => ['due date', 'billing due date', 'monthly due date', 'billing cycle day', 'due day'],
    ];

    /** @return array{source_label:string,rows:array<int,array<string,mixed>>,summary:array<string,int>} */
    public function previewUploadedFile(UploadedFile $file): array
    {
        return $this->previewPath($file->getRealPath(), $file->getClientOriginalName() ?: 'uploaded spreadsheet');
    }

    /** @return array{source_label:string,rows:array<int,array<string,mixed>>,summary:array<string,int>} */
    public function previewGoogleSheet(string $url): array
    {
        $sheetId = $this->googleSheetId($url);
        $path = tempnam(sys_get_temp_dir(), 'solarnet-sheet-');
        if ($path === false) {
            throw new InvalidArgumentException('The server could not prepare the Google Sheet import.');
        }

        $xlsxPath = $path . '.xlsx';
        @unlink($path);

        try {
            $response = Http::accept('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->timeout(20)
                ->get("https://docs.google.com/spreadsheets/d/{$sheetId}/export", ['format' => 'xlsx']);

            if (! $response->successful()) {
                throw new InvalidArgumentException('Google could not export this sheet. Set sharing to "Anyone with the link can view", then try again.');
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > 10 * 1024 * 1024) {
                throw new InvalidArgumentException('Google Sheet export is empty or exceeds the 10 MB import limit.');
            }

            file_put_contents($xlsxPath, $body);

            return $this->previewPath($xlsxPath, "Google Sheet {$sheetId}");
        } finally {
            @unlink($xlsxPath);
        }
    }

    /**
     * Apply only previewed, unique matches. The customer is re-read and
     * rechecked inside the transaction so a later rename or status change
     * cannot update the wrong account.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{updated:int,unchanged:int,skipped:array<int,string>}
     */
    public function apply(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $updated = 0;
            $unchanged = 0;
            $skipped = [];

            foreach ($rows as $row) {
                if (($row['status'] ?? null) !== 'ready') {
                    continue;
                }

                $customer = Customer::query()->lockForUpdate()->find($row['customer_id'] ?? null);
                if (! $customer) {
                    $skipped[] = "Row {$row['row']}: customer no longer exists.";
                    continue;
                }
                if ($customer->status === 'pending') {
                    $skipped[] = "Row {$row['row']}: pending installation applications are not changed by this import.";
                    continue;
                }
                if ($this->normalizeCustomerName($customer->full_name) !== ($row['matched_customer_name'] ?? $row['match_name'] ?? null)) {
                    $skipped[] = "Row {$row['row']}: customer name changed after preview.";
                    continue;
                }

                $address = trim((string) ($row['address'] ?? ''));
                $dueDay = (int) ($row['due_day'] ?? 0);
                if ($address === '' || $dueDay < 1 || $dueDay > 31) {
                    $skipped[] = "Row {$row['row']}: imported address or due date is no longer valid.";
                    continue;
                }

                $changes = [];
                $importedName = trim((string) ($row['client_name'] ?? ''));
                if ($importedName !== '' && $customer->full_name !== $importedName) {
                    $changes['full_name'] = $importedName;
                }
                if ($customer->address !== $address) {
                    $changes['address'] = $address;
                }
                if ((int) $customer->billing_cycle_day !== $dueDay) {
                    $changes['billing_cycle_day'] = $dueDay;
                }

                if ($changes === []) {
                    $unchanged++;
                    continue;
                }

                // Intentionally no updates to MAC, DHCP, router, coordinates,
                // service plan, balance, invoice, or installation-date fields.
                $customer->update($changes);
                $updated++;
            }

            return compact('updated', 'unchanged', 'skipped');
        });
    }

    public function normalizeCustomerName(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii(trim((string) $value)))) ?: '';
    }

    /**
     * Accept a name extension or reordered full name only when both sides have
     * at least two meaningful name parts and the existing code-name tokens are
     * all contained in the imported name. For example, "Rueza Jade" safely
     * matches "Pormida Rueza Jade", without allowing a shorter sheet value to
     * erase a saved surname.
     */
    public function namesAreSafeVariation(string $existingName, string $importedName): bool
    {
        $existingTokens = $this->nameTokens($existingName);
        $importedTokens = $this->nameTokens($importedName);
        if (count($existingTokens) < 2 || count($importedTokens) < 2) {
            return false;
        }

        return $this->tokensAreSubset($existingTokens, $importedTokens);
    }

    public function dueDayFromCell(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return (int) $value->format('j');
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d+(?:\.0+)?$/', $text) === 1) {
            $number = (float) $text;
            if ($number >= 1 && $number <= 31) {
                return (int) $number;
            }
            // Current Excel dates are well beyond the Unix epoch serial. Do
            // not reinterpret a bad due day such as "32" as 1900-02-01.
            if ($number < 25569 || $number >= 100000) {
                return null;
            }

            try {
                return (int) ExcelDate::excelToDateTimeObject($number)->format('j');
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($text)->day;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{source_label:string,rows:array<int,array<string,mixed>>,summary:array<string,int>} */
    private function previewPath(string $path, string $sourceLabel): array
    {
        try {
            $sheetRows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable) {
            throw new InvalidArgumentException('SolarNet could not read this spreadsheet. Upload a valid XLSX, XLS, or CSV file.');
        }

        if ($sheetRows === []) {
            throw new InvalidArgumentException('The spreadsheet is empty.');
        }

        $headers = array_shift($sheetRows) ?? [];
        $columns = $this->mapColumns($headers);
        if (count($sheetRows) > 2000) {
            throw new InvalidArgumentException('This import has more than 2,000 rows. Split it into smaller files for safe review.');
        }

        $customers = Customer::query()
            ->select(['id', 'account_number', 'full_name', 'address', 'billing_cycle_day', 'status'])
            ->get();
        $customersByName = $customers->groupBy(fn (Customer $customer) => $this->normalizeCustomerName($customer->full_name));

        $rows = [];
        $summary = [
            'total' => 0,
            'ready' => 0,
            'unchanged' => 0,
            'no_match' => 0,
            'ambiguous' => 0,
            'pending' => 0,
            'invalid' => 0,
        ];

        foreach ($sheetRows as $index => $row) {
            $record = [
                'name' => trim((string) ($row[$columns['name']] ?? '')),
                'address' => trim((string) ($row[$columns['address']] ?? '')),
                'due_date' => trim((string) ($row[$columns['due_date']] ?? '')),
            ];
            if ($record['name'] === '' && $record['address'] === '' && $record['due_date'] === '') {
                continue;
            }

            $summary['total']++;
            $rowNumber = $index + 2;
            $dueDay = $this->dueDayFromCell($record['due_date']);
            $importedNormalizedName = $this->normalizeCustomerName($record['name']);
            $matches = $importedNormalizedName !== '' ? ($customersByName->get($importedNormalizedName, collect())) : collect();
            $matchType = 'exact';
            if ($matches->count() === 0 && $record['name'] !== '') {
                $matches = $customers->filter(fn (Customer $candidate) => $this->namesAreSafeVariation($candidate->full_name, $record['name']))->values();
                $matchType = 'name_variation';
            }
            $status = 'ready';
            $reason = $matchType === 'exact'
                ? 'Exact normalized name match. Full name, address, and monthly due day are ready for review.'
                : 'Unique safe name variation match. Full name, address, and monthly due day are ready for review.';
            $customer = null;

            if ($record['name'] === '' || $record['address'] === '' || $dueDay === null) {
                $status = 'invalid';
                $reason = 'Client Name, Address, and a valid Due Date are required on every imported row.';
                $summary['invalid']++;
            } elseif ($matches->count() === 0) {
                $status = 'no_match';
                $reason = 'No existing customer has this exact name or a safe two-part name variation.';
                $summary['no_match']++;
            } elseif ($matches->count() > 1) {
                $status = 'ambiguous';
                $reason = 'More than one existing customer has this normalized full name. Resolve it manually; SolarNet will not choose one.';
                $summary['ambiguous']++;
            } else {
                $customer = $matches->first();
                if ($customer->status === 'pending') {
                    $status = 'pending';
                    $reason = 'This is a pending installation application, so its customer profile is not changed by this import.';
                    $summary['pending']++;
                } else {
                    $sameName = $customer->full_name === $record['name'];
                    $sameAddress = $customer->address === $record['address'];
                    $sameDueDay = (int) $customer->billing_cycle_day === $dueDay;
                    if ($sameName && $sameAddress && $sameDueDay) {
                        $status = 'unchanged';
                        $reason = 'The saved full name, address, and monthly due day already match this row.';
                        $summary['unchanged']++;
                    } else {
                        $summary['ready']++;
                    }
                }
            }

            $rows[] = [
                'row' => $rowNumber,
                'status' => $status,
                'reason' => $reason,
                'client_name' => $record['name'],
                'address' => $record['address'],
                'due_date' => $record['due_date'],
                'due_day' => $dueDay,
                'match_name' => $importedNormalizedName,
                'matched_customer_name' => $customer ? $this->normalizeCustomerName($customer->full_name) : null,
                'matched_full_name' => $customer?->full_name,
                'match_type' => $customer ? $matchType : null,
                'customer_id' => $customer?->id,
                'account_number' => $customer?->account_number,
                'current_address' => $customer?->address,
                'current_due_day' => $customer?->billing_cycle_day,
            ];
        }

        return ['source_label' => $sourceLabel, 'rows' => $rows, 'summary' => $summary];
    }

    /** @param array<int,mixed> $headers @return array{name:int,address:int,due_date:int} */
    private function mapColumns(array $headers): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            $normalizedHeaders[$this->normalizeHeader((string) $header)] = $index;
        }

        $columns = [];
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $normalizedAlias = $this->normalizeHeader($alias);
                if (array_key_exists($normalizedAlias, $normalizedHeaders)) {
                    $columns[$field] = $normalizedHeaders[$normalizedAlias];
                    break;
                }
            }
        }

        if (count($columns) !== count(self::HEADER_ALIASES)) {
            throw new InvalidArgumentException('Required spreadsheet headers: Client Name, Address, Due Date. "Customer Name" and "Full Name" are also accepted for Client Name.');
        }

        return $columns;
    }

    private function normalizeHeader(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii(trim($value)))) ?: '';
    }

    /** @return array<int,string> */
    private function nameTokens(string $value): array
    {
        $ascii = strtolower(Str::ascii(trim($value)));
        $tokens = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($tokens, fn (string $token) => strlen($token) >= 2)));
    }

    /** @param array<int,string> $needles @param array<int,string> $haystack */
    private function tokensAreSubset(array $needles, array $haystack): bool
    {
        return count(array_diff($needles, $haystack)) === 0;
    }

    private function googleSheetId(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (! in_array($host, ['docs.google.com'], true)
            || preg_match('#^/spreadsheets/d/([A-Za-z0-9_-]+)(?:/|$)#', $path, $matches) !== 1) {
            throw new InvalidArgumentException('Enter a Google Sheets URL beginning with https://docs.google.com/spreadsheets/d/...');
        }

        return $matches[1];
    }
}
