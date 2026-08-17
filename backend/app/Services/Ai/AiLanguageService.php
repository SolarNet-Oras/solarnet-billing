<?php

namespace App\Services\Ai;

/**
 * Small, deterministic language policy for SolarNet conversations.
 *
 * Detection deliberately uses only lightweight language cues. It chooses a
 * response language and lets the model understand the full message; it never
 * claims that an unsupported language has been enabled for customer replies.
 */
class AiLanguageService
{
    /** @return array<string, array{name: string, response_enabled: bool}> */
    public function supported(): array
    {
        return config('ai_languages.languages', []);
    }

    public function defaultLanguage(): string
    {
        return $this->normalise((string) config('ai_languages.default', 'en')) ?? 'en';
    }

    public function fallbackLanguage(): string
    {
        return $this->normalise((string) config('ai_languages.fallback', 'en')) ?? 'en';
    }

    public function languageName(?string $code): string
    {
        $code = $this->normalise($code);
        return $code && isset($this->supported()[$code])
            ? (string) $this->supported()[$code]['name']
            : 'English';
    }

    public function isResponseEnabled(?string $code): bool
    {
        $code = $this->normalise($code);
        return $code !== null && (bool) ($this->supported()[$code]['response_enabled'] ?? false);
    }

    /**
     * @return array{detected_language: string, confidence: float, source: string, explicit: bool}
     */
    public function detect(string $message): array
    {
        $message = trim($message);
        $normalised = mb_strtolower($message);

        if ($explicit = $this->explicitLanguageRequest($normalised)) {
            return [
                'detected_language' => $explicit,
                'confidence' => 1.0,
                'source' => 'explicit_request',
                'explicit' => true,
            ];
        }

        if (preg_match('/[\p{Hiragana}\p{Katakana}]/u', $message)) {
            return ['detected_language' => 'ja', 'confidence' => 0.95, 'source' => 'automatic_detection', 'explicit' => false];
        }
        if (preg_match('/\p{Han}/u', $message)) {
            return ['detected_language' => 'zh', 'confidence' => 0.9, 'source' => 'automatic_detection', 'explicit' => false];
        }

        $scores = [
            'fil' => $this->score($normalised, [
                'bakit', 'wala', 'walang', 'hindi', 'hindi po', 'mabagal',
                'namin', 'ninyo', 'pakitingnan', 'paki', 'salamat', 'opo',
                'po', 'kayo', 'kami', 'yung', 'ang internet', 'nagbayad',
            ]),
            'ceb' => $this->score($normalised, [
                'ngano', 'hinay', 'kaayo', 'among', 'wala mi', 'dili',
                'palihog', 'salamat kaayo',
            ]),
            'ilo' => $this->score($normalised, ['apay', 'awan', 'uneg', 'manong', 'manang']),
            'hil' => $this->score($normalised, ['ngaa', 'wala kami', 'gid', 'palihog']),
            'war' => $this->score($normalised, ['kay ano', 'waray', 'maupay', 'ada']),
            'es' => $this->score($normalised, ['hola', 'gracias', 'por favor', 'internet lento']),
        ];

        arsort($scores);
        $language = (string) array_key_first($scores);
        $score = (int) ($scores[$language] ?? 0);
        if ($score > 0) {
            return [
                'detected_language' => $language,
                'confidence' => min(0.95, 0.42 + ($score * 0.16)),
                'source' => 'automatic_detection',
                'explicit' => false,
            ];
        }

        return [
            'detected_language' => 'en',
            'confidence' => 0.55,
            'source' => 'automatic_detection',
            'explicit' => false,
        ];
    }

    /**
     * Resolve a conversation response language. An existing language remains
     * stable unless the person explicitly asks to switch.
     *
     * @return array{language: string, language_name: string, detected_language: string, detected_language_name: string, source: string, explicit: bool, fallback_required: bool}
     */
    public function resolve(?string $currentLanguage, string $message): array
    {
        $detected = $this->detect($message);
        $current = $this->normalise($currentLanguage);
        $requested = $detected['detected_language'];
        $fallbackRequired = false;

        if ($detected['explicit']) {
            $language = $requested;
        } elseif ($current && $this->isResponseEnabled($current)) {
            $language = $current;
        } elseif ($this->isResponseEnabled($requested)) {
            $language = $requested;
        } else {
            $language = $this->fallbackLanguage();
            $fallbackRequired = $requested !== $language;
        }

        if (!$this->isResponseEnabled($language)) {
            $language = $this->fallbackLanguage();
            $fallbackRequired = true;
        }
        if (!$this->isResponseEnabled($requested) && $requested !== $this->fallbackLanguage()) {
            $fallbackRequired = true;
        }

        return [
            'language' => $language,
            'language_name' => $this->languageName($language),
            'detected_language' => $requested,
            'detected_language_name' => $this->languageName($requested),
            'source' => $detected['source'],
            'explicit' => $detected['explicit'],
            'fallback_required' => $fallbackRequired,
        ];
    }

    /**
     * A customer preference can change immediately only on an explicit choice.
     * Otherwise require three consecutive, confidently detected messages.
     *
     * @param array<int, string> $recentLanguages
     */
    public function customerPreferenceCandidate(?string $storedLanguage, array $decision, array $recentLanguages): ?string
    {
        $target = $decision['language'] ?? null;
        if (!$this->isResponseEnabled($target) || $target === $this->normalise($storedLanguage)) {
            return null;
        }

        if (!empty($decision['explicit'])) {
            return $target;
        }

        $recent = array_values(array_filter(array_map(fn ($code) => $this->normalise($code), $recentLanguages)));
        if (count($recent) >= 3 && count(array_slice($recent, -3)) === 3 && count(array_unique(array_slice($recent, -3))) === 1 && end($recent) === $target) {
            return $target;
        }

        return null;
    }

    public function unsupportedLanguageNotice(array $decision): ?string
    {
        if (empty($decision['fallback_required'])) {
            return null;
        }

        if (($decision['language'] ?? 'en') === 'fil') {
            return 'Mukhang mas gusto ninyong gumamit ng ' . $decision['detected_language_name'] . '. Sa ngayon, maaari akong magpatuloy nang malinaw sa English o Filipino. Alin po ang gusto ninyo?';
        }

        return 'I understood that you may prefer ' . $decision['detected_language_name'] . '. For now, I can continue clearly in English or Filipino. Which would you prefer?';
    }

    private function score(string $message, array $markers): int
    {
        $score = 0;
        foreach ($markers as $marker) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($marker, '/') . '(?![\p{L}\p{N}])/u', $message)) {
                $score++;
            }
        }
        return $score;
    }

    private function explicitLanguageRequest(string $message): ?string
    {
        $requests = [
            'en' => ['english please', 'in english', 'english po', 'speak english'],
            'fil' => ['tagalog', 'filipino', 'filipino po', 'tagalog po'],
            'ceb' => ['cebuano', 'bisaya'],
            'ilo' => ['ilocano'],
            'hil' => ['hiligaynon', 'ilonggo'],
            'war' => ['waray'],
            'es' => ['spanish', 'espanol'],
            'zh' => ['chinese', 'mandarin'],
            'ja' => ['japanese'],
        ];

        foreach ($requests as $language => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($message, $phrase)) {
                    return $language;
                }
            }
        }

        return null;
    }

    private function normalise(?string $code): ?string
    {
        $code = $code ? mb_strtolower(trim($code)) : null;
        if ($code === 'tl') {
            $code = 'fil';
        }
        return $code && isset($this->supported()[$code]) ? $code : null;
    }
}
