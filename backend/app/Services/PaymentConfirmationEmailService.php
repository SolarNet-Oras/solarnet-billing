<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Sends one customer receipt email for each successfully recorded payment. */
class PaymentConfirmationEmailService
{
    /** @return 'sent'|'skipped_no_email'|'skipped_already_sent'|'failed' */
    public function send(Payment $payment): string
    {
        $payment->loadMissing(['customer', 'invoice']);
        $customer = $payment->customer;

        if (!$customer || blank($customer->email)) {
            return 'skipped_no_email';
        }

        if ($payment->payment_confirmation_email_sent_at !== null) {
            return 'skipped_already_sent';
        }

        $company = (string) Setting::get('company.name', 'Solarnet Internet');
        $subject = "{$company} payment confirmation {$payment->payment_number}";

        try {
            Mail::raw($this->body($payment), function (Message $message) use ($customer, $subject) {
                $message->to($customer->email, $customer->full_name)->subject($subject);
            });

            $payment->forceFill(['payment_confirmation_email_sent_at' => now()])->save();

            Log::info('Payment confirmation email sent', [
                'payment_id' => $payment->id,
                'customer_id' => $customer->id,
            ]);

            return 'sent';
        } catch (\Throwable $e) {
            Log::warning('Payment confirmation email failed', [
                'payment_id' => $payment->id,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    public function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'mobile_money' => 'GCash / mobile wallet',
            'bank_transfer' => 'Bank transfer',
            'credit_card' => 'Credit card',
            'debit_card' => 'Debit card',
            'other' => 'Other',
            default => filled($method) ? ucwords(str_replace('_', ' ', $method)) : 'Unspecified',
        };
    }

    private function body(Payment $payment): string
    {
        $customer = $payment->customer;
        $invoice = $payment->invoice;
        $company = (string) Setting::get('company.name', 'Solarnet Internet');
        $currency = trim((string) Setting::get('company.currency', '₱'));
        $amount = $currency . number_format((float) $payment->amount, 2);
        $date = $payment->payment_date?->format('Y-m-d') ?? now()->toDateString();

        $body = "Hi {$customer->full_name},\n\n"
            . "We have received your payment. Thank you.\n\n"
            . "Receipt no. : {$payment->payment_number}\n"
            . "Account no. : {$customer->account_number}\n"
            . "Payment date: {$date}\n"
            . "Amount      : {$amount}\n"
            . 'Method      : ' . $this->paymentMethodLabel($payment->payment_method) . "\n";

        if ($invoice) {
            $body .= 'Status      : ' . ((float) $invoice->balance <= 0 ? 'PAID' : 'PARTIAL PAYMENT') . "\n";
            $body .= "Invoice     : {$invoice->invoice_number}\n"
                . 'Remaining balance: ' . $currency . number_format((float) $invoice->balance, 2) . "\n";
        } else {
            $body .= "Status      : ADVANCE CREDIT\n";
            $body .= "Payment type: Advance credit for future billing\n";
        }

        if (filled($payment->transaction_id)) {
            $body .= "Transaction ID: {$payment->transaction_id}\n";
        }

        if (filled($payment->reference)) {
            $body .= "Reference   : {$payment->reference}\n";
        }

        return $body . "\nYou can review your payment history in the SolarNet customer portal.\n\nThank you,\n{$company}\n";
    }
}
