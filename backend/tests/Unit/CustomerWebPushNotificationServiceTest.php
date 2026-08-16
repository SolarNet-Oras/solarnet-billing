<?php

namespace Tests\Unit;

use App\Services\CustomerWebPushNotificationService;
use Tests\TestCase;

class CustomerWebPushNotificationServiceTest extends TestCase
{
    public function test_push_is_disabled_without_explicit_server_configuration(): void
    {
        config()->set('services.web_push.enabled', false);
        config()->set('services.web_push.vapid_subject', null);
        config()->set('services.web_push.vapid_public_key', null);
        config()->set('services.web_push.vapid_private_key', null);

        $this->assertFalse(app(CustomerWebPushNotificationService::class)->isConfigured());
    }
}
