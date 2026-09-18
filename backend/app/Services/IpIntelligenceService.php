<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class IpIntelligenceService
{
    public function lookup(string $ip): array
    {
        $ip = trim($ip);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw ValidationException::withMessages(['ip' => ['Enter a valid public IPv4 or IPv6 address. Private and reserved addresses are not sent externally.']]);
        }

        return Cache::remember('ip-intelligence:'.hash('sha256', $ip), now()->addHours(6), function () use ($ip): array {
            $response = Http::acceptJson()->timeout(12)->retry(1, 300)
                ->get(rtrim((string) config('services.ip_intelligence.base_url'), '/').'/'.$ip);
            if (! $response->successful() || $response->json('success') !== true) {
                $message = trim((string) ($response->json('message') ?? 'The IP intelligence provider could not complete this lookup.'));
                throw ValidationException::withMessages(['ip' => [$message]]);
            }

            $connection = (array) $response->json('connection', []);
            $security = (array) $response->json('security', []);
            $hosting = (bool) ($security['hosting'] ?? false);

            return [
                'ip' => (string) ($response->json('ip') ?? $ip),
                'ip_type' => $response->json('type'),
                'asn' => isset($connection['asn']) ? 'AS'.ltrim((string) $connection['asn'], 'ASas') : null,
                'as_name' => $connection['org'] ?? $connection['isp'] ?? null,
                'isp' => $connection['isp'] ?? null,
                'organization' => $connection['org'] ?? null,
                'domain' => $connection['domain'] ?? null,
                'connection_type' => $connection['type'] ?? null,
                'country' => $response->json('country'),
                'country_code' => $response->json('country_code'),
                'region' => $response->json('region'),
                'city' => $response->json('city'),
                'latitude' => $response->json('latitude'),
                'longitude' => $response->json('longitude'),
                'hosting' => $hosting,
                'proxy' => (bool) ($security['proxy'] ?? false),
                'vpn' => (bool) ($security['vpn'] ?? false),
                'tor' => (bool) ($security['tor'] ?? false),
                'anonymous' => (bool) ($security['anonymous'] ?? false),
                'classification' => $hosting ? 'hosting' : 'access_network',
                'checked_at' => now()->toIso8601String(),
                'source' => 'IPWhois',
            ];
        });
    }
}
