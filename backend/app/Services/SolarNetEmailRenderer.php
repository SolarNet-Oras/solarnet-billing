<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\CustomerPortalUrl;
use Carbon\Carbon;

/**
 * Shared presentation layer for customer-facing billing email. It deliberately
 * does not send email or make any billing/network decision.
 */
class SolarNetEmailRenderer
{
    public function initialInvoice(Invoice $invoice): string
    {
        $invoice->loadMissing(['customer.servicePlan']);
        $customer = $invoice->customer;

        return $this->render([
            'notice' => 'AUTOMATED BILLING NOTICE',
            'headline' => 'Your monthly invoice is ready.',
            'intro' => 'Your latest SolarNet bill is ready to review. Please settle it on or before the due date to keep your service uninterrupted.',
            'customer' => $customer,
            'customer_card_title' => 'Customer account',
            'summary_title' => 'Invoice summary',
            'summary_rows' => [
                ['label' => 'Invoice number', 'value' => $invoice->invoice_number],
                ['label' => 'Due date', 'value' => $this->date($invoice->due_date)],
                ['label' => 'Billing period', 'value' => $this->dateRange($invoice->billing_period_start, $invoice->billing_period_end)],
            ],
            'detail_rows' => [
                ['label' => 'Service plan', 'value' => $customer?->servicePlan?->name ?? 'SolarNet Internet'],
                ['label' => 'Invoice status', 'value' => 'Ready for payment'],
            ],
            'amount_label' => 'Amount due',
            'amount' => $this->money((float) $invoice->balance),
            'cta_label' => 'VIEW & PAY ONLINE',
            'cta_url' => $this->portalUrl(),
            'payment_note' => 'Use the secure SolarNet portal to review this invoice and complete payment. Online checkout is handled through PayMongo.',
            'reminder' => 'A PDF copy of this invoice is attached for your records. If you have already paid, please allow time for the payment to post.',
            'features' => ['Account-specific invoice', 'Secure online payment', 'SolarNet billing support'],
        ]);
    }

    public function paymentConfirmation(Payment $payment, string $methodLabel): string
    {
        $payment->loadMissing(['customer.servicePlan', 'invoice']);
        $customer = $payment->customer;
        $invoice = $payment->invoice;
        $isAdvance = $invoice === null;
        $isPaid = $invoice !== null && (float) $invoice->balance <= 0;

        $summaryRows = [
            ['label' => 'Receipt number', 'value' => $payment->payment_number],
            ['label' => 'Payment date', 'value' => $this->date($payment->payment_date)],
            ['label' => 'Payment method', 'value' => $methodLabel],
        ];
        if ($invoice) $summaryRows[] = ['label' => 'Invoice number', 'value' => $invoice->invoice_number];
        if (filled($payment->transaction_id)) $summaryRows[] = ['label' => 'Transaction ID', 'value' => $payment->transaction_id];
        if (filled($payment->reference)) $summaryRows[] = ['label' => 'Reference', 'value' => $payment->reference];

        return $this->render([
            'notice' => 'PAYMENT CONFIRMATION',
            'headline' => $isAdvance ? 'Your advance payment was received.' : 'Your payment was received.',
            'intro' => $isAdvance
                ? 'Thank you. This amount is protected as advance credit for a future SolarNet billing cycle.'
                : 'Thank you. Your payment has been posted to your SolarNet account.',
            'customer' => $customer,
            'customer_card_title' => 'Customer account',
            'summary_title' => $isAdvance ? 'Advance-credit receipt' : 'Payment receipt',
            'summary_rows' => $summaryRows,
            'detail_rows' => $isAdvance
                ? [
                    ['label' => 'Payment status', 'value' => 'Advance credit saved'],
                    ['label' => 'Application', 'value' => 'Applied to your next eligible invoice'],
                ]
                : [
                    ['label' => 'Invoice status', 'value' => $isPaid ? 'Paid in full' : 'Partial payment received'],
                    ['label' => 'Remaining balance', 'value' => $this->money((float) $invoice->balance)],
                ],
            'amount_label' => 'Payment received',
            'amount' => $this->money((float) $payment->amount),
            'cta_label' => 'VIEW PAYMENT HISTORY',
            'cta_url' => $this->portalUrl(),
            'payment_note' => $isPaid
                ? 'Your payment is recorded. If service was restricted solely by this settled balance, SolarNet will reconcile access automatically.'
                : 'Your receipt is recorded. You can view your current balance and payment history in the secure SolarNet portal.',
            'reminder' => 'Keep this message as your payment confirmation. Contact SolarNet support if any payment detail needs correction.',
            'features' => ['Payment recorded', 'Secure account history', 'SolarNet support'],
        ]);
    }

    /** @param array<string, mixed> $event */
    public function finalGraceWarning(Customer $customer, array $event): string
    {
        return $this->render([
            'notice' => 'FINAL BILLING WARNING',
            'headline' => 'Your service is at risk of suspension.',
            'intro' => 'Your account still has an unpaid balance. Please settle it before the grace period ends to avoid interruption to your SolarNet service.',
            'customer' => $customer,
            'customer_card_title' => 'Customer account',
            'summary_title' => 'Final billing summary',
            'summary_rows' => [
                ['label' => 'Original due date', 'value' => $this->date($event['original_due_date'] ?? null)],
                ['label' => 'Grace period', 'value' => (int) ($event['grace_days'] ?? 0) . ' day(s)'],
                ['label' => 'Grace period ends', 'value' => $this->date($event['grace_period_end'] ?? null)],
            ],
            'detail_rows' => [
                ['label' => 'Service status', 'value' => 'Suspension pending'],
                ['label' => 'Action needed', 'value' => 'Settle the outstanding balance before the grace period expires'],
            ],
            'amount_label' => 'Outstanding balance',
            'amount' => $this->money((float) ($event['outstanding_balance'] ?? 0)),
            'cta_label' => 'VIEW & PAY ONLINE',
            'cta_url' => $this->safeUrl($event['portal_url'] ?? null) ?? $this->portalUrl(),
            'payment_note' => 'Payment is completed through the secure SolarNet portal. Online checkout is handled through PayMongo.',
            'reminder' => 'If you have already made a payment, please allow time for verification and posting. Contact SolarNet support if you need assistance.',
            'features' => ['Final account notice', 'Secure online payment', 'Fast support access'],
        ]);
    }

    /** @param array<string, mixed> $content */
    private function render(array $content): string
    {
        $company = [
            'name' => (string) Setting::get('company.name', 'Solarnet Internet'),
            'address' => trim((string) Setting::get('company.address', '')),
            'contact' => trim((string) Setting::get('company.contact', '')),
            'email' => trim((string) Setting::get('company.email', config('mail.from.address', ''))),
            'website' => trim((string) Setting::get('company.website', '')),
            'facebook_url' => $this->safeUrl(Setting::get('company.facebook_url', '')),
            'logo_url' => $this->absoluteUrl((string) Setting::get('company.logo_url', '')),
        ];

        return view('emails.solarnet.master', array_merge($content, [
            'company' => $company,
            'preheader' => $content['headline'] . ' ' . $content['amount_label'] . ': ' . $content['amount'],
            'year' => now(config('app.timezone', 'Asia/Manila'))->year,
        ]))->render();
    }

    private function money(float $amount): string
    {
        $currency = trim((string) Setting::get('company.currency', ''));
        if (preg_match('/^\p{Sc}$/u', $currency) !== 1) {
            $currency = html_entity_decode('&#8369;', ENT_QUOTES, 'UTF-8');
        }
        return $currency . number_format($amount, 2);
    }

    private function date(mixed $date): string
    {
        if (!$date) return html_entity_decode('&mdash;', ENT_QUOTES, 'UTF-8');
        return $date instanceof \DateTimeInterface
            ? $date->format('F j, Y')
            : Carbon::parse($date, config('app.timezone', 'Asia/Manila'))->format('F j, Y');
    }

    private function dateRange(mixed $start, mixed $end): string
    {
        if (!$start && !$end) return html_entity_decode('&mdash;', ENT_QUOTES, 'UTF-8');
        if (!$start || !$end) return $this->date($start ?: $end);
        return $this->date($start) . ' to ' . $this->date($end);
    }

    private function portalUrl(): string
    {
        return CustomerPortalUrl::to('/customer/billing');
    }

    private function absoluteUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        if (filter_var($url, FILTER_VALIDATE_URL)) return $url;
        return rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }

    private function safeUrl(mixed $url): ?string
    {
        $url = trim((string) $url);
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
