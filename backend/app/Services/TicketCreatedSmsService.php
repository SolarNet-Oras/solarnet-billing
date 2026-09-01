<?php

namespace App\Services;

use App\Jobs\SendTicketCreatedSms;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TicketCreatedSmsService
{
    /**
     * Make the first provider request immediately after commit. Only a real
     * provider/network failure is queued for retry, so delivery does not rely
     * on a healthy worker for its initial attempt.
     */
    public function sendAfterCreation(string $ticketId): string
    {
        try {
            $result = $this->deliver($ticketId);
            if ($result === 'failed') {
                $this->queueRetry($ticketId);
            }

            return $result;
        } catch (Throwable $exception) {
            Log::error('Immediate ticket-created SMS attempt crashed; retry queued', [
                'ticket_id' => $ticketId,
                'error' => $exception->getMessage(),
            ]);
            $this->queueRetry($ticketId);

            return 'failed';
        }
    }

    private function queueRetry(string $ticketId): void
    {
        try {
            SendTicketCreatedSms::dispatch($ticketId)->delay(now()->addMinute());
        } catch (Throwable $exception) {
            Log::error('Ticket-created SMS retry could not be queued', [
                'ticket_id' => $ticketId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @return 'sent'|'failed'|'skipped_not_configured'|'skipped_no_phone'|'skipped_invalid_phone'|'skipped_invalid_sender_id'|'skipped_empty_message' */
    public function deliver(string $ticketId): string
    {
        $ticket = Ticket::with('customer')->find($ticketId);
        if (! $ticket || ! $ticket->customer) {
            Log::warning('Ticket-created SMS skipped because its ticket or customer no longer exists', ['ticket_id' => $ticketId]);

            return 'skipped_no_phone';
        }

        $sms = app(PhilSmsService::class);
        $result = $sms->send($ticket->customer->contact_number, $this->message($ticket));

        Log::log($result === 'sent' ? 'info' : ($result === 'failed' ? 'warning' : 'notice'), 'Ticket-created SMS delivery result', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'customer_id' => $ticket->customer_id,
            'result' => $result,
            'provider_message_id' => $sms->lastProviderMessageId(),
            'failure_reason' => $sms->lastFailureReason(),
        ]);

        return $result;
    }

    public function message(Ticket $ticket): string
    {
        $name = Str::limit(trim((string) $ticket->customer?->full_name), 32, '');
        $subject = Str::limit((string) preg_replace('/\s+/', ' ', trim((string) $ticket->subject)), 72, '...');
        return "SOLARNET SUPPORT\n\n"
            . "Hi {$name},\n\n"
            . "Your ticket {$ticket->ticket_number} has been created.\n"
            . "Concern: {$subject}\n\n"
            . "Please be advised that our technician may visit your home at any time within 48 hours. Please be patient. We sincerely apologize for the inconvenience.\n\n"
            . "Keep your ticket number for follow-up.\n"
            . "This is an auto-generated SMS.";
    }
}
