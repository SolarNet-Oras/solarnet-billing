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

        return Cache::remember('ip-intelligence:ipinfo-lite:'.hash('sha256', $ip), now()->addHours(6), function () use ($ip): array {
            $token = trim((string) config('services.ip_intelligence.token'));
            if ($token === '') {
                abort(503, 'IPinfo Lite is not configured. Add the server-side IPINFO_TOKEN setting.');
            }

            $response = Http::withToken($token)->acceptJson()->timeout(12)->retry(1, 300)
                ->get(rtrim((string) config('services.ip_intelligence.base_url'), '/').'/'.rawurlencode($ip));
            if (! $response->successful()) {
                $message = trim((string) ($response->json('error.message') ?? $response->json('error') ?? 'IPinfo Lite could not complete this lookup.'));
                throw ValidationException::withMessages(['ip' => [$message]]);
            }

            return [
                'ip' => (string) ($response->json('ip') ?? $ip),
                'ip_type' => str_contains($ip, ':') ? 'IPv6' : 'IPv4',
                'asn' => $response->json('asn'),
                'as_name' => $response->json('as_name'),
                'isp' => null,
                'organization' => $response->json('as_name'),
                'domain' => $response->json('as_domain'),
                'connection_type' => null,
                'country' => $response->json('country'),
                'country_code' => $response->json('country_code'),
                'continent' => $response->json('continent'),
                'continent_code' => $response->json('continent_code'),
                'region' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
                'hosting' => null,
                'proxy' => null,
                'vpn' => null,
                'tor' => null,
                'anonymous' => null,
                'classification' => 'unavailable',
                'checked_at' => now()->toIso8601String(),
                'source' => 'IPinfo Lite',
            ];
        });
    }
}
