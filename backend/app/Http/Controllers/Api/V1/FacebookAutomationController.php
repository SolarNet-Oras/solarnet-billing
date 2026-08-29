<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FacebookMarketingCampaign;
use App\Models\FacebookMessengerConversation;
use App\Models\FacebookPageConnection;
use App\Models\FacebookPagePostDraft;
use App\Models\Setting;
use App\Services\FacebookMessengerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FacebookAutomationController extends Controller
{
    public function __construct(protected FacebookMessengerService $facebook)
    {
    }

    public function status(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->facebook->status()]);
    }

    public function connectUrl(Request $request): JsonResponse
    {
        $url = $this->facebook->authorizationUrl($request->user());
        if (! $url) {
            return response()->json([
                'success' => false,
                'message' => 'Facebook OAuth is not configured. Add FACEBOOK_APP_ID, FACEBOOK_APP_SECRET, and FACEBOOK_OAUTH_REDIRECT_URI on the server first.',
            ], 503);
        }

        return response()->json(['success' => true, 'data' => ['url' => $url]]);
    }

    /** Public Meta OAuth callback. State binds this response to the staff user who began OAuth. */
    public function oauthCallback(Request $request): RedirectResponse
    {
        $frontEnd = rtrim((string) config('app.url'), '/') . '/facebook-automation';
        if ($request->filled('error')) {
            return redirect($frontEnd . '?facebook_error=authorization_declined');
        }

        $state = (string) $request->query('state');
        $code = (string) $request->query('code');
        if ($state === '' || $code === '') {
            return redirect($frontEnd . '?facebook_error=missing_callback_data');
        }

        $result = $this->facebook->exchangeOAuthCode($state, $code);
        if (! $result['success']) {
            return redirect($frontEnd . '?facebook_error=connection_failed');
        }

        return redirect($frontEnd . '?facebook_setup=' . rawurlencode((string) $result['setup_token']));
    }

    public function pageCandidates(Request $request): JsonResponse
    {
        $token = trim((string) $request->query('setup_token'));
        if ($token === '') {
            return response()->json(['success' => false, 'message' => 'Facebook Page selection token is required.'], 422);
        }

        $pages = $this->facebook->pageCandidates($request->user(), $token);
        if ($pages === []) {
            return response()->json(['success' => false, 'message' => 'The Page selection expired. Start Facebook connection again.'], 410);
        }

        return response()->json(['success' => true, 'data' => $pages]);
    }

    public function connectPage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'setup_token' => 'required|string|max:100',
            'page_id' => 'required|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Select a Facebook Page to connect.', 'errors' => $validator->errors()], 422);
        }

        $result = $this->facebook->connectSelectedPage($request->user(), $request->input('setup_token'), $request->input('page_id'));
        if (! $result['success']) {
            return response()->json($result, 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Facebook Page connected. Complete Meta webhook subscription before enabling AI replies.',
            'data' => $result['connection']->load('connectedBy:id,name')->toAutomationArray(),
        ]);
    }

    public function deactivateConnection(FacebookPageConnection $connection): JsonResponse
    {
        $connection->forceFill(['is_active' => false])->save();
        return response()->json(['success' => true, 'message' => 'Facebook Page connection was disabled. No new messages will be sent.']);
    }

    public function conversations(Request $request): JsonResponse
    {
        $connectionId = trim((string) $request->query('connection_id'));
        $query = FacebookMessengerConversation::query()
            ->with(['connection:id,page_id,page_name,is_active', 'messages' => fn ($messages) => $messages->latest('created_at')->limit(1)])
            ->orderByDesc('last_message_at')
            ->limit(100);
        if ($connectionId !== '') {
            $query->where('facebook_page_connection_id', $connectionId);
        }

        return response()->json(['success' => true, 'data' => $query->get()->map(function (FacebookMessengerConversation $conversation): array {
            $data = $conversation->toAutomationArray();
            $last = $conversation->messages->first();
            $data['page_name'] = $conversation->connection?->page_name;
            $data['last_message'] = $last?->toAutomationArray();
            return $data;
        })->values()]);
    }

    public function messages(FacebookMessengerConversation $conversation): JsonResponse
    {
        $conversation->load('connection:id,page_id,page_name,is_active');
        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => array_merge($conversation->toAutomationArray(), ['page_name' => $conversation->connection?->page_name]),
                'messages' => $conversation->messages()->latest('created_at')->limit(100)->get()->reverse()->values()->map(fn ($message) => $message->toAutomationArray())->values(),
            ],
        ]);
    }

    public function updateConversation(Request $request, FacebookMessengerConversation $conversation): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'human_handoff_required' => 'sometimes|boolean',
            'marketing_opt_in' => 'sometimes|boolean',
            'confirmed_customer_consent' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Invalid conversation update.', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (array_key_exists('human_handoff_required', $data)) {
            $conversation->human_handoff_required = (bool) $data['human_handoff_required'];
        }
        if (array_key_exists('marketing_opt_in', $data)) {
            if ($data['marketing_opt_in'] && ! ($data['confirmed_customer_consent'] ?? false)) {
                return response()->json(['success' => false, 'message' => 'Confirm that this Messenger contact explicitly consented before enabling marketing.'], 422);
            }
            if ($data['marketing_opt_in']) {
                $conversation->marketing_opt_in_at = now();
                $conversation->marketing_opt_in_by = $request->user()->id;
                $conversation->marketing_opt_out_at = null;
            } else {
                $conversation->marketing_opt_in_at = null;
                $conversation->marketing_opt_in_by = null;
            }
        }
        $conversation->save();

        return response()->json(['success' => true, 'data' => $conversation->fresh()->toAutomationArray()]);
    }

    public function aiDraft(FacebookMessengerConversation $conversation): JsonResponse
    {
        $result = $this->facebook->aiDraft($conversation);
        return response()->json($result, $result['success'] ? 200 : 503);
    }

    public function reply(Request $request, FacebookMessengerConversation $conversation): JsonResponse
    {
        $validator = Validator::make($request->all(), ['message_text' => 'required|string|min:1|max:900']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'A Messenger reply is required and must be 900 characters or fewer.', 'errors' => $validator->errors()], 422);
        }

        $result = $this->facebook->sendConversationReply($conversation, $request->input('message_text'));
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function campaigns(): JsonResponse
    {
        $campaigns = FacebookMarketingCampaign::query()->latest()->limit(30)->get();
        return response()->json(['success' => true, 'data' => $campaigns->map(fn (FacebookMarketingCampaign $campaign) => $campaign->toAutomationArray())->values()]);
    }

    public function posts(): JsonResponse
    {
        $posts = FacebookPagePostDraft::query()->latest()->limit(30)->get();
        return response()->json(['success' => true, 'data' => $posts->map(fn (FacebookPagePostDraft $post) => $post->toAutomationArray())->values()]);
    }

    public function generatePost(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'topic' => 'required|string|min:3|max:160',
            'details' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'A clear post topic is required.', 'errors' => $validator->errors()], 422);
        }

        $result = $this->facebook->aiMarketingPostDraft($request->input('topic'), $request->input('details'));
        return response()->json($result, $result['success'] ? 200 : 503);
    }

    public function createPost(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'connection_id' => 'required|exists:facebook_page_connections,id',
            'topic' => 'required|string|min:3|max:160',
            'message_text' => 'required|string|min:3|max:5000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Choose a Page, topic, and post text before saving.', 'errors' => $validator->errors()], 422);
        }

        $connection = FacebookPageConnection::query()->whereKey($request->input('connection_id'))->where('is_active', true)->first();
        if (! $connection) {
            return response()->json(['success' => false, 'message' => 'Choose an active connected Facebook Page.'], 422);
        }

        $post = FacebookPagePostDraft::create([
            'facebook_page_connection_id' => $connection->id,
            'topic' => trim($request->input('topic')),
            'message_text' => trim($request->input('message_text')),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'data' => $post->toAutomationArray()], 201);
    }

    public function publishPost(Request $request, FacebookPagePostDraft $post): JsonResponse
    {
        if (! $request->boolean('confirm_publish')) {
            return response()->json(['success' => false, 'message' => 'Confirm publication before this Facebook Page post is sent.'], 422);
        }

        $result = $this->facebook->publishPost($post, $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function createCampaign(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'connection_id' => 'required|exists:facebook_page_connections,id',
            'name' => 'required|string|min:3|max:120',
            'message_text' => 'required|string|min:3|max:900',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Campaign name, Page, and message are required.', 'errors' => $validator->errors()], 422);
        }

        $connection = FacebookPageConnection::query()->whereKey($request->input('connection_id'))->where('is_active', true)->first();
        if (! $connection) {
            return response()->json(['success' => false, 'message' => 'Choose an active connected Facebook Page.'], 422);
        }

        $campaign = FacebookMarketingCampaign::create([
            'facebook_page_connection_id' => $connection->id,
            'name' => trim($request->input('name')),
            'message_text' => trim($request->input('message_text')),
            'recipient_count' => $this->facebook->eligibleMarketingCount($connection),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'data' => $campaign->toAutomationArray()], 201);
    }

    public function sendCampaign(Request $request, FacebookMarketingCampaign $campaign): JsonResponse
    {
        if (! $request->boolean('confirm_send')) {
            return response()->json(['success' => false, 'message' => 'Confirm this campaign send before any Messenger messages are queued.'], 422);
        }

        $result = $this->facebook->queueCampaign($campaign, $request->user());
        return response()->json($result, $result['success'] ? 202 : 422);
    }

    public function updateAutomationSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'auto_reply_enabled' => 'required|boolean',
            'marketing_enabled' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Automation controls must be true or false.', 'errors' => $validator->errors()], 422);
        }

        Setting::put('facebook_automation.auto_reply_enabled', $request->boolean('auto_reply_enabled'), 'bool');
        Setting::put('facebook_automation.marketing_enabled', $request->boolean('marketing_enabled'), 'bool');

        return response()->json(['success' => true, 'message' => 'Facebook automation controls updated.']);
    }

    /** Meta webhook verification endpoint; intentionally unauthenticated. */
    public function verifyWebhook(Request $request)
    {
        if (! $this->facebook->verifyWebhook((string) $request->query('hub_mode'), (string) $request->query('hub_verify_token'))) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    /** Meta webhook event endpoint; signature verification is mandatory. */
    public function receiveWebhook(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (! $this->facebook->validWebhookSignature($raw, $request->header('X-Hub-Signature-256'))) {
            return response()->json(['success' => false, 'message' => 'Invalid Facebook webhook signature.'], 403);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid Facebook webhook body.'], 422);
        }

        $this->facebook->receiveWebhook($payload);
        return response()->json(['success' => true]);
    }
}
