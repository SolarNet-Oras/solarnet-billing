<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerTroubleshootingSession;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\Ai\AiLanguageService;
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
    public function __construct(private AiLanguageService $languages) {}

    public function start(Customer $customer): array
    {
        $language = $this->languages->resolve($customer->preferred_language, '');
        $storedLanguage = $this->languages->isResponseEnabled($customer->preferred_language)
            ? $language['language']
            : null;
        $session = CustomerTroubleshootingSession::create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'stage' => 'led_check',
            'state' => [
                'issue' => 'NO_INTERNET',
                'observations' => [],
                // Keep the default only for the welcome copy. The first
                // customer reply should still be able to select Filipino.
                'language' => $storedLanguage,
                'language_observations' => [],
            ],
            'messages' => [],
            'expires_at' => now()->addHours(24),
        ]);

        return $this->respond($session, $this->ledQuestion($customer, $language['language']), 'led_check');
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
        $language = $this->languages->resolve($state['language'] ?? $customer->preferred_language, $message);
        $state = $this->recordLanguageDecision($customer, $state, $language);
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

        if (($notice = $this->languages->unsupportedLanguageNotice($language)) && empty($state['language_fallback_notified'])) {
            $assistant = $notice . "\n\n" . $assistant;
            $state['language_fallback_notified'] = true;
        }

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
        if (($leds['power'] ?? null) === 'off') return [$this->copy($state, 'power_off'), 'power_check', null];
        if (($leds['los'] ?? null) === 'red' || ($leds['pon'] ?? null) === 'off') return [$this->copy($state, 'fiber_signal'), 'fiber_check', null];
        return [$this->copy($state, 'wifi_visible'), 'wifi_visibility', null];
    }

    private function afterPowerCheck(Customer $customer, string $message, array &$state): array
    {
        if ($this->isPositive($message)) return [$this->copy($state, 'wifi_visible'), 'wifi_visibility', null];
        return [$this->copy($state, 'power_ticket'), 'ticket_confirmation', ['confidence' => 'LIKELY', 'cause' => 'power_or_equipment_issue']];
    }

    private function afterFiberCheck(Customer $customer, string $message, array &$state): array
    {
        if ($this->containsAny($message, ['red', 'still off', 'still', 'pula', 'wala pa rin', 'hindi pa rin'])) return [$this->copy($state, 'fiber_ticket'), 'ticket_confirmation', ['confidence' => 'LIKELY', 'cause' => 'optical_or_fiber_issue']];
        return [$this->copy($state, 'wifi_visible'), 'wifi_visibility', null];
    }

    private function afterWifiVisibility(Customer $customer, string $message, array &$state): array
    {
        if ($this->isNegative($message)) return [$this->copy($state, 'wifi_not_visible'), 'verify', ['confidence' => 'POSSIBLE', 'cause' => 'router_wifi_or_power_issue']];
        return [$this->copy($state, 'wifi_connect'), 'wifi_connection', null];
    }

    private function afterWifiConnection(Customer $customer, string $message, array &$state): array
    {
        if ($this->isNegative($message)) return [$this->copy($state, 'other_device'), 'device_test', ['confidence' => 'POSSIBLE', 'cause' => 'wifi_authentication_or_device_issue']];
        return $this->backendDiagnosis($customer, $state);
    }

    private function afterDeviceTest(Customer $customer, string $message, array &$state): array
    {
        if ($this->isNegative($message)) return [$this->copy($state, 'single_device'), 'verify', ['confidence' => 'LIKELY', 'cause' => 'single_device_issue']];
        return $this->backendDiagnosis($customer, $state);
    }

    private function afterBackendCheck(Customer $customer, string $message, array &$state): array
    {
        return $this->backendDiagnosis($customer, $state);
    }

    private function afterVerify(Customer $customer, string $message, array &$state): array
    {
        if ($this->isPositive($message)) return [$this->copy($state, 'restored'), 'resolved', ['confidence' => 'CONFIRMED', 'cause' => 'service_restored']];
        return [$this->copy($state, 'safe_checks_complete'), 'ticket_confirmation', $state['diagnosis'] ?? ['confidence' => 'UNKNOWN', 'cause' => 'unresolved_no_internet']];
    }

    private function afterTicketConfirmation(Customer $customer, CustomerTroubleshootingSession $session, string $message, array &$state): array
    {
        if ($this->isPositive($message)) {
            $result = $this->createTicket($customer, $session);
            return [$this->copy($state, 'ticket_created', ['ticket_number' => $result['ticket']['ticket_number']]), 'ticket_created', $state['diagnosis'] ?? null];
        }
        return [$this->copy($state, 'ticket_declined'), 'active', $state['diagnosis'] ?? null];
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
                return [$this->copy($state, 'paid_not_restored', ['status' => strtoupper($snapshot['service_status'])]), 'verify', $diagnosis];
            }
            $diagnosis = ['confidence' => 'CONFIRMED', 'cause' => 'billing_suspension', 'balance' => $billing['balance'], 'due_date' => $billing['due_date'], 'suspension_date' => $billing['suspension_date']];
            return [$this->copy($state, 'billing_suspension', [
                'status' => strtoupper($snapshot['service_status']),
                'balance' => $this->peso($billing['balance']),
                'due_date' => $billing['due_date'] ? ', due ' . $billing['due_date'] : '',
            ]), 'verify', $diagnosis];
        }
        if ($snapshot['service_status'] === 'pending') return [$this->copy($state, 'pending_activation'), 'ticket_confirmation', ['confidence' => 'CONFIRMED', 'cause' => 'pending_activation']];
        if ($billing['balance'] > 0 && $snapshot['service_status'] === 'active') {
            $diagnosis = ['confidence' => 'CONFIRMED', 'cause' => 'active_grace_period', 'balance' => $billing['balance'], 'due_date' => $billing['due_date']];
            return [$this->copy($state, 'active_grace', ['balance' => $this->peso($billing['balance'])]), 'verify', $diagnosis];
        }
        if (!$network['dhcp_bound']) {
            $diagnosis = ['confidence' => 'LIKELY', 'cause' => 'device_or_session_not_visible'];
            return [$this->copy($state, 'dhcp_missing'), 'verify', $diagnosis];
        }
        $diagnosis = ['confidence' => 'POSSIBLE', 'cause' => 'active_service_network_or_dns_issue'];
        return [$this->copy($state, 'active_network'), 'verify', $diagnosis];
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
        return [
            'session' => [
                'id' => $session->id,
                'status' => $session->fresh()->status,
                'stage' => $stage,
                'diagnosis' => $diagnosis ?: ($session->diagnosis ?: null),
                'language' => $state['language'] ?? $this->languages->defaultLanguage(),
            ],
            'assistant' => $assistant,
            'next_question' => $stage === 'ticket_confirmation' ? $this->copy($state, 'ticket_question') : null,
        ];
    }

    private function ledQuestion(Customer $customer, string $language): string
    {
        $model = trim((string) ($customer->onu_information ?? ''));
        return $this->copy(['language' => $language], 'led_question', [
            'model' => $model ? ' (' . $model . ')' : '',
        ]);
    }

    /** @param array<string, mixed> $state @param array<string, string> $replace */
    private function copy(array $state, string $key, array $replace = []): string
    {
        $language = ($state['language'] ?? 'en') === 'fil' ? 'fil' : 'en';
        $templates = [
            'en' => [
                'led_question' => "I'm sorry you're having trouble. Let's check it step by step. Please look at your router or ONU{model}. Tell me which lights are ON, OFF, or RED. If possible include Power, PON, LOS, Internet/WAN, and WLAN/Wi-Fi. Please report only the light names you see.",
                'power_off' => 'Please check that the power adapter is firmly connected and that the outlet works. Wait about 30 seconds, then tell me whether the Power light is on.',
                'fiber_signal' => 'The lights may indicate that the fiber signal is not reaching the ONU normally. Please do not bend or disconnect the fiber. Check only that the connector is firmly seated, then tell me whether LOS is still red or PON is still off.',
                'wifi_visible' => 'Thank you. Is your SolarNet Wi-Fi name visible on your phone?',
                'power_ticket' => 'Please use only a normal power check and do not press the reset button. If the Power light stays off after checking the outlet and adapter, would you like SolarNet to create a technician ticket?',
                'fiber_ticket' => 'The fiber signal still does not look normal. Do not open the fiber connector or factory-reset the ONU. I recommend a technician inspection. Would you like me to create a support ticket?',
                'wifi_not_visible' => 'If the Wi-Fi name is not visible, check that the router is powered and that its WLAN light is on. Do not factory-reset it. Does the Wi-Fi name appear after a normal restart?',
                'wifi_connect' => 'Can your phone connect to that Wi-Fi network?',
                'other_device' => 'Does the same problem happen on another phone or laptop?',
                'single_device' => 'That points more toward the first device or its Wi-Fi settings than a SolarNet outage. Please reconnect that device to Wi-Fi and try once more. Is it working now?',
                'restored' => 'Good to hear. Please keep the router in its normal state. Your connection appears to be working again.',
                'safe_checks_complete' => 'I have completed the safe checks available to the portal. Would you like me to create a technical support ticket with these diagnostics?',
                'ticket_created' => 'Your technical support ticket has been created. SolarNet staff can now review the diagnostic details. Ticket number: {ticket_number}.',
                'ticket_declined' => 'No ticket was created. You can continue the check or start a new troubleshooting session whenever you need help.',
                'paid_not_restored' => 'Your account still shows as {status} in the network system, but there is no current outstanding balance requiring another payment. Please do not pay again. This looks like a service-restoration synchronization issue and needs staff review. Is your internet still unavailable?',
                'billing_suspension' => 'I checked your SolarNet account. Your service is currently {status} because of an outstanding balance of {balance}{due_date}. After payment is confirmed, the service can be restored. If you already paid, please tell me so we can check the payment instead of asking you to pay again.',
                'pending_activation' => 'Your account is still pending activation. This is not a suspension. SolarNet staff must complete the installation and activation workflow before normal service can begin.',
                'active_grace' => 'Your service is ACTIVE according to SolarNet. You have an outstanding balance of {balance}, but this does not currently show as a suspension. Please try opening a website after a normal router restart. Does it work now?',
                'dhcp_missing' => 'Your account is ACTIVE and paid, but SolarNet does not currently see a bound DHCP lease for your device. Your router may not have reconnected yet. Please restart it normally, wait for the lights to stabilize, and try a website. Does it work now?',
                'active_network' => 'Your account is ACTIVE and the network has a current lease for your service. This does not look like a billing suspension. Please try one other device and open a website. If all devices still fail, I can create a technician ticket.',
                'ticket_question' => 'Would you like me to create a support ticket?',
            ],
            'fil' => [
                'led_question' => 'Pasensya na po sa abala. I-check natin ito nang paisa-isa. Pakitingnan po ang inyong router o ONU{model}. Sabihin po kung alin sa mga ilaw ang ON, OFF, o pula. Kung makita ninyo, isama ang Power, PON, LOS, Internet/WAN, at WLAN/Wi-Fi. Ilaw lamang po na nakikita ninyo ang i-report.',
                'power_off' => 'Pakitingnan po kung maayos na nakakabit ang power adapter at gumagana ang saksakan. Maghintay po nang mga 30 segundo, pagkatapos sabihin kung naka-on na ang Power light.',
                'fiber_signal' => 'Mukhang hindi normal ang fiber signal papunta sa ONU. Huwag pong baluktutin o tanggalin ang fiber cable. Pakitingnan lamang kung maayos ang pagkakakabit ng connector, pagkatapos sabihin kung pula pa rin ang LOS o naka-off pa rin ang PON.',
                'wifi_visible' => 'Salamat po. Nakikita po ba sa phone ninyo ang pangalan ng SolarNet Wi-Fi?',
                'power_ticket' => 'Normal power check lang po at huwag pindutin ang reset button. Kung naka-off pa rin ang Power light matapos i-check ang saksakan at adapter, gusto po ba ninyong gumawa ako ng technician ticket?',
                'fiber_ticket' => 'Hindi pa rin normal ang fiber signal. Huwag pong buksan ang fiber connector o mag-factory reset ng ONU. Mas mainam po ang technician inspection. Gusto po ba ninyong gumawa ako ng support ticket?',
                'wifi_not_visible' => 'Kung hindi nakikita ang Wi-Fi name, pakitingnan kung may power ang router at naka-on ang WLAN light. Huwag pong mag-factory reset. Lumabas po ba ang Wi-Fi name pagkatapos ng normal na restart?',
                'wifi_connect' => 'Nakakakonekta po ba ang phone ninyo sa Wi-Fi na iyon?',
                'other_device' => 'Nangyayari rin po ba ang parehong problema sa ibang phone o laptop?',
                'single_device' => 'Mukhang mas nasa unang device o Wi-Fi settings nito ang problema kaysa SolarNet outage. Pakikonekta muli ang device sa Wi-Fi at subukan ulit. Gumagana na po ba?',
                'restored' => 'Mabuti po. Panatilihin lang ang router sa normal nitong setup. Mukhang gumagana na uli ang connection ninyo.',
                'safe_checks_complete' => 'Natapos ko na po ang mga ligtas na checks na available sa portal. Gusto po ba ninyong gumawa ako ng technical support ticket kasama ang diagnostic details?',
                'ticket_created' => 'Nagawa na po ang technical support ticket ninyo. Maaaring i-review ng SolarNet staff ang diagnostic details. Ticket number: {ticket_number}.',
                'ticket_declined' => 'Wala pong nagawang ticket. Maaari ninyong ipagpatuloy ang check o magsimula ng bagong troubleshooting session kapag kailangan ninyo ng tulong.',
                'paid_not_restored' => 'Ang account ninyo ay {status} pa rin sa network system, pero wala nang current outstanding balance na kailangang bayaran. Huwag na po munang magbayad ulit. Mukhang kailangan ng staff review para sa service-restoration synchronization. Wala pa rin po ba kayong internet?',
                'billing_suspension' => 'Na-check ko po ang SolarNet account ninyo. Ang service status ay {status} dahil may outstanding balance na {balance}{due_date}. Kapag confirmed na ang payment, maaari nang maibalik ang service. Kung nakapagbayad na po kayo, sabihin lamang para ma-check natin at hindi kayo magbayad ulit.',
                'pending_activation' => 'Pending activation pa po ang account ninyo. Hindi ito suspension. Kailangan munang matapos ng SolarNet staff ang installation at activation workflow bago magsimula ang normal na service.',
                'active_grace' => 'ACTIVE po ang service ninyo ayon sa SolarNet. May outstanding balance na {balance}, pero hindi pa ito suspension. Subukan po munang magbukas ng website pagkatapos ng normal na restart ng router. Gumagana na po ba?',
                'dhcp_missing' => 'ACTIVE at paid po ang account ninyo, pero wala pang nakikitang bound DHCP lease ang SolarNet para sa device ninyo. Maaaring hindi pa ito nakareconnect. Pakirestart nang normal ang router, hintaying maging stable ang lights, at subukang magbukas ng website. Gumagana na po ba?',
                'active_network' => 'ACTIVE po ang account ninyo at may current lease ang network para sa service ninyo. Hindi ito mukhang billing suspension. Subukan po sa isa pang device at magbukas ng website. Kung wala pa rin sa lahat ng device, maaari akong gumawa ng technician ticket.',
                'ticket_question' => 'Gusto po ba ninyong gumawa ako ng support ticket?',
            ],
        ];

        return strtr($templates[$language][$key] ?? $templates['en'][$key] ?? '', $replace);
    }

    private function peso(float $amount): string
    {
        return "\xE2\x82\xB1" . number_format($amount, 2);
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $decision */
    private function recordLanguageDecision(Customer $customer, array $state, array $decision): array
    {
        $history = $state['language_observations'] ?? [];
        $history[] = $decision['detected_language'];
        $history = array_slice($history, -6);
        $state['language_observations'] = $history;
        $state['language'] = $decision['language'];

        if ($candidate = $this->languages->customerPreferenceCandidate($customer->preferred_language, $decision, $history)) {
            $customer->update(['preferred_language' => $candidate]);
        }

        return $state;
    }

    private function parseLeds(string $message): array
    {
        $result = [];
        foreach (['power', 'pon', 'los', 'internet', 'wan', 'wlan', 'wifi'] as $led) {
            $value = null;
            if (preg_match('/\b' . preg_quote($led, '/') . '\b\s*(?:is|=|:|ay|po ay)?\s*(on|off|green|red|blinking|blink|pula|berde)/iu', $message, $m)) {
                $value = mb_strtolower($m[1]);
            } elseif (preg_match('/\b(red|green|pula|berde|on|off)\b(?:\s+\p{L}+){0,3}\s+\b' . preg_quote($led, '/') . '\b/iu', $message, $m)) {
                $value = mb_strtolower($m[1]);
            }
            if ($value !== null) {
                $result[$led] = in_array($value, ['green', 'berde', 'blinking', 'blink', 'on'], true)
                    ? 'on'
                    : (in_array($value, ['red', 'pula'], true) ? 'red' : 'off');
            }
        }
        return $result;
    }

    private function isPositive(string $message): bool { return $this->containsAny($message, ['yes', 'working', 'works', 'on now', 'appears', 'connected', 'fixed', 'restored', 'create', 'please do', 'oo', 'opo', 'gumagana', 'naayos', 'lumabas', 'nakakonekta', 'sige']); }
    private function isNegative(string $message): bool { return $this->containsAny($message, ['no', 'not', 'still', 'cannot', "can't", 'unable', 'off', 'hindi', 'wala', 'walang', 'ayaw', 'di pa', 'hindi pa', 'wala pa rin']); }
    private function containsAny(string $message, array $needles): bool
    {
        $message = mb_strtolower($message);
        foreach ($needles as $needle) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($needle), '/') . '(?![\p{L}\p{N}])/u', $message)) {
                return true;
            }
        }
        return false;
    }
}
