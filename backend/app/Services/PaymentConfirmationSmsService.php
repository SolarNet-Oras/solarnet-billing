<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/** Sends and audits one transactional SMS receipt per payment. */
class PaymentConfirmationSmsService
{
    /** @return 'sent'|'skipped_already_sent'|'skipped_no_phone'|'failed' */
    public function send(Payment $payment): string
    {
        $payment->loadMissing(['customer', 'invoice']);
        if ($payment->payment_confirmation_sms_sent_at !== null) {
            return 'skipped_already_sent';
        }

        $customer = $payment->customer;
        $sms = app(PhilSmsService::class);
        $phone = $sms->normalisePhilippineMobile((string) $customer?->contact_number);
        if (!$customer || $phone === null) {
            $payment->forceFill([
                'payment_confirmation_sms_status' => 'skipped_no_phone',
                'payment_confirmation_sms_failure_reason' => 'Customer does not have a valid Philippine mobile number.',
            ])->save();
            return 'skipped_no_phone';
        }

        $payment->forceFill([
            'payment_confirmation_sms_status' => 'sending',
            'payment_confirmation_sms_attempt_count' => ((int) $payment->payment_confirmation_sms_attempt_count) + 1,
            'payment_confirmation_sms_last_attempt_at' => now(),
            'payment_confirmation_sms_failure_reason' => null,
        ])->save();

        $invoice = $payment->invoice;
        $message = 'SOLARNET: Hi ' . trim(explode(' ', trim($customer->full_name))[0] ?: 'Customer') . ",\n\n"
            . 'We received PHP ' . number_format((float) $payment->amount, 2)
            . ' as your advance payment.'
            . ($invoice ? "\nInvoice: {$invoice->invoice_number}\nDue: {$invoice->due_date->format('M j, Y')}" : '')
            . "\nReceipt: {$payment->payment_number}\n\nThank you. Auto-generated SMS.";

        $result = $sms->send($phone, $message);
        if ($result === 'sent') {
            $payment->forceFill([
                'payment_confirmation_sms_status' => 'sent',
                'payment_confirmation_sms_sent_at' => now(),
                'payment_confirmation_sms_provider_id' => $sms->lastProviderMessageId(),
            ])->save();
            Log::info('Payment confirmation SMS sent', ['payment_id' => $payment->id, 'customer_id' => $customer->id]);
            return 'sent';
        }

        $payment->forceFill([
            'payment_confirmation_sms_status' => 'failed',
            'payment_confirmation_sms_failure_reason' => $sms->lastFailureReason() ?? "PhilSMS delivery result: {$result}.",
        ])->save();
        return 'failed';
    }
}
