<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Server-side Semaphore client for approved transactional and billing SMS. */
class SemaphoreSmsService
{
    public const DRIVER = 'semaphore';

    protected ?string $lastFailureReason = null;
    protected ?string $lastProviderMessageId = null;

    /** @return 'sent'|'skipped_not_configured'|'skipped_no_phone'|'skipped_invalid_phone'|'skipped_empty_message'|'failed' */
    public function send(?string $phone, string $message): string
    {
        $this->lastFailureReason = null;
        $this->lastProviderMessageId = null;

        if (blank($phone)) return 'skipped_no_phone';
        if (! $this->isConfigured()) return 'skipped_not_configured';

        $recipient = $this->normalisePhilippineMobile($phone);
        if ($recipient === null) {
            Log::warning('Semaphore skipped invalid Philippine mobile number', ['recipient_last4' => $this->lastFour($phone)]);
            return 'skipped_invalid_phone';
        }

        $message = trim($message);
        if ($message === '') return 'skipped_empty_message';

        $payload = [
            'apikey' => (string) config('services.sms.semaphore_api_key'),
            'number' => $recipient,
            'message' => $message,
        ];
        $senderName = trim((string) config('services.sms.semaphore_sender_name'));
        if ($senderName !== '') $payload['sendername'] = $senderName;

        try {
            $response = Http::acceptJson()->asForm()->timeout(15)
                ->post($this->endpoint('/messages'), $payload);
        } catch (\Throwable $e) {
            $this->lastFailureReason = 'Network request to Semaphore failed: '.$e->getMessage();
            Log::error('Semaphore request failed', ['recipient_last4' => $this->lastFour($recipient), 'error' => $e->getMessage()]);
            return 'failed';
        }

        $record = $response->json('0');
        $status = strtolower((string) ($record['status'] ?? ''));
        if (! $response->successful() || ! is_array($record) || ! in_array($status, ['queued', 'pending', 'sent'], true)) {
            $providerMessage = trim((string) ($response->json('message') ?? $response->json('error') ?? ''));
            $this->lastFailureReason = 'Semaphore returned HTTP '.$response->status().($providerMessage === '' ? '.' : ': '.$providerMessage);
            Log::error('Semaphore rejected SMS request', ['recipient_last4' => $this->lastFour($recipient), 'http_status' => $response->status(), 'provider_message' => $providerMessage]);
            return 'failed';
        }

        $this->lastProviderMessageId = filled($record['message_id'] ?? null) ? (string) $record['message_id'] : null;
        Log::info('Semaphore accepted SMS request', [
            'recipient_last4' => $this->lastFour($recipient),
            'sender_name' => $record['sender_name'] ?? $senderName ?: null,
            'message_id' => $this->lastProviderMessageId,
            'provider_status' => $record['status'] ?? null,
        ]);

        return 'sent';
    }

    public function lastFailureReason(): ?string { return $this->lastFailureReason; }
    public function lastProviderMessageId(): ?string { return $this->lastProviderMessageId; }

    /** @return array{status: 'available'|'not_configured'|'failed', data?: mixed} */
    public function balance(): array
    {
        $this->lastFailureReason = null;
        if (! $this->isConfigured()) return ['status' => 'not_configured'];

        try {
            $response = Http::acceptJson()->timeout(15)->get($this->endpoint('/account'), [
                'apikey' => (string) config('services.sms.semaphore_api_key'),
            ]);
        } catch (\Throwable $e) {
            $this->lastFailureReason = 'Network request to Semaphore failed: '.$e->getMessage();
            Log::error('Semaphore account request failed', ['error' => $e->getMessage()]);
            return ['status' => 'failed'];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            $message = trim((string) ($response->json('message') ?? $response->json('error') ?? ''));
            $this->lastFailureReason = 'Semaphore returned HTTP '.$response->status().($message === '' ? '.' : ': '.$message);
            return ['status' => 'failed'];
        }

        return ['status' => 'available', 'data' => $response->json()];
    }

    public function isConfigured(): bool
    {
        return config('services.sms.driver') === self::DRIVER
            && filled(config('services.sms.semaphore_api_key'));
    }

    public function normalisePhilippineMobile(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($phone));
        if (! $digits) return null;
        if (str_starts_with($digits, '0')) $digits = '63'.substr($digits, 1);
        elseif (str_starts_with($digits, '9')) $digits = '63'.$digits;
        return preg_match('/^639\d{9}$/', $digits) === 1 ? $digits : null;
    }

    protected function endpoint(string $path): string
    {
        return rtrim((string) config('services.sms.semaphore_base_url'), '/').'/'.ltrim($path, '/');
    }

    protected function lastFour(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        return $digits ? substr($digits, -4) : 'none';
    }
}
