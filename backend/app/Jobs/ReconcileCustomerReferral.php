<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CustomerReferralService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileCustomerReferral implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $customerId) {}

    public function handle(CustomerReferralService $service): void
    {
        $customer = Customer::find($this->customerId);
        if ($customer) $service->qualifyFor($customer);
    }
}
