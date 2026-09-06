<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\CustomerReferral;
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
                CustomerCredit::firstOrCreate(
                    ['customer_id' => $customer->id, 'payment_id' => null, 'notes' => 'Referral bonus '.$locked->id],
                    ['original_amount' => self::REWARD_AMOUNT, 'remaining_amount' => self::REWARD_AMOUNT, 'status' => 'unallocated'],
                );
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
}
