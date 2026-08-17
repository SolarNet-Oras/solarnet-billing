<?php

namespace Tests\Unit;

use App\Services\Ai\AiLanguageService;
use Tests\TestCase;

class AiLanguageServiceTest extends TestCase
{
    public function test_it_detects_english_filipino_taglish_and_cebuano(): void
    {
        $service = app(AiLanguageService::class);

        $this->assertSame('en', $service->detect('My internet is slow.')['detected_language']);
        $this->assertSame('en', $service->detect('Please create a support ticket.')['detected_language']);
        $this->assertSame('fil', $service->detect('Bakit po mabagal ang internet namin?')['detected_language']);
        $this->assertSame('fil', $service->detect('Boss wala po kaming internet since morning.')['detected_language']);
        $this->assertSame('ceb', $service->detect('Ngano hinay kaayo among internet?')['detected_language']);
    }

    public function test_an_explicit_language_request_overrides_conversation_memory(): void
    {
        $decision = app(AiLanguageService::class)->resolve('fil', 'Please explain this in English.');

        $this->assertSame('en', $decision['language']);
        $this->assertTrue($decision['explicit']);
    }

    public function test_a_single_different_message_does_not_replace_customer_preference(): void
    {
        $service = app(AiLanguageService::class);
        $decision = $service->resolve('fil', 'My internet is slow.');

        $this->assertSame('fil', $decision['language']);
        $this->assertNull($service->customerPreferenceCandidate('fil', $decision, ['en']));
    }

    public function test_consistent_detected_language_can_become_a_customer_preference(): void
    {
        $service = app(AiLanguageService::class);
        $decision = $service->resolve(null, 'Boss wala po kaming internet since morning.');

        $this->assertSame('fil', $service->customerPreferenceCandidate(null, $decision, ['fil', 'fil', 'fil']));
    }

    public function test_an_unsupported_detected_language_uses_safe_fallback(): void
    {
        $decision = app(AiLanguageService::class)->resolve(null, 'Ngano hinay kaayo among internet?');

        $this->assertSame('ceb', $decision['detected_language']);
        $this->assertSame('en', $decision['language']);
        $this->assertTrue($decision['fallback_required']);
    }
}
