<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->unique(['payment_id', 'invoice_id']);
            $table->index(['invoice_id', 'created_at']);
        });

        // Preserve every historical assignment exactly as recorded. The legacy
        // payments.invoice_id column remains during the compatibility period,
        // but new balance calculations use this explicit ledger relationship.
        DB::table('payments')
            ->whereNotNull('invoice_id')
            ->orderBy('created_at')
            ->chunkById(250, function ($payments): void {
                $now = now();
                $rows = $payments->map(fn ($payment) => [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'payment_id' => $payment->id,
                    'invoice_id' => $payment->invoice_id,
                    'amount' => $payment->amount,
                    'created_at' => $payment->created_at ?? $now,
                    'updated_at' => $payment->updated_at ?? $now,
                ])->all();
                if ($rows !== []) {
                    DB::table('payment_allocations')->insertOrIgnore($rows);
                }
            }, 'id');

        // Older fully funded advance cycles were marked consumed before an
        // invoice existed. Re-open only rows for which no matching recurring
        // invoice exists, allowing the invoice to own the charge and receive
        // an explicit allocation when its cycle is generated.
        DB::table('customer_credits')
            ->whereNotNull('covered_cycle_date')
            ->where('remaining_amount', '<=', 0)
            ->whereIn('status', ['fully_applied', 'covered'])
            ->orderBy('id')
            ->chunkById(250, function ($credits): void {
                foreach ($credits as $credit) {
                    $invoiceExists = DB::table('invoices')
                        ->where('customer_id', $credit->customer_id)
                        ->whereDate('recurring_cycle_date', $credit->covered_cycle_date)
                        ->exists();
                    if (! $invoiceExists) {
                        DB::table('customer_credits')->where('id', $credit->id)->update([
                            'remaining_amount' => $credit->original_amount,
                            'status' => 'advance',
                            'applied_at' => null,
                            'updated_at' => now(),
                        ]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
