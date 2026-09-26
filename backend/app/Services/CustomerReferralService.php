<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\CustomerReferral;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerReferralService
{
    public const REWARD_AMOUNT = 200.00;

    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    public function qualifyFor(Customer $customer): void
    {
        if ($customer->status !== 'active') return;
        $phone = self::normalizePhone($customer->contact_number);
        $email = strtolower(trim((string) $customer->email));
        if ($phone === '' && $email === '') return;

        $referrals = CustomerReferral::query()->where('status', 'submitted')
            ->where(function ($query) use ($phone, $email) {
                if ($phone !== '') $query->orWhere('phone_normalized', $phone);
                if ($email !== '') $query->orWhere('email_normalized', $email);
            })->get();

        foreach ($referrals as $referral) {
            if ($referral->referrer_customer_id === $customer->id) continue;
            $qualified = DB::transaction(function () use ($referral, $customer) {
                $locked = CustomerReferral::query()->lockForUpdate()->find($referral->id);
                if (!$locked || $locked->status !== 'submitted') return null;
                $locked->update(['referred_customer_id' => $customer->id, 'status' => 'qualified', 'qualified_at' => now()]);
                return $locked->fresh('referrer');
            });
            if ($qualified?->referrer) {
                app(CustomerWebPushNotificationService::class)->sendReferralQualified($qualified->referrer, $qualified);
            }
        }
    }

    public function chooseReward(Customer $customer, CustomerReferral $referral, string $choice): CustomerReferral
    {
        if (!in_array($choice, ['cash', 'billing_credit'], true)) {
            throw ValidationException::withMessages(['reward_choice' => 'Choose cash or future billing credit.']);
        }

        return DB::transaction(function () use ($customer, $referral, $choice) {
            $locked = CustomerReferral::query()->lockForUpdate()->whereKey($referral->id)
                ->where('referrer_customer_id', $customer->id)->firstOrFail();
            if ($locked->status !== 'qualified' || $locked->reward_choice !== null) {
                throw ValidationException::withMessages(['reward_choice' => 'This referral reward was already selected or is not yet qualified.']);
            }

            $status = $choice === 'billing_credit' ? 'rewarded' : 'cash_claim_requested';
            if ($choice === 'billing_credit') {
                $credit = CustomerCredit::firstOrCreate(
                    ['customer_id' => $customer->id, 'payment_id' => null, 'notes' => 'Referral bonus '.$locked->id],
                    ['original_amount' => self::REWARD_AMOUNT, 'remaining_amount' => self::REWARD_AMOUNT, 'status' => 'unallocated'],
                );
                $this->applyReferralCreditToOpenInvoices($customer, $credit);
            }
            $locked->update([
                'reward_choice' => $choice,
                'status' => $status,
                'choice_at' => now(),
                'rewarded_at' => $choice === 'billing_credit' ? now() : null,
            ]);
            return $locked->fresh();
        });
    }

    public function payCashReward(CustomerReferral $referral, User $actor, array $cashBreakdown, string $reference): CustomerReferral
    {
        return DB::transaction(function () use ($referral, $actor, $cashBreakdown, $reference): CustomerReferral {
            $locked = CustomerReferral::query()->lockForUpdate()->findOrFail($referral->id);
            if ($locked->status !== 'cash_claim_requested' || $locked->reward_choice !== 'cash') {
                throw ValidationException::withMessages(['referral' => 'Only a pending cash referral claim can be released.']);
            }

            $breakdown = app(CashDenominationService::class)->normalize($cashBreakdown);
            app(CashDenominationService::class)->assertEqualsAmount($breakdown, $locked->reward_amount);

            $entry = FinancialEntry::firstOrCreate(
                ['idempotency_key' => $locked->id],
                [
                    'type' => 'expense',
                    'category' => 'Referral Reward',
                    'description' => 'Cash referral reward for '.$locked->prospect_name,
                    'amount' => $locked->reward_amount,
                    'cash_breakdown' => $breakdown,
                    'entry_date' => now()->toDateString(),
                    'payment_method' => 'cash',
                    'effect_type' => 'expense',
                    'source_wallet' => 'cash',
                    'destination_wallet' => null,
                    'reference' => $reference,
                    'notes' => 'Referral '.$locked->id.'; referrer customer '.$locked->referrer_customer_id,
                    'recorded_by' => $actor->id,
                ],
            );

            $locked->update([
                'status' => 'rewarded',
                'rewarded_at' => now(),
                'cash_paid_at' => now(),
                'cash_paid_by' => $actor->id,
                'cash_financial_entry_id' => $entry->id,
                'cash_payout_reference' => $reference,
            ]);

            return $locked->fresh(['referrer:id,full_name,account_number', 'cashPayer:id,name', 'cashFinancialEntry']);
        });
    }

    private function applyReferralCreditToOpenInvoices(Customer $customer, CustomerCredit $credit): void
    {
        $remaining = round((float) $credit->remaining_amount, 2);
        if ($remaining <= 0) return;

        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) break;
            $applied = min($remaining, (float) $invoice->balance);
            $invoice->discount = round((float) $invoice->discount + $applied, 2);
            $invoice->save();
            app(InvoiceService::class)->calculateInvoiceTotals($invoice->fresh());
            app(InvoiceService::class)->reconcileInvoiceFromAllocations($invoice->fresh());
            $remaining = round($remaining - $applied, 2);
        }

        $credit->update([
            'remaining_amount' => $remaining,
            'status' => $remaining <= 0 ? 'fully_applied' : ($remaining < (float) $credit->original_amount ? 'partially_applied' : 'unallocated'),
            'applied_at' => $remaining <= 0 ? now() : null,
        ]);
    }
}
