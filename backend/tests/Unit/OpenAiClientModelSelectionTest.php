<?php

namespace Tests\Unit;

use App\Services\Ai\OpenAiClient;
use Tests\TestCase;

class OpenAiClientModelSelectionTest extends TestCase
{
    public function test_only_the_configured_administrator_models_can_be_selected(): void
    {
        config()->set('openai.admin_chat_models', [
            'gpt-5.4-mini',
            'gpt-5.4',
            'gpt-5.4-pro',
            'gpt-5.6-luna',
            'gpt-5.3-codex',
        ]);

        $client = app(OpenAiClient::class);

        $this->assertTrue($client->canSelectChatModel('gpt-5.4-mini'));
        $this->assertTrue($client->canSelectChatModel('gpt-5.6-luna'));
        $this->assertFalse($client->canSelectChatModel('gpt-4o'));
        $this->assertFalse($client->canSelectChatModel('arbitrary-model-name'));
    }

    public function test_luna_and_pro_use_the_responses_transport(): void
    {
        $client = app(OpenAiClient::class);
        $method = new \ReflectionMethod($client, 'usesResponsesApi');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($client, 'gpt-5.6-luna'));
        $this->assertTrue($method->invoke($client, 'gpt-5.4-pro'));
        $this->assertFalse($method->invoke($client, 'gpt-5.4-mini'));
        $this->assertFalse($method->invoke($client, 'gpt-5.3-codex'));
    }
}
