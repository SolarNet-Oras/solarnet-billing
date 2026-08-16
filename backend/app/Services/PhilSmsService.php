<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side PhilSMS client for explicit transactional SMS only.
 *
 * This service does not decide when to notify a customer. In particular, the
 * recurring invoice reminder job is intentionally Web Push only.
 */
class PhilSmsService
{
    public const DRIVER = 'philsms';

    /** A safe provider/network reason for an operator-facing test command. */
    protected ?string $lastFailureReason = null;

    /** @return 'sent'|'skipped_not_configured'|'skipped_no_phone'|'skipped_invalid_phone'|'skipped_invalid_sender_id'|'skipped_empty_message'|'failed' */
    public function send(?string $phone, string $message): string
    {
        $this->lastFailureReason = null;

        if (blank($phone)) {
            return 'skipped_no_phone';
        }

        if (!$this->isConfigured()) {
            return 'skipped_not_configured';
        }

        $recipient = $this->normalisePhilippineMobile($phone);
        if ($recipient === null) {
            Log::warning('PhilSMS skipped invalid Philippine mobile number', [
                'recipient_last4' => $this->lastFour($phone),
            ]);

            return 'skipped_invalid_phone';
        }

        $senderId = trim((string) config('services.sms.philsms_sender_id'));
        if ($senderId === '' || strlen($senderId) > 11) {
            Log::warning('PhilSMS skipped invalid sender ID configuration', [
                'length' => strlen($senderId),
            ]);

            return 'skipped_invalid_sender_id';
        }

        $message = trim($message);
        if ($message === '') {
            return 'skipped_empty_message';
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('services.sms.philsms_api_token'))
                ->asJson()
                ->timeout(15)
                ->post($this->endpoint('/sms/send'), [
                    'recipient' => $recipient,
                    'sender_id' => $senderId,
                    'type' => preg_match('/[^\x00-\x7F]/', $message) === 1 ? 'unicode' : 'plain',
                    'message' => $message,
                ]);
        } catch (\Throwable $e) {
            $this->lastFailureReason = 'Network request to PhilSMS failed: ' . $e->getMessage();
            Log::error('PhilSMS request failed', [
                'recipient_last4' => $this->lastFour($recipient),
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        if (!$response->successful() || strtolower((string) $response->json('status')) !== 'success') {
            $providerMessage = trim((string) $response->json('message'));
            $this->lastFailureReason = 'PhilSMS returned HTTP ' . $response->status()
                . ($providerMessage === '' ? '.' : ': ' . $providerMessage);
            Log::error('PhilSMS rejected SMS request', [
                'recipient_last4' => $this->lastFour($recipient),
                'http_status' => $response->status(),
                'provider_message' => $providerMessage,
            ]);

            return 'failed';
        }

        Log::info('PhilSMS accepted SMS request', [
            'recipient_last4' => $this->lastFour($recipient),
            'sender_id' => $senderId,
            'message_uid' => $response->json('data.uid'),
        ]);

        return 'sent';
    }

    /** A token-safe explanation of the most recent failed provider request. */
    public function lastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

    /**
     * Read the remaining PhilSMS units without creating an SMS message.
     *
     * @return array{status: 'available'|'not_configured'|'failed', data?: mixed}
     */
    public function balance(): array
    {
        $this->lastFailureReason = null;

        if (!$this->isConfigured()) {
            return ['status' => 'not_configured'];
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('services.sms.philsms_api_token'))
                ->timeout(15)
                ->get($this->endpoint('/balance'));
        } catch (\Throwable $e) {
            $this->lastFailureReason = 'Network request to PhilSMS failed: ' . $e->getMessage();
            Log::error('PhilSMS balance request failed', ['error' => $e->getMessage()]);

            return ['status' => 'failed'];
        }

        if (!$response->successful() || strtolower((string) $response->json('status')) !== 'success') {
            $providerMessage = trim((string) $response->json('message'));
            $this->lastFailureReason = 'PhilSMS returned HTTP ' . $response->status()
                . ($providerMessage === '' ? '.' : ': ' . $providerMessage);
            Log::error('PhilSMS rejected balance request', [
                'http_status' => $response->status(),
                'provider_message' => $providerMessage,
            ]);

            return ['status' => 'failed'];
        }

        return ['status' => 'available', 'data' => $response->json('data')];
    }

    public function isConfigured(): bool
    {
        return config('services.sms.driver') === self::DRIVER
            && filled(config('services.sms.philsms_api_token'))
            && filled(config('services.sms.philsms_sender_id'));
    }

    /** Convert 09XXXXXXXXX, 9XXXXXXXXX, +63XXXXXXXXXX, or 63XXXXXXXXXX to 63XXXXXXXXXX. */
    public function normalisePhilippineMobile(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($phone));
        if (!$digits) {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '63' . substr($digits, 1);
        } elseif (str_starts_with($digits, '9')) {
            $digits = '63' . $digits;
        }

        return preg_match('/^639\d{9}$/', $digits) === 1 ? $digits : null;
    }

    protected function endpoint(string $path): string
    {
        return rtrim((string) config('services.sms.philsms_base_url'), '/') . '/' . ltrim($path, '/');
    }

    protected function lastFour(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        return $digits ? substr($digits, -4) : 'none';
    }
}
