<?php

namespace App\Services;

class CollectionAddressNormalizer
{
    /**
     * These aliases are intentionally conservative. Misspellings are reported
     * for review instead of being silently treated as a verified barangay.
     */
    private const VERIFIED_ALIASES = [
        'san eduardo' => 'San Eduardo',
        'saurong' => 'Saurong',
        'paypayon' => 'Paypayon',
    ];

    public function analyze(?string $address): array
    {
        $original = trim((string) $address);
        $normalized = $this->normalize($original);
        preg_match('/\b(?:phase|ph)\s*([a-g]|[1-7])\b/i', $normalized, $phaseMatch);
        $phase = isset($phaseMatch[1]) ? 'Phase '.strtoupper($phaseMatch[1]) : null;
        $barangay = null;

        foreach (self::VERIFIED_ALIASES as $alias => $canonical) {
            if (preg_match('/(?:^|\s)'.preg_quote($alias, '/').'(?:$|\s)/i', $normalized)) {
                $barangay = $canonical;
                break;
            }
        }

        $ambiguousReason = null;
        if ($original === '') {
            $ambiguousReason = 'address_missing';
        } elseif (!$barangay) {
            $words = array_values(array_filter(explode(' ', $normalized), fn ($word) => strlen($word) >= 4));
            foreach (array_keys(self::VERIFIED_ALIASES) as $alias) {
                foreach ($words as $word) {
                    if (levenshtein($word, str_replace(' ', '', $alias)) <= 1) {
                        $ambiguousReason = 'possible_misspelling_requires_review';
                        break 2;
                    }
                }
            }
            $ambiguousReason ??= 'barangay_not_verified';
        }

        return [
            'original' => $original,
            'normalized' => $normalized,
            'barangay' => $barangay,
            'phase' => $phase,
            'reliable' => $barangay !== null,
            'ambiguous_reason' => $ambiguousReason,
        ];
    }

    public function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/[\/,_\.\-]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b(?:barangay|brgy)\b\.?/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b(?:oras|eastern samar|philippines)\b/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public function matches(?string $address, ?string $territoryPattern): bool
    {
        $addressTokens = array_values(array_filter(explode(' ', $this->normalize($address))));
        $patternTokens = array_values(array_filter(explode(' ', $this->normalize($territoryPattern))));
        if ($patternTokens === []) {
            return false;
        }

        return collect($patternTokens)->every(fn (string $token): bool => collect($addressTokens)
            ->contains(fn (string $addressToken): bool => str_starts_with($addressToken, $token)));
    }
}
