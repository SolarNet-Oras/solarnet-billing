<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reconciles legacy pending self-signups created before duplicate protection. */
class MergePendingCustomerSignups extends Command
{
    protected $signature = 'customers:merge-pending-signups {--apply : Apply the safe merges; without it this is a preview}';
    protected $description = 'Merge pending self-signups into a uniquely matching existing customer with no email';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $pending = Customer::where('status', 'pending')->get();
        $merged = 0;

        foreach ($pending as $signup) {
            $matches = Customer::query()
                ->where('id', '!=', $signup->id)
                ->where('status', '!=', 'pending')
                ->get()
                ->filter(fn (Customer $customer) => $this->normalize($customer->full_name) === $this->normalize($signup->full_name) && $this->placeholder($customer->email))
                ->values();

            if ($matches->count() !== 1) continue;
            $existing = $matches->first();
            $this->line("{$signup->full_name}: pending {$signup->account_number} -> existing {$existing->account_number}");

            if (!$apply) continue;
            DB::transaction(function () use ($signup, $existing) {
                $existing->forceFill([
                    'email' => $signup->email,
                    'contact_number' => $this->placeholder($existing->contact_number) ? $signup->contact_number : $existing->contact_number,
                    'address' => $this->placeholder($existing->address) ? $signup->address : $existing->address,
                    'notes' => trim((string) $existing->notes . "\nMerged self-signup application {$signup->account_number}."),
                ])->save();
                $signup->delete();
            });
            $merged++;
        }

        $this->info($apply ? "Merged {$merged} pending signup(s)." : 'Preview complete. Re-run with --apply to perform the listed merges.');
        return self::SUCCESS;
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value)) : strtolower(trim($value));
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    private function placeholder(?string $value): bool
    {
        return blank($value) || in_array(strtolower(trim((string) $value)), ['n/a', 'na', 'to be updated'], true);
    }
}
