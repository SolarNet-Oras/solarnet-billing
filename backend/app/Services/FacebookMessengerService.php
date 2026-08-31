<?php

namespace App\Services;

use App\Jobs\SendFacebookMarketingCampaign;
use App\Jobs\SendFacebookMessengerAutoReply;
use App\Models\FacebookMarketingCampaign;
use App\Models\FacebookMessengerConversation;
use App\Models\FacebookMessengerMessage;
use App\Models\FacebookPageConnection;
use App\Models\FacebookPagePostDraft;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenAiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

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
    private const POST_IMAGE_STAGING_DIRECTORY = 'facebook-page-post-images/staging';
    private const POST_IMAGE_DIRECTORY = 'facebook-page-post-images/posts';

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
        $activeConnections = $connections->where('is_active', true);
        $autoReplyEnabled = (bool) Setting::get('facebook_automation.auto_reply_enabled', false);
        $blockers = [];
        if (! $autoReplyEnabled) $blockers[] = 'Automatic replies are disabled. Enable the AI reply control and save it.';
        if (! $this->webhookReady()) $blockers[] = 'Facebook webhook verification is not configured on the server.';
        if (! $this->ai->isConfigured()) $blockers[] = 'OpenAI is not configured on the server.';
        if ($activeConnections->isEmpty()) $blockers[] = 'No active Facebook Page connection exists.';
        if ($activeConnections->isNotEmpty() && $activeConnections->every(fn (FacebookPageConnection $connection) => $connection->last_webhook_at === null)) {
            $blockers[] = 'Meta has not delivered a signed Messenger webhook to this Page yet.';
        }
        $latestAutoReply = FacebookMessengerMessage::query()->where('source', 'ai_auto')->latest('created_at')->first();

        return [
            'oauth_ready' => $this->oauthReady(),
            'webhook_ready' => $this->webhookReady(),
            'ai_ready' => $this->ai->isConfigured(),
            'webhook_url' => rtrim((string) config('app.url'), '/') . '/api/v1/integrations/facebook/webhook',
            'redirect_url' => (string) config('services.facebook.oauth_redirect_uri'),
            'graph_version' => (string) config('services.facebook.graph_version'),
            'auto_reply_enabled' => $autoReplyEnabled,
            'auto_reply_ready' => $blockers === [],
            'auto_reply_blockers' => $blockers,
            'last_auto_reply' => $latestAutoReply ? [
                'delivery_status' => $latestAutoReply->delivery_status,
                'delivery_error' => $latestAutoReply->delivery_error,
                'created_at' => $latestAutoReply->created_at?->toIso8601String(),
            ] : null,
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
                'scope' => 'pages_show_list,pages_read_engagement,pages_messaging,pages_manage_metadata,pages_manage_posts',
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
            FacebookMessengerMessage::create([
                'facebook_messenger_conversation_id' => $conversation->id,
                'reply_to_message_id' => $inbound->id,
                'direction' => 'outbound',
                'source' => 'ai_auto',
                'message_text' => null,
                'delivery_status' => 'failed',
                'delivery_error' => $draft['message'] ?? 'AI could not prepare an automatic reply.',
            ]);
            Log::warning('Facebook Messenger automatic reply was not prepared', [
                'conversation_id' => $conversation->id,
                'inbound_message_id' => $inbound->id,
                'reason' => $draft['message'] ?? 'unknown',
            ]);
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

    /** @return array{success:bool, message_text?:string, message?:string} */
    public function aiMarketingPostDraft(string $topic, ?string $details = null): array
    {
        if (! $this->ai->isConfigured()) {
            return ['success' => false, 'message' => 'OpenAI is not configured on the server.'];
        }

        $topic = Str::limit(trim($topic), 160, '');
        $details = Str::limit(trim((string) $details), 1000, '');
        if ($topic === '') {
            return ['success' => false, 'message' => 'A post topic is required.'];
        }

        try {
            $result = $this->ai->chatCompletion([
                ['role' => 'system', 'content' => $this->aiMarketingPostPrompt()],
                ['role' => 'user', 'content' => "Create one Facebook Page post. Topic: {$topic}\nAdditional staff notes: {$details}"],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Facebook Page AI post draft failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'AI could not prepare a post draft right now.'];
        }

        $message = trim((string) ($result['content'] ?? ''));
        if ($message === '') {
            return ['success' => false, 'message' => 'AI returned an empty post draft.'];
        }

        return ['success' => true, 'message_text' => Str::limit($message, 5000, '')];
    }

    /**
     * Study only previously public Page-post copy. This is content rotation,
     * not a claim of conversion learning: Meta reach/engagement insights are
     * unavailable until the Page grants the corresponding reviewed access.
     *
     * @return array{success:bool,learned_from:int,learning_note:string,suggestions:array<int,array<string,string>>,message?:string}
     */
    public function marketingPostSuggestions(): array
    {
        if (! $this->ai->isConfigured()) {
            return ['success' => false, 'learned_from' => 0, 'learning_note' => '', 'suggestions' => [], 'message' => 'OpenAI is not configured on the server.'];
        }

        $history = FacebookPagePostDraft::query()
            ->where('status', 'published')
            ->latest('published_at')
            ->limit(12)
            ->get(['topic', 'message_text', 'published_at']);
        $historyText = $history->map(fn (FacebookPagePostDraft $post): string =>
            '- ' . Str::limit($post->topic, 120, '') . ': ' . Str::limit(preg_replace('/\s+/', ' ', $post->message_text), 260, '')
        )->implode("\n");
        if ($historyText === '') {
            $historyText = '- No published Page-post history yet.';
        }

        $prompt = <<<'PROMPT'
Return valid JSON only with a top-level "suggestions" array containing exactly 3 objects. Each object must contain: "objective", "topic", "angle", "call_to_action", and "why".
Create three distinct organic Facebook content ideas for a local internet provider: one for qualified installation inquiries, one for follower/reach growth through useful education, and one trust/community or advertising-ready concept.
Use prior public posts only to avoid repetitive topics and wording. Never claim those posts performed well because no engagement or conversion metrics are available. Never invent coverage, prices, promotions, speeds, dates, availability, guarantees, testimonials, or customer facts. Keep each field concise. Every idea remains an unpublished administrator-reviewed draft; do not suggest automatic posting or automatic ad spending.
PROMPT;

        try {
            $result = $this->ai->chatCompletion([
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => "Previously published SolarNet Page posts:\n{$historyText}"],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Facebook marketing suggestions failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'learned_from' => $history->count(), 'learning_note' => '', 'suggestions' => [], 'message' => 'AI could not prepare marketing suggestions right now.'];
        }

        $content = trim((string) ($result['content'] ?? ''));
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($content, true);
        $rows = is_array($decoded['suggestions'] ?? null) ? array_slice($decoded['suggestions'], 0, 3) : [];
        $suggestions = collect($rows)->map(function ($row): ?array {
            if (! is_array($row)) return null;
            $suggestion = [];
            foreach (['objective', 'topic', 'angle', 'call_to_action', 'why'] as $field) {
                $suggestion[$field] = Str::limit(trim((string) ($row[$field] ?? '')), $field === 'topic' ? 160 : 500, '');
            }
            return $suggestion['topic'] === '' || $suggestion['angle'] === '' ? null : $suggestion;
        })->filter()->values()->all();

        if (count($suggestions) !== 3) {
            return ['success' => false, 'learned_from' => $history->count(), 'learning_note' => '', 'suggestions' => [], 'message' => 'AI returned incomplete marketing suggestions. Please try again.'];
        }

        return [
            'success' => true,
            'learned_from' => $history->count(),
            'learning_note' => $history->isEmpty()
                ? 'Starter ideas only; no published post history is available yet.'
                : 'Ideas were rotated against recent published copy. Engagement and conversion performance are not inferred.',
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Keep an uploaded image private until a post draft claims it. The token is
     * tied to its administrator and expires quickly, so a browser cannot attach
     * an arbitrary server file to a Facebook Page post.
     *
     * @return array{success:bool, image_token?:string, message?:string}
     */
    public function stagePostImageUpload(User $user, UploadedFile $image): array
    {
        $this->pruneExpiredStagedPostImages();

        $mime = (string) $image->getMimeType();
        $extension = $this->postImageExtension($mime);
        if ($extension === null) {
            return ['success' => false, 'message' => 'Upload a PNG or JPEG image.'];
        }

        $token = Str::random(64);
        $path = self::POST_IMAGE_STAGING_DIRECTORY . '/' . $token . '.' . $extension;
        if (! Storage::disk('local')->put($path, (string) file_get_contents($image->getRealPath()))) {
            return ['success' => false, 'message' => 'The selected image could not be stored securely.'];
        }

        $this->rememberStagedPostImage($token, $user, $path, $mime);

        return ['success' => true, 'image_token' => $token];
    }

    /**
     * @return array{success:bool, image_token?:string, preview_data_url?:string, message?:string}
     */
    public function generateMarketingPostImage(User $user, string $topic, ?string $details = null): array
    {
        if (! $this->ai->isConfigured()) {
            return ['success' => false, 'message' => 'OpenAI is not configured on the server.'];
        }

        $topic = Str::limit(trim($topic), 160, '');
        $details = Str::limit(trim((string) $details), 1000, '');
        if ($topic === '') {
            return ['success' => false, 'message' => 'A post topic is required before generating an image.'];
        }

        try {
            $image = $this->ai->generateImage($this->aiMarketingPostImagePrompt($topic, $details));
        } catch (\Throwable $e) {
            Log::warning('Facebook Page AI image draft failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage() ?: 'AI could not prepare a marketing image right now.'];
        }

        if (strlen($image['bytes']) > 10 * 1024 * 1024) {
            return ['success' => false, 'message' => 'AI returned an image that is too large to attach to a Facebook post.'];
        }

        $this->pruneExpiredStagedPostImages();
        $token = Str::random(64);
        $path = self::POST_IMAGE_STAGING_DIRECTORY . '/' . $token . '.png';
        if (! Storage::disk('local')->put($path, $image['bytes'])) {
            return ['success' => false, 'message' => 'The generated image could not be stored securely.'];
        }

        $this->rememberStagedPostImage($token, $user, $path, 'image/png');

        return [
            'success' => true,
            'image_token' => $token,
            // This temporary preview is returned only to the authenticated
            // administrator who initiated generation. The stored source stays
            // on Laravel's private disk.
            'preview_data_url' => 'data:image/png;base64,' . base64_encode($image['bytes']),
        ];
    }

    /** @return array{image_path:string,image_mime:string}|null */
    public function claimStagedPostImage(User $user, string $token, FacebookPagePostDraft $post): ?array
    {
        $staged = Cache::pull($this->stagedPostImageKey($token));
        if (! is_array($staged) || ! hash_equals((string) ($staged['user_id'] ?? ''), (string) $user->id)) {
            return null;
        }

        $path = (string) ($staged['path'] ?? '');
        $mime = (string) ($staged['mime'] ?? '');
        $extension = $this->postImageExtension($mime);
        if (! $this->isManagedPostImagePath($path) || $extension === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $newPath = self::POST_IMAGE_DIRECTORY . '/' . $post->id . '.' . $extension;
        if (! Storage::disk('local')->move($path, $newPath)) {
            return null;
        }

        return ['image_path' => $newPath, 'image_mime' => $mime];
    }

    public function copyPostImage(FacebookPagePostDraft $from, FacebookPagePostDraft $to): bool
    {
        if (blank($from->image_path)) {
            return true;
        }

        $path = (string) $from->image_path;
        $extension = $this->postImageExtension((string) $from->image_mime);
        if (! $this->isManagedPostImagePath($path) || $extension === null || ! Storage::disk('local')->exists($path)) {
            return false;
        }

        $copyPath = self::POST_IMAGE_DIRECTORY . '/' . $to->id . '.' . $extension;
        if (! Storage::disk('local')->copy($path, $copyPath)) {
            return false;
        }

        $to->forceFill(['image_path' => $copyPath, 'image_mime' => $from->image_mime])->save();
        return true;
    }

    /** @return array{path:string,mime:string}|null */
    public function postImageFile(FacebookPagePostDraft $post): ?array
    {
        $path = (string) $post->image_path;
        if (! $this->isManagedPostImagePath($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return [
            'path' => Storage::disk('local')->path($path),
            'mime' => (string) ($post->image_mime ?: Storage::disk('local')->mimeType($path) ?: 'image/jpeg'),
        ];
    }

    public function deletePostImage(FacebookPagePostDraft $post): void
    {
        $path = (string) $post->image_path;
        if ($this->isManagedPostImagePath($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    /** @return array{success:bool, post?:FacebookPagePostDraft, message?:string} */
    public function publishPost(FacebookPagePostDraft $post, User $approvedBy): array
    {
        if ($post->status !== 'draft') {
            return ['success' => false, 'message' => 'Only an unpublished draft can be approved and posted.'];
        }

        $post->loadMissing('connection');
        if (! $post->connection || ! $post->connection->is_active) {
            return ['success' => false, 'message' => 'The selected Facebook Page connection is inactive.'];
        }

        $post->forceFill([
            'status' => 'publishing',
            'approved_by' => $approvedBy->id,
            'approved_at' => now(),
            'last_error' => null,
        ])->save();

        $attachedImage = $this->postImageFile($post);
        if (filled($post->image_path) && $attachedImage === null) {
            $post->forceFill(['status' => 'failed', 'last_error' => 'The attached image is no longer available. Upload or generate a new image before reposting.'])->save();
            return ['success' => false, 'message' => 'The attached image is no longer available.'];
        }

        try {
            if ($attachedImage !== null) {
                $stream = fopen($attachedImage['path'], 'rb');
                try {
                    $response = Http::acceptJson()->timeout(45)
                        ->attach('source', $stream, basename($attachedImage['path']), ['Content-Type' => $attachedImage['mime']])
                        ->post(
                            $this->graphUrl('/' . $post->connection->page_id . '/photos'),
                            ['access_token' => $post->connection->page_access_token, 'caption' => $post->message_text],
                        );
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            } else {
                $response = Http::asForm()->acceptJson()->timeout(20)->post(
                    $this->graphUrl('/' . $post->connection->page_id . '/feed'),
                    ['access_token' => $post->connection->page_access_token, 'message' => $post->message_text],
                );
            }
        } catch (\Throwable $e) {
            Log::error('Facebook Page post request failed', ['post_id' => $post->id, 'error' => $e->getMessage()]);
            $post->forceFill(['status' => 'failed', 'last_error' => 'Facebook could not be reached while publishing this post.'])->save();
            return ['success' => false, 'message' => 'Facebook could not be reached while publishing the post.'];
        }

        $facebookPostId = trim((string) ($response->json('post_id') ?: $response->json('id')));
        if (! $response->successful() || $facebookPostId === '') {
            $reason = trim((string) ($response->json('error.message') ?: 'Facebook rejected the Page post.'));
            Log::warning('Facebook Page post was rejected', ['post_id' => $post->id, 'http_status' => $response->status(), 'reason' => $reason]);
            $post->forceFill(['status' => 'failed', 'last_error' => Str::limit($reason, 1000, '')])->save();
            return ['success' => false, 'message' => 'Facebook rejected the Page post. Confirm pages_manage_posts access and Page permissions.'];
        }

        $post->forceFill([
            'status' => 'published',
            'facebook_post_id' => $facebookPostId,
            'published_at' => now(),
            'last_error' => null,
        ])->save();

        return ['success' => true, 'post' => $post->fresh()];
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

    protected function aiMarketingPostPrompt(): string
    {
        $company = (string) Setting::get('company.name', 'SolarNet');

        return <<<PROMPT
You are writing one public Facebook Page post for {$company}, a local internet service provider.
- Write a concise, friendly Filipino/English or Taglish post suitable for public customers.
- State only information supplied by staff. Do not invent prices, coverage, promotions, speeds, deadlines, guarantees, or technical claims.
- Do not mention or infer any customer's account, payment, address, network, or personal data.
- Include a simple invitation to message the Page or contact official SolarNet support.
- Use short paragraphs and at most three relevant hashtags. Do not include a heading such as "Draft".
- Keep it below 1,200 characters.
PROMPT;
    }

    protected function aiMarketingPostImagePrompt(string $topic, string $details): string
    {
        $company = (string) Setting::get('company.name', 'SolarNet');

        return <<<PROMPT
Use case: ads-marketing
Asset type: a single square Facebook Page image for {$company}, a local internet service provider.
Primary request: Create a clean, modern, trustworthy broadband-internet visual supporting this staff-approved topic: {$topic}
Staff-approved facts: {$details}
Style/medium: polished contemporary commercial illustration or photography, suitable for a professional local internet provider.
Composition/framing: square social-media composition with generous negative space; no in-image writing is required.
Constraints: Do not include people who could be mistaken for a real customer, customer homes, bills, account data, payment claims, addresses, network credentials, QR codes, prices, speeds, guarantees, dates, third-party logos, or copyrighted characters. Do not create a SolarNet logo. Do not add text, letters, numbers, watermark, or UI screenshots inside the image. The administrator will review the image before publication.
PROMPT;
    }

    protected function rememberStagedPostImage(string $token, User $user, string $path, string $mime): void
    {
        Cache::put($this->stagedPostImageKey($token), [
            'user_id' => (string) $user->id,
            'path' => $path,
            'mime' => $mime,
        ], now()->addHour());
    }

    protected function stagedPostImageKey(string $token): string
    {
        return 'facebook_automation.post_image_staging.' . hash('sha256', $token);
    }

    protected function postImageExtension(string $mime): ?string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };
    }

    protected function isManagedPostImagePath(string $path): bool
    {
        return str_starts_with($path, self::POST_IMAGE_DIRECTORY . '/')
            || str_starts_with($path, self::POST_IMAGE_STAGING_DIRECTORY . '/');
    }

    /** Remove abandoned staging files when the next image action is performed. */
    protected function pruneExpiredStagedPostImages(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours(2)->getTimestamp();
        foreach ($disk->files(self::POST_IMAGE_STAGING_DIRECTORY) as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
            }
        }
    }
}
