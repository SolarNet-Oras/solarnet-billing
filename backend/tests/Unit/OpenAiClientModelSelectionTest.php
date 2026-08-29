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
}
