<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\BillingSuspensionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class InvoiceService
{
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
    ): Invoice {
        try {
            return DB::transaction(function () use ($customer, $billingPeriodStart, $billingPeriodEnd, $additionalItems, $issueDate, $dueDate, $recurringCycleDate) {
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
    public function generateRecurringInvoices(?Carbon $billingDate = null): array
    {
        $billingDate = ($billingDate ?? now(config('app.timezone', 'Asia/Manila')))
            ->copy()
            ->setTimezone(config('app.timezone', 'Asia/Manila'))
            ->startOfDay();

        $customers = Customer::where('status', 'active')
                           ->whereNotNull('installation_date')
                           ->whereDate('installation_date', '<=', $billingDate)
                           ->with('servicePlan')
                           ->get()
                           ->filter(fn (Customer $customer) => min(
                               $customer->installation_date->day,
                               $billingDate->daysInMonth,
                           ) === $billingDate->day);

        $results = [
            'total' => $customers->count(),
            'generated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($customers as $customer) {
            try {
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
                    $billingDate,
                    $billingDate->copy()->addDays((int) Setting::get('billing.due_days', 7)),
                    $billingDate,
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
     * Mark invoice as sent
     * 
     * @param Invoice $invoice
     * @return void
     */
    public function markAsSent(Invoice $invoice): void
    {
        $invoice->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
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
            // Create payment record
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'collector_id' => $paymentData['collector_id'] ?? null,
                'received_by' => $paymentData['received_by'] ?? null,
                'payment_number' => $this->generatePaymentNumber(),
                'amount' => $paymentData['amount'],
                'cash_counted_amount' => $paymentData['cash_counted_amount'] ?? null,
                'cash_breakdown' => $paymentData['cash_breakdown'] ?? null,
                'payer_signature' => $paymentData['payer_signature'] ?? null,
                'payer_signature_similarity' => $paymentData['payer_signature_similarity'] ?? null,
                'signature_signer_type' => $paymentData['signature_signer_type'] ?? null,
                'signature_signer_name' => $paymentData['signature_signer_name'] ?? null,
                'payment_method' => $paymentData['payment_method'],
                'payment_date' => $paymentData['payment_date'] ?? now(),
                'transaction_id' => $paymentData['transaction_id'] ?? null,
                'reference' => $paymentData['reference'] ?? null,
                'notes' => $paymentData['notes'] ?? null,
            ]);

            // Update invoice paid amount and balance
            $invoice->paid_amount += $payment->amount;
            $invoice->balance = $invoice->total - $invoice->paid_amount;

            // Update invoice status
            if ($invoice->balance <= 0) {
                $invoice->status = 'paid';
                $invoice->paid_at = now();
            } elseif ($invoice->paid_amount > 0) {
                $invoice->status = 'partial';
            }

            $invoice->save();

            // A payment is not automatically a reason to restore service. The
            // suspension service re-evaluates every unpaid invoice after the
            // transaction commits, so another eligible overdue balance keeps
            // the customer restricted.
            $customer = $invoice->customer;
            if ($customer) {
                DB::afterCommit(function () use ($customer) {
                    try {
                        $freshCustomer = $customer->fresh(['servicePlan', 'router']);
                        app(BillingSuspensionService::class)->syncCustomerMikrotikStatus($freshCustomer);
                    } catch (\Throwable $e) {
                        Log::warning('Deferred customer network sync after payment failed', [
                            'customer_id' => $customer->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }

            Log::info('Payment recorded', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $payment->amount,
                'method' => $payment->payment_method,
            ]);

            return $payment->fresh(['invoice', 'customer']);
        });
    }

    public function recordAdvancePayment(Customer $customer, array $paymentData): Payment
    {
        return DB::transaction(function () use ($customer, $paymentData) {
            $payment = Payment::create([
                'customer_id' => $customer->id,
                'received_by' => $paymentData['received_by'] ?? null,
                'payment_number' => $this->generatePaymentNumber(),
                'amount' => $paymentData['amount'],
                'cash_counted_amount' => $paymentData['cash_counted_amount'] ?? null,
                'cash_breakdown' => $paymentData['cash_breakdown'] ?? null,
                'payment_method' => $paymentData['payment_method'],
                'payment_date' => $paymentData['payment_date'] ?? now(),
                'transaction_id' => $paymentData['transaction_id'] ?? null,
                'reference' => $paymentData['reference'] ?? null,
                'notes' => $paymentData['notes'] ?? 'Advance payment for future billing',
            ]);
            CustomerCredit::create(['customer_id' => $customer->id, 'payment_id' => $payment->id, 'original_amount' => $payment->amount, 'remaining_amount' => $payment->amount, 'notes' => 'Advance payment credit']);
            return $payment;
        });
    }

    private function applyAvailableCredits(Invoice $invoice): void
    {
        $remaining = (float) $invoice->balance;
        if ($remaining <= 0) return;
        $credits = CustomerCredit::where('customer_id', $invoice->customer_id)->where('remaining_amount', '>', 0)->orderBy('created_at')->lockForUpdate()->get();
        foreach ($credits as $credit) {
            if ($remaining <= 0) break;
            $applied = min($remaining, (float) $credit->remaining_amount);
            Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'payment_number' => $this->generatePaymentNumber(), 'amount' => $applied, 'payment_method' => 'other', 'payment_date' => now(), 'notes' => 'Applied advance credit']);
            $credit->decrement('remaining_amount', $applied);
            $invoice->paid_amount += $applied;
            $remaining -= $applied;
        }
        $invoice->balance = max(0, $invoice->total - $invoice->paid_amount);
        if ($invoice->balance <= 0) { $invoice->status = 'paid'; $invoice->paid_at = now(); }
        elseif ($invoice->paid_amount > 0) $invoice->status = 'partial';
        $invoice->save();
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
