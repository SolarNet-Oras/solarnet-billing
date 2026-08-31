<?php

namespace Tests\Unit;

use App\Services\FacebookMessengerService;
use App\Models\User;
use ReflectionMethod;
use Tests\TestCase;

class FacebookMessengerServiceTest extends TestCase
{
    public function test_it_requires_the_exact_meta_webhook_verification_token_and_signature(): void
    {
        config()->set('services.facebook.app_secret', 'facebook-test-secret');
        config()->set('services.facebook.webhook_verify_token', 'facebook-verify-token');

        $service = app(FacebookMessengerService::class);
        $body = '{"object":"page","entry":[]}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'facebook-test-secret');

        $this->assertTrue($service->verifyWebhook('subscribe', 'facebook-verify-token'));
        $this->assertFalse($service->verifyWebhook('subscribe', 'wrong-token'));
        $this->assertTrue($service->validWebhookSignature($body, $signature));
        $this->assertFalse($service->validWebhookSignature($body, 'sha256=invalid'));
    }

    public function test_it_interprets_clear_customer_opt_out_words_without_guessing(): void
    {
        $method = new ReflectionMethod(FacebookMessengerService::class, 'isOptOutText');

        $this->assertTrue($method->invoke(app(FacebookMessengerService::class), 'STOP'));
        $this->assertTrue($method->invoke(app(FacebookMessengerService::class), 'huwag na po'));
        $this->assertFalse($method->invoke(app(FacebookMessengerService::class), 'Please tell me about your plan'));
        $this->assertFalse($method->invoke(app(FacebookMessengerService::class), 'I will stop by later'));
    }

    public function test_page_connection_requests_messaging_and_manual_post_permissions(): void
    {
        config()->set('services.facebook.app_id', 'test-app');
        config()->set('services.facebook.app_secret', 'test-secret');
        config()->set('services.facebook.oauth_redirect_uri', 'https://billing.solarnetportal.com/api/v1/integrations/facebook/callback');

        $user = new User();
        $user->id = 'facebook-test-user';
        $url = app(FacebookMessengerService::class)->authorizationUrl($user);

        $this->assertNotNull($url);
        $this->assertStringContainsString('pages_messaging', $url);
        $this->assertStringContainsString('pages_read_engagement', $url);
        $this->assertStringContainsString('pages_manage_posts', $url);
    }

    public function test_auto_reply_prompt_requires_empathy_and_multilingual_customer_service(): void
    {
        $method = new ReflectionMethod(FacebookMessengerService::class, 'aiReplyPrompt');
        $prompt = $method->invoke(app(FacebookMessengerService::class));

        $this->assertStringContainsString('sincere empathy', $prompt);
        $this->assertStringContainsString('Filipino/Taglish', $prompt);
        $this->assertStringContainsString('Waray', $prompt);
        $this->assertStringContainsString('one short clarifying question', $prompt);
    }
}
