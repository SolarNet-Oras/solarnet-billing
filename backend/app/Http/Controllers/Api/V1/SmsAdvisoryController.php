<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendSmsAdvisoryRecipient;
use App\Models\Customer;
use App\Models\SmsAdvisoryCampaign;
use App\Models\SmsAdvisoryRecipient;
use App\Services\PhilSmsService;
use App\Services\Ai\OpenAiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SmsAdvisoryController extends Controller
{
    public function index(): JsonResponse
    {
        $campaigns = SmsAdvisoryCampaign::with('creator:id,name')
            ->latest()->limit(30)->get();

        return response()->json(['data' => $campaigns]);
    }

    public function preview(Request $request, PhilSmsService $sms): JsonResponse
    {
        $data = $this->validated($request, false);
        $recipients = $this->recipients($data['recipient_filter'], $sms);

        return response()->json(['data' => [
            'eligible_recipients' => $recipients->count(),
            'excluded_invalid_or_missing_phone' => $this->query($data['recipient_filter'])->count() - $recipients->count(),
            'sms_parts' => $this->smsParts($data['message']),
            'estimated_units' => $recipients->count() * $this->smsParts($data['message']),
            'provider_configured' => $sms->isConfigured(),
            'sample' => $recipients->take(5)->map(fn (array $row) => ['name' => $row['customer']->full_name, 'phone' => '••••'.substr($row['recipient'], -4)])->values(),
        ]]);
    }

    public function compose(Request $request, OpenAiClient $ai): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'min:3', 'max:160'],
            'verified_facts' => ['required', 'string', 'min:5', 'max:1200'],
            'language' => ['required', Rule::in(['english', 'filipino', 'bilingual'])],
            'tone' => ['required', Rule::in(['clear', 'empathetic', 'urgent'])],
        ]);

        try {
            $response = $ai->chatCompletion([
                ['role' => 'system', 'content' => implode("\n", [
                    'You write operational SMS advisories for SolarNet Internet in the Philippines.',
                    'Use only the verified facts supplied by the administrator. Never invent dates, places, outage causes, prices, restoration times, links, or contact details.',
                    'Return only one ready-to-review SMS message with no markdown, quotation marks, headings, analysis, emojis, or placeholders.',
                    'Keep it at or below 420 characters. Start with SOLARNET ADVISORY:. Use a calm customer-service tone and concise spacing.',
                    'This is a draft only and will be reviewed by an administrator before sending.',
                ])],
                ['role' => 'user', 'content' => "Topic: {$data['topic']}\nLanguage: {$data['language']}\nTone: {$data['tone']}\nVerified facts:\n{$data['verified_facts']}"],
            ], [], 'gpt-5.4-mini');
        } catch (\Throwable $e) {
            Log::warning('SMS advisory AI composition failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'AI could not prepare an advisory draft. No SMS was sent.'], 503);
        }

        $draft = trim((string) ($response['content'] ?? ''));
        $draft = preg_replace('/^```(?:text)?\s*|\s*```$/i', '', $draft) ?: $draft;
        $draft = trim($draft, " \t\n\r\0\x0B\"");
        if ($draft === '') {
            return response()->json(['message' => 'AI returned an empty advisory draft. No SMS was sent.'], 503);
        }

        return response()->json(['data' => ['message' => mb_substr($draft, 0, 459)]]);
    }

    public function send(Request $request, PhilSmsService $sms): JsonResponse
    {
        $data = $this->validated($request, true);
        abort_unless($sms->isConfigured(), 422, 'PhilSMS is not configured. No advisory was queued.');
        $recipients = $this->recipients($data['recipient_filter'], $sms);
        abort_if($recipients->isEmpty(), 422, 'No customer with a valid Philippine mobile number matched this filter.');

        $campaign = DB::transaction(function () use ($request, $data, $recipients): SmsAdvisoryCampaign {
            $campaign = SmsAdvisoryCampaign::create([
                'created_by' => $request->user()->id,
                'title' => $data['title'],
                'message' => trim($data['message']),
                'recipient_filter' => $data['recipient_filter'],
                'status' => 'queued',
                'recipient_count' => $recipients->count(),
            ]);
            foreach ($recipients as $row) {
                SmsAdvisoryRecipient::create([
                    'campaign_id' => $campaign->id,
                    'customer_id' => $row['customer']->id,
                    'recipient' => $row['recipient'],
                    'recipient_last4' => substr($row['recipient'], -4),
                    'status' => 'queued',
                ]);
            }
            return $campaign;
        });

        $campaign->recipients()->pluck('id')->each(function (string $id, int $index): void {
            SendSmsAdvisoryRecipient::dispatch($id)->delay(now()->addSeconds(intdiv($index, 5)));
        });

        return response()->json(['message' => "Advisory queued for {$campaign->recipient_count} verified recipient(s).", 'data' => $campaign], 202);
    }

    private function validated(Request $request, bool $sending): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:5', 'max:459'],
            'recipient_filter' => ['required', Rule::in(['all', 'active', 'suspended', 'disconnected'])],
            'confirmation' => [$sending ? 'required' : 'nullable', Rule::in(['SEND SOLARNET ADVISORY'])],
            'authorized' => [$sending ? 'accepted' : 'nullable'],
        ]);
    }

    private function query(string $filter): Builder
    {
        return Customer::query()->when($filter !== 'all', fn (Builder $query) => $query->where('status', $filter));
    }

    private function recipients(string $filter, PhilSmsService $sms)
    {
        return $this->query($filter)->get(['id', 'full_name', 'contact_number'])
            ->map(fn (Customer $customer) => ['customer' => $customer, 'recipient' => $sms->normalisePhilippineMobile((string) $customer->contact_number)])
            ->filter(fn (array $row) => $row['recipient'] !== null)
            ->unique('recipient')->values();
    }

    private function smsParts(string $message): int
    {
        $unicode = preg_match('/[^\x00-\x7F]/', $message) === 1;
        $length = mb_strlen($message);
        $single = $unicode ? 70 : 160;
        $multipart = $unicode ? 67 : 153;
        return $length <= $single ? 1 : (int) ceil($length / $multipart);
    }
}
