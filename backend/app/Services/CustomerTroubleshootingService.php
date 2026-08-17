<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerTroubleshootingSession;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer-scoped, stateful troubleshooting flow.
 *
 * It intentionally uses a small deterministic decision tree instead of
 * allowing a model to invent network conclusions or run RouterOS commands.
 * All network facts are read from records already synchronized by SolarNet.
 */
class CustomerTroubleshootingService
{
    public function start(Customer $customer): array
    {
        $session = CustomerTroubleshootingSession::create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'stage' => 'led_check',
            'state' => ['issue' => 'NO_INTERNET', 'observations' => []],
            'messages' => [],
            'expires_at' => now()->addHours(24),
        ]);

        return $this->respond($session, $this->ledQuestion($customer), 'led_check');
    }

    public function reply(Customer $customer, CustomerTroubleshootingSession $session, string $message): array
    {
        if ($session->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['session' => 'Troubleshooting session not found.']);
        }
        if ($session->status !== 'active' || ($session->expires_at && $session->expires_at->isPast())) {
            throw ValidationException::withMessages(['session' => 'This troubleshooting session has expired. Start a new check.']);
        }

        $message = trim($message);
        if ($message === '') throw ValidationException::withMessages(['message' => 'Please describe what you see or try.']);

        $state = $session->state ?: ['issue' => 'NO_INTERNET', 'observations' => []];
        $stage = $session->stage ?: 'led_check';
        $state['observations'] = $state['observations'] ?? [];
        $state['observations'][] = ['stage' => $stage, 'answer' => mb_substr($message, 0, 500)];
        $state['observations'] = array_slice($state['observations'], -12);

        [$assistant, $nextStage, $diagnosis] = match ($stage) {
            'led_check' => $this->afterLedCheck($customer, $message, $state),
            'power_check' => $this->afterPowerCheck($customer, $message, $state),
            'fiber_check' => $this->afterFiberCheck($customer, $message, $state),
            'wifi_visibility' => $this->afterWifiVisibility($customer, $message, $state),
            'wifi_connection' => $this->afterWifiConnection($customer, $message, $state),
            'device_test' => $this->afterDeviceTest($customer, $message, $state),
            'verify' => $this->afterVerify($customer, $message, $state),
            'ticket_confirmation' => $this->afterTicketConfirmation($customer, $session, $message, $state),
            default => $this->afterBackendCheck($customer, $message, $state),
        };

        if ($diagnosis) $state['diagnosis'] = $diagnosis;
        $state['last_backend_check'] = $state['last_backend_check'] ?? null;
        return $this->respond($session, $assistant, $nextStage, $diagnosis, $state);
    }

    public function createTicket(Customer $customer, CustomerTroubleshootingSession $session): array
    {
        if ($session->customer_id !== $customer->id || $session->status !== 'active' || $session->stage !== 'ticket_confirmation') {
            throw ValidationException::withMessages(['session' => 'This troubleshooting session is no longer active.']);
        }

        $state = $session->state ?: [];
        $snapshot = $this->backendSnapshot($customer);
        $description = $this->ticketDescription($customer, $state, $snapshot);

        $ticket = DB::transaction(function () use ($customer, $description, $session, $state) {
            $ticket = Ticket::create([
                'ticket_number' => app(TicketService::class)->generateTicketNumber(),
                'customer_id' => $customer->id,
                'subject' => 'No Internet — interactive troubleshooting escalation',
                'description' => $description,
                'priority' => 'medium',
                'category' => 'technical',
                'ticket_type' => 'repair',
                'status' => 'open',
                'workflow_status' => 'open',
            ]);
            TicketComment::create([
                'ticket_id' => $ticket->id,
                'customer_id' => $customer->id,
                'comment' => 'Created by the customer after the SolarNet troubleshooting flow remained unresolved.',
                'is_internal' => false,
            ]);
            app(TicketWorkflowService::class)->history($ticket, null, 'customer_troubleshooting_escalated', null, 'open', null, ['session_id' => $session->id]);
            $session->update(['status' => 'escalated', 'stage' => 'ticket_created', 'ticket_id' => $ticket->id, 'completed_at' => now(), 'diagnosis' => $state['diagnosis'] ?? null]);
            return $ticket;
        });

        return ['ticket' => ['id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'status' => $ticket->status], 'message' => 'Your technical support ticket has been created. SolarNet staff can now review the diagnostic details.'];
    }

    private function afterLedCheck(Customer $customer, string $message, array &$state): array
    {
        $leds = $this->parseLeds($message);
        $state['leds'] = $leds;
        if (($leds['power'] ?? null) === 'off') return ['Please check that the power adapter is firmly connected and that the outlet works. Wait about 30 seconds, then tell me whether the Power light is on.', 'power_check', null];
        if (($leds['los'] ?? null) === 'red' || ($leds['pon'] ?? null) === 'off') return ['The lights may indicate that the fiber signal is not reaching the ONU normally. Do not bend or disconnect the fiber. Check only that the connector is firmly seated and tell me whether LOS is still red or PON is still off.', 'fiber_check', null];
        return ['Thank you. Is your SolarNet Wi-Fi name visible on your phone?', 'wifi_visibility', null];
    }

    private function afterPowerCheck(Customer $customer, string $message, array &$state): array
    {
        if ($this->isPositive($message)) return ['Good. Is your SolarNet Wi-Fi name visible on your phone?', 'wifi_visibility', null];
        return ['Please use only a normal power check—do not press the reset button. If the Power light stays off after checking the outlet and adapter, would you like SolarNet to create a technician ticket?', 'ticket_confirmation', ['confidence' => 'LIKELY', 'cause' => 'power_or_equipment_issue']];
    }

    private function afterFiberCheck(Customer $customer, string $message, array &$state): array
    {
        if ($this->containsAny($message, ['red', 'still off', 'still'])) return ['The fiber signal still does not look normal. Do not open the fiber connector or factory-reset the ONU. I recommend a technician inspection. Would you like me to create a support ticket?', 'ticket_confirmation', ['confidence' => 'LIKELY', 'cause' => 'optical_or_fiber_issue']];
        return ['Thanks. Is your SolarNet Wi-Fi name visible on your phone?', 'wifi_visibility', null];
    }

    private function afterWifiVisibility(Customer $customer, string $message, array &$state): array
    {
        if ($this->isNegative($message)) return ['If the Wi-Fi name is not visible, check that the router is powered and that its WLAN light is on. Do not factory-reset it. Does the Wi-Fi name appear after a normal restart?', 'verify', ['confidence' => 'POSSIBLE', 'cause' => 'router_wifi_or_power_issue']];
        return ['Can your phone connect to that Wi-Fi network?', 'wifi_connection', null];
    }

    private function afterWifiConnection(Customer $customer, string $message, array &$state): array
    {
        if ($this->isNegative($message)) return ['Does the same problem happen on another phone or laptop?', 'device_test', ['confidence' => 'POSSIBLE', 'cause' => 'wifi_authentication_or_device_issue']];
        return $this->backendDiagnosis($customer, $state);
    }

    private function afterDeviceTest(Customer $customer, string $message, array &$state): array
    {
        if ($this->isNegative($message)) return ['That points more toward the first device or its Wi-Fi settings than a SolarNet outage. Please reconnect that device to Wi-Fi and try once more. Is it working now?', 'verify', ['confidence' => 'LIKELY', 'cause' => 'single_device_issue']];
        return $this->backendDiagnosis($customer, $state);
    }

    private function afterBackendCheck(Customer $customer, string $message, array &$state): array
    {
        return $this->backendDiagnosis($customer, $state);
    }

    private function afterVerify(Customer $customer, string $message, array &$state): array
    {
        if ($this->isPositive($message)) return ['Great—please keep the router in its normal state. Your connection appears to be working again.', 'resolved', ['confidence' => 'CONFIRMED', 'cause' => 'service_restored']];
        return ['I have completed the safe checks available to the portal. Would you like me to create a technical support ticket with these diagnostics?', 'ticket_confirmation', $state['diagnosis'] ?? ['confidence' => 'UNKNOWN', 'cause' => 'unresolved_no_internet']];
    }

    private function afterTicketConfirmation(Customer $customer, CustomerTroubleshootingSession $session, string $message, array &$state): array
    {
        if ($this->isPositive($message)) {
            $result = $this->createTicket($customer, $session);
            return [$result['message'] . ' Ticket number: ' . $result['ticket']['ticket_number'] . '.', 'ticket_created', $state['diagnosis'] ?? null];
        }
        return ['No ticket was created. You can continue the check or start a new troubleshooting session whenever you need help.', 'active', $state['diagnosis'] ?? null];
    }

    private function backendDiagnosis(Customer $customer, array &$state): array
    {
        $snapshot = $this->backendSnapshot($customer);
        $state['last_backend_check'] = $snapshot;
        $billing = $snapshot['billing'];
        $network = $snapshot['network'];

        if (in_array($snapshot['service_status'], ['suspended', 'expired'], true)) {
            if (($snapshot['payment']['status'] ?? null) === 'confirmed' || $billing['balance'] <= 0) {
                $diagnosis = ['confidence' => 'CONFIRMED', 'cause' => 'payment_confirmed_but_service_not_restored'];
                return ['Your account is still showing as ' . strtoupper($snapshot['service_status']) . ' in the network system, but there is no current outstanding balance requiring another payment. Please do not pay again. This looks like a service-restoration synchronization issue and needs staff review. Is your internet still unavailable?', 'verify', $diagnosis];
            }
            $diagnosis = ['confidence' => 'CONFIRMED', 'cause' => 'billing_suspension', 'balance' => $billing['balance'], 'due_date' => $billing['due_date'], 'suspension_date' => $billing['suspension_date']];
            return ['I checked your SolarNet account. Your service is currently ' . strtoupper($snapshot['service_status']) . ' because of an outstanding balance of ₱' . number_format($billing['balance'], 2) . ($billing['due_date'] ? ', due ' . $billing['due_date'] : '') . '. Pay the balance through the portal, wait for confirmation, then your service can be restored. If you already paid, tell me and I will check the payment instead of asking you to pay again.', 'verify', $diagnosis];
        }
        if ($snapshot['service_status'] === 'pending') return ['Your account is still pending activation. This is not a suspension. SolarNet staff must complete the installation/activation workflow before normal service can begin.', 'ticket_confirmation', ['confidence' => 'CONFIRMED', 'cause' => 'pending_activation']];
        if ($billing['balance'] > 0 && $snapshot['service_status'] === 'active') {
            $diagnosis = ['confidence' => 'CONFIRMED', 'cause' => 'active_grace_period', 'balance' => $billing['balance'], 'due_date' => $billing['due_date']];
            return ['Your service is ACTIVE according to SolarNet. You have an outstanding balance of ₱' . number_format($billing['balance'], 2) . ', but this does not currently show as a suspension. Let’s continue with the connection check. Please try opening a website after a normal router restart. Does it work now?', 'verify', $diagnosis];
        }
        if (!$network['dhcp_bound']) {
            $diagnosis = ['confidence' => 'LIKELY', 'cause' => 'device_or_session_not_visible'];
            return ['Your account is ACTIVE and paid, but SolarNet does not currently see a bound DHCP lease for your device. Your router may not have reconnected yet. Please restart it normally, wait for the lights to stabilize, and try a website. Does it work now?', 'verify', $diagnosis];
        }
        $diagnosis = ['confidence' => 'POSSIBLE', 'cause' => 'active_service_network_or_dns_issue'];
        return ['Your account is ACTIVE and the network has a current lease for your service. That means this does not look like a billing suspension. Please try one other device and open a website. If all devices still fail, I can create a technician ticket.', 'verify', $diagnosis];
    }

    private function backendSnapshot(Customer $customer): array
    {
        $customer->loadMissing(['servicePlan', 'router']);
        $invoice = Invoice::query()->where('customer_id', $customer->id)->where('balance', '>', 0)->whereIn('status', ['sent', 'partial', 'overdue'])->orderBy('due_date')->first();
        $payment = Payment::query()->where('customer_id', $customer->id)->latest('payment_date')->first();
        $checkout = $payment?->paymongoCheckout;
        $lease = DhcpLease::query()->where('customer_id', $customer->id)->where('is_current', true)->where('status', 'bound')->latest('last_seen_at')->first();
        $schedule = app(BillingSuspensionService::class)->gracePeriodSchedule($customer);
        $paymentStatus = $checkout ? (in_array($checkout->status, ['paid', 'succeeded', 'processing'], true) ? 'confirmed' : 'pending') : null;

        return [
            'service_status' => (string) $customer->status,
            'billing' => [
                'balance' => round((float) ($invoice?->balance ?? 0), 2),
                'due_date' => $invoice?->due_date?->toDateString(),
                'grace_period_end' => $schedule['grace_period_end']?->toDateString(),
                'suspension_date' => $schedule['suspension_at']?->toDateString(),
            ],
            'payment' => $payment ? ['status' => $paymentStatus ?: 'recorded', 'amount' => (float) $payment->amount, 'date' => $payment->payment_date?->toDateString()] : ['status' => null],
            'network' => [
                'router_status' => $customer->router?->connection_status,
                'dhcp_bound' => (bool) $lease,
                'last_seen' => $lease?->last_seen_at?->toIso8601String(),
                'ip_address' => $lease?->ip_address,
            ],
        ];
    }

    private function ticketDescription(Customer $customer, array $state, array $snapshot): string
    {
        $lines = [
            'Interactive SolarNet troubleshooting escalation.',
            'Issue: No Internet',
            'Customer: ' . $customer->full_name,
            'Account: ' . $customer->account_number,
            'Service status: ' . strtoupper((string) $snapshot['service_status']),
            'Billing: ₱' . number_format((float) $snapshot['billing']['balance'], 2) . ' outstanding; due ' . ($snapshot['billing']['due_date'] ?: 'none'),
            'DHCP lease: ' . ($snapshot['network']['dhcp_bound'] ? 'current bound lease' : 'not currently visible'),
            'Last seen: ' . ($snapshot['network']['last_seen'] ?: 'unknown'),
            'Assessment: ' . (($state['diagnosis']['confidence'] ?? 'UNKNOWN') . ' — ' . ($state['diagnosis']['cause'] ?? 'unresolved_no_internet')),
            'Customer observations:',
        ];
        foreach (array_slice($state['observations'] ?? [], -8) as $observation) $lines[] = '- ' . $observation['stage'] . ': ' . $observation['answer'];
        return implode("\n", $lines);
    }

    private function respond(CustomerTroubleshootingSession $session, string $assistant, string $stage, ?array $diagnosis = null, ?array $state = null): array
    {
        $state = $state ?? ($session->state ?: []);
        $messages = $session->messages ?: [];
        $messages[] = ['role' => 'assistant', 'content' => $assistant, 'created_at' => now()->toIso8601String()];
        $messages = array_slice($messages, -24);
        $status = $stage === 'ticket_created' ? 'escalated' : (in_array($stage, ['resolved'], true) ? 'completed' : 'active');
        $session->update(['stage' => $stage, 'status' => $status, 'state' => $state, 'messages' => $messages, 'diagnosis' => $diagnosis ?: ($session->diagnosis ?: null), 'completed_at' => $status !== 'active' ? now() : null]);
        return ['session' => ['id' => $session->id, 'status' => $session->fresh()->status, 'stage' => $stage, 'diagnosis' => $diagnosis ?: ($session->diagnosis ?: null)], 'assistant' => $assistant, 'next_question' => $stage === 'ticket_confirmation' ? 'Would you like me to create a support ticket?' : null];
    }

    private function ledQuestion(Customer $customer): string
    {
        $model = trim((string) ($customer->onu_information ?? ''));
        return 'I’m sorry you’re having trouble. Let’s check it step by step. Please look at your router or ONU' . ($model ? ' (' . $model . ')' : '') . '. Tell me which lights are ON, OFF, or RED. If possible include Power, PON, LOS, Internet/WAN, and WLAN/Wi-Fi. The exact lights depend on your equipment, so please only report the names you see.';
    }

    private function parseLeds(string $message): array
    {
        $result = [];
        foreach (['power', 'pon', 'los', 'internet', 'wan', 'wlan', 'wifi'] as $led) {
            if (preg_match('/\b' . preg_quote($led, '/') . '\b\s*(?:is|=|:)?\s*(on|off|green|red|blinking|blink)/i', $message, $m)) {
                $value = strtolower($m[1]);
                $result[$led] = $value === 'green' || $value === 'blinking' || $value === 'blink' ? 'on' : $value;
            }
        }
        return $result;
    }

    private function isPositive(string $message): bool { return $this->containsAny($message, ['yes', 'working', 'works', 'on now', 'appears', 'connected', 'fixed', 'restored', 'create', 'please do']); }
    private function isNegative(string $message): bool { return $this->containsAny($message, ['no', 'not', 'still', 'cannot', "can't", 'unable', 'off']); }
    private function containsAny(string $message, array $needles): bool { $message = strtolower($message); foreach ($needles as $needle) if (str_contains($message, $needle)) return true; return false; }
}
