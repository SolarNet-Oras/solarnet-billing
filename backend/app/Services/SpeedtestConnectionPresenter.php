<?php

namespace App\Services;

/**
 * Builds the public identity payload for SolarNet's speed-test UI.
 *
 * This intentionally does not perform an ISP/ASN lookup. If an existing
 * measurement service supplies that information later, it can add it without
 * changing the branded provider label or the visitor's actual public IP.
 */
class SpeedtestConnectionPresenter
{
    /** @return array<string, string|null> */
    public function present(?string $ip, string $providerDisplayName): array
    {
        $publicIp = $this->validIp($ip) ? $ip : null;

        return [
            'public_ip' => $publicIp,
            'provider_display_name' => $this->providerDisplayName($providerDisplayName),
            // Reserved for a future trusted network-information source. They
            // must never override provider_display_name in the customer UI.
            'detected_isp' => null,
            'detected_asn' => null,
            'detected_org' => null,
            'detected_city' => null,
            'detected_country' => null,
        ];
    }

    public function validIp(?string $ip): bool
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function providerDisplayName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return $name !== '' ? mb_substr($name, 0, 80) : 'Provider unavailable';
    }
}
