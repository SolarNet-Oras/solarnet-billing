<?php

namespace App\Services;

use App\Jobs\SendFacebookMarketingCampaign;
use App\Jobs\SendFacebookMessengerAutoReply;
use App\Models\FacebookMarketingCampaign;
use App\Models\FacebookMessengerConversation;
use App\Models\FacebookMessengerMessage;
use App\Models\FacebookPageConnection;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenAiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Page-only Meta Graph API integration.
 *
 * The service never operates a personal Facebook account and never receives
 * customer billing data. It only handles Page Messenger conversations after a
 * Page administrator completes OAuth. All outbound replies stay within an
 * active 24-hour customer conversation; campaigns additionally require a
 * recorded opt-in and explicit administrator send action.
 */
class FacebookMessengerService
{
    public function __construct(protected OpenAiClient $ai)
    {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $connections = FacebookPageConnection::query()
            ->with('connectedBy:id,name')
            ->latest()
            ->get();

        return [
            'oauth_ready' => $this->oauthReady(),
            'webhook_ready' => $this->webhookReady(),
            'ai_ready' => $this->ai->isConfigured(),
            'webhook_url' => rtrim((string) config('app.url'), '/') . '/api/v1/integrations/facebook/webhook',
            'redirect_url' => (string) config('services.facebook.oauth_redirect_uri'),
            'graph_version' => (string) config('services.facebook.graph_version'),
            'auto_reply_enabled' => (bool) Setting::get('facebook_automation.auto_reply_enabled', false),
            'marketing_enabled' => (bool) Setting::get('facebook_automation.marketing_enabled', false),
            'connections' => $connections->map(fn (FacebookPageConnection $connection) => $connection->toAutomationArray())->values(),
        ];
    }

    public function oauthReady(): bool
    {
        return filled(config('services.facebook.app_id'))
            && filled(config('services.facebook.app_secret'))
            && filled(config('services.facebook.oauth_redirect_uri'));
    }

    public function webhookReady(): bool
    {
        return filled(config('services.facebook.app_secret'))
            && filled(config('services.facebook.webhook_verify_token'));
    }

    public function authorizationUrl(User $user): ?string
    {
        if (! $this->oauthReady()) {
            return null;
        }

        $state = Str::random(64);
        Cache::put($this->oauthStateKey($state), ['user_id' => $user->id], now()->addMinutes(10));

        return 'https://www.facebook.com/' . rawurlencode((string) config('services.facebook.graph_version')) . '/dialog/oauth?'
            . http_build_query([
                'client_id' => (string) config('services.facebook.app_id'),
                'redirect_uri' => (string) config('services.facebook.oauth_redirect_uri'),
                'state' => $state,
                // Page-only permissions. Meta app review may be required before
                // these work outside of the app's own administrator accounts.
                'scope' => 'pages_show_list,pages_messaging,pages_manage_metadata',
                'response_type' => 'code',
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{success:bool, setup_token?:string, message?:string} */
    public function exchangeOAuthCode(string $state, string $code): array
    {
        $stateData = Cache::pull($this->oauthStateKey($state));
        if (! is_array($stateData) || blank($stateData['user_id'] ?? null)) {
            return ['success' => false, 'message' => 'The Facebook connection request expired or was already used. Start again from Facebook Automation.'];
        }

        try {
            $tokenResponse = Http::acceptJson()->timeout(15)->get($this->graphUrl('/oauth/access_token'), [
                'client_id' => (string) config('services.facebook.app_id'),
                'client_secret' => (string) config('services.facebook.app_secret'),
                'redirect_uri' => (string) config('services.facebook.oauth_redirect_uri'),
                'code' => $code,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Facebook OAuth token exchange request failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Facebook could not be reached while linking the Page. Please try again.'];
        }

        $userToken = trim((string) $tokenResponse->json('access_token'));
        if (! $tokenResponse->successful() || $userToken === '') {
            Log::warning('Facebook OAuth token exchange was rejected', ['http_status' => $tokenResponse->status(), 'error' => $tokenResponse->json('error.message')]);
            return ['success' => false, 'message' => 'Facebook rejected this connection request. Confirm the app ID, app secret, redirect URL, and Page permissions.'];
        }

        // Prefer a long-lived user token when Meta permits the exchange. The
        // Page access token is still stored only through Laravel encryption.
        try {
            $longLived = Http::acceptJson()->timeout(15)->get($this->graphUrl('/oauth/access_token'), [
                'grant_type' => 'fb_exchange_token',
                'client_id' => (string) config('services.facebook.app_id'),
                'client_secret' => (string) config('services.facebook.app_secret'),
                'fb_exchange_token' => $userToken,
            ]);
            if ($longLived->successful() && filled($longLived->json('access_token'))) {
                $userToken = (string) $longLived->json('access_token');
            }
        } catch (\Throwable $e) {
            // The original token remains valid for this short selection flow.
            Log::info('Facebook long-lived token exchange unavailable', ['error' => $e->getMessage()]);
        }

        try {
            $pagesResponse = Http::acceptJson()->timeout(15)->get($this->graphUrl('/me/accounts'), [
                'access_token' => $userToken,
                'fields' => 'id,name,access_token,tasks',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Facebook Page list request failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Facebook authorization succeeded, but the available Pages could not be read.'];
        }

        $pages = collect($pagesResponse->json('data', []))
            ->filter(fn ($page) => is_array($page) && filled($page['id'] ?? null) && filled($page['access_token'] ?? null))
            ->map(fn (array $page) => [
                'id' => (string) $page['id'],
                'name' => trim((string) ($page['name'] ?? 'Facebook Page')),
                'access_token' => (string) $page['access_token'],
            ])->values()->all();

        if (! $pagesResponse->successful() || $pages === []) {
            Log::warning('Facebook Page list was empty or rejected', ['http_status' => $pagesResponse->status(), 'error' => $pagesResponse->json('error.message')]);
            return ['success' => false, 'message' => 'No manageable Facebook Page was returned. Log in with a Page administrator account and grant the requested Page permissions.'];
        }

        $setupToken = (string) Str::uuid();
        Cache::put($this->pageSelectionKey($setupToken), [
            'user_id' => (string) $stateData['user_id'],
            'pages' => $pages,
        ], now()->addMinutes(10));

        return ['success' => true, 'setup_token' => $setupToken];
    }

    /** @return array<int, array{id:string,name:string}> */
    public function pageCandidates(User $user, string $setupToken): array
    {
        $data = Cache::get($this->pageSelectionKey($setupToken));
        if (! is_array($data) || ($data['user_id'] ?? null) !== $user->id) {
            return [];
        }

        return collect($data['pages'] ?? [])
            ->map(fn (array $page) => ['id' => (string) $page['id'], 'name' => (string) $page['name']])
            ->values()->all();
    }

    /** @return array{success:bool, connection?:FacebookPageConnection, message?:string} */
    public function connectSelectedPage(User $user, string $setupToken, string $pageId): array
    {
        $data = Cache::pull($this->pageSelectionKey($setupToken));
        if (! is_array($data) || ($data['user_id'] ?? null) !== $user->id) {
            return ['success' => false, 'message' => 'The Facebook Page selection expired. Start the Page connection again.'];
        }

        $page = collect($data['pages'] ?? [])->first(fn (array $candidate) => hash_equals((string) ($candidate['id'] ?? ''), $pageId));
        if (! is_array($page)) {
            return ['success' => false, 'message' => 'The selected Facebook Page was not part of this authorization request.'];
        }

        $connection = FacebookPageConnection::updateOrCreate(
            ['page_id' => $page['id']],
            [
                'page_name' => $page['name'] ?: 'Facebook Page',
                'page_access_token' => $page['access_token'],
                'is_active' => true,
                'last_error' => null,
                'connected_by' => $user->id,
            ],
        );

        return ['success' => true, 'connection' => $connection];
    }

    public function verifyWebhook(string $mode, string $verifyToken): bool
    {
        return $mode === 'subscribe'
            && $this->webhookReady()
            && hash_equals((string) config('services.facebook.webhook_verify_token'), $verifyToken);
    }

    public function validWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if (! $this->webhookReady() || blank($signature)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, (string) config('services.facebook.app_secret'));
        return hash_equals($expected, trim((string) $signature));
    }

    /** @param array<string, mixed> $payload */
    public function receiveWebhook(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'page') {
            return;
        }

        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry) || blank($entry['id'] ?? null)) {
                continue;
            }

            $connection = FacebookPageConnection::query()
                ->where('page_id', (string) $entry['id'])
                ->where('is_active', true)
                ->first();
            if (! $connection) {
                continue;
            }

            $connection->forceFill(['last_webhook_at' => now(), 'last_error' => null])->save();
            foreach (($entry['messaging'] ?? []) as $event) {
                if (is_array($event)) {
                    $this->recordInboundEvent($connection, $event);
                }
            }
        }
    }

    /** @param array<string, mixed> $event */
    protected function recordInboundEvent(FacebookPageConnection $connection, array $event): void
    {
        $message = is_array($event['message'] ?? null) ? $event['message'] : null;
        if (! $message || ! empty($message['is_echo']) || blank($message['mid'] ?? null) || blank($event['sender']['id'] ?? null)) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $conversation = FacebookMessengerConversation::firstOrCreate(
            ['facebook_page_connection_id' => $connection->id, 'page_scoped_id' => (string) $event['sender']['id']],
            ['last_inbound_at' => now(), 'last_message_at' => now()],
        );

        $record = FacebookMessengerMessage::firstOrCreate(
            ['facebook_mid' => (string) $message['mid']],
            [
                'facebook_messenger_conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'source' => 'webhook',
                'message_text' => $text === '' ? null : Str::limit($text, 4000, ''),
                'meta_payload' => ['attachments' => $message['attachments'] ?? null],
                'delivery_status' => 'received',
                'sent_at' => now(),
            ],
        );

        if (! $record->wasRecentlyCreated) {
            return;
        }

        $conversation->forceFill(['last_inbound_at' => now(), 'last_message_at' => now()]);
        if ($this->isOptOutText($text)) {
            $conversation->marketing_opt_out_at = now();
        }
        $conversation->save();

        if ($text !== ''
            && (bool) Setting::get('facebook_automation.auto_reply_enabled', false)
            && ! $conversation->human_handoff_required
            && $conversation->marketing_opt_out_at === null) {
            SendFacebookMessengerAutoReply::dispatch($record->id);
        }
    }

    /** @return array{success:bool, reply?:string, message?:string} */
    public function aiDraft(FacebookMessengerConversation $conversation): array
    {
        if (! $this->ai->isConfigured()) {
            return ['success' => false, 'message' => 'OpenAI is not configured on the server.'];
        }

        $history = $conversation->messages()->latest('created_at')->limit(12)->get()->reverse()->values();
        $transcript = $history->map(function (FacebookMessengerMessage $message): string {
            $speaker = $message->direction === 'inbound' ? 'Customer' : 'SolarNet';
            return $speaker . ': ' . trim((string) ($message->message_text ?: '[non-text Messenger event]'));
        })->implode("\n");

        try {
            $result = $this->ai->chatCompletion([
                ['role' => 'system', 'content' => $this->aiReplyPrompt()],
                ['role' => 'user', 'content' => "Messenger conversation:\n{$transcript}\n\nWrite one concise, customer-ready reply to the latest customer message."],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Facebook Messenger AI draft failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'AI could not prepare a reply right now.'];
        }

        $reply = trim((string) ($result['content'] ?? ''));
        if ($reply === '') {
            return ['success' => false, 'message' => 'AI returned an empty draft.'];
        }

        return ['success' => true, 'reply' => Str::limit($reply, 900, '')];
    }

    /** @return array{success:bool, message?:string, record?:FacebookMessengerMessage} */
    public function sendConversationReply(
        FacebookMessengerConversation $conversation,
        string $text,
        string $source = 'staff',
        ?string $replyToMessageId = null,
        ?FacebookMarketingCampaign $campaign = null,
    ): array {
        $conversation->loadMissing('connection');
        if (! $conversation->connection || ! $conversation->connection->is_active) {
            return ['success' => false, 'message' => 'This Facebook Page is not connected or is inactive.'];
        }
        if (! $conversation->canReceiveResponse()) {
            return ['success' => false, 'message' => 'Facebook replies are limited to an active customer conversation. Wait for a new customer message before sending.'];
        }
        if ($source === 'campaign' && ($conversation->marketing_opt_in_at === null || $conversation->marketing_opt_out_at !== null)) {
            return ['success' => false, 'message' => 'This contact is not eligible for marketing.'];
        }

        $text = Str::limit(trim($text), 900, '');
        if ($text === '') {
            return ['success' => false, 'message' => 'A Messenger reply cannot be empty.'];
        }

        $delivery = $this->sendText($conversation->connection, $conversation->page_scoped_id, $text);
        $record = FacebookMessengerMessage::create([
            'facebook_messenger_conversation_id' => $conversation->id,
            'facebook_marketing_campaign_id' => $campaign?->id,
            'reply_to_message_id' => $replyToMessageId,
            'facebook_mid' => $delivery['message_id'] ?? null,
            'direction' => 'outbound',
            'source' => $source,
            'message_text' => $text,
            'delivery_status' => $delivery['success'] ? 'sent' : 'failed',
            'delivery_error' => $delivery['success'] ? null : $delivery['message'],
            'sent_at' => now(),
        ]);

        if ($delivery['success']) {
            $conversation->forceFill(['last_outbound_at' => now(), 'last_message_at' => now()])->save();
        }

        return $delivery['success']
            ? ['success' => true, 'record' => $record]
            : ['success' => false, 'message' => $delivery['message'], 'record' => $record];
    }

    public function sendAutomaticReply(FacebookMessengerMessage $inbound): void
    {
        $inbound->loadMissing('conversation.connection');
        $conversation = $inbound->conversation;
        if (! $conversation
            || $inbound->direction !== 'inbound'
            || ! (bool) Setting::get('facebook_automation.auto_reply_enabled', false)
            || $conversation->human_handoff_required
            || $conversation->marketing_opt_out_at !== null
            || FacebookMessengerMessage::query()->where('reply_to_message_id', $inbound->id)->exists()) {
            return;
        }

        $draft = $this->aiDraft($conversation);
        if (! $draft['success']) {
            return;
        }

        $this->sendConversationReply($conversation, (string) $draft['reply'], 'ai_auto', $inbound->id);
    }

    public function eligibleMarketingCount(FacebookPageConnection $connection): int
    {
        return $this->eligibleMarketingConversations($connection)->count();
    }

    public function queueCampaign(FacebookMarketingCampaign $campaign, User $approvedBy): array
    {
        if (! (bool) Setting::get('facebook_automation.marketing_enabled', false)) {
            return ['success' => false, 'message' => 'Marketing is disabled. Enable it only after confirming your Meta messaging policy and customer consent process.'];
        }
        if ($campaign->status !== 'draft') {
            return ['success' => false, 'message' => 'Only a draft campaign can be approved and sent.'];
        }

        $campaign->loadMissing('connection');
        if (! $campaign->connection || ! $campaign->connection->is_active) {
            return ['success' => false, 'message' => 'The Facebook Page connection is inactive.'];
        }

        $campaign->forceFill([
            'recipient_count' => $this->eligibleMarketingCount($campaign->connection),
            'status' => 'sending',
            'approved_by' => $approvedBy->id,
            'approved_at' => now(),
            'last_error' => null,
        ])->save();
        SendFacebookMarketingCampaign::dispatch($campaign->id);

        return ['success' => true];
    }

    public function sendCampaign(FacebookMarketingCampaign $campaign): void
    {
        $campaign->loadMissing('connection');
        if (! $campaign->connection || $campaign->status !== 'sending') {
            return;
        }

        $eligible = $this->eligibleMarketingConversations($campaign->connection)
            ->orderBy('id')
            ->get();
        $campaign->forceFill(['recipient_count' => $eligible->count()])->save();

        foreach ($eligible as $conversation) {
            if (FacebookMessengerMessage::query()
                ->where('facebook_marketing_campaign_id', $campaign->id)
                ->where('facebook_messenger_conversation_id', $conversation->id)
                ->exists()) {
                continue;
            }

            try {
                $result = $this->sendConversationReply($conversation, $campaign->message_text, 'campaign', null, $campaign);
                $campaign->increment($result['success'] ? 'sent_count' : 'failed_count');
                if (! $result['success']) {
                    $campaign->forceFill(['last_error' => Str::limit((string) $result['message'], 1000, '')])->save();
                }
            } catch (\Throwable $e) {
                Log::error('Facebook Messenger campaign delivery failed', ['campaign_id' => $campaign->id, 'conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
                $campaign->increment('failed_count');
                $campaign->forceFill(['last_error' => 'A campaign delivery request failed. Check the message records.'])->save();
            }
        }

        $campaign->forceFill(['status' => 'sent', 'completed_at' => now()])->save();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<FacebookMessengerConversation> */
    protected function eligibleMarketingConversations(FacebookPageConnection $connection)
    {
        return $connection->conversations()
            ->whereNotNull('marketing_opt_in_at')
            ->whereNull('marketing_opt_out_at')
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '>=', now()->subHours(24));
    }

    /** @return array{success:bool, message_id?:string, message?:string} */
    protected function sendText(FacebookPageConnection $connection, string $pageScopedId, string $text): array
    {
        try {
            $response = Http::acceptJson()->timeout(15)->post($this->graphUrl('/' . $connection->page_id . '/messages'), [
                'access_token' => $connection->page_access_token,
                'messaging_type' => 'RESPONSE',
                'recipient' => ['id' => $pageScopedId],
                'message' => ['text' => $text],
            ]);
        } catch (\Throwable $e) {
            Log::error('Facebook Messenger send request failed', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
            $connection->forceFill(['last_error' => 'Messenger network request failed.'])->save();
            return ['success' => false, 'message' => 'Facebook could not be reached.'];
        }

        if (! $response->successful() || blank($response->json('message_id'))) {
            $reason = trim((string) ($response->json('error.message') ?: 'Facebook rejected the Messenger message.'));
            Log::warning('Facebook Messenger send was rejected', ['connection_id' => $connection->id, 'http_status' => $response->status(), 'reason' => $reason]);
            $connection->forceFill(['last_error' => Str::limit($reason, 1000, '')])->save();
            return ['success' => false, 'message' => 'Facebook rejected the message. Check the active conversation window and Page permissions.'];
        }

        $connection->forceFill(['last_error' => null])->save();
        return ['success' => true, 'message_id' => (string) $response->json('message_id')];
    }

    protected function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . rawurlencode((string) config('services.facebook.graph_version')) . '/' . ltrim($path, '/');
    }

    protected function oauthStateKey(string $state): string
    {
        return 'facebook_automation.oauth_state.' . hash('sha256', $state);
    }

    protected function pageSelectionKey(string $token): string
    {
        return 'facebook_automation.page_selection.' . hash('sha256', $token);
    }

    protected function isOptOutText(string $text): bool
    {
        return preg_match('/^\s*(stop|unsubscribe|cancel|huwag|ayaw)\b/i', $text) === 1;
    }

    protected function aiReplyPrompt(): string
    {
        $company = (string) Setting::get('company.name', 'SolarNet');
        $portal = rtrim((string) config('app.customer_portal_url'), '/');

        return <<<PROMPT
You are the customer-support assistant for {$company} answering Facebook Page Messenger.
- Write one natural, helpful, concise reply in the customer's language. Use polite Filipino/Taglish when the customer does.
- Never claim to be human. Do not disclose, request, infer, or confirm account balances, payment status, personal information, MAC/IP addresses, passwords, or service status in Messenger.
- For billing or account-specific requests, direct the customer to the secure portal at {$portal}/customer/login or to official support. Do not ask for a customer account number in Messenger.
- Do not promise restoration times, offer discounts, or make an unsolicited sale. If the customer asks about plans, offer to have staff assist and ask only one relevant question.
- Never ask a customer to open fiber equipment, expose a connector, reset an ONU/router, or alter network settings.
- Keep the answer under 700 characters and do not use markdown headings.
PROMPT;
    }
}
