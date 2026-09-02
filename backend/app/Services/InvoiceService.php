<?php

namespace App\Services;

use App\Jobs\SendInitialInvoiceEmail;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use App\Services\BillingSuspensionService;
use Carbon\Carbon;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\QueryException;

class InvoiceService
{
    /**
     * Creates a one-time opening balance from a verified client-migration row.
     * It deliberately has no recurring cycle, so normal monthly billing remains
     * anchored to the customer's configured billing anniversary.
     */
    public function createMigrationOpeningBalanceInvoice(Customer $customer, float $previousBalance, float $currentBalance, Carbon $installationDate, ?Carbon $dueDate): ?Invoice
    {
        $previousBalance = round(max(0, $previousBalance), 2);
        $currentBalance = round(max(0, $currentBalance), 2);
        $total = round($previousBalance + $currentBalance, 2);
        if ($total <= 0 || $customer->hasCompanyOwnedPlan()) return null;

        return DB::transaction(function () use ($customer, $previousBalance, $currentBalance, $total, $installationDate, $dueDate) {
            $existing = Invoice::query()->where('customer_id', $customer->id)
                ->where('notes', 'like', 'Migrated opening balance%')->first();
            if ($existing) return $existing;

            $invoice = Invoice::create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'customer_id' => $customer->id,
                'issue_date' => $installationDate,
                'due_date' => $dueDate ?? $installationDate,
                'billing_period_start' => $installationDate,
                'billing_period_end' => $dueDate ?? $installationDate,
                'subtotal' => $total,
                'tax' => 0,
                'discount' => 0,
                'total' => $total,
                'paid_amount' => 0,
                'balance' => $total,
                'status' => 'sent',
                'generation_source' => 'migration',
                'sent_at' => now(),
                'notes' => 'Migrated opening balance. Previous: '.number_format($previousBalance, 2, '.', '').'; Current: '.number_format($currentBalance, 2, '.', ''),
            ]);
            if ($previousBalance > 0) InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Migrated previous balance', 'quantity' => 1, 'unit_price' => $previousBalance, 'total' => $previousBalance]);
            if ($currentBalance > 0) InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Migrated current balance', 'quantity' => 1, 'unit_price' => $currentBalance, 'total' => $currentBalance]);

            return $invoice;
        });
    }

    /**
     * Generate invoice for a customer
     * 
     * @param Customer $customer
     * @param Carbon $billingPeriodStart
     * @param Carbon $billingPeriodEnd
     * @param array $additionalItems (optional one-time charges)
     * @return Invoice
     */
    public function generateInvoice(
        Customer $customer,
        Carbon $billingPeriodStart,
        Carbon $billingPeriodEnd,
        array $additionalItems = [],
        ?Carbon $issueDate = null,
        ?Carbon $dueDate = null,
        ?Carbon $recurringCycleDate = null,
        string $generationSource = 'manual',
    ): Invoice {
        if ($customer->hasCompanyOwnedPlan()) {
            throw new \LogicException('Company Owned plans are excluded from invoices and recurring billing.');
        }
        try {
            return DB::transaction(function () use ($customer, $billingPeriodStart, $billingPeriodEnd, $additionalItems, $issueDate, $dueDate, $recurringCycleDate, $generationSource) {
            if ($recurringCycleDate) {
                $existing = Invoice::query()
                    ->where('customer_id', $customer->id)
                    ->whereDate('recurring_cycle_date', $recurringCycleDate)
                    ->first();
                if ($existing) {
                    return $existing->fresh(['items', 'customer']);
                }
            }

            // Create invoice
            $invoice = Invoice::create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'customer_id' => $customer->id,
                'issue_date' => $issueDate ?? now(config('app.timezone', 'Asia/Manila')),
                'due_date' => $dueDate ?? now(config('app.timezone', 'Asia/Manila'))->addDays((int) Setting::get('billing.due_days', 7)),
                'billing_period_start' => $billingPeriodStart,
                'billing_period_end' => $billingPeriodEnd,
                'recurring_cycle_date' => $recurringCycleDate,
                'generation_source' => $generationSource,
                'status' => 'draft',
            ]);

            // Add service plan charge if customer has one
            if ($customer->servicePlan) {
                $servicePlan = $customer->servicePlan;
                $description = "{$servicePlan->name} Internet Service - " .
                              "{$servicePlan->download_speed}Mbps/{$servicePlan->upload_speed}Mbps";
                
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => $servicePlan->price,
                    'total' => $servicePlan->price,
                ]);
            }

            // Add monthly fee if any
            if ($customer->monthly_fee > 0 && !$customer->servicePlan) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Monthly Service Fee',
                    'quantity' => 1,
                    'unit_price' => $customer->monthly_fee,
                    'total' => $customer->monthly_fee,
                ]);
            }

            // Add additional items (installations, equipment, etc.)
            foreach ($additionalItems as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    'total' => ($item['quantity'] ?? 1) * $item['unit_price'],
                ]);
            }

            // Calculate totals
            $this->calculateInvoiceTotals($invoice);
            $this->applyAvailableCredits($invoice);

            Log::info('Invoice generated', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $customer->id,
                'total' => $invoice->total,
            ]);

            return $invoice->fresh(['items', 'customer']);
            });
        } catch (QueryException $e) {
            // The partial unique index is the final protection when two
            // scheduler/manual requests arrive at exactly the same time.
            if ($recurringCycleDate) {
                $existing = Invoice::query()
                    ->where('customer_id', $customer->id)
                    ->whereDate('recurring_cycle_date', $recurringCycleDate)
                    ->first();
                if ($existing) {
                    return $existing->loadMissing(['items', 'customer']);
                }
            }
            throw $e;
        }
    }

    /**
     * Generate invoices for all active customers (monthly recurring)
     * 
     * @param Carbon|null $billingDate
     * @return array
     */
    public function generateRecurringInvoices(?Carbon $runDate = null): array
    {
        $runDate = ($runDate ?? now(config('app.timezone', 'Asia/Manila')))
            ->copy()
            ->setTimezone(config('app.timezone', 'Asia/Manila'))
            ->startOfDay();
        $leadDays = (int) Setting::get('billing.invoice_generation_days_before_due', 7);
        $billingDate = $runDate->copy()->addDays($leadDays);

        $customers = Customer::where('status', 'active')
                           ->whereNotNull('installation_date')
                           ->whereDate('installation_date', '<=', $runDate)
                           ->with('servicePlan')
                           ->get()
                           ->filter(fn (Customer $customer) => min(
                               $customer->billingCycleDay(),
                               $billingDate->daysInMonth,
                           ) === $billingDate->day);

        $results = [
            'total' => $customers->count(),
            'generated' => 0,
            'covered' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($customers as $customer) {
            try {
                if ($customer->hasCompanyOwnedPlan()) {
                    $results['skipped']++;
                    continue;
                }
                if (!$customer->servicePlan && (float) $customer->monthly_fee <= 0) {
                    $results['skipped']++;
                    continue;
                }

                // The recurring-cycle date is an idempotency key. A one-off
                // invoice must not prevent the normal monthly cycle.
                $existingInvoice = Invoice::where('customer_id', $customer->id)
                    ->whereDate('recurring_cycle_date', $billingDate)
                    ->first();

                if ($existingInvoice) {
                    $results['skipped']++;
                    continue;
                }

                $invoice = $this->generateInvoice(
                    $customer,
                    $billingDate->copy()->subMonthNoOverflow(),
                    $billingDate,
                    [],
                    $runDate,
                    $billingDate,
                    $billingDate,
                    'recurring',
                );
                $this->markAsSent($invoice);
                $results['generated']++;

            } catch (\Exception $e) {
                $results['errors'][] = [
                    'customer' => $customer->account_number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Calculate and update invoice totals
     * PH BIR VAT-inclusive: item prices already include 8% VAT.
     * subtotal = VATable Sale (net), tax = VAT amount, total = gross (customer pays this)
     * 
     * @param Invoice $invoice
     * @return void
     */
    public function calculateInvoiceTotals(Invoice $invoice): void
    {
        $vatRate = 0.08; // 8% VAT (PH BIR)
        $gross = $invoice->items()->sum('total'); // items are VAT-inclusive
        $grossAfterDiscount = round($gross - $invoice->discount, 2);
        $vatableSale = round($grossAfterDiscount / (1 + $vatRate), 2);
        $vat = round($grossAfterDiscount - $vatableSale, 2);
        $balance = round($grossAfterDiscount - $invoice->paid_amount, 2);

        $invoice->update([
            'subtotal' => $vatableSale,      // VATable Sale (net)
            'tax' => $vat,                    // VAT (informational, already included in total)
            'total' => $grossAfterDiscount,   // Amount customer actually pays
            'balance' => $balance,
        ]);
    }

    /**
     * Mark an invoice as sent. Only recurring invoices deliver the automatic
     * creation-time email; manual invoices remain silent until a payment is
     * recorded and the customer receipt is sent.
     *
     * Recurring invoices are generated seven days before their due date, so
     * this is the creation-time email. Repeated scheduler/manual calls do not
     * create another initial invoice email after sent_at has been recorded.
     */
    public function markAsSent(Invoice $invoice): void
    {
        if ($invoice->sent_at !== null) {
            return;
        }

        $invoice->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        if ($invoice->allowsAutomaticBillingNotifications()) {
            $invoice->update([
                'initial_email_status' => 'queued',
                'initial_email_failure_reason' => null,
            ]);
            SendInitialInvoiceEmail::dispatch($invoice->id)->afterCommit();

            Log::info('Initial invoice email queued', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
            ]);
        } else {
            Log::info('Initial invoice email skipped by policy', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
                'generation_source' => $invoice->generation_source,
            ]);
        }
    }

    /**
     * SMTP delivery is deliberately best-effort: a mail issue must never roll
     * back invoice creation, billing dates, payment credits, or MikroTik sync.
     *
     * @return 'sent'|'skipped_already_sent'|'skipped_in_progress'|'skipped_attempt_limit'|'skipped_no_email'|'skipped_no_balance'|'failed'
     */
    public function sendInitialInvoiceEmail(Invoice $invoice): string
    {
        $invoice->loadMissing(['customer', 'items', 'payments']);
        $customer = $invoice->customer;
        if ($invoice->exists && $invoice->initial_email_sent_at !== null) {
            return 'skipped_already_sent';
        }
        if (!$customer || blank($customer->email)) {
            $this->recordInitialEmailState($invoice, 'skipped_no_email', 'Customer does not have an email address.');
            Log::info('Initial invoice email skipped: no recipient email', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
            ]);

            return 'skipped_no_email';
        }
        if ((float) $invoice->balance <= 0) {
            $this->recordInitialEmailState($invoice, 'skipped_no_balance', 'Invoice has no outstanding balance.');
            Log::info('Initial invoice email skipped: zero balance', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $customer->id,
                'balance' => (float) $invoice->balance,
            ]);

            return 'skipped_no_balance';
        }

        if ($invoice->exists) {
            $claimed = Invoice::query()
                ->whereKey($invoice->id)
                ->whereNull('initial_email_sent_at')
                ->where('initial_email_attempt_count', '<', 2)
                ->where(function ($query) {
                    $query->where('initial_email_status', '!=', 'sending')
                        ->orWhereNull('initial_email_status')
                        ->orWhere('initial_email_last_attempt_at', '<=', now()->subMinutes(10));
                })
                ->update([
                    'initial_email_status' => 'sending',
                    'initial_email_attempt_count' => DB::raw('initial_email_attempt_count + 1'),
                    'initial_email_last_attempt_at' => now(),
                    'initial_email_failure_reason' => null,
                ]);

            if ($claimed !== 1) {
                $invoice->refresh();
                if ($invoice->initial_email_sent_at !== null) {
                    return 'skipped_already_sent';
                }

                return (int) $invoice->initial_email_attempt_count >= 2
                    ? 'skipped_attempt_limit'
                    : 'skipped_in_progress';
            }

            $invoice->refresh()->loadMissing(['customer', 'items', 'payments']);
            $customer = $invoice->customer;
        }

        try {
            $subject = "Your SolarNet invoice {$invoice->invoice_number}";
            $html = app(SolarNetEmailRenderer::class)->initialInvoice($invoice);
            $pdf = $this->generatePdf($invoice)->output();
            Mail::html($html, function (Message $message) use ($customer, $subject, $pdf, $invoice) {
                $message->to($customer->email, $customer->full_name)
                    ->subject($subject)
                    ->attachData($pdf, "invoice-{$invoice->invoice_number}.pdf", ['mime' => 'application/pdf']);
            });

            $this->recordInitialEmailState($invoice, 'sent', null, true);

            Log::info('Initial invoice email sent', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $customer->id,
            ]);

            return 'sent';
        } catch (\Throwable $e) {
            $this->recordInitialEmailState($invoice, 'failed', $e->getMessage());
            Log::warning('Initial invoice email failed', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function recordInitialEmailState(Invoice $invoice, string $status, ?string $reason = null, bool $sent = false): void
    {
        if (!$invoice->exists) {
            return;
        }

        $values = [
            'initial_email_status' => $status,
            'initial_email_failure_reason' => $reason ? substr($reason, 0, 500) : null,
        ];
        if ($sent) {
            $values['initial_email_sent_at'] = now();
        }

        Invoice::query()->whereKey($invoice->id)->update($values);
        $invoice->forceFill($values);
    }

    /**
     * Update overdue invoices
     * 
     * @return int Count of updated invoices
     */
    public function updateOverdueInvoices(): int
    {
        return Invoice::whereIn('status', ['sent', 'partial'])
            ->where('due_date', '<', now(config('app.timezone', 'Asia/Manila'))->startOfDay())
            ->where('balance', '>', 0)
            ->whereHas('customer', fn ($customer) => $customer->whereDoesntHave('servicePlan', fn ($plan) => $plan->whereRaw('LOWER(name) LIKE ?', ['%company owned%'])))
            ->update(['status' => 'overdue']);
    }

    /**
     * Generate unique invoice number
     * Format: INV-YYYYMM-XXXX
     * 
     * @return string
     */
    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ym') . '-';
        $lastInvoice = Invoice::where('invoice_number', 'like', $prefix . '%')
                              ->orderBy('invoice_number', 'desc')
                              ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get company info for invoices
     * 
     * @return array
     */
    public function getCompanyInfo(): array
    {
        return [
            'name' => Setting::get('company.name', 'Solarnet Internet'),
            'tagline' => 'High-Speed Internet & Network Solutions',
            'address' => Setting::get('company.address', ''),
            'phone' => Setting::get('company.contact', ''),
            'email' => Setting::get('company.email', ''),
            'website' => Setting::get('company.website', ''),
            'tax_id' => Setting::get('company.tax_id', ''),
        ];
    }

    /**
     * Record a payment against an invoice
     * 
     * @param Invoice $invoice
     * @param array $paymentData
     * @return Payment
     */
    public function recordPayment(Invoice $invoice, array $paymentData): Payment
    {
        return DB::transaction(function () use ($invoice, $paymentData) {
            // Lock the customer first, then every collectible invoice in a
            // deterministic order. This serializes payments across different
            // invoice screens for the same account.
            $invoice = Invoice::query()->whereKey($invoice->id)->firstOrFail();
            $customer = Customer::query()->with('servicePlan')->whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
            $amountCents = $this->moneyToCents($paymentData['amount']);

            // Gateway callbacks provide a durable transaction ID. Treat a
            // replay for the same invoice/customer as idempotent; reject an
            // attempt to reuse the ID for a different financial record.
            $transactionId = trim((string) ($paymentData['transaction_id'] ?? '')) ?: null;
            if ($transactionId) {
                $existing = Payment::query()->where('transaction_id', $transactionId)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->invoice_id === $invoice->id
                        && $existing->customer_id === $invoice->customer_id
                        && $this->moneyToCents($existing->amount) === $amountCents) {
                        return $existing->fresh(['invoice', 'customer']);
                    }
                    throw new \RuntimeException('This payment transaction ID was already used for a different payment.');
                }
            }
            if ($amountCents <= 0) {
                throw new \RuntimeException('Payment amount must be greater than zero.');
            }

            // Create payment record
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'collector_id' => $paymentData['collector_id'] ?? null,
                'received_by' => $paymentData['received_by'] ?? null,
                'payment_number' => $this->generatePaymentNumber(),
                // This is the full amount of money received. Allocations below
                // never rewrite or split this source record.
                'amount' => $this->centsToMoney($amountCents),
                'cash_counted_amount' => $paymentData['cash_counted_amount'] ?? null,
                'cash_change_amount' => $paymentData['cash_change_amount'] ?? null,
                'cash_change_advance_amount' => $paymentData['cash_change_advance_amount'] ?? null,
                'cash_breakdown' => $paymentData['cash_breakdown'] ?? null,
                'payer_signature' => $paymentData['payer_signature'] ?? null,
                'payer_signature_similarity' => $paymentData['payer_signature_similarity'] ?? null,
                'signature_signer_type' => $paymentData['signature_signer_type'] ?? null,
                'signature_signer_name' => $paymentData['signature_signer_name'] ?? null,
                'payment_method' => $paymentData['payment_method'],
                'payment_date' => $paymentData['payment_date'] ?? now(),
                'transaction_id' => $transactionId,
                'reference' => $paymentData['reference'] ?? null,
                'notes' => $paymentData['notes'] ?? null,
            ]);

            $unallocatedCents = $this->allocatePaymentOldestFirst($payment, $customer, $amountCents);
            if ($unallocatedCents > 0) {
                CustomerCredit::create([
                    'customer_id' => $customer->id,
                    'payment_id' => $payment->id,
                    'original_amount' => $this->centsToMoney($unallocatedCents),
                    'remaining_amount' => $this->centsToMoney($unallocatedCents),
                    'status' => 'unallocated',
                    'notes' => 'Unallocated overpayment after all outstanding invoices were settled.',
                ]);
            }
            $this->assertPaymentOwnership($payment);

            $cashChangeAdvance = round((float) ($paymentData['cash_change_advance_amount'] ?? 0), 2);
            if ($cashChangeAdvance > 0) {
                $cashTendered = round((float) ($paymentData['cash_counted_amount'] ?? 0), 2);
                $availableChange = max(0, round($cashTendered - ($amountCents / 100), 2));
                if ($payment->payment_method !== 'cash' || $cashChangeAdvance > $availableChange) {
                    throw new \RuntimeException('Advance credit from change must match cash received above the invoice payment amount.');
                }

                $changeAdvancePayment = Payment::create([
                    'customer_id' => $invoice->customer_id,
                    'collector_id' => $paymentData['collector_id'] ?? null,
                    'received_by' => $paymentData['received_by'] ?? null,
                    'payment_number' => $this->generatePaymentNumber(),
                    'amount' => $cashChangeAdvance,
                    'cash_counted_amount' => 0,
                    'cash_change_amount' => 0,
                    'cash_change_advance_amount' => 0,
                    'payment_method' => 'cash',
                    'payment_date' => $paymentData['payment_date'] ?? now(),
                    'transaction_id' => 'ADV-CHANGE-' . $payment->id,
                    'reference' => $paymentData['reference'] ?? null,
                    'notes' => 'Client-approved change retained as advance credit from payment ' . $payment->payment_number . ' for invoice ' . $invoice->invoice_number . '.',
                ]);

                $this->createAdvanceCredits($invoice->customer, $changeAdvancePayment, $cashChangeAdvance, null);
                $this->assertPaymentOwnership($changeAdvancePayment);
            }

            // Re-evaluate every unpaid invoice after commit. A settled balance
            // restores a suspended/expired customer; another eligible overdue
            // balance keeps the customer restricted.
            if ($customer) {
                DB::afterCommit(function () use ($customer, $payment) {
                    try {
                        $freshCustomer = $customer->fresh(['servicePlan', 'router']);
                        app(BillingSuspensionService::class)->reconcileAfterPayment($freshCustomer, $payment->fresh(['invoice']));
                    } catch (\Throwable $e) {
                        Log::warning('Deferred customer network sync after payment failed', [
                            'customer_id' => $customer->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }

            // Push is an optional post-commit alert. A delivery problem must
            // never roll back a verified cash, bank, GCash, or PayMongo payment.
            DB::afterCommit(function () use ($payment) {
                try {
                    app(CustomerWebPushNotificationService::class)
                        ->sendPaymentReceived($payment->fresh(['customer', 'invoice']));
                } catch (\Throwable $e) {
                    Log::warning('Deferred customer payment push failed', [
                        'payment_id' => $payment->id,
                        'error_type' => $e::class,
                    ]);
                }
            });

            // A receipt email covers every verified payment method. It is
            // deliberately post-commit and best-effort, so mail failures can
            // never undo a cash, bank, GCash, or PayMongo payment.
            DB::afterCommit(function () use ($payment) {
                try {
                    app(PaymentConfirmationEmailService::class)
                        ->send($payment->fresh(['customer', 'invoice']));
                } catch (\Throwable $e) {
                    Log::warning('Deferred payment confirmation email failed', [
                        'payment_id' => $payment->id,
                        'error_type' => $e::class,
                    ]);
                }
            });

            Log::info('Payment recorded', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $payment->amount,
                'method' => $payment->payment_method,
            ]);

            return $payment->fresh(['invoice', 'customer', 'allocations.invoice']);
        });
    }

    /** Allocate one immutable receipt across this customer's oldest debt. */
    private function allocatePaymentOldestFirst(Payment $payment, Customer $customer, int $availableCents): int
    {
        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->where('balance', '>', 0)
            ->orderBy('billing_period_start')
            ->orderBy('issue_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $receivable) {
            if ($availableCents <= 0) break;

            // Reconcile cached fields from the allocation ledger before using
            // the balance. Existing payments were backfilled by the migration.
            $this->reconcileInvoiceFromAllocations($receivable);
            $balanceCents = $this->moneyToCents($receivable->balance);
            if ($balanceCents <= 0) continue;

            $allocatedCents = min($availableCents, $balanceCents);
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $receivable->id,
                'amount' => $this->centsToMoney($allocatedCents),
            ]);
            $availableCents -= $allocatedCents;
            $this->reconcileInvoiceFromAllocations($receivable->fresh());
        }

        return $availableCents;
    }

    /** Materialize paid_amount, balance and status from the allocation ledger. */
    private function reconcileInvoiceFromAllocations(Invoice $invoice): void
    {
        $paidCents = $this->moneyToCents(PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount'));
        $chargeCents = $this->moneyToCents($invoice->total);
        $balanceCents = max(0, $chargeCents - $paidCents);

        $invoice->paid_amount = $this->centsToMoney($paidCents);
        $invoice->balance = $this->centsToMoney($balanceCents);
        if ($balanceCents === 0) {
            $invoice->status = 'paid';
            $invoice->paid_at ??= now();
        } elseif ($paidCents > 0) {
            $invoice->status = 'partial';
            $invoice->paid_at = null;
        } elseif ($invoice->due_date?->lt(now(config('app.timezone', 'Asia/Manila'))->startOfDay())) {
            $invoice->status = 'overdue';
            $invoice->paid_at = null;
        } else {
            $invoice->status = 'sent';
            $invoice->paid_at = null;
        }
        $invoice->save();
    }

    private function moneyToCents(mixed $amount): int
    {
        $normalized = is_float($amount)
            ? number_format($amount, 2, '.', '')
            : str_replace([',', ' '], '', trim((string) $amount));
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
            throw new \InvalidArgumentException('Invalid monetary amount.');
        }

        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $cents = ((int) $matches[2] * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) $cents++;
        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), abs($cents % 100));
    }

    /** Every received peso is either allocated to an invoice or still credit. */
    private function assertPaymentOwnership(Payment $payment): void
    {
        $received = $this->moneyToCents($payment->amount);
        $allocated = $this->moneyToCents(PaymentAllocation::query()
            ->where('payment_id', $payment->id)->sum('amount'));
        $credit = $this->moneyToCents(CustomerCredit::query()
            ->where('payment_id', $payment->id)->sum('remaining_amount'));

        if ($received !== $allocated + $credit) {
            throw new \RuntimeException('Payment ownership invariant failed; no financial changes were committed.');
        }
    }

    public function recordAdvancePayment(Customer $customer, array $paymentData): Payment
    {
        return DB::transaction(function () use ($customer, $paymentData) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $paymentData['amount'], 2);
            if ($amount <= 0) {
                throw new \RuntimeException('Advance payment amount must be greater than zero.');
            }
            $transactionId = trim((string) ($paymentData['transaction_id'] ?? '')) ?: null;
            if ($transactionId) {
                $existing = Payment::query()->where('transaction_id', $transactionId)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->customer_id === $customer->id && !$existing->invoice_id
                        && (int) round((float) $existing->amount * 100) === (int) round($amount * 100)) {
                        return $existing->fresh(['invoice', 'customer']);
                    }
                    throw new \RuntimeException('This payment transaction ID was already used for a different payment.');
                }
            }

            $payment = Payment::create([
                'customer_id' => $customer->id,
                'received_by' => $paymentData['received_by'] ?? null,
                'payment_number' => $this->generatePaymentNumber(),
                'amount' => $amount,
                'cash_counted_amount' => $paymentData['cash_counted_amount'] ?? null,
                'cash_change_amount' => $paymentData['cash_change_amount'] ?? null,
                'cash_change_advance_amount' => $paymentData['cash_change_advance_amount'] ?? null,
                'cash_breakdown' => $paymentData['cash_breakdown'] ?? null,
                'payment_method' => $paymentData['payment_method'],
                'payment_date' => $paymentData['payment_date'] ?? now(),
                'transaction_id' => $transactionId,
                'reference' => $paymentData['reference'] ?? null,
                'notes' => $paymentData['notes'] ?? 'Advance payment for future billing',
            ]);
            $this->createAdvanceCredits(
                $customer,
                $payment,
                (float) $payment->amount,
                isset($paymentData['covered_cycle_date'])
                    ? Carbon::parse($paymentData['covered_cycle_date'], config('app.timezone', 'Asia/Manila'))
                    : null,
            );
            $this->assertPaymentOwnership($payment);

            DB::afterCommit(function () use ($payment) {
                try {
                    $customer = $payment->fresh(['customer'])?->customer;
                    if ($customer) {
                        app(BillingSuspensionService::class)
                            ->reconcileAfterPayment($customer->load(['servicePlan', 'router']), $payment->fresh(['invoice']));
                    }
                } catch (\Throwable $e) {
                    Log::warning('Deferred customer network sync after advance payment failed', [
                        'payment_id' => $payment->id,
                        'error_type' => $e::class,
                    ]);
                }
            });

            DB::afterCommit(function () use ($payment) {
                try {
                    app(CustomerWebPushNotificationService::class)
                        ->sendPaymentReceived($payment->fresh(['customer', 'invoice']));
                } catch (\Throwable $e) {
                    Log::warning('Deferred customer advance-payment push failed', [
                        'payment_id' => $payment->id,
                        'error_type' => $e::class,
                    ]);
                }
            });

            DB::afterCommit(function () use ($payment) {
                try {
                    app(PaymentConfirmationEmailService::class)
                        ->send($payment->fresh(['customer', 'invoice']));
                } catch (\Throwable $e) {
                    Log::warning('Deferred advance-payment confirmation email failed', [
                        'payment_id' => $payment->id,
                        'error_type' => $e::class,
                    ]);
                }
            });

            return $payment;
        });
    }

    /**
     * Reserve an advance payment for future anniversary cycles. Each row is a
     * deliberate allocation, so a September advance cannot be consumed by an
     * older overdue invoice. Legacy credits retain a null cycle date.
     */
    protected function createAdvanceCredits(Customer $customer, Payment $payment, float $amount, ?Carbon $firstCycle): void
    {
        $customer->loadMissing('servicePlan');
        $cycleAmount = $this->customerCycleAmount($customer);
        $cycleDate = $firstCycle ? $firstCycle->copy()->startOfDay() : $this->nextBillingCycle($customer, Carbon::parse($payment->payment_date));

        if ($cycleAmount <= 0 || !$cycleDate) {
            CustomerCredit::create([
                'customer_id' => $customer->id, 'payment_id' => $payment->id,
                'original_amount' => $amount, 'remaining_amount' => $amount,
                'status' => 'unallocated', 'notes' => 'Advance payment credit without a billable future cycle',
            ]);
            return;
        }

        foreach ((new AdvancePaymentAllocator())->allocate($amount, $cycleAmount) as $allocation) {
            $allocated = $allocation['amount'];
            CustomerCredit::create([
                'customer_id' => $customer->id,
                'payment_id' => $payment->id,
                'covered_cycle_date' => $cycleDate,
                'covered_period_start' => $cycleDate->copy()->subMonthNoOverflow(),
                'covered_period_end' => $cycleDate,
                'original_amount' => $allocated,
                // The invoice remains the owner of the future service charge.
                // Even a fully funded cycle stays available until that invoice
                // exists and receives an explicit allocation.
                'remaining_amount' => $allocated,
                'status' => 'advance',
                'applied_at' => null,
                'notes' => 'Advance payment reserved for billing cycle ' . $cycleDate->toDateString(),
            ]);
            $cycleDate->addMonthNoOverflow();
        }
    }

    public function isCycleFullyCovered(Customer $customer, Carbon $cycleDate): bool
    {
        $cycleAmount = $this->customerCycleAmount($customer);
        if ($cycleAmount <= 0) return false;

        return (float) CustomerCredit::query()
            ->where('customer_id', $customer->id)
            ->whereDate('covered_cycle_date', $cycleDate)
            ->whereIn('status', ['fully_applied', 'covered'])
            ->sum('original_amount') >= $cycleAmount;
    }

    public function isValidFutureBillingCycle(Customer $customer, Carbon $cycleDate, Carbon $paymentDate): bool
    {
        if (!$customer->installation_date || !$cycleDate->copy()->startOfDay()->gt($paymentDate->copy()->startOfDay())) {
            return false;
        }

        return $cycleDate->day === min($customer->billingCycleDay(), $cycleDate->daysInMonth);
    }

    public function creditSummary(Customer $customer): array
    {
        $credits = CustomerCredit::query()->where('customer_id', $customer->id)->get();
        return [
            'available_credit' => round((float) $credits->sum('remaining_amount'), 2),
            'covered_cycles' => $credits->whereNotNull('covered_cycle_date')->map(fn (CustomerCredit $credit) => [
                'cycle_date' => $credit->covered_cycle_date?->toDateString(),
                'amount' => (float) $credit->original_amount,
                'remaining_amount' => (float) $credit->remaining_amount,
                'status' => $credit->status,
            ])->values()->all(),
        ];
    }

    protected function customerCycleAmount(Customer $customer): float
    {
        return round((float) ($customer->servicePlan?->price ?? $customer->monthly_fee), 2);
    }

    protected function nextBillingCycle(Customer $customer, Carbon $fromDate): ?Carbon
    {
        if (!$customer->installation_date) return null;
        $timezone = config('app.timezone', 'Asia/Manila');
        $fromDate = $fromDate->copy()->setTimezone($timezone)->startOfDay();
        $day = $customer->billingCycleDay();
        $cycle = $fromDate->copy()->startOfMonth()->setDay(min($day, $fromDate->daysInMonth));
        return $cycle->lte($fromDate) ? $cycle->addMonthNoOverflow() : $cycle;
    }

    private function applyAvailableCredits(Invoice $invoice): void
    {
        $remaining = (float) $invoice->balance;
        if ($remaining <= 0) return;
        $credits = CustomerCredit::where('customer_id', $invoice->customer_id)
            ->where('remaining_amount', '>', 0)
            ->where(function ($query) use ($invoice) {
                $query->whereNull('covered_cycle_date')
                    ->orWhereDate('covered_cycle_date', $invoice->recurring_cycle_date);
            })
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();
        foreach ($credits as $credit) {
            if ($remaining <= 0) break;
            $applied = min($remaining, (float) $credit->remaining_amount);
            if (! $credit->payment_id) {
                // Legacy credit without a source receipt cannot fabricate new
                // money. Leave it untouched for an explicit reconciliation.
                continue;
            }
            $allocation = PaymentAllocation::query()
                ->where('payment_id', $credit->payment_id)
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();
            $newAllocationCents = $this->moneyToCents($applied)
                + ($allocation ? $this->moneyToCents($allocation->amount) : 0);
            if ($allocation) {
                $allocation->update(['amount' => $this->centsToMoney($newAllocationCents)]);
            } else {
                PaymentAllocation::create([
                    'payment_id' => $credit->payment_id,
                    'invoice_id' => $invoice->id,
                    'amount' => $this->centsToMoney($newAllocationCents),
                ]);
            }
            $credit->decrement('remaining_amount', $applied);
            $credit->refresh();
            if ((float) $credit->remaining_amount <= 0) {
                $credit->update(['status' => 'fully_applied', 'applied_at' => now()]);
            } elseif ($credit->covered_cycle_date) {
                $credit->update(['status' => 'partially_applied']);
            }
            $this->assertPaymentOwnership($credit->payment()->firstOrFail());
            $remaining -= $applied;
        }
        $this->reconcileInvoiceFromAllocations($invoice->fresh());
    }

    /**
     * Generate unique payment number
     * Format: PAY-YYYYMM-XXXX
     * 
     * @return string
     */
    protected function generatePaymentNumber(): string
    {
        $prefix = 'PAY-' . now()->format('Ym') . '-';
        $lastPayment = Payment::where('payment_number', 'like', $prefix . '%')
                              ->orderBy('payment_number', 'desc')
                              ->first();

        if ($lastPayment) {
            $lastNumber = (int) substr($lastPayment->payment_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate PDF for an invoice
     * 
     * @param Invoice $invoice
     * @return \Barryvdh\DomPDF\PDF
     */
    public function generatePdf(Invoice $invoice)
    {
        $invoice->load(['customer', 'items', 'payments']);
        $company = $this->getCompanyInfo();

        $pdf = \PDF::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'company' => $company,
        ]);

        return $pdf;
    }
}
