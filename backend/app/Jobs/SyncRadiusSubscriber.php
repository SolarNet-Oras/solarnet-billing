<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\User;
use App\Services\RadiusSubscriberService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Asynchronous local policy staging; it never connects to RouterOS or RADIUS. */
class SyncRadiusSubscriber implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public string $customerId,
        public string $reason = 'customer_changed',
        public ?string $actorId = null,
    ) {}

    public function handle(RadiusSubscriberService $radius): void
    {
        $customer = Customer::find($this->customerId);
        if (!$customer) return;
        $radius->syncForCustomer($customer, $this->reason, $this->actorId ? User::find($this->actorId) : null);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('RADIUS subscriber policy staging failed', [
            'customer_id' => $this->customerId,
            'error_type' => $exception::class,
        ]);
    }
}
