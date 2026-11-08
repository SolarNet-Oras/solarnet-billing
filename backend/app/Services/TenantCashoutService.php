<?php

namespace App\Services;

use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantCashoutRequest;

class TenantCashoutService
{
    public function position(Tenant $tenant): array
    {
        $net = Payment::forTenant($tenant)->with('paymongoCheckout:id,payment_id,provider_fee,settlement_status,status')
            ->whereHas('paymongoCheckout', fn ($query) => $query->where('status', 'paid'))->get()
            ->sum(fn ($payment) => (float)$payment->amount - ($payment->paymongoCheckout?->settlement_status === 'provider_confirmed' ? (float)$payment->paymongoCheckout->provider_fee : 0));
        $sent = FinancialEntry::forTenant($tenant)->where('effect_type','transfer')->where('source_wallet','paymongo')->where('destination_wallet','gcash')->sum('amount');
        $pending = TenantCashoutRequest::query()->where('tenant_id',$tenant->id)->where('status','pending')->sum('amount');
        return ['paymongo_net_ledger'=>round($net-$sent,2),'pending_requests'=>round((float)$pending,2),'available_to_request'=>max(0,round($net-$sent-$pending,2))];
    }
}
