<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Remittance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OfficeCashLiquidationService
{
    /**
     * Put a direct office cash receipt into the same counted-cash workflow as
     * field collections. One receipt gets one remittance so it cannot be
     * accidentally mixed with another cashier's till.
     */
    public function submit(Payment $payment, User $officeUser): ?Remittance
    {
        if ($payment->payment_method !== 'cash'
            || ! $officeUser->hasAnyRole(['super_admin', 'admin', 'cashier', 'office_admin'])) {
            return null;
        }

        return DB::transaction(function () use ($payment, $officeUser) {
            $payments = Payment::query()
                ->whereNull('remittance_id')
                ->where('payment_method', 'cash')
                ->where(function ($query) use ($payment) {
                    $query->whereKey($payment->id)
                        ->orWhere('transaction_id', 'ADV-CHANGE-' . $payment->id);
                })
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                return $payment->remittance;
            }

            $remittance = Remittance::create([
                'collector_id' => $officeUser->id,
                'declared_amount' => round((float) $payments->sum('amount'), 2),
                'status' => 'submitted',
                'notes' => 'Direct office cash receipt awaiting independent cash liquidation.',
                'submitted_at' => now(),
            ]);

            Payment::query()->whereKey($payments->pluck('id'))->update([
                'collector_id' => $officeUser->id,
                'remittance_id' => $remittance->id,
            ]);

            return $remittance->load('payments');
        });
    }
}
